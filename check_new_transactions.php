<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Consider restricting this in production
header('Cache-Control: no-cache, no-store, must-revalidate');

// Ensure user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(["error" => "Unauthorized access. Please login."]);
    exit;
}

$userPhoneNumber = $_SESSION['phone_number'] ?? null;
if (!$userPhoneNumber) {
    echo json_encode(["error" => "User phone number not found in session."]);
    exit;
}

// Get sender's UPI ID variants from frontend (comma-separated string)
$senderUpiIdVariantsString = $_GET['sender_upi_id_variants'] ?? '';
// Get the original raw sender UPI ID for Fampay-to-Fampay check
$rawSenderUpiId = $_GET['raw_sender_upi_id'] ?? '';
// Get receiver's UPI ID (App owner's Fampay VPA, e.g., 'kumarchx@fam')
$receiverUpiIdOnFampay = $_GET['receiver_upi_id_on_fampay'] ?? '';

if (empty($senderUpiIdVariantsString) || empty($receiverUpiIdOnFampay) || empty($rawSenderUpiId)) {
    echo json_encode(["error" => "Sender UPI ID variants, raw sender UPI ID, and App Owner's Fampay UPI ID are required for tracking."]);
    exit;
}

// Convert variants string to an array and normalize
$senderUpiIdVariants = array_map('strtolower', array_map('trim', explode(',', $senderUpiIdVariantsString)));
$normalizedReceiverUpiOnFampay = strtolower(trim($receiverUpiIdOnFampay));
$normalizedRawSenderUpiId = strtolower(trim($rawSenderUpiId));

$processedIDsFile = __DIR__ . '/../data/processed_ids.json';

// Load processed IDs
$processedIDs = [];
if (file_exists($processedIDsFile)) {
    $processedIDs = json_decode(file_get_contents($processedIDsFile), true);
    if (!is_array($processedIDs)) $processedIDs = [];
}

// Fetch data from external API
$url = "https://tnx-all.vercel.app/";
$response = @file_get_contents($url);

if ($response === FALSE) {
    error_log("Failed to fetch from external TNX API: " . error_get_last()['message']);
    echo json_encode(["error" => "Failed to fetch data from external payment API."]);
    exit;
}

$data = json_decode($response, true);
if (!$data || !isset($data['results'])) {
    error_log("Invalid or empty response from external TNX API: " . $response);
    echo json_encode(["error" => "Received invalid response from external API."]);
    exit;
}

$newRelevantTransactions = [];
$updatedProcessedIDs = $processedIDs;

foreach ($data['results'] as $txn) {
    // Check if the transaction has already been processed
    if (in_array($txn['id'], $processedIDs)) {
        continue;
    }

    $txn_id = $txn['id'] ?? 'N/A';
    $txn_status_code = $txn['status'] ?? null; // Status code, e.g., 2
    $txn_mode = $txn['mode'] ?? ''; // e.g., "UC" for UPI Credit

    // According to your JSON:
    // `is_me: true` means the transaction is *for* the Fampay account owner (Manish Kumar).
    // `mode: "UC"` means it's a UPI Credit.
    // `external.vpa_data.vpa` is the VPA *from which* the money came (sender).
    $is_credit_to_app_owner_fampay = ($txn['is_me'] === true && $txn_mode === 'UC');
    $is_successful = ($txn_status_code === 2); // Assuming status 2 means success in your JSON

    // Extract sender's VPA from the external transaction
    $txn_sender_vpa_from_api = strtolower(trim($txn['external']['vpa_data']['vpa'] ?? ''));

    // Filter conditions:
    // 1. Transaction is a successful UPI Credit to the app owner's Fampay account.
    // 2. The sender's VPA (`txn_sender_vpa_from_api`) must match one of the user's provided external VPA variants.
    // 3. IMPORTANT: Explicitly filter out payments where the sender's VPA is the App Owner's Fampay ID.
    //    This means a user trying to add funds by sending from kumarchx@fam to kumarchx@fam will be rejected.
    if (
        $is_credit_to_app_owner_fampay &&
        $is_successful &&
        in_array($txn_sender_vpa_from_api, $senderUpiIdVariants, true) && // Sender VPA matches one of the user's variants
        $txn_sender_vpa_from_api !== $normalizedReceiverUpiOnFampay // Sender's VPA is NOT the app owner's Fampay ID itself
    ) {
        $newRelevantTransactions[] = $txn;
        $updatedProcessedIDs[] = $txn['id']; // Mark this new one as processed
    }
}

// Save updated processed IDs
file_put_contents($processedIDsFile, json_encode($updatedProcessedIDs));

echo json_encode(["results" => $newRelevantTransactions]);
?>
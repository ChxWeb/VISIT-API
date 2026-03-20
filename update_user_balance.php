<?php
session_start();
header('Content-Type: application/json');

// Include email configuration and sender helper
require_once __DIR__ . '/email/config.php';
require_once __DIR__ . '/email/send_transaction_email.php'; // Our helper function

// Ensure user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

// Get logged-in user's phone number and email from session (this is the NexusPay account holder)
$sessionPhoneNumber = $_SESSION['phone_number'] ?? null;
$sessionUserEmail = $_SESSION['email'] ?? null;
$sessionUserName = $_SESSION['name'] ?? 'NexusPay User';

if (!$sessionPhoneNumber || !$sessionUserEmail || !$sessionUserName) {
    echo json_encode(['success' => false, 'message' => 'User session data incomplete. Please re-login.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// This amount is ALREADY divided by 100 in the frontend (JavaScript)
$amountINR = (float)($input['amount'] ?? 0); // Amount received in INR (e.g., if external API was 100, frontend sends 1.00)

$senderName = htmlspecialchars($input['sender_name'] ?? 'External Sender'); // Name from external payment
$transactionId = htmlspecialchars($input['transaction_id'] ?? 'N/A'); // External transaction ID
$senderUpiIdExternal = htmlspecialchars($input['sender_upi_id_external'] ?? 'N/A'); // User's external VPA
$receiverUpiIdFampay = htmlspecialchars($input['receiver_upi_id_on_fampay'] ?? 'N/A'); // App owner's Fampay VPA
$nexusPayUserVpa = htmlspecialchars($input['nexus_pay_user_vpa'] ?? 'N/A'); // Logged-in NexusPay user's own VPA

if ($amountINR <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid amount.']);
    exit;
}

// Define the NEX to INR conversion rate
const NEX_TO_INR_RATE = 0.5;
// Calculate equivalent NEX from INR
$amountNEX = $amountINR / NEX_TO_INR_RATE;

$userFilePath = '../data/user.json';
$transactionsFilePath = '../data/transactions.json';

$users = [];
if (file_exists($userFilePath)) {
    $jsonContent = file_get_contents($userFilePath);
    $users = json_decode($jsonContent, true);
    if (!is_array($users)) {
        $users = [];
    }
}

$currentUser = null;
$currentUserKey = null;

// Find the logged-in NexusPay user to update THEIR balance
foreach ($users as $key => $user) {
    if (isset($user['phoneNumber']) && $user['phoneNumber'] === $sessionPhoneNumber) {
        $currentUser = &$users[$key]; // Use reference to directly modify
        $currentUserKey = $key;
        break;
    }
}

if ($currentUser === null) {
    echo json_encode(['success' => false, 'message' => 'Logged-in user account not found for balance update.']);
    exit;
}

// Update NexusPay user's virtual token balance
$currentUser['balance'] += $amountNEX;
$updatedBalanceNEX = $currentUser['balance'];
$updatedBalanceINR = $updatedBalanceNEX * NEX_TO_INR_RATE;

// Record transaction in NexusPay's internal transaction log
$timestamp = date('Y-m-d H:i:s');
$newTransaction = [
    'transaction_id' => $transactionId, // External transaction ID
    'timestamp' => $timestamp,
    'type' => 'credit', // This is an incoming payment (to NexusPay user's virtual account)
    'amount' => $amountNEX,
    'sender_name' => $senderName, // Name of the person who sent from external app
    'sender_phone' => 'External App User', // Generic for external sender
    'sender_email' => $senderUpiIdExternal, // User's external UPI ID as sender identifier
    'receiver_name' => $currentUser['name'], // NexusPay user's name
    'receiver_phone' => $currentUser['phoneNumber'], // NexusPay user's phone
    'receiver_email' => $currentUser['email'], // NexusPay user's email
    'status' => 'success',
    'source_vpa_external_app' => $senderUpiIdExternal, // External app's VPA used by user
    'target_vpa_fampay_owner' => $receiverUpiIdFampay, // App owner's Fampay VPA (where real money came)
    'credited_to_nexuspay_vpa' => $nexusPayUserVpa // NexusPay user's own VPA (where NEX tokens were credited)
];

$transactions = [];
if (file_exists($transactionsFilePath)) {
    $jsonContent = file_get_contents($transactionsFilePath);
    $transactions = json_decode($jsonContent, true);
    if (!is_array($transactions)) {
        $transactions = [];
    }
}
$transactions[] = $newTransaction;

// Save updated data
if (
    file_put_contents($userFilePath, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) &&
    file_put_contents($transactionsFilePath, json_encode($transactions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
) {
    // Update logged-in user's session balance immediately
    $_SESSION['balance'] = $updatedBalanceNEX;

    // --- NEW FEATURE: Send email notification for funds added ---
    sendTransactionEmail(
        $sessionUserEmail,
        $sessionUserName,
        'added_funds', // Custom type to trigger specific email content
        number_format($amountNEX, 2),
        number_format($amountINR, 2),
        $transactionId,
        $timestamp,
        $senderUpiIdExternal // Pass sender's external VPA for email context
    );

    echo json_encode([
        'success' => true,
        'message' => 'Funds successfully added to your NexusPay account!',
        'new_balance_nex' => number_format($updatedBalanceNEX, 2),
        'new_balance_inr' => number_format($updatedBalanceINR, 2)
    ]);
} else {
    error_log("CRITICAL ERROR: Failed to save user/transaction data after adding funds for {$sessionPhoneNumber}");
    echo json_encode(['success' => false, 'message' => 'Failed to add funds due to a server error. Please contact support.']);
}
?>
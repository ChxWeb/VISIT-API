<?php
session_start();
header('Content-Type: application/json');

// Include email configuration and sender helper
require_once __DIR__ . '/email/config.php';
require_once __DIR__ . '/email/send_transaction_email.php';

// Enable error logging for debugging (REMOVE IN PRODUCTION)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Ensure user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE ERROR: Unauthorized access attempt. Session not logged in.");
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

// Get logged-in user's details from session
$sessionPhoneNumber = $_SESSION['phone_number'] ?? null;
$sessionUserEmail = $_SESSION['email'] ?? null;
$sessionUserName = $_SESSION['name'] ?? 'NexusPay User';

if (!$sessionPhoneNumber || !$sessionUserEmail || !$sessionUserName) {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE ERROR: User session data incomplete for phone: " . ($sessionPhoneNumber ?? 'N/A') . ", email: " . ($sessionUserEmail ?? 'N/A'));
    echo json_encode(['success' => false, 'message' => 'User session data incomplete. Please re-login.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$requestedAmountINR = (float)($input['amount_inr'] ?? 0);

if ($requestedAmountINR <= 0) {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE ERROR: Invalid amount_inr received: {$requestedAmountINR}. User: {$sessionPhoneNumber}");
    echo json_encode(['success' => false, 'message' => 'Invalid amount for Google Play code.']);
    exit;
}

const NEX_TO_INR_RATE = 0.5; // Conversion rate
$amountNEX_needed = $requestedAmountINR / NEX_TO_INR_RATE;

$googlePlayCodesFilePath = '../data/google_play_codes.json';
$userFilePath = '../data/user.json';
$transactionsFilePath = '../data/transactions.json';

// --- Read User Data & Check Balance ---
$users = [];
if (file_exists($userFilePath)) {
    $jsonContent = file_get_contents($userFilePath);
    $users = json_decode($jsonContent, true);
    if (!is_array($users)) {
        error_log("PROCESS_GOOGLE_PLAY_PURCHASE WARNING: user.json is malformed or empty. Initializing empty array. File: {$userFilePath}");
        $users = [];
    }
} else {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE WARNING: user.json does not exist. Initializing empty array. File: {$userFilePath}");
}

$currentUser = null;
foreach ($users as $key => $user) {
    if (isset($user['phoneNumber']) && $user['phoneNumber'] === $sessionPhoneNumber) {
        $currentUser = &$users[$key]; // Use reference to modify
        break;
    }
}

if ($currentUser === null) {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE ERROR: Logged-in user '{$sessionPhoneNumber}' not found in user.json.");
    echo json_encode(['success' => false, 'message' => 'Logged-in user account not found.']);
    exit;
}

if (($currentUser['balance'] ?? 0.00) < $amountNEX_needed) {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE ERROR: Insufficient balance for user '{$sessionPhoneNumber}'. Needed {$amountNEX_needed} NEX, has " . ($currentUser['balance'] ?? 0.00) . ".");
    echo json_encode(['success' => false, 'message' => 'Insufficient NEX balance. Please add funds.']);
    exit;
}

// --- Read Google Play Codes Data & Find Unused Code ---
$allGooglePlayCodes = [];
if (file_exists($googlePlayCodesFilePath)) {
    $jsonContent = file_get_contents($googlePlayCodesFilePath);
    $allGooglePlayCodes = json_decode($jsonContent, true);
    if (!is_array($allGooglePlayCodes)) {
        error_log("PROCESS_GOOGLE_PLAY_PURCHASE WARNING: google_play_codes.json is malformed or empty. Initializing empty array. File: {$googlePlayCodesFilePath}");
        $allGooglePlayCodes = [];
    }
} else {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE WARNING: google_play_codes.json does not exist. Initializing empty array. File: {$googlePlayCodesFilePath}");
}

// Log current state of Google Play codes for debugging
error_log("PROCESS_GOOGLE_PLAY_PURCHASE DEBUG: Searching for code. Requested INR: {$requestedAmountINR}. Current Google Play codes: " . json_encode($allGooglePlayCodes));

$foundCode = null;
$foundCodeKey = null;

foreach ($allGooglePlayCodes as $key => $codeData) {
    // Log each code being evaluated
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE DEBUG: Evaluating code ID: " . ($codeData['id'] ?? 'N/A') . ", Amount: " . ($codeData['amount_inr'] ?? 'N/A') . ", Is_used: " . var_export($codeData['is_used'] ?? null, true));

    // Find an unused code that matches the requested INR amount
    if ((!isset($codeData['is_used']) || $codeData['is_used'] === false) && (float)($codeData['amount_inr'] ?? 0) === $requestedAmountINR) {
        $foundCode = &$allGooglePlayCodes[$key]; // Use reference to modify
        $foundCodeKey = $key;
        error_log("PROCESS_GOOGLE_PLAY_PURCHASE DEBUG: Found matching unused code: " . json_encode($foundCode));
        break;
    }
}

if ($foundCode === null) {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE ERROR: No unused Google Play code found for requested amount: {$requestedAmountINR} INR. User: {$sessionPhoneNumber}. File content during search: " . json_encode($allGooglePlayCodes));
    echo json_encode(['success' => false, 'message' => 'No Google Play codes available for this amount. Please try again later.']);
    exit;
}

// --- Deduct Balance & Update User Data ---
$currentUser['balance'] -= $amountNEX_needed;
$updatedBalanceNEX = $currentUser['balance'];
$updatedBalanceINR = $updatedBalanceNEX * NEX_TO_INR_RATE;

// --- Mark Google Play Code as Used & Save Codes ---
$foundCode['is_used'] = true;
$foundCode['used_by_user_phone'] = $sessionPhoneNumber;
$foundCode['used_at'] = date('Y-m-d H:i:s');
$foundCode['user_email'] = $sessionUserEmail; // Store user's email for redemption record
$redeemCodeString = $foundCode['code'] ?? 'N/A'; // Get the actual code for email and logs

if (!is_writable(dirname($googlePlayCodesFilePath))) {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE CRITICAL ERROR: Directory not writable: " . dirname($googlePlayCodesFilePath));
    // Attempt to revert user balance if directory not writable
    $currentUser['balance'] += $amountNEX_needed;
    // Don't try to write to user.json again if data directory is problematic
    echo json_encode(['success' => false, 'message' => 'Server error: Data directory not writable. Please contact support.']);
    exit;
}
if (!file_put_contents($googlePlayCodesFilePath, json_encode($allGooglePlayCodes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE CRITICAL ERROR: Failed to write to google_play_codes.json for code ID " . ($foundCode['id'] ?? 'N/A') . ". Check file permissions. File: {$googlePlayCodesFilePath}");
    // Attempt to revert user balance if code update fails (critical inconsistency)
    $currentUser['balance'] += $amountNEX_needed;
    // Try to save user balance change, though consistency is already compromised
    @file_put_contents($userFilePath, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $_SESSION['balance'] = $currentUser['balance']; // Update session even if it's a compromised rollback
    echo json_encode(['success' => false, 'message' => 'Failed to process Google Play purchase due to server error (code not marked used). Please contact support.']);
    exit;
}

// --- Record Transaction ---
$transactionId = 'GPL' . uniqid(); // Generate a unique transaction ID for Google Play purchase
$timestamp = date('Y-m-d H:i:s');
$newTransaction = [
    'transaction_id' => $transactionId,
    'timestamp' => $timestamp,
    'type' => 'google_play_purchase', // Specific type
    'amount' => $amountNEX_needed, // Amount in NEX (debited)
    'google_play_code_amount_inr' => $requestedAmountINR, // Store INR value of the code
    'sender_name' => $sessionUserName, // User is the sender (paying for code)
    'sender_phone' => $sessionPhoneNumber,
    'sender_email' => $sessionUserEmail,
    'receiver_name' => 'Google Play Store', // Receiver of funds from user
    'receiver_phone' => 'N/A',
    'receiver_email' => 'store@nexuspay.com',
    'status' => 'success',
    'purchased_code_id' => $foundCode['id'],
    'purchased_redeem_code' => $redeemCodeString // Store the actual redeem code for transaction history
];

$transactions = [];
if (file_exists($transactionsFilePath)) {
    $jsonContent = file_get_contents($transactionsFilePath);
    $transactions = json_decode($jsonContent, true);
    if (!is_array($transactions)) {
        error_log("PROCESS_GOOGLE_PLAY_PURCHASE WARNING: transactions.json is malformed or empty. Initializing empty array. File: {$transactionsFilePath}");
        $transactions = [];
    }
} else {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE WARNING: transactions.json does not exist. Initializing empty array. File: {$transactionsFilePath}");
}
$transactions[] = $newTransaction;

// --- Save All Data (user balance and transaction log) ---
if (!is_writable(dirname($userFilePath)) || !is_writable(dirname($transactionsFilePath))) {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE CRITICAL ERROR: Data directory for user/transactions not writable. Directory: " . dirname($userFilePath));
    echo json_encode(['success' => false, 'message' => 'Server error: Critical data directory not writable. Please contact support.']);
    exit;
}

if (
    file_put_contents($userFilePath, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) &&
    file_put_contents($transactionsFilePath, json_encode($transactions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
) {
    // Update session balance
    $_SESSION['balance'] = $updatedBalanceNEX;

    // Store transaction details in session for the success page
    $_SESSION['last_google_play_purchase_details'] = $newTransaction;

    // --- Send email notification ---
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE INFO: Attempting to send Google Play purchase email to {$sessionUserEmail} for code ID {$foundCode['id']}.");
    $emailSent = sendTransactionEmail(
        $sessionUserEmail,
        $sessionUserName,
        'google_play_purchase', // Custom type
        number_format($amountNEX_needed, 2),
        number_format($requestedAmountINR, 2),
        $transactionId,
        $timestamp,
        $redeemCodeString // Pass the actual redeem code for email content
    );

    if ($emailSent) {
        error_log("PROCESS_GOOGLE_PLAY_PURCHASE INFO: Google Play purchase email sent successfully to {$sessionUserEmail}.");
    } else {
        error_log("PROCESS_GOOGLE_PLAY_PURCHASE WARNING: Failed to send Google Play purchase email to {$sessionUserEmail}. Check send_transaction_email.php logs for PHPMailer errors.");
    }

    echo json_encode([
        'success' => true,
        'message' => "Google Play code purchased successfully! Redeem code sent to your email.",
        'redirect' => 'google_play_success.php' // Redirect to the new success page
    ]);
} else {
    error_log("PROCESS_GOOGLE_PLAY_PURCHASE CRITICAL ERROR: Failed to write to user.json or transactions.json after Google Play purchase for '{$sessionPhoneNumber}'. Check file permissions.");
    echo json_encode(['success' => false, 'message' => 'Failed to save transaction data. Please contact support.']);
}
<?php
session_start();
header('Content-Type: application/json');

// Include email configuration and sender helper
require_once __DIR__ . '/email/config.php';
require_once __DIR__ . '/email/send_transaction_email.php';

// Enable error logging for debugging (remove in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Ensure user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

// Get logged-in user's details from session
$sessionPhoneNumber = $_SESSION['phone_number'] ?? null;
$sessionUserEmail = $_SESSION['email'] ?? null;
$sessionUserName = $_SESSION['name'] ?? 'NexusPay User';
$nexusPayUserVpa = str_replace('+91', '', $sessionPhoneNumber) . '@nexiopay';

if (!$sessionPhoneNumber || !$sessionUserEmail || !$sessionUserName) {
    error_log("PROCESS_REDEEM_CODE ERROR: User session data incomplete for phone: " . ($sessionPhoneNumber ?? 'N/A') . ", email: " . ($sessionUserEmail ?? 'N/A'));
    echo json_encode(['success' => false, 'message' => 'User session data incomplete. Please re-login.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$redeemCode = trim($input['redeem_code'] ?? '');

if (empty($redeemCode)) {
    echo json_encode(['success' => false, 'message' => 'Redeem code cannot be empty.']);
    exit;
}

const NEX_TO_INR_RATE = 0.5; // Conversion rate

$redeemCodesFilePath = '../data/redeem_codes.json';
$userFilePath = '../data/user.json';
$transactionsFilePath = '../data/transactions.json';

// --- Read Redeem Codes Data ---
$allRedeemCodes = [];
if (file_exists($redeemCodesFilePath)) {
    $jsonContent = file_get_contents($redeemCodesFilePath);
    $allRedeemCodes = json_decode($jsonContent, true);
    if (!is_array($allRedeemCodes)) {
        $allRedeemCodes = [];
    }
}

$foundCode = null;
$foundCodeKey = null;

// Find the redeem code
foreach ($allRedeemCodes as $key => $codeData) {
    if (isset($codeData['code']) && strtolower($codeData['code']) === strtolower($redeemCode)) {
        $foundCode = &$allRedeemCodes[$key]; // Use reference to modify
        $foundCodeKey = $key;
        break;
    }
}

if ($foundCode === null) {
    error_log("PROCESS_REDEEM_CODE ERROR: Invalid redeem code entered: {$redeemCode} by {$sessionPhoneNumber}");
    echo json_encode(['success' => false, 'message' => 'Invalid redeem code.']);
    exit;
}

if ($foundCode['is_used']) {
    error_log("PROCESS_REDEEM_CODE ERROR: Redeem code already used: {$redeemCode} by {$sessionPhoneNumber}");
    echo json_encode(['success' => false, 'message' => 'This redeem code has already been used.']);
    exit;
}

$amountINR = (float)($foundCode['amount_inr'] ?? 0);
if ($amountINR <= 0) {
    error_log("PROCESS_REDEEM_CODE ERROR: Redeem code has invalid amount: {$redeemCode} for {$amountINR} INR.");
    echo json_encode(['success' => false, 'message' => 'Redeem code has an invalid amount. Please contact support.']);
    exit;
}

$amountNEX = $amountINR / NEX_TO_INR_RATE;

// --- Update User Balance ---
$users = [];
if (file_exists($userFilePath)) {
    $jsonContent = file_get_contents($userFilePath);
    $users = json_decode($jsonContent, true);
    if (!is_array($users)) {
        $users = [];
    }
}

$currentUser = null;
foreach ($users as $key => $user) {
    if (isset($user['phoneNumber']) && $user['phoneNumber'] === $sessionPhoneNumber) {
        $currentUser = &$users[$key];
        break;
    }
}

if ($currentUser === null) {
    error_log("PROCESS_REDEEM_CODE ERROR: Logged-in user not found in user.json: {$sessionPhoneNumber}");
    echo json_encode(['success' => false, 'message' => 'Logged-in user account not found.']);
    exit;
}

$currentUser['balance'] += $amountNEX;
$updatedBalanceNEX = $currentUser['balance'];
$updatedBalanceINR = $updatedBalanceNEX * NEX_TO_INR_RATE;

// --- Mark Code as Used & Save Redeem Codes ---
$foundCode['is_used'] = true;
$foundCode['used_by_user_phone'] = $sessionPhoneNumber;
$foundCode['used_at'] = date('Y-m-d H:i:s');

if (!file_put_contents($redeemCodesFilePath, json_encode($allRedeemCodes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    error_log("PROCESS_REDEEM_CODE CRITICAL ERROR: Failed to update redeem_codes.json for code {$redeemCode}");
    echo json_encode(['success' => false, 'message' => 'Failed to process redeem code due to server error. Please contact support.']);
    exit;
}

// --- Record Transaction ---
$transactionId = 'RED' . uniqid(); // Generate a unique transaction ID for redemption
$timestamp = date('Y-m-d H:i:s');
$newTransaction = [
    'transaction_id' => $transactionId,
    'timestamp' => $timestamp,
    'type' => 'redeem_code',
    'amount' => $amountNEX,
    'sender_name' => 'NexusPay System', // Sender for redeem code
    'sender_phone' => 'N/A',
    'sender_email' => 'system@nexuspay.com',
    'receiver_name' => $sessionUserName,
    'receiver_phone' => $sessionPhoneNumber,
    'receiver_email' => $sessionUserEmail,
    'status' => 'success',
    'redeemed_code' => $redeemCode // Specific field for redeem code
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

// --- Save All Data ---
if (
    file_put_contents($userFilePath, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) &&
    file_put_contents($transactionsFilePath, json_encode($transactions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
) {
    // Update session balance
    $_SESSION['balance'] = $updatedBalanceNEX;

    // Store transaction details in session for the success page
    $_SESSION['last_redeem_details'] = $newTransaction;

    // --- Send email notification ---
    error_log("PROCESS_REDEEM_CODE INFO: Attempting to send redeem code email to {$sessionUserEmail} for code {$redeemCode}.");
    $emailSent = sendTransactionEmail(
        $sessionUserEmail,
        $sessionUserName,
        'redeemed_code', // Custom type
        number_format($amountNEX, 2),
        number_format($amountINR, 2),
        $transactionId,
        $timestamp,
        $redeemCode // Pass the redeemed code for email context
    );

    if ($emailSent) {
        error_log("PROCESS_REDEEM_CODE INFO: Redeem code email sent successfully to {$sessionUserEmail}.");
    } else {
        error_log("PROCESS_REDEEM_CODE WARNING: Failed to send redeem code email to {$sessionUserEmail}. Check send_transaction_email.php logs.");
    }

    echo json_encode([
        'success' => true,
        'message' => "Redeem code '{$redeemCode}' applied successfully! {$amountNEX} NEX added.",
        'redirect' => 'redeem_success.php' // Redirect to the new success page
    ]);
} else {
    error_log("PROCESS_REDEEM_CODE CRITICAL ERROR: Failed to save user/transaction data after redeeming code {$redeemCode} for {$sessionPhoneNumber}");
    echo json_encode(['success' => false, 'message' => 'Failed to save data after redemption. Please contact support.']);
}
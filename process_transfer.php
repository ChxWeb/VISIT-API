<?php
session_start();
header('Content-Type: application/json');

// Include email configuration and sender helper
require_once __DIR__ . '/email/config.php';
require_once __DIR__ . '/email/send_transaction_email.php'; // Our new helper function

// Redirect to login page if not logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

// Get sender's details from session
$senderPhoneNumber = $_SESSION['phone_number'] ?? null;
$senderName = $_SESSION['name'] ?? null;
$senderEmail = $_SESSION['email'] ?? null;

if (!$senderPhoneNumber || !$senderName || !$senderEmail) {
    echo json_encode(['success' => false, 'message' => 'Sender information missing from session. Please re-login.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$receiverUpiId = strtolower(trim($input['receiver_upi_id'] ?? '')); // Convert to lowercase for matching
$amountNEX = (float)($input['amount'] ?? 0);
$receivedToken = $input['payment_token'] ?? ''; 

// --- FIX: Token Validation (Nonce Check) ---
if (!isset($_SESSION['payment_token']) || $_SESSION['payment_token'] !== $receivedToken || empty($receivedToken)) {
    // Crucially, always unset the session token after validation (successful or failed)
    unset($_SESSION['payment_token']);
    echo json_encode(['success' => false, 'message' => 'Security token invalid or missing. Please refresh and try submitting the payment again.']);
    exit;
}
// Immediately unset the token to make it a one-time nonce (prevents replay/automation)
unset($_SESSION['payment_token']);
// --- END FIX ---

if (empty($receiverUpiId) || $amountNEX <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid receiver UPI ID or amount.']);
    exit;
}

// Define the NEX to INR conversion rate
const NEX_TO_INR_RATE = 0.5;

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

$sender = null;
$receiver = null;
$receiverKey = null;
$senderKey = null;

// Find sender and receiver
foreach ($users as $key => &$user) {
    // Check if current user is the sender
    if (isset($user['phoneNumber']) && $user['phoneNumber'] === $senderPhoneNumber) {
        $sender = &$users[$key]; // Use reference to directly modify the user data in $users array
        $senderKey = $key;
    }

    // --- NEW: Check for receiver based on UPI ID derived from phone, email OR custom_upi_handle ---
    $userPhoneNumberDigits = str_replace('+91', '', $user['phoneNumber'] ?? '');
    $userDefaultVpa = strtolower($userPhoneNumberDigits . '@nexiopay');
    $userCustomVpa = isset($user['custom_upi_handle']) ? strtolower($user['custom_upi_handle'] . '@nexiopay') : '';
    $userEmail = strtolower($user['email'] ?? '');

    if (
        ($userEmail === $receiverUpiId) || 
        ($userDefaultVpa === $receiverUpiId) || 
        (!empty($userCustomVpa) && $userCustomVpa === $receiverUpiId)
    ) {
        $receiver = &$users[$key]; // Use reference
        $receiverKey = $key;
    }
}
unset($user); // Clear reference for safety

// Validate sender
if ($sender === null) {
    echo json_encode(['success' => false, 'message' => 'Sender account not found.']);
    exit;
}

// Check if sender is trying to send money to themselves (must check keys, not just derived VPA, for robustness)
if ($senderKey === $receiverKey) {
    echo json_encode(['success' => false, 'message' => 'Cannot send money to yourself.']);
    exit;
}

// Validate receiver
if ($receiver === null) {
    echo json_encode(['success' => false, 'message' => 'Receiver UPI ID not found.']);
    exit;
}
// --- END NEW RECEIVER CHECK ---

// Check sender's balance
if ($sender['balance'] < $amountNEX) {
    echo json_encode(['success' => false, 'message' => 'Insufficient balance.']);
    exit;
}

// Perform transaction
$sender['balance'] -= $amountNEX;
$receiver['balance'] += $amountNEX;

// Generate unique transaction ID
$transactionId = 'TNX' . uniqid('', true);
$timestamp = date('Y-m-d H:i:s');

// Record transaction
$newTransaction = [
    'transaction_id' => $transactionId,
    'timestamp' => $timestamp,
    'type' => 'transfer',
    'amount' => $amountNEX,
    'sender_name' => $sender['name'],
    'sender_phone' => $sender['phoneNumber'],
    'sender_email' => $sender['email'],
    'receiver_name' => $receiver['name'],
    'receiver_phone' => $receiver['phoneNumber'],
    'receiver_email' => $receiver['email'],
    'status' => 'success'
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
    // Update sender's session balance immediately
    $_SESSION['balance'] = $sender['balance'];

    $amountINR = $amountNEX * NEX_TO_INR_RATE;

    // Send email to sender
    sendTransactionEmail(
        $sender['email'],
        $sender['name'],
        'sent',
        number_format($amountNEX, 2),
        number_format($amountINR, 2),
        $transactionId,
        $timestamp,
        $receiver['name']
    );

    // Send email to receiver
    sendTransactionEmail(
        $receiver['email'],
        $receiver['name'],
        'received',
        number_format($amountNEX, 2),
        number_format($amountINR, 2),
        $transactionId,
        $timestamp,
        $sender['name']
    );

    // Store transaction details in session for the success page
    $_SESSION['last_transaction_details'] = $newTransaction;

    echo json_encode(['success' => true, 'message' => 'Money sent successfully!', 'redirect' => 'payment_success.php']);
} else {
    error_log("CRITICAL ERROR: Failed to save user/transaction data after processing transfer for {$senderPhoneNumber} to {$receiverUpiId}");
    echo json_encode(['success' => false, 'message' => 'Failed to complete transfer due to a server error. Please contact support.']);
}
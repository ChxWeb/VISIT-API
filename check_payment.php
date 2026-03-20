<?php
session_start();
header('Content-Type: application/json');

// 1. Security & Login Check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$userPhone = $_SESSION['phone_number'];
$requestedAmount = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;

if ($requestedAmount <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Amount']);
    exit;
}

// 2. Settings & API URL
$mailApiUrl = "https://chxmail-fetcher.onrender.com/famapp";
const NEX_TO_INR_RATE = 0.5; // 1 NEX = 0.5 INR

// Paths
$userFile = __DIR__ . '/../data/user.json';
$txnFile = __DIR__ . '/../data/transactions.json';

// 3. Fetch Email Data
$response = @file_get_contents($mailApiUrl);
if (!$response) {
    echo json_encode(['status' => 'pending', 'message' => 'API Fetch Failed']);
    exit;
}

$data = json_decode($response, true);
if (!isset($data['body']) || !isset($data['date'])) {
    echo json_encode(['status' => 'pending', 'message' => 'Invalid API Data']);
    exit;
}

// 4. Parse Email Body (Regex)
$body = $data['body'];
// Pattern: "received ₹50.0 from Nilay at ... transaction id FMPIB3953023625"
// Note: Regex handles float amounts and alphanumeric IDs
$pattern = '/received ₹([\d\.]+) from (.*?) at .*? transaction id ([A-Z0-9]+)/i';

if (preg_match($pattern, $body, $matches)) {
    $emailAmount = floatval($matches[1]);
    $senderName = trim($matches[2]);
    $txnId = trim($matches[3]);
} else {
    echo json_encode(['status' => 'pending', 'message' => 'Pattern not found in latest email']);
    exit;
}

// 5. Logic Checks

// A. Check Time (Prevent using old emails)
// Email Date format: "Wed, 3 Dec 2025 14:55:10 +0000"
$emailTime = strtotime($data['date']);
$currentTime = time();

// If email is older than 5 minutes, ignore it (User must click start, then pay)
// Adjust this buffer as needed.
if (($currentTime - $emailTime) > 300) { 
    echo json_encode(['status' => 'pending', 'message' => 'Latest email is too old']);
    exit;
}

// B. Check if Transaction ID already processed
$transactions = [];
if (file_exists($txnFile)) {
    $transactions = json_decode(file_get_contents($txnFile), true) ?? [];
}

foreach ($transactions as $t) {
    // Check if we already recorded this external ID
    if (isset($t['external_txn_id']) && $t['external_txn_id'] === $txnId) {
        echo json_encode(['status' => 'pending', 'message' => 'Transaction already processed']);
        exit;
    }
}

// C. Check Amount Match (Optional: strictly match request or just accept what came)
// Currently, logic credits whatever is in the email to avoid loss, 
// but we check if it matches request to confirm it's THE transaction the user is waiting for.
/* 
if ($emailAmount != $requestedAmount) {
    // You can enable this strict check if you want
    // echo json_encode(['status' => 'pending', 'message' => 'Amount mismatch']);
    // exit;
}
*/

// 6. Credit User
$users = json_decode(file_get_contents($userFile), true);
$userFound = false;
$newBalance = 0;

foreach ($users as &$u) {
    if ($u['phoneNumber'] === $userPhone) {
        // Calculate NEX
        $nexAmount = $emailAmount / NEX_TO_INR_RATE;
        
        $u['balance'] += $nexAmount;
        $newBalance = $u['balance'];
        
        $_SESSION['balance'] = $newBalance; // Update Session
        $userFound = true;
        break;
    }
}

if (!$userFound) {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

// 7. Save Data & Transaction
$newTxn = [
    'transaction_id' => 'ADD' . time() . rand(100,999),
    'external_txn_id' => $txnId, // Crucial to prevent double add
    'timestamp' => date('Y-m-d H:i:s'),
    'type' => 'credit',
    'amount' => $emailAmount / NEX_TO_INR_RATE,
    'sender_name' => $senderName . ' (FamApp)',
    'sender_phone' => 'FamApp API',
    'receiver_phone' => $userPhone,
    'status' => 'success',
    'description' => "Auto-Added ₹$emailAmount via FamApp Mail Parser"
];

$transactions[] = $newTxn;

file_put_contents($userFile, json_encode($users, JSON_PRETTY_PRINT));
file_put_contents($txnFile, json_encode($transactions, JSON_PRETTY_PRINT));

echo json_encode(['status' => 'success', 'message' => 'Funds Added']);
?>
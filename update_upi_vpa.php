<?php
session_start();
header('Content-Type: application/json');

// Include email configuration and sender helper
require_once __DIR__ . '/email/config.php';
require_once __DIR__ . '/email/send_transaction_email.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

// Get sender's details from session
$sessionPhoneNumber = $_SESSION['phone_number'] ?? null;
$sessionUserName = $_SESSION['name'] ?? null;
$sessionUserEmail = $_SESSION['email'] ?? null;

if (!$sessionPhoneNumber || !$sessionUserName || !$sessionUserEmail) {
    echo json_encode(['success' => false, 'message' => 'User information missing from session. Please re-login.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$newUpiHandle = strtolower(trim($input['new_upi_handle'] ?? ''));

// Constants for VPA change
const VPA_CHANGE_INR_FEE = 29.00; // Fixed fee
const NEX_TO_INR_RATE = 0.5;
const VPA_CHANGE_NEX_FEE = VPA_CHANGE_INR_FEE / NEX_TO_INR_RATE; // 58 NEX

if (empty($newUpiHandle)) {
    echo json_encode(['success' => false, 'message' => 'New UPI handle cannot be empty.']);
    exit;
}

// Basic VPA handle validation: only letters, numbers, dot, and hyphen allowed
if (!preg_match('/^[a-z0-9.-]+$/', $newUpiHandle) || $newUpiHandle === '@nexiopay' || strpos($newUpiHandle, '@') !== false) {
    echo json_encode(['success' => false, 'message' => 'Invalid characters or format in UPI handle. Use only letters, numbers, dots, and hyphens.']);
    exit;
}

// Handle minimum length rule
if (strlen($newUpiHandle) < 3) {
     echo json_encode(['success' => false, 'message' => "UPI handle '{$newUpiHandle}' is too short (min 3 characters)."]);
    exit;
}

// Full VPA format for saving
$newFullVpa = $newUpiHandle . '@nexiopay';

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

// Find current user and check for handle uniqueness and sufficient balance
foreach ($users as $key => &$user) {
    // 1. Find the current user
    if (isset($user['phoneNumber']) && $user['phoneNumber'] === $sessionPhoneNumber) {
        $currentUser = &$users[$key]; 
        $currentUserKey = $key;
    }

    // 2. Check for UPI VPA (handle part) uniqueness against all other users
    if ($user['phoneNumber'] !== $sessionPhoneNumber) {
        $userPhoneNumberDigits = str_replace('+91', '', $user['phoneNumber'] ?? '');
        $userDerivedHandle = strtolower($userPhoneNumberDigits);
        $userCustomHandle = strtolower($user['custom_upi_handle'] ?? '');
        $userEmailHandle = strtolower(explode('@', $user['email'] ?? '')[0]);

        // Check against other users' default VPA handle (phone number digits)
        if ($userDerivedHandle === $newUpiHandle) {
             echo json_encode(['success' => false, 'message' => "The handle '{$newUpiHandle}' conflicts with another user's default VPA."]);
             exit;
        }
        
        // Check against other users' custom handles
        if (!empty($userCustomHandle) && $userCustomHandle === $newUpiHandle) {
             echo json_encode(['success' => false, 'message' => "The custom handle '{$newUpiHandle}' is already taken by another user."]);
             exit;
        }
        
        // Check against other users' email handles (basic prefix check for safety)
         if ($userEmailHandle === $newUpiHandle) {
             echo json_encode(['success' => false, 'message' => "The handle '{$newUpiHandle}' conflicts with another user's registered email prefix."]);
             exit;
        }
    }
}
unset($user); // Unset the reference to allow continued use of $users array

// Final check for sender
if ($currentUser === null) {
    echo json_encode(['success' => false, 'message' => 'Sender account not found.']);
    exit;
}

// Check balance (only now that current user is identified)
if ($currentUser['balance'] < VPA_CHANGE_NEX_FEE) {
    echo json_encode(['success' => false, 'message' => "Insufficient balance. Requires " . number_format(VPA_CHANGE_NEX_FEE, 2) . " NEX (₹" . number_format(VPA_CHANGE_INR_FEE, 2) . ") to change VPA."]);
    exit;
}

// Perform transaction and update VPA
$currentUser['balance'] -= VPA_CHANGE_NEX_FEE;
$currentUser['custom_upi_handle'] = $newUpiHandle; // Store the new custom handle

// Generate transaction details
$transactionId = 'VPA' . uniqid();
$timestamp = date('Y-m-d H:i:s');
$description = "VPA Change Fee: New handle set to {$newUpiHandle}@nexiopay (Cost: " . VPA_CHANGE_NEX_FEE . " NEX)";

// Record transaction
$newTransaction = [
    'transaction_id' => $transactionId,
    'timestamp' => $timestamp,
    'type' => 'vpa_change_fee',
    'amount' => VPA_CHANGE_NEX_FEE,
    'sender_name' => $currentUser['name'],
    'sender_phone' => $currentUser['phoneNumber'],
    'sender_email' => $currentUser['email'],
    'receiver_name' => 'NexusPay System',
    'receiver_phone' => 'N/A',
    'receiver_email' => 'system@nexuspay.com',
    'status' => 'success',
    'description' => $description
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
    $_SESSION['balance'] = $currentUser['balance'];
    $_SESSION['custom_upi_handle'] = $newUpiHandle; // Store the new handle in session

    // --- NEW: Send email notification for the fee deduction ---
    sendTransactionEmail(
        $currentUser['email'],
        $currentUser['name'],
        'sent', 
        number_format(VPA_CHANGE_NEX_FEE, 2),
        number_format(VPA_CHANGE_INR_FEE, 2),
        $transactionId,
        $timestamp,
        "NexusPay System (VPA Change Fee: {$newUpiHandle}@nexiopay)"
    );

    echo json_encode([
        'success' => true, 
        'message' => "UPI VPA handle updated to '{$newUpiHandle}'! ₹" . number_format(VPA_CHANGE_INR_FEE, 2) . " fee deducted.",
        'new_vpa' => $newFullVpa,
        'new_balance' => number_format($currentUser['balance'], 2)
    ]);
} else {
    error_log("CRITICAL ERROR: Failed to save user/transaction data after VPA change for {$sessionPhoneNumber}");
    echo json_encode(['success' => false, 'message' => 'Failed to complete VPA update due to a server error. Please contact support.']);
}
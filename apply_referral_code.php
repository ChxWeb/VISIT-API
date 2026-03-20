<?php
session_start();
header('Content-Type: application/json');

// 1. Check Login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$sessionPhone = $_SESSION['phone_number'];
$input = json_decode(file_get_contents('php://input'), true);
$enteredCode = strtoupper(trim($input['referral_code'] ?? ''));

if (empty($enteredCode)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a code.']);
    exit;
}

$userFilePath = __DIR__ . '/../data/user.json';
$transactionsFilePath = __DIR__ . '/../data/transactions.json';

if (!file_exists($userFilePath)) {
    echo json_encode(['success' => false, 'message' => 'System error: User data missing.']);
    exit;
}

$users = json_decode(file_get_contents($userFilePath), true);
$currentUserIndex = null;
$referrerIndex = null;

// 2. Find Current User and Referrer
foreach ($users as $key => $user) {
    // Find Me
    if ($user['phoneNumber'] === $sessionPhone) {
        $currentUserIndex = $key;
    }
    // Find Referrer (The owner of the code)
    if (isset($user['referral_code']) && $user['referral_code'] === $enteredCode) {
        $referrerIndex = $key;
    }
}

// 3. Validations
if ($currentUserIndex === null) {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}

// Check if already referred
if (!empty($users[$currentUserIndex]['referred_by'])) {
    echo json_encode(['success' => false, 'message' => 'You have already applied a referral code.']);
    exit;
}

// Check if code is valid
if ($referrerIndex === null) {
    echo json_encode(['success' => false, 'message' => 'Invalid Referral Code.']);
    exit;
}

// Check self-referral
if ($currentUserIndex === $referrerIndex) {
    echo json_encode(['success' => false, 'message' => 'You cannot use your own code.']);
    exit;
}

// 4. Apply Code
$users[$currentUserIndex]['referred_by'] = $enteredCode;
$message = "Referral code applied successfully!";

// 5. Check KYC Status for Immediate Reward
// Agar KYC pehle se verified hai, toh abhi coins de do.
// Agar nahi hai, toh sirf code save karo (Coins KYC hone par milenge verify_otp script ke through).
if (!empty($users[$currentUserIndex]['kyc_verified']) && $users[$currentUserIndex]['kyc_verified'] === true) {
    
    // Check if reward already given (just in case)
    if (empty($users[$currentUserIndex]['referral_reward_given']) || $users[$currentUserIndex]['referral_reward_given'] === false) {
        
        // Initialize reward balance if missing
        if (!isset($users[$referrerIndex]['reward_balance'])) {
            $users[$referrerIndex]['reward_balance'] = 0;
        }

        // Credit 50 Coins to Referrer
        $users[$referrerIndex]['reward_balance'] += 50;
        
        // Mark as given
        $users[$currentUserIndex]['referral_reward_given'] = true;

        // Log Transaction for Referrer
        $transactions = file_exists($transactionsFilePath) ? json_decode(file_get_contents($transactionsFilePath), true) : [];
        $transactions[] = [
            'transaction_id' => 'REW' . uniqid(),
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'referral_bonus',
            'amount' => 50, // Coins
            'sender_name' => 'System',
            'sender_phone' => 'N/A',
            'receiver_name' => $users[$referrerIndex]['name'],
            'receiver_phone' => $users[$referrerIndex]['phoneNumber'],
            'status' => 'success',
            'description' => "Referral Bonus for user: " . $users[$currentUserIndex]['name']
        ];
        file_put_contents($transactionsFilePath, json_encode($transactions, JSON_PRETTY_PRINT));
        
        $message = "Code applied! Since you are KYC verified, your referrer received 50 Coins.";
    }
} else {
    $message = "Code applied! Rewards will be unlocked once you complete KYC.";
}

// 6. Save Data
if (file_put_contents($userFilePath, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    echo json_encode(['success' => true, 'message' => $message]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
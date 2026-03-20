<?php
// PROGRESS/api/verify_telegram_otp.php
session_start();
header('Content-Type: application/json');

// Debugging Enable
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/otp_debug.log'); // Errors yahan check karein
error_reporting(E_ALL);

require_once __DIR__ . '/telegram_api.php'; 

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

$sessionPhoneNumber = $_SESSION['phone_number'] ?? null;
$sessionUserName = $_SESSION['name'] ?? 'User';
$sessionBalance = $_SESSION['balance'] ?? 0.00;

if (!$sessionPhoneNumber) {
    echo json_encode(['success' => false, 'message' => 'User session data incomplete. Please re-login.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$rawUsername = $input['telegram_username'] ?? '';
$userOtp = trim($input['otp'] ?? '');

if (empty($rawUsername) || empty($userOtp)) {
    echo json_encode(['success' => false, 'message' => 'Telegram Username and OTP are required.']);
    exit;
}

// --- FIX 1: Username Cleaning (Lowercase & Trim) ---
// Send aur Verify dono jagah same logic hona chahiye
$cleanUsername = strtolower(ltrim(trim($rawUsername), '@'));

$otpFile = __DIR__ . '/../data/telegram_otps.json';
$otps = [];
if (file_exists($otpFile)) {
    $otps = json_decode(file_get_contents($otpFile), true);
    if (!is_array($otps)) {
        $otps = [];
    }
}

// Key generate karein
$otpKey = $sessionPhoneNumber . '_' . $cleanUsername;

// Debugging Log (Check karein ki kya compare ho raha hai)
error_log("Verifying OTP for Key: $otpKey | Input OTP: $userOtp");

if (!isset($otps[$otpKey])) {
    error_log("Failed: Key not found in JSON. Available keys: " . implode(", ", array_keys($otps)));
    echo json_encode(['success' => false, 'message' => 'OTP request not found. Please request a new OTP.']);
    exit;
}

$storedOtpData = $otps[$otpKey];

// Check Expiry
if (time() > $storedOtpData['expires']) {
    unset($otps[$otpKey]);
    file_put_contents($otpFile, json_encode($otps, JSON_PRETTY_PRINT));
    echo json_encode(['success' => false, 'message' => 'OTP expired. Please request a new one.']);
    exit;
}

// --- FIX 2: Flexible Comparison ---
// Dono ko string bana ke compare karein taaki type issue na ho
if ((string)$storedOtpData['otp'] !== (string)$userOtp) {
    error_log("Failed: Mismatch. Stored: " . $storedOtpData['otp'] . " vs Input: " . $userOtp);
    echo json_encode(['success' => false, 'message' => 'Invalid OTP. Please try again.']);
    exit;
}

// Agar yahan tak aa gaye, matlab OTP Sahi hai!
unset($otps[$otpKey]);
file_put_contents($otpFile, json_encode($otps, JSON_PRETTY_PRINT));


// --- UPDATE USER STATUS & REWARD LOGIC ---

$userFilePath = __DIR__ . '/../data/user.json';
$users = [];
if (file_exists($userFilePath)) {
    $jsonContent = file_get_contents($userFilePath);
    $users = json_decode($jsonContent, true);
    if (!is_array($users)) {
        $users = [];
    }
}

$userFound = false;
$currentUserKey = null;

// 1. Find Current User
foreach ($users as $key => $user) {
    if (isset($user['phoneNumber']) && $user['phoneNumber'] === $sessionPhoneNumber) {
        $userFound = true;
        $currentUserKey = $key;
        
        // Update Data
        $users[$key]['kyc_data'] = $users[$key]['kyc_data'] ?? ["face_verification" => "pending", "telegram_verification" => "pending"];
        $users[$key]['kyc_data']['telegram_verification'] = 'verified'; 
        $users[$key]['telegram_username'] = $cleanUsername;
        
        // Agar webhook ne chat_id save nahi ki thi, toh abhi mapping se utha lo (Backup plan)
        if (empty($users[$key]['telegram_chat_id'])) {
             $mappingFile = __DIR__ . '/../data/telegram_mapping.json';
             if(file_exists($mappingFile)) {
                 $maps = json_decode(file_get_contents($mappingFile), true);
                 if(isset($maps[$cleanUsername])) {
                     $users[$key]['telegram_chat_id'] = $maps[$cleanUsername];
                 }
             }
        }

        // Set Overall Verified
        $users[$key]['kyc_verified'] = true; 
        $_SESSION['kyc_verified'] = true; 

        break;
    }
}

if (!$userFound) {
    echo json_encode(['success' => false, 'message' => 'User not found in database.']);
    exit;
}

// 2. --- REFERRAL REWARD LOGIC ---
// Sirf tab reward do agar pehle nahi mila
$rewardGiven = $users[$currentUserKey]['referral_reward_given'] ?? false;
$referrerCode = $users[$currentUserKey]['referred_by'] ?? null;

if ($referrerCode && !$rewardGiven) {
    // Referrer ko dhoondo
    foreach ($users as $refKey => $refUser) {
        if (isset($refUser['referral_code']) && $refUser['referral_code'] === $referrerCode) {
            
            // 50 Coins Add karo
            if (!isset($users[$refKey]['reward_balance'])) {
                $users[$refKey]['reward_balance'] = 0;
            }
            $users[$refKey]['reward_balance'] += 50;

            // Mark as given
            $users[$currentUserKey]['referral_reward_given'] = true;
            error_log("Reward: Gave 50 coins to " . $refUser['name'] . " for referring " . $sessionUserName);
            break; 
        }
    }
}

// 3. Save Everything
if (file_put_contents($userFilePath, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    
    // Send Welcome Message on Telegram
    $finalChatId = $users[$currentUserKey]['telegram_chat_id'] ?? null;
    if ($finalChatId) {
        $welcomeMessage = "🎉 <b>Verification Successful!</b>\n\nHello <b>{$sessionUserName}</b>, your NexusPay account is verified.\nYour current balance: <b>" . number_format($sessionBalance, 2) . " NEX</b>.";
        sendTelegramMessage($finalChatId, $welcomeMessage);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Telegram Verified Successfully!',
        'new_kyc_data' => $users[$currentUserKey]['kyc_data'],
        'overall_kyc_verified' => true
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database Error: Could not save status.']);
}
?>
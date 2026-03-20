<?php
// PROGRESS/api/send_telegram_otp.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/telegram_api.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

$sessionPhoneNumber = $_SESSION['phone_number'] ?? null;
$input = json_decode(file_get_contents('php://input'), true);
$rawUsername = $input['telegram_username'] ?? '';

if (empty($rawUsername)) {
    echo json_encode(['success' => false, 'message' => 'Telegram Username is required.']);
    exit;
}

// Clean and normalize username
$cleanUsername = strtolower(ltrim(trim($rawUsername), '@'));

$userFilePath = __DIR__ . '/../data/user.json';
$users = file_exists($userFilePath) ? json_decode(file_get_contents($userFilePath), true) : [];

$currentUserKey = null;
$chatId = null;

// 1. Find User & Update Username
foreach ($users as $key => $user) {
    if (isset($user['phoneNumber']) && $user['phoneNumber'] === $sessionPhoneNumber) {
        $currentUserKey = $key;
        // Username save kar lo
        $users[$key]['telegram_username'] = $cleanUsername;
        $users[$key]['kyc_data'] = $users[$key]['kyc_data'] ?? ["face_verification" => "pending", "telegram_verification" => "pending"];
        
        // Check if Chat ID already exists
        if (!empty($user['telegram_chat_id'])) {
            $chatId = $user['telegram_chat_id'];
        }
        break;
    }
}

if ($currentUserKey === null) {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}

// 2. Agar Chat ID nahi mili, toh Temporary Mapping File check karo
if (!$chatId) {
    $mappingFile = __DIR__ . '/../data/telegram_mapping.json';
    if (file_exists($mappingFile)) {
        $mappings = json_decode(file_get_contents($mappingFile), true);
        
        if (isset($mappings[$cleanUsername])) {
            $chatId = $mappings[$cleanUsername];
            
            // Chat ID mil gayi! User.json me save kar do permanently
            $users[$currentUserKey]['telegram_chat_id'] = $chatId;
            
            // (Optional) Mapping file se delete kar sakte ho clean rakhne ke liye
            // unset($mappings[$cleanUsername]);
            // file_put_contents($mappingFile, json_encode($mappings));
        }
    }
}

// Save updated user data (Username & potentially Chat ID)
file_put_contents($userFilePath, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// 3. Final Check & Send OTP
if (empty($chatId)) {
    echo json_encode([
        'success' => false,
        'message' => 'Chat ID not found. Please open our bot @' . ltrim(TELEGRAM_BOT_USERNAME, '@') . ' and send /start command first.'
    ]);
    exit;
}

// Generate OTP
$otpFile = __DIR__ . '/../data/telegram_otps.json';
$otps = file_exists($otpFile) ? json_decode(file_get_contents($otpFile), true) : [];

$otp = rand(100000, 999999);
$otps[$sessionPhoneNumber . '_' . $cleanUsername] = [
    'otp' => $otp,
    'expires' => time() + 300,
    'telegram_username' => $cleanUsername
];

if (file_put_contents($otpFile, json_encode($otps, JSON_PRETTY_PRINT))) {
    $msg = "Your NexusPay verification code is: <b>{$otp}</b>.\n\nValid for 5 minutes.";
    $resp = sendTelegramMessage($chatId, $msg);

    if (isset($resp['ok']) && $resp['ok'] === true) {
        echo json_encode(['success' => true, 'message' => 'OTP sent to Telegram! Check your app.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send message. Has the user blocked the bot?']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Server error generating OTP.']);
}
?>
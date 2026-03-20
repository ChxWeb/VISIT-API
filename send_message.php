<?php
session_start();
header('Content-Type: application/json');

// 1. Login Check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userPhone = $_SESSION['phone_number'];
$userName = $_SESSION['name'];

$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');

if (empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Empty message']);
    exit;
}

// Paths
$userFile = __DIR__ . '/../data/user.json';
$chatFile = __DIR__ . '/../data/chats.json';

// 2. Load Users to check Ban Status
$users = json_decode(file_get_contents($userFile), true);
$currentUserIndex = null;

foreach ($users as $key => $u) {
    if ($u['phoneNumber'] === $userPhone) {
        $currentUserIndex = $key;
        break;
    }
}

if ($currentUserIndex === null) {
    echo json_encode(['success' => false, 'message' => 'User error']);
    exit;
}

// Check if Banned
$banExpire = $users[$currentUserIndex]['chat_ban_expires'] ?? null;
if ($banExpire && time() < $banExpire) {
    $timeLeft = ceil(($banExpire - time()) / 3600); // Hours left
    echo json_encode(['success' => false, 'message' => "You are banned from chatting for {$timeLeft} hours due to abusive language."]);
    exit;
}

// 3. Abuse / Gaali Detection Logic
// List of bad words (Hindi + English mix) - Add more as needed
$badWords = ['madarchod', 'bhenchod', 'gandu', 'chutiya', 'bhadwa', 'randi', 'loda', 'fuck', 'bitch', 'asshole', 'bastard', 'bsdk', 'mc', 'bc', 'chut'];

$isAbusive = false;
$lowerMsg = strtolower($message);

foreach ($badWords as $word) {
    if (strpos($lowerMsg, $word) !== false) {
        $isAbusive = true;
        break;
    }
}

if ($isAbusive) {
    // Increment Abuse Count
    $currentAbuseCount = ($users[$currentUserIndex]['abuse_count'] ?? 0) + 1;
    $users[$currentUserIndex]['abuse_count'] = $currentAbuseCount;

    $warningMsg = "Warning: Abusive language detected! ({$currentAbuseCount}/5)";

    // Check Limit (5 Strikes)
    if ($currentAbuseCount >= 5) {
        // Ban for 3 Days (3 * 24 * 60 * 60 seconds)
        $users[$currentUserIndex]['chat_ban_expires'] = time() + (3 * 24 * 60 * 60);
        $users[$currentUserIndex]['abuse_count'] = 0; // Reset count after ban
        $warningMsg = "You have been BANNED from community chat for 3 days due to repeated abusive language.";
    }

    // Save User Data
    file_put_contents($userFile, json_encode($users, JSON_PRETTY_PRINT));

    echo json_encode(['success' => false, 'message' => $warningMsg]);
    exit;
}

// 4. Save Message (If clean)
$chats = [];
if (file_exists($chatFile)) {
    $chats = json_decode(file_get_contents($chatFile), true) ?? [];
}

// Keep only last 100 messages to keep file light
if (count($chats) > 100) {
    $chats = array_slice($chats, -100);
}

$newChat = [
    'id' => uniqid(),
    'sender' => $userName,
    'phone' => $userPhone, // To identify self messages
    'message' => htmlspecialchars($message), // Security: Prevent XSS
    'time' => date('h:i A'),
    'timestamp' => time()
];

$chats[] = $newChat;
file_put_contents($chatFile, json_encode($chats, JSON_PRETTY_PRINT));

echo json_encode(['success' => true]);
?>
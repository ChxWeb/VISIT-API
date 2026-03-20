<?php
session_start();
header('Content-Type: application/json');

// 1. Security Check
if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$myPhone = $_SESSION['phone_number'];

// 2. Load Data
$chatFile = __DIR__ . '/../data/chats.json';
$userFile = __DIR__ . '/../data/user.json';
$activityFile = __DIR__ . '/../data/activity.json';

$chats = file_exists($chatFile) ? json_decode(file_get_contents($chatFile), true) : [];
$users = file_exists($userFile) ? json_decode(file_get_contents($userFile), true) : [];
$activity = file_exists($activityFile) ? json_decode(file_get_contents($activityFile), true) : [];

// 3. Map Users for Profile Pics (Optimization)
$userMap = [];
foreach ($users as $u) {
    // Use default initial if photo missing
    $photo = !empty($u['profile_photo']) ? $u['profile_photo'] : null;
    $userMap[$u['phoneNumber']] = [
        'photo' => $photo,
        'initial' => strtoupper(substr($u['name'], 0, 1)),
        'color' => '#00B4DB' // Default color logic can be added here
    ];
}

// 4. Process Messages (HIDE PHONE NUMBER)
$safeChats = [];
foreach ($chats as $chat) {
    $senderPhone = $chat['phone'];
    $userData = $userMap[$senderPhone] ?? ['photo' => null, 'initial' => '?', 'color' => '#999'];

    $safeChats[] = [
        'id' => $chat['id'],
        'sender' => htmlspecialchars($chat['sender']), // XSS Protection
        'message' => htmlspecialchars($chat['message']),
        'time' => $chat['time'],
        'is_me' => ($senderPhone === $myPhone), // Boolean flag for frontend
        'avatar_url' => $userData['photo'],
        'avatar_initial' => $userData['initial']
        // Phone number is NOT included here
    ];
}

// 5. Calculate Online & Typing Status
$onlineCount = 0;
$typingUsers = [];
$currentTime = time();

foreach ($activity as $phone => $data) {
    // Consider online if seen in last 10 seconds
    if ($currentTime - $data['last_seen'] < 10) {
        $onlineCount++;
        
        // Check typing (exclude self)
        if ($data['is_typing'] && $phone !== $myPhone) {
            // Show only first name
            $typingUsers[] = explode(' ', $data['name'])[0];
        }
    }
}

// Formatting Typing Text
$typingText = "";
if (!empty($typingUsers)) {
    $count = count($typingUsers);
    if ($count == 1) {
        $typingText = $typingUsers[0] . " is typing...";
    } elseif ($count < 3) {
        $typingText = implode(", ", $typingUsers) . " are typing...";
    } else {
        $typingText = "Multiple people are typing...";
    }
}

// 6. Send Response
echo json_encode([
    'messages' => $safeChats,
    'online_count' => $onlineCount,
    'typing_text' => $typingText
]);
?>
<?php
// PROGRESS/api/telegram_webhook.php

// Error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/webhook_errors.log');
error_reporting(E_ALL);

require_once __DIR__ . '/telegram_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!isset($update['message']['from']['id']) || !isset($update['message']['chat']['id'])) {
    exit('No relevant message data.');
}

$telegramUserId = $update['message']['from']['id'];
// Username ko lowercase aur trim kar lo taaki match karne me aasani ho
$telegramUsername = isset($update['message']['from']['username']) ? strtolower(trim($update['message']['from']['username'])) : '';
$telegramChatId = $update['message']['chat']['id'];
$messageText = trim($update['message']['text'] ?? '');

// 1. Try to Update Existing User in user.json
$userFilePath = __DIR__ . '/../data/user.json';
$users = file_exists($userFilePath) ? json_decode(file_get_contents($userFilePath), true) : [];
$userLinked = false;
$nexusName = "User";

if (!empty($telegramUsername)) {
    foreach ($users as $key => $user) {
        // Check matches (Case insensitive)
        if (isset($user['telegram_username']) && strtolower(trim($user['telegram_username'])) === $telegramUsername) {
            $users[$key]['telegram_chat_id'] = $telegramChatId;
            $users[$key]['telegram_id'] = $telegramUserId;
            file_put_contents($userFilePath, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $userLinked = true;
            $nexusName = $user['name'];
            break; 
        }
    }
}

// 2. IMPORTANT: Agar user nahi mila, toh Temporary Mapping File me save karo
// Taaki jab user website par username dale, hum yahan se Chat ID utha lein.
if (!$userLinked && !empty($telegramUsername)) {
    $mappingFile = __DIR__ . '/../data/telegram_mapping.json';
    $mappings = file_exists($mappingFile) ? json_decode(file_get_contents($mappingFile), true) : [];
    
    // Save: username => chat_id
    $mappings[$telegramUsername] = $telegramChatId;
    
    file_put_contents($mappingFile, json_encode($mappings, JSON_PRETTY_PRINT));
    error_log("Saved temporary mapping for @$telegramUsername -> $telegramChatId");
}

// 3. Send Reply
if ($messageText === '/start') {
    if ($userLinked) {
        $msg = "✅ <b>Account Connected!</b>\nHi $nexusName, you can now request OTP.";
    } else {
        // User abhi link nahi hua hai, par mapping save ho gayi hai
        $msg = "👋 <b>Welcome!</b>\n\nI have noted your username: <b>@$telegramUsername</b>\n\n👉 Now go back to the <b>NexusPay App</b> website and enter this username to receive your OTP.";
    }
    sendTelegramMessage($telegramChatId, $msg);
}
?>
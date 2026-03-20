<?php
// PROGRESS/api/telegram_api.php

require_once __DIR__ . '/telegram_config.php';

/**
 * Sends a text message to a Telegram user.
 * 
 * @param string|int $chatId The Telegram Chat ID
 * @param string $message The text message (supports HTML)
 * @return array|false Response from Telegram API
 */
function sendTelegramMessage($chatId, $message) {
    $url = TELEGRAM_API_URL . 'sendMessage';
    $params = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    return makeTelegramRequest($url, $params);
}

/**
 * Sends a document/file to a Telegram user.
 * Used for Marketplace deliveries.
 * 
 * @param string|int $chatId The Telegram Chat ID
 * @param string $filePath Absolute path to the file
 * @param string $caption Optional caption for the file
 * @return array|false Response from Telegram API
 */
function sendTelegramDocument($chatId, $filePath, $caption = '') {
    $url = TELEGRAM_API_URL . 'sendDocument';
    
    // Create a CURLFile object for file upload
    // realpath() ensures we have the absolute path
    $realPath = realpath($filePath);
    
    if (!$realPath || !file_exists($realPath)) {
        error_log("Telegram File Error: File not found at $filePath");
        return false;
    }

    $postFields = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'parse_mode' => 'HTML',
        'document' => new CURLFile($realPath)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields); // Must be array for file upload
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("Telegram API Document Error: " . $err);
        return false;
    }
    
    return json_decode($result, true);
}

/**
 * Edits an existing message.
 * Useful for updating status indicators.
 */
function editTelegramMessage($chatId, $messageId, $newMessage) {
    $url = TELEGRAM_API_URL . 'editMessageText';
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $newMessage,
        'parse_mode' => 'HTML'
    ];

    return makeTelegramRequest($url, $params);
}

/**
 * Sends a "Typing..." or "Uploading..." status to the chat header.
 */
function sendTelegramAction($chatId, $action = 'typing') {
    $url = TELEGRAM_API_URL . 'sendChatAction';
    $params = [
        'chat_id' => $chatId,
        'action' => $action
    ];
    
    // Action requests don't need to return detailed responses usually
    makeTelegramRequest($url, $params);
}

/**
 * Helper function to make standard cURL requests (POST).
 */
function makeTelegramRequest($url, $params) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("Telegram API cURL Error: " . $error);
        return ['ok' => false, 'error' => $error];
    }

    return json_decode($response, true);
}

/**
 * Retrieves the chat_id for a given Telegram username from local database.
 */
function getChatIdFromUsername($username) {
    $cleanUsername = ltrim(strtolower($username), '@');
    $userFilePath = __DIR__ . '/../data/user.json';
    $users = [];
    if (file_exists($userFilePath)) {
        $jsonContent = file_get_contents($userFilePath);
        $users = json_decode($jsonContent, true);
        if (!is_array($users)) $users = [];
    }

    foreach ($users as $user) {
        if (isset($user['telegram_username']) && strtolower($user['telegram_username']) === $cleanUsername) {
            if (isset($user['telegram_chat_id']) && !empty($user['telegram_chat_id'])) {
                return $user['telegram_chat_id'];
            }
        }
    }
    
    // Fallback: Check temporary mapping file
    $mappingFile = __DIR__ . '/../data/telegram_mapping.json';
    if (file_exists($mappingFile)) {
        $mappings = json_decode(file_get_contents($mappingFile), true);
        if (isset($mappings[$cleanUsername])) {
            return $mappings[$cleanUsername];
        }
    }
    
    return null;
}
?>
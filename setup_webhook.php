<?php
// Is file ko browser mein open karein: yourdomain.com/PROGRESS/api/setup_webhook.php

require_once __DIR__ . '/telegram_config.php';

$url = TELEGRAM_API_URL . "setWebhook?url=" . TELEGRAM_WEBHOOK_URL;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$result = curl_exec($ch);
curl_close($ch);

$response = json_decode($result, true);

if ($response['ok']) {
    echo "<h1>✅ Webhook Successfully Set!</h1>";
    echo "<p>Telegram ab updates yahan bhejega: " . TELEGRAM_WEBHOOK_URL . "</p>";
    echo "<p>Response: " . $response['description'] . "</p>";
} else {
    echo "<h1>❌ Error Setting Webhook</h1>";
    echo "<p>Error Code: " . $response['error_code'] . "</p>";
    echo "<p>Description: " . $response['description'] . "</p>";
    echo "<p>Check your Token and URL in telegram_config.php</p>";
}
?>
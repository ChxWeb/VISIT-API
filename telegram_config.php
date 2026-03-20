<?php
// PROGRESS/api/telegram_config.php

// This token is obtained from BotFather on Telegram.
// !!! IMPORTANT: NEVER share this token. Keep it secret. !!!
define('TELEGRAM_BOT_TOKEN', '7227439616:AAH3n6CNb27vi4AoIdW-8UZRaoh-vkqOdi8'); // <<< REPLACE THIS WITH YOUR ACTUAL BOT TOKEN if changed

// Base URL for Telegram Bot API
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/');

// Your bot's username (optional, for display/reference)
define('TELEGRAM_BOT_USERNAME', '@nexiopaysupport_bot'); // Updated to the specified bot username

// --- NEW: Webhook URL (MUST BE PUBLICLY ACCESSIBLE) ---
// Isko aapko apne actual server ke public URL se replace karna hoga.
// For example, agar aapka domain example.com hai aur PROGRESS folder root mein hai:
// define('TELEGRAM_WEBHOOK_URL', 'https://example.com/PROGRESS/api/telegram_webhook.php');
// Jahaan "flipcartstore.serv00.net" aapka domain hoga.
define('TELEGRAM_WEBHOOK_URL', 'https://flipcartstore.serv00.net/PROGRESS/api/telegram_webhook.php');
?>

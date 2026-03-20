<?php
// api/fix_telegram.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🛠️ Fixing Telegram & User Database...</h2>";

// Define paths
$dataDir = __DIR__ . '/../data';
$mappingFile = $dataDir . '/telegram_mapping.json';
$otpFile = $dataDir . '/telegram_otps.json';
$userFile = $dataDir . '/user.json';
$webhookLog = __DIR__ . '/webhook_errors.log';

// 1. Check Data Directory
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
    echo "✅ Created 'data' directory.<br>";
} else {
    chmod($dataDir, 0777); // Try to set permissions
    echo "✅ 'data' directory exists.<br>";
}

// 2. Create/Fix Telegram Mapping File
if (!file_exists($mappingFile)) {
    file_put_contents($mappingFile, json_encode([], JSON_PRETTY_PRINT));
    chmod($mappingFile, 0777);
    echo "✅ Created 'telegram_mapping.json' file.<br>";
} else {
    chmod($mappingFile, 0777);
    echo "✅ 'telegram_mapping.json' exists and permissions updated.<br>";
}

// 3. Create/Fix OTP File
if (!file_exists($otpFile)) {
    file_put_contents($otpFile, json_encode([], JSON_PRETTY_PRINT));
    chmod($otpFile, 0777);
    echo "✅ Created 'telegram_otps.json' file.<br>";
} else {
    chmod($otpFile, 0777);
    echo "✅ 'telegram_otps.json' exists and permissions updated.<br>";
}

// 4. Check User File
if (!file_exists($userFile)) {
    echo "❌ 'user.json' missing! Please register a new account.<br>";
} else {
    chmod($userFile, 0777);
    $users = json_decode(file_get_contents($userFile), true);
    if (is_array($users)) {
        echo "✅ 'user.json' contains " . count($users) . " users.<br>";
    } else {
        echo "⚠️ 'user.json' is empty or corrupted.<br>";
    }
}

// 5. Create Webhook Log
if (!file_exists($webhookLog)) {
    file_put_contents($webhookLog, "Log started...\n");
    chmod($webhookLog, 0777);
    echo "✅ Created webhook error log.<br>";
} else {
    chmod($webhookLog, 0777);
}

echo "<hr>";
echo "<h3>🚀 Instructions:</h3>";
echo "1. <b>Log Out</b> from the app and <b>Log In</b> again (Important).<br>";
echo "2. Open Telegram Bot and send <b>/start</b> again.<br>";
echo "3. Go to App > Verification > Enter Telegram Username.<br>";
echo "4. Click Send OTP.<br>";
?>
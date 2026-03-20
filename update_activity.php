<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in'])) {
    exit(json_encode(['success' => false]));
}

$phone = $_SESSION['phone_number'];
$name = $_SESSION['name'];
$input = json_decode(file_get_contents('php://input'), true);
$isTyping = $input['typing'] ?? false;

$file = __DIR__ . '/../data/activity.json';
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

// Update user activity
$data[$phone] = [
    'last_seen' => time(),
    'is_typing' => $isTyping === true,
    'name' => $name
];

// Clean up old users (offline > 30 seconds) to keep file small
$currentTime = time();
foreach ($data as $p => $info) {
    if ($currentTime - $info['last_seen'] > 30) {
        unset($data[$p]);
    }
}

file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
echo json_encode(['success' => true]);
?>
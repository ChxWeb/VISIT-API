<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$sessionPhone = $_SESSION['phone_number'];
$input = json_decode(file_get_contents('php://input'), true);

$type = $input['verification_type'] ?? '';
$status = $input['status'] ?? 'pending';

$userFile = __DIR__ . '/../data/user.json';
$users = json_decode(file_get_contents($userFile), true);
$found = false;

foreach ($users as &$user) {
    if ($user['phoneNumber'] === $sessionPhone) {
        $user['kyc_data'] = $user['kyc_data'] ?? [];
        $user['kyc_data'][$type] = $status;
        
        // Check overall status
        $k = $user['kyc_data'];
        $isBio = ($k['biometric_verification'] ?? '') === 'verified';
        $isFace = ($k['face_verification'] ?? '') === 'verified';
        $isTele = ($k['telegram_verification'] ?? '') === 'verified';
        
        if($isBio && $isFace && $isTele) {
            $user['kyc_verified'] = true;
            $_SESSION['kyc_verified'] = true;
        }

        $found = true;
        break;
    }
}

if ($found) {
    file_put_contents($userFile, json_encode($users, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'message' => 'Status Updated']);
} else {
    echo json_encode(['success' => false, 'message' => 'User not found']);
}
?>
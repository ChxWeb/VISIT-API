<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

$sessionPhoneNumber = $_SESSION['phone_number'] ?? null;
if (!$sessionPhoneNumber) {
    echo json_encode(['success' => false, 'message' => 'User phone number not found in session.']);
    exit;
}

$userFilePath = '../data/user.json';
$users = [];

if (file_exists($userFilePath)) {
    $jsonContent = file_get_contents($userFilePath);
    $users = json_decode($jsonContent, true);
    if (!is_array($users)) {
        $users = [];
    }
}

$kycData = null;
foreach ($users as $user) {
    if (isset($user['phoneNumber']) && $user['phoneNumber'] === $sessionPhoneNumber) {
        $kycData = $user['kyc_data'] ?? [
            "face_verification" => "pending",
            "telegram_verification" => "pending"
        ];
        $overallKycVerified = $user['kyc_verified'] ?? false;
        break;
    }
}

if ($kycData) {
    echo json_encode(['success' => true, 'kyc_data' => $kycData, 'overall_kyc_verified' => $overallKycVerified]);
} else {
    echo json_encode(['success' => false, 'message' => 'KYC data not found for user.']);
}
?>
<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in (optional, but good practice for API access)
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$receiverUpiId = strtolower(trim($input['receiver_upi_id'] ?? ''));

if (empty($receiverUpiId)) {
    echo json_encode(['success' => false, 'message' => 'Recipient UPI ID is required.']);
    exit;
}

// Get the currently logged-in user's details for comparison
$senderPhoneNumber = $_SESSION['phone_number'] ?? '';
$senderEmail = $_SESSION['email'] ?? '';

$userFilePath = '../data/user.json';
$users = [];
if (file_exists($userFilePath)) {
    $jsonContent = file_get_contents($userFilePath);
    $users = json_decode($jsonContent, true);
    if (!is_array($users)) {
        $users = [];
    }
}

$recipientDetails = null;

// Find receiver based on UPI ID
foreach ($users as $user) {
    $userPhoneNumberDigits = str_replace('+91', '', $user['phoneNumber'] ?? '');
    
    // --- NEW: Derive all possible VPAs for the current user in the loop ---
    $userDefaultVpa = strtolower($userPhoneNumberDigits . '@nexiopay');
    $userCustomVpa = isset($user['custom_upi_handle']) && !empty($user['custom_upi_handle']) ? strtolower($user['custom_upi_handle'] . '@nexiopay') : '';
    $userEmail = strtolower($user['email'] ?? '');
    // --- END NEW ---

    // Check if the input receiverUpiId matches any of the user's possible VPAs
    if (
        ($userEmail === $receiverUpiId) || // 1. Check Email (as some apps allow email for UPI)
        ($userDefaultVpa === $receiverUpiId) || // 2. Check Default VPA
        (!empty($userCustomVpa) && $userCustomVpa === $receiverUpiId) // 3. Check Custom VPA
    ) {
        
        // --- FIX: Filter out sensitive data (phone and email) ---
        $recipientDetails = [
            'name' => htmlspecialchars($user['name'] ?? 'Unknown Recipient'),
            'kyc_verified' => (bool)($user['kyc_verified'] ?? false)
        ];

        // OPTIONAL: If the sender searches for their OWN UPI ID, we can return everything
        if ($user['phoneNumber'] === $senderPhoneNumber || $userEmail === strtolower($senderEmail)) {
             $recipientDetails['phone_number'] = htmlspecialchars($user['phoneNumber'] ?? 'N/A');
             $recipientDetails['email'] = htmlspecialchars($user['email'] ?? 'N/A');
        }
        // --- END FIX ---
        
        break;
    }
}

if ($recipientDetails) {
    echo json_encode(['success' => true, 'data' => $recipientDetails]);
} else {
    echo json_encode(['success' => false, 'message' => 'Recipient not found.']);
}
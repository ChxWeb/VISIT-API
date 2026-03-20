<?php
session_start(); // Start session to access verification flag
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$phoneNumber = $input['phoneNumber'] ?? '';
$name = $input['name'] ?? '';
$email = $input['email'] ?? '';
$password = $input['password'] ?? '';
$enteredReferralCode = isset($input['referralCode']) ? trim($input['referralCode']) : '';

// --- SECURITY CHECK 1: ANTI-CURL / ANTI-BOT ---
// Verify that the phone number was actually verified via OTP in this session.
if (!isset($_SESSION['verified_registration_number']) || $_SESSION['verified_registration_number'] !== $phoneNumber) {
    http_response_code(403); // Forbidden
    echo json_encode([
        'success' => false, 
        'message' => 'Security Error: Mobile number verification failed or session expired. Please verify OTP again.'
    ]);
    exit;
}

// Basic Validations
if (empty($phoneNumber) || empty($name) || empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

$filePath = '../data/user.json';
$users = [];

if (file_exists($filePath)) {
    $users = json_decode(file_get_contents($filePath), true) ?? [];
}

// Check duplicates
foreach ($users as $user) {
    if ($user['phoneNumber'] === $phoneNumber) {
        echo json_encode(['success' => false, 'message' => 'Mobile number already registered.']);
        exit;
    }
    if ($user['email'] === $email) {
        echo json_encode(['success' => false, 'message' => 'Email already registered.']);
        exit;
    }
}

// --- SECURITY CHECK 2: VALIDATE REFERRAL CODE ---
$validReferral = null;

if (!empty($enteredReferralCode)) {
    $referralExists = false;
    foreach ($users as $u) {
        if (isset($u['referral_code']) && $u['referral_code'] === $enteredReferralCode) {
            $referralExists = true;
            $validReferral = $enteredReferralCode;
            break;
        }
    }

    if (!$referralExists) {
        echo json_encode(['success' => false, 'message' => 'Invalid Referral Code. Please check or leave blank.']);
        exit;
    }
}

// Generate Code for New User
function generateReferralCode($name) {
    $cleanName = preg_replace('/[^a-zA-Z]/', '', $name);
    $prefix = strtoupper(substr($cleanName, 0, 3));
    if(strlen($prefix) < 3) $prefix = "NEX";
    return $prefix . rand(1000, 9999);
}

$myReferralCode = generateReferralCode($name);
// Ensure unique
foreach($users as $u) {
    if(isset($u['referral_code']) && $u['referral_code'] === $myReferralCode) {
        $myReferralCode = generateReferralCode($name) . 'X';
    }
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$newUser = [
    'phoneNumber' => $phoneNumber,
    'name' => $name,
    'email' => $email,
    'password' => $hashedPassword,
    'registrationDate' => date('Y-m-d H:i:s'),
    'balance' => 0.00,
    'reward_balance' => 0, 
    'referral_code' => $myReferralCode,
    'referred_by' => $validReferral, // Only save if valid
    'referral_reward_given' => false,
    'profile_photo' => '',
    'kyc_verified' => false,
    'kyc_data' => [
        "face_verification" => "pending",
        "telegram_verification" => "pending"
    ],
    "status" => "active",
    "telegram_username" => null,
    "telegram_chat_id" => null,
    "telegram_id" => null
];

$users[] = $newUser;

if (file_put_contents($filePath, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    
    // Login the user
    $_SESSION['logged_in'] = true;
    $_SESSION['phone_number'] = $phoneNumber;
    $_SESSION['name'] = $name;
    $_SESSION['email'] = $email;
    $_SESSION['balance'] = 0.00;
    $_SESSION['profile_photo'] = '';
    $_SESSION['kyc_verified'] = false;

    // Unset the verification token so it can't be reused for another request
    unset($_SESSION['verified_registration_number']);

    $userDataToReturn = [
        'phone_number' => htmlspecialchars($phoneNumber),
        'name' => htmlspecialchars($name),
        'email' => htmlspecialchars($email),
        'balance' => 0.00,
        'profile_photo' => '',
        'kyc_verified' => false
    ];

    echo json_encode(['success' => true, 'message' => 'Account created successfully.', 'user_data' => $userDataToReturn]);
} else {
    echo json_encode(['success' => false, 'message' => 'Server error saving data.']);
}
?>
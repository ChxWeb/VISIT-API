<?php
// Enable error reporting for debugging (REMOVE IN PRODUCTION)
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
session_start(); // Start session to store verification proof and login state

$input = json_decode(file_get_contents('php://input'), true);

$otp = $input['otp'] ?? '';
$otpRecipientType = $input['otpRecipientType'] ?? ''; // 'mobile' or 'email'
$otpRecipient = $input['otpRecipient'] ?? ''; // phone number with prefix OR email address
$deviceId = $input['deviceId'] ?? 'WEB_APP_FALLBACK_DEVICE_ID';

if (empty($otp) || empty($otpRecipientType) || empty($otpRecipient)) {
    echo json_encode(['success' => false, 'message' => 'OTP, recipient type, and recipient are required.']);
    exit;
}

// Get user's IP address
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
}

$userFilePath = __DIR__ . '/../data/user.json';
$userExistsInLocalDB = false;
$actualRecipientForResponse = ''; 
$targetUser = null;
$targetUserKey = null;

// Read existing user data
$existingUsers = [];
if (file_exists($userFilePath)) {
    $jsonContent = file_get_contents($userFilePath);
    $existingUsers = json_decode($jsonContent, true) ?? [];
}

// ============================================================================
// CASE 1: MOBILE OTP VERIFICATION
// ============================================================================
if ($otpRecipientType === 'mobile') {
    $phoneNumberWithPrefix = $otpRecipient;
    $phoneNumber = str_replace('+91', '', $phoneNumberWithPrefix);

    if (!preg_match('/^\d{10}$/', $phoneNumber)) {
        echo json_encode(['success' => false, 'message' => 'Invalid 10-digit mobile number format.']);
        exit;
    }
    $actualRecipientForResponse = $phoneNumberWithPrefix;

    // Check if user exists in DB
    if (is_array($existingUsers)) {
        foreach ($existingUsers as $key => $user) {
            if (isset($user['phoneNumber']) && $user['phoneNumber'] === $phoneNumberWithPrefix) {
                $userExistsInLocalDB = true;
                $targetUser = &$existingUsers[$key];
                $targetUserKey = $key;
                break;
            }
        }
    }

    // Call Proxy API to verify OTP
    $curl = curl_init();
    $postFields = "phone=" . urlencode($phoneNumber) . "&otp=" . urlencode($otp);

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://flipcartstore.serv00.net/otp/otp_api.php?action=verify_otp',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded'),
        CURLOPT_SSL_VERIFYPEER => false, // Enable in production
        CURLOPT_SSL_VERIFYHOST => false, // Enable in production
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        echo json_encode(['success' => false, 'message' => 'API Connection Error: ' . $err]);
        exit;
    }

    $responseData = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($responseData['ok']) && $responseData['ok'] === true) {
        // --- SUCCESS: MOBILE OTP VERIFIED ---
        
        // SECURITY FIX: Store proof of verification in session
        $_SESSION['verified_registration_number'] = $phoneNumberWithPrefix;

        handleLoginOrRegistration($userExistsInLocalDB, $targetUser, $existingUsers, $userFilePath, $responseData['message'] ?? 'Verified');
    } else {
        $msg = $responseData['message'] ?? 'Invalid OTP or Expired.';
        echo json_encode(['success' => false, 'message' => $msg]);
    }

} 
// ============================================================================
// CASE 2: EMAIL OTP VERIFICATION
// ============================================================================
elseif ($otpRecipientType === 'email') {
    $email = $otpRecipient;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address format.']);
        exit;
    }
    $actualRecipientForResponse = $email;

    // Check if user exists in DB
    if (is_array($existingUsers)) {
        foreach ($existingUsers as $key => $user) {
            if (isset($user['email']) && strtolower($user['email']) === strtolower($email)) {
                $userExistsInLocalDB = true;
                $targetUser = &$existingUsers[$key];
                $targetUserKey = $key;
                break;
            }
        }
    }

    if (!$userExistsInLocalDB) {
        echo json_encode(['success' => false, 'message' => 'No account found with this email.']);
        exit;
    }

    // Call Internal Email Verify API
    $curl = curl_init();
    $postFields = json_encode(["email" => $email, "otp" => $otp]);
    
    // Ensure this URL is correct for your server structure
    $emailVerifyOtpUrl = 'https://flipcartstore.serv00.net/PROGRESS/api/email/verify_otp.php';

    curl_setopt_array($curl, array(
        CURLOPT_URL => $emailVerifyOtpUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        echo json_encode(['success' => false, 'message' => 'Email API Error: ' . $err]);
        exit;
    }

    $responseData = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($responseData['status']) && $responseData['status'] === 'success') {
        // --- SUCCESS: EMAIL OTP VERIFIED ---

        // SECURITY FIX: Store proof of verification in session (using linked phone number)
        // Since email login implies user exists, we use their phone number for the session lock
        $_SESSION['verified_registration_number'] = $targetUser['phoneNumber'];

        handleLoginOrRegistration(true, $targetUser, $existingUsers, $userFilePath, 'Email Verified Successfully');
    } else {
        $msg = $responseData['message'] ?? 'Invalid Email OTP.';
        echo json_encode(['success' => false, 'message' => $msg]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid recipient type.']);
    exit;
}

// ============================================================================
// HELPER FUNCTION: Handle Login Logic & Ban Check
// ============================================================================
function handleLoginOrRegistration($userExists, &$targetUser, &$allUsers, $filePath, $successMsg) {
    if ($userExists && $targetUser) {
        // --- Check Ban Status ---
        if (isset($targetUser['status']) && $targetUser['status'] === 'banned') {
            
            // Check if temporary ban has expired
            if (isset($targetUser['ban_temporary']) && $targetUser['ban_temporary'] === true && 
                isset($targetUser['ban_expires']) && $targetUser['ban_expires'] < time()) {
                
                // Unban User
                $targetUser['status'] = 'active';
                $targetUser['ban_reason'] = null;
                $targetUser['ban_temporary'] = false;
                $targetUser['ban_expires'] = null;
                
                // Save changes to DB
                file_put_contents($filePath, json_encode($allUsers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                
            } else {
                // User is still banned
                echo json_encode([
                    'success' => false,
                    'message' => 'Account Banned: ' . ($targetUser['ban_reason'] ?? 'Violation of terms'),
                    'isBanned' => true,
                    'banDetails' => [
                        'reason' => $targetUser['ban_reason'] ?? 'No reason provided.',
                        'temporary' => $targetUser['ban_temporary'] ?? false,
                        'expires' => $targetUser['ban_expires'] ?? null
                    ]
                ]);
                return;
            }
        }

        // --- Proceed to Login ---
        $_SESSION['logged_in'] = true;
        $_SESSION['phone_number'] = $targetUser['phoneNumber'];
        $_SESSION['name'] = $targetUser['name'];
        $_SESSION['email'] = $targetUser['email'];
        $_SESSION['balance'] = $targetUser['balance'];
        $_SESSION['profile_photo'] = $targetUser['profile_photo'] ?? '';
        $_SESSION['kyc_verified'] = $targetUser['kyc_verified'] ?? false;
        
        // Custom Handle Logic (from previous steps)
        $_SESSION['custom_upi_handle'] = $targetUser['custom_upi_handle'] ?? null;

        // Prepare sanitized user data for frontend
        $userDataToReturn = [
            'phone_number' => htmlspecialchars($targetUser['phoneNumber']),
            'name' => htmlspecialchars($targetUser['name'] ?? ''),
            'email' => htmlspecialchars($targetUser['email'] ?? ''),
            'balance' => $targetUser['balance'],
            'profile_photo' => htmlspecialchars($targetUser['profile_photo'] ?? ''),
            'kyc_verified' => $targetUser['kyc_verified'] ?? false
        ];

        echo json_encode([
            'success' => true,
            'message' => $successMsg,
            'userExists' => true,
            'isBanned' => false,
            'user_data' => $userDataToReturn
        ]);

    } else {
        // --- User Does Not Exist (Registration Flow) ---
        echo json_encode([
            'success' => true,
            'message' => 'OTP Verified. Please complete registration.',
            'userExists' => false,
            'isBanned' => false
        ]);
    }
}
?>
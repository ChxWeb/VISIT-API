<?php
// Enable error reporting for debugging (REMOVE IN PRODUCTION)
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);

$phoneNumberWithPrefix = $input['phoneNumber'] ?? ''; // e.g., "+91XXXXXXXXXX"
$deviceId = $input['deviceId'] ?? 'WEB_APP_FALLBACK_DEVICE_ID'; // Captured from JS, but not forwarded to proxy

if (empty($phoneNumberWithPrefix)) {
    echo json_encode(['success' => false, 'message' => 'Mobile number is required.']);
    exit;
}

// Remove "+91" prefix as the proxy API probably expects only the 10 digits
$phoneNumber = str_replace('+91', '', $phoneNumberWithPrefix);

// Basic validation for 10 digits after removing prefix
if (!preg_match('/^\d{10}$/', $phoneNumber)) {
    echo json_encode(['success' => false, 'message' => 'Invalid 10-digit mobile number format.']);
    exit;
}

// Get user's IP address for logging if needed
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
}

// --- NEW LOGIC: Check if user exists in local database ---
$userFilePath = __DIR__ . '/../data/user.json';
$registeredUserEmail = null;

if (file_exists($userFilePath)) {
    $jsonContent = file_get_contents($userFilePath);
    $existingUsers = json_decode($jsonContent, true);

    if (is_array($existingUsers)) {
        foreach ($existingUsers as $user) {
            // Check for a match with the full phoneNumberWithPrefix
            if (isset($user['phoneNumber']) && $user['phoneNumber'] === $phoneNumberWithPrefix) {
                if (isset($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                    $registeredUserEmail = $user['email'];
                    break;
                }
            }
        }
    }
}

if ($registeredUserEmail) {
    // User exists, send OTP to their registered email
    error_log("Existing user found. Sending OTP to registered email: {$registeredUserEmail}");

    $curl = curl_init();
    $postFields = json_encode(["email" => $registeredUserEmail]);

    // Construct the absolute URL for the internal email send_otp.php script
    // You MUST verify this URL is correct and publicly accessible on your server.
    // Assuming 'PROGRESS' is a directory in your web root, and 'api/email' is under it.
    $emailSendOtpUrl = 'https://flipcartstore.serv00.net/PROGRESS/api/email/send_otp.php';

    curl_setopt_array($curl, array(
        CURLOPT_URL => $emailSendOtpUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($postFields)
        ),
        // Disable SSL verification for development/testing if you face issues
        // REMOVE THESE TWO LINES IN PRODUCTION!
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        error_log("CURL_ERROR_INTERNAL_EMAIL_OTP_REQUEST [Email: {$registeredUserEmail} | Device: {$deviceId} | IP: {$ip}]: " . $err);
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP to email due to cURL connection error: ' . $err]);
        exit;
    } else {
        $responseData = json_decode($response, true);
        error_log("INTERNAL_EMAIL_OTP_RESPONSE [Email: {$registeredUserEmail} | Device: {$deviceId} | IP: {$ip} | HTTP: {$httpCode}]: " . $response);

        if ($httpCode >= 200 && $httpCode < 300 && isset($responseData['status']) && $responseData['status'] === 'success') {
            echo json_encode([
                'success' => true,
                'message' => 'OTP sent to your registered email address.',
                'otpRecipientType' => 'email', // ADDED THIS
                'otpRecipient' => $registeredUserEmail // ADDED THIS
            ]);
        } else {
            $apiErrorMessage = $responseData['message'] ?? 'Failed to send OTP to email. Server error. Full response: ' . ($response ?: 'No response received.');
            echo json_encode(['success' => false, 'message' => $apiErrorMessage]);
        }
    }

} else {
    // User does not exist, proceed with sending OTP to phone number via proxy
    error_log("New user or email not found. Sending OTP to mobile number: {$phoneNumberWithPrefix}");

    $curl = curl_init();

    // --- IMPORTANT CHANGE: Proxy API expects form-urlencoded data ---
    $postFields = "phone=" . urlencode($phoneNumber);

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://flipcartstore.serv00.net/otp/otp_api.php?action=send_otp', // New proxy endpoint
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30, // Increased timeout to 30 seconds
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postFields, // Send form-urlencoded data
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded', // Required for form-urlencoded data
            // Other Pagarbook-specific headers are handled by the proxy, no need to send them here.
        ),
        // Disable SSL verification for development/testing if you face issues
        // REMOVE THESE TWO LINES IN PRODUCTION!
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    // --- Enhanced Error Handling and Logging ---
    if ($err) {
        // Log detailed cURL connection error
        error_log("CURL_ERROR_PROXY_OTP_REQUEST [Phone: {$phoneNumber} | Device: {$deviceId} | IP: {$ip}]: " . $err);
        echo json_encode(['success' => false, 'message' => 'API connection error with proxy. Please try again later. cURL error: ' . $err]);
        exit;
    } else {
        $responseData = json_decode($response, true);

        // Log the full proxy API response for debugging
        error_log("PROXY_OTP_REQUEST_RESPONSE [Phone: {$phoneNumber} | Device: {$deviceId} | IP: {$ip} | HTTP: {$httpCode}]: " . $response);

        // Check HTTP status code first
        if ($httpCode >= 200 && $httpCode < 300) {
            // Then check proxy's internal 'ok' field
            if (isset($responseData['ok']) && $responseData['ok'] === true) {
                // Further check Pagarbook's status if necessary, but 'ok:true' from proxy is primary
                echo json_encode([
                    'success' => true,
                    'message' => $responseData['note'] ?? 'OTP sent successfully to your mobile number.',
                    'otpRecipientType' => 'mobile', // ADDED THIS
                    'otpRecipient' => $phoneNumberWithPrefix // ADDED THIS
                ]);
            } else {
                // Provide proxy's specific error message if available
                $apiErrorMessage = $responseData['message'] ?? 'Failed to send OTP (Proxy API did not return "ok:true"). Full response: ' . ($response ?: 'No response received.');
                echo json_encode(['success' => false, 'message' => $apiErrorMessage]);
            }
        } else {
            // Handle non-2xx HTTP responses from proxy API
            $apiErrorMessage = $responseData['message'] ?? 'Proxy API returned an error (HTTP ' . $httpCode . '). Full response: ' . ($response ?: 'No response received.');
            echo json_encode(['success' => false, 'message' => $apiErrorMessage]);
        }
    }
}
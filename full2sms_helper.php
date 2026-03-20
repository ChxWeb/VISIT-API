<?php
require_once __DIR__ . '/payout_config.php';

function initiatePayout($amountINR, $upiId) {
    // Validation: Amount must be between 10 and 1000 as per API docs
    if ($amountINR < 10 || $amountINR > 1000) {
        return ['status' => 'error', 'message' => 'Amount must be between ₹10 and ₹1000 for automated withdrawal.'];
    }

    $params = [
        'mid' => F2S_MID,
        'mkey' => F2S_MKEY,
        'guid' => F2S_GUID,
        'type' => 'upi', // Hum UPI use kar rahe hain
        'amount' => $amountINR,
        'upi' => $upiId,
        'info' => 'W' // Max 1 char allowed as per docs (W for Withdrawal)
    ];

    // URL Query String banayein
    $queryString = http_build_query($params);
    $url = F2S_PAYOUT_URL . '?' . $queryString;

    // cURL Request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Production me true rakhein agar SSL setup hai
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['status' => 'error', 'message' => 'Connection Error: ' . $error];
    }

    $data = json_decode($response, true);

    // Check Response
    if (isset($data['status']) && $data['status'] === 'success') {
        return [
            'status' => 'success',
            'txn_id' => $data['txn_id'],
            'message' => 'Payout successful'
        ];
    } else {
        return [
            'status' => 'error',
            'message' => $data['message'] ?? 'Unknown API Error'
        ];
    }
}
?>
<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$sessionPhone = $_SESSION['phone_number'];
$input = json_decode(file_get_contents('php://input'), true);
$coinsToConvert = intval($input['coins'] ?? 0);

// CONFIG: Exchange Rate (10 Coins = 1 NEX)
const EXCHANGE_RATE = 10;

if ($coinsToConvert < 10) {
    echo json_encode(['success' => false, 'message' => 'Minimum conversion is 10 Coins.']);
    exit;
}

$userFilePath = '../data/user.json';
$users = json_decode(file_get_contents($userFilePath), true);
$userFound = false;
$newNEX = 0;
$newRewards = 0;

foreach ($users as &$user) {
    if ($user['phoneNumber'] === $sessionPhone) {
        $currentRewards = $user['reward_balance'] ?? 0;
        
        if ($currentRewards < $coinsToConvert) {
            echo json_encode(['success' => false, 'message' => 'Insufficient Reward Coins.']);
            exit;
        }

        // Calculate NEX Amount
        $nexAmount = $coinsToConvert / EXCHANGE_RATE;

        // Update Balances
        $user['reward_balance'] -= $coinsToConvert;
        $user['balance'] += $nexAmount;

        // Update Session
        $_SESSION['balance'] = $user['balance'];
        
        $newNEX = $user['balance'];
        $newRewards = $user['reward_balance'];
        $userFound = true;
        
        // Log Transaction (Optional but good)
        $transactionsFilePath = '../data/transactions.json';
        $transactions = json_decode(file_get_contents($transactionsFilePath), true) ?? [];
        $transactions[] = [
            'transaction_id' => 'REW' . uniqid(),
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'credit',
            'amount' => $nexAmount,
            'sender_name' => 'NexusPay Rewards',
            'sender_phone' => 'System',
            'receiver_phone' => $sessionPhone,
            'status' => 'success',
            'description' => "Converted $coinsToConvert Coins to NEX"
        ];
        file_put_contents($transactionsFilePath, json_encode($transactions, JSON_PRETTY_PRINT));
        
        break;
    }
}

if ($userFound) {
    file_put_contents($userFilePath, json_encode($users, JSON_PRETTY_PRINT));
    echo json_encode([
        'success' => true, 
        'message' => "Success! Converted to $nexAmount NEX.",
        'new_rewards' => $newRewards,
        'new_nex' => $newNEX
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
}
?>
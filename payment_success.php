<?php
session_start();

// Redirect to login page if not logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Get transaction details from session, which would be set by process_transfer.php
$transactionDetails = $_SESSION['last_transaction_details'] ?? null;

if (!$transactionDetails) {
    // If no details found, redirect to dashboard or show a generic error
    header('Location: dash.php');
    exit;
}

// Clear the session variable to prevent displaying old data on refresh
unset($_SESSION['last_transaction_details']);

$senderName = htmlspecialchars($transactionDetails['sender_name'] ?? 'You');
$receiverName = htmlspecialchars($transactionDetails['receiver_name'] ?? 'Recipient');
$amountNEX = htmlspecialchars(number_format($transactionDetails['amount'] ?? 0, 2));
$transactionId = htmlspecialchars($transactionDetails['transaction_id'] ?? 'N/A');
$timestamp = htmlspecialchars(date('M d, Y h:i A', strtotime($transactionDetails['timestamp'] ?? 'now')));

// Convert NEX to INR for display
const NEX_TO_INR_RATE = 0.5;
$amountINR = htmlspecialchars(number_format(($transactionDetails['amount'] ?? 0) * NEX_TO_INR_RATE, 2));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NexusPay | Payment Successful!</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --deep-blue: #0f172a;
            --dark-blue: #1e293b;
            --medium-blue: #334155;
            --light-blue: #475569;
            --neon-blue: #0ea5e9;
            --neon-blue-glow: rgba(14, 165, 233, 0.4);
            --gold: #fbbf24;
            --gold-glow: rgba(251, 191, 36, 0.3);
            --white: #f8fafc;
            --green: #10b981;
            --red: #ef4444;
            --success-color: #2ecc71;
            --error-color: #e74c3c;
            --glass-bg: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(255, 255, 255, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background: linear-gradient(135deg, var(--deep-blue) 0%, #1a1f3d 100%);
            color: var(--white);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
            overflow: hidden; /* Hide overflow for animation */
        }

        .success-container {
            max-width: 420px;
            width: 100%;
            background: linear-gradient(135deg, var(--dark-blue) 0%, #27354d 100%);
            border-radius: 20px;
            padding: 40px 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(10px);
            text-align: center;
            position: relative;
            transform: translateY(20px);
            opacity: 0;
            animation: fadeIn 0.5s ease-out forwards;
        }

        @media (max-width: 600px) {
            .success-container {
                border-radius: 0;
                height: 100vh;
                padding-top: 80px;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
            }
        }

        .success-animation {
            width: 120px;
            height: 120px;
            position: relative;
            margin: 0 auto 30px;
        }

        .checkmark-circle {
            stroke-dasharray: 330;
            stroke-dashoffset: 330;
            stroke-width: 8;
            stroke-miterlimit: 10;
            stroke: var(--success-color);
            fill: none;
            animation: stroke 1s cubic-bezier(0.65, 0, 0.45, 1) forwards;
            position: absolute;
            width: 100%;
            height: 100%;
        }

        .checkmark-stem {
            stroke-dasharray: 50;
            stroke-dashoffset: 50;
            stroke-width: 8;
            stroke-miterlimit: 10;
            stroke: var(--success-color);
            fill: none;
            animation: stroke 0.5s cubic-bezier(0.65, 0, 0.45, 1) 0.8s forwards;
            position: absolute;
            width: 100%;
            height: 100%;
        }

        .checkmark-kick {
            stroke-dasharray: 100;
            stroke-dashoffset: 100;
            stroke-width: 8;
            stroke-miterlimit: 10;
            stroke: var(--success-color);
            fill: none;
            animation: stroke 0.5s cubic-bezier(0.65, 0, 0.45, 1) 1.3s forwards;
            position: absolute;
            width: 100%;
            height: 100%;
        }

        @keyframes stroke {
            100% {
                stroke-dashoffset: 0;
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h1 {
            font-size: 28px;
            color: var(--success-color);
            margin-bottom: 20px;
        }

        .payment-summary {
            background: var(--glass-bg);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid var(--glass-border);
            text-align: left;
            font-size: 16px;
            line-height: 1.6;
        }

        .payment-summary p {
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
        }
        .payment-summary p:last-child {
            margin-bottom: 0;
        }
        .payment-summary strong {
            color: var(--white);
            font-weight: 600;
        }
        .payment-summary span {
            color: #94a3b8;
        }
        .payment-summary .amount {
            color: var(--gold);
            font-weight: 700;
        }

        .action-button {
            background: linear-gradient(135deg, var(--neon-blue) 0%, #0284c7 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            width: 100%;
            max-width: 250px;
            box-shadow: 0 5px 15px rgba(14, 165, 233, 0.3);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .action-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(14, 165, 233, 0.5);
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-animation">
            <svg class="checkmark-circle" viewBox="0 0 130 130">
                <circle cx="65" cy="65" r="60" />
            </svg>
            <svg class="checkmark-stem" viewBox="0 0 130 130">
                <path d="M40 70 L60 90 L100 40" />
            </svg>
            <svg class="checkmark-kick" viewBox="0 0 130 130">
                <path d="M40 70 L60 90 L100 40" />
            </svg>
        </div>
        <h1>Payment Successful!</h1>
        <p style="margin-bottom: 25px; color: #b0bec5;">Your transaction was completed successfully.</p>

        <div class="payment-summary">
            <p><span>Amount:</span> <strong class="amount"><?= $amountNEX ?> NEX</strong></p>
            <p><span>Equivalent:</span> <span>≈ ₹<?= $amountINR ?></span></p>
            <p><span>Sent To:</span> <strong><?= $receiverName ?></strong></p>
            <p><span>Transaction ID:</span> <span><?= $transactionId ?></span></p>
            <p><span>Date:</span> <span><?= $timestamp ?></span></p>
        </div>

        <a href="dash.php" class="action-button">
            <i class="fas fa-home"></i> Go to Dashboard
        </a>
    </div>
</body>
</html>
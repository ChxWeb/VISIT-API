<?php
session_start();
if (!isset($_SESSION['logged_in'])) { die("Unauthorized"); }

$orderId = $_GET['order_id'] ?? '';
$userPhone = $_SESSION['phone_number'];

$orderFile = __DIR__ . '/../data/marketplace_orders.json';
$orders = file_exists($orderFile) ? json_decode(file_get_contents($orderFile), true) : [];

$targetOrder = null;

foreach($orders as $o) {
    // Verify that the logged-in user actually bought this order
    if($o['order_id'] === $orderId && $o['buyer_phone'] === $userPhone) {
        $targetOrder = $o;
        break;
    }
}

if (!$targetOrder) {
    die("Access Denied: You have not purchased this item.");
}

// Redirect to the External Link
if (!empty($targetOrder['file_link'])) {
    header("Location: " . $targetOrder['file_link']);
    exit;
} elseif (!empty($targetOrder['file_path'])) {
    // Fallback for old products (Local Files)
    $filePath = __DIR__ . '/../' . $targetOrder['file_path'];
    if (file_exists($filePath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($filePath).'"');
        readfile($filePath);
        exit;
    }
} 

die("Error: Download link missing.");
?>
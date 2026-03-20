<?php
session_start();
header('Content-Type: application/json');

// Enable Error Logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Include Helpers
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/email/send_product_link.php'; // Uses the Link Email helper

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$prodId = $input['product_id'];
$buyerPhone = $_SESSION['phone_number'];
$buyerName = $_SESSION['name'];
$buyerEmail = $_SESSION['email'];

// Load Data
$prodFile = __DIR__ . '/../data/marketplace_products.json';
$userFile = __DIR__ . '/../data/user.json';
$orderFile = __DIR__ . '/../data/marketplace_orders.json';
$transFile = __DIR__ . '/../data/transactions.json';

$products = json_decode(file_get_contents($prodFile), true);
$users = json_decode(file_get_contents($userFile), true);
$transactions = file_exists($transFile) ? json_decode(file_get_contents($transFile), true) : [];

// 1. Find Product
$pIndex = -1;
foreach($products as $k => $p) {
    if($p['id'] === $prodId) { $pIndex = $k; break; }
}

if($pIndex === -1) { echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }

$product = $products[$pIndex];
if($product['stock'] < 1) { echo json_encode(['success'=>false,'message'=>'Out of Stock']); exit; }

// 2. Price Calculation
$basePrice = $product['price'];
$tax = $basePrice * 0.15; // 15% Tax/Commission
$totalPrice = $basePrice + $tax;

// 3. Find Buyer & Seller
$buyerIdx = -1;
$sellerIdx = -1;

foreach($users as $k => $u) {
    if($u['phoneNumber'] === $buyerPhone) $buyerIdx = $k;
    if($u['phoneNumber'] === $product['seller_phone']) $sellerIdx = $k;
}

if($buyerIdx === -1) { echo json_encode(['success'=>false,'message'=>'Buyer Error']); exit; }

// 4. Check Balance
if($users[$buyerIdx]['balance'] < $totalPrice) {
    echo json_encode(['success'=>false,'message'=>'Insufficient Balance']); exit;
}

// --- PROCESS TRANSACTION ---

// 5. Deduct from Buyer
$users[$buyerIdx]['balance'] -= $totalPrice;
$_SESSION['balance'] = $users[$buyerIdx]['balance']; // Update Session

// 6. Credit to Seller
if($sellerIdx !== -1) {
    $users[$sellerIdx]['balance'] += $basePrice;
}

// 7. Update Stock & Sales
$products[$pIndex]['stock']--;
$products[$pIndex]['sales_count']++;

// 8. Create Order Record (Important for History)
// We save the external link here permanently
$orderId = uniqid('ORD');
$newOrder = [
    'order_id' => $orderId,
    'product_id' => $product['id'],
    'product_name' => $product['name'],
    'file_link' => $product['file_link'], // Save External Link
    'buyer_phone' => $buyerPhone,
    'amount_paid' => $totalPrice,
    'timestamp' => time()
];
$orders[] = $newOrder;

// 9. Log Transactions (For History Tab)

// 9a. Log Debit for Buyer
$transactions[] = [
    'transaction_id' => uniqid('TRX_BUY'),
    'timestamp' => date('Y-m-d H:i:s'),
    'type' => 'market_buy',
    'amount' => $totalPrice,
    'sender_phone' => $buyerPhone,
    'sender_email' => $buyerEmail,
    'sender_name' => $buyerName,
    'receiver_name' => 'Store',
    'status' => 'success',
    'description' => "Purchased: " . $product['name']
];

// 9b. Log Credit for Seller
$transactions[] = [
    'transaction_id' => uniqid('TRX_SELL'),
    'timestamp' => date('Y-m-d H:i:s'),
    'type' => 'market_sell',
    'amount' => $basePrice,
    'receiver_phone' => $product['seller_phone'],
    'receiver_email' => $product['seller_email'],
    'receiver_name' => $product['seller_name'],
    'sender_name' => 'Store',
    'status' => 'success',
    'description' => "Sold: " . $product['name']
];

// 10. Save All Files
file_put_contents($userFile, json_encode($users, JSON_PRETTY_PRINT));
file_put_contents($prodFile, json_encode($products, JSON_PRETTY_PRINT));
file_put_contents($orderFile, json_encode($orders, JSON_PRETTY_PRINT));
file_put_contents($transFile, json_encode($transactions, JSON_PRETTY_PRINT));

// --- SEND DOWNLOAD LINK ---
$downloadUrl = $product['file_link']; 
$buyerData = $users[$buyerIdx];

// 11. Send to Telegram
if (!empty($buyerData['telegram_chat_id'])) {
    $msg = "✅ <b>Purchase Successful!</b>\n\n";
    $msg .= "Item: <b>{$product['name']}</b>\n";
    $msg .= "Cost: <b>{$totalPrice} NEX</b>\n\n";
    $msg .= "🔗 <b>Download Link:</b>\n<a href='{$downloadUrl}'>Click here to Download</a>\n\n";
    $msg .= "<i>(Save this link, it's your product)</i>";
    
    sendTelegramMessage($buyerData['telegram_chat_id'], $msg);
}

// 12. Send to Email
if (!empty($buyerData['email'])) {
    sendProductLinkEmail($buyerData['email'], $buyerData['name'], $product['name'], $downloadUrl, $totalPrice);
}

echo json_encode(['success' => true]);
?>
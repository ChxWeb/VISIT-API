<?php
session_start();
if (!isset($_SESSION['logged_in'])) { die("Unauthorized"); }

$userPhone = $_SESSION['phone_number'];
$id = $_GET['id'] ?? '';

$file = __DIR__ . '/../data/marketplace_products.json';
$products = json_decode(file_get_contents($file), true);

$newProducts = [];
$deleted = false;

foreach($products as $p) {
    // If ID matches AND User matches, skip adding it to new array (delete it)
    if($p['id'] === $id) {
        if($p['seller_phone'] === $userPhone) {
            $deleted = true;
            // Optionally: Delete the image file here if you want
            if(file_exists(__DIR__ . '/../' . $p['image'])) {
                unlink(__DIR__ . '/../' . $p['image']);
            }
            continue; 
        }
    }
    $newProducts[] = $p;
}

if($deleted) {
    file_put_contents($file, json_encode($newProducts, JSON_PRETTY_PRINT));
    header('Location: ../market_dashboard.php');
} else {
    die("Product not found or you don't have permission to delete it.");
}
?>
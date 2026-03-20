<?php
session_start();
if (!isset($_SESSION['logged_in'])) { die("Unauthorized"); }

$userPhone = $_SESSION['phone_number'];
$id = $_POST['id'];

$file = __DIR__ . '/../data/marketplace_products.json';
$products = json_decode(file_get_contents($file), true);

$found = false;

foreach($products as $k => $p) {
    // Check ID and Ownership
    if($p['id'] === $id && $p['seller_phone'] === $userPhone) {
        $products[$k]['name'] = htmlspecialchars($_POST['name']);
        $products[$k]['description'] = htmlspecialchars($_POST['desc']);
        $products[$k]['price'] = (float)$_POST['price'];
        $products[$k]['stock'] = (int)$_POST['stock'];
        $products[$k]['file_link'] = $_POST['file_link'];
        $found = true;
        break;
    }
}

if($found) {
    file_put_contents($file, json_encode($products, JSON_PRETTY_PRINT));
    echo "<script>alert('Product Updated!'); window.location.href='../market_dashboard.php';</script>";
} else {
    die("Error updating product.");
}
?>
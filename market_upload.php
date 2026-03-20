<?php
session_start();
if (!isset($_SESSION['logged_in'])) { die("Unauthorized"); }

$userPhone = $_SESSION['phone_number'];
$userName = $_SESSION['name'];
$userEmail = $_SESSION['email'];

// JSON File Path
$file = __DIR__ . '/../data/marketplace_products.json';
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

// --- 1. Handle Image Upload (Thumbnail only) ---
$uploadDirImg = __DIR__ . '/../uploads/market_thumbs/';
if (!is_dir($uploadDirImg)) mkdir($uploadDirImg, 0777, true);

$imgName = 'default.png';

if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
    $imgName = uniqid() . '_' . time() . '.' . $ext;
    move_uploaded_file($_FILES['image']['tmp_name'], $uploadDirImg . $imgName);
}

// --- 2. Get External Link ---
$downloadLink = $_POST['file_link']; // User pasted link

// Validate URL
if (!filter_var($downloadLink, FILTER_VALIDATE_URL)) {
    die("Invalid URL. Please enter a valid download link.");
}

// --- 3. Save Product Data ---
$newProduct = [
    'id' => uniqid('PROD'),
    'seller_phone' => $userPhone,
    'seller_name' => $userName,
    'seller_email' => $userEmail,
    'name' => htmlspecialchars($_POST['name']),
    'description' => htmlspecialchars($_POST['desc']),
    'price' => (float)$_POST['price'],
    'stock' => (int)$_POST['stock'],
    'status' => 'active',
    'image' => 'uploads/market_thumbs/' . $imgName,
    'file_link' => $downloadLink, // Saving Link instead of Path
    'sales_count' => 0,
    'created_at' => time()
];

$data[] = $newProduct;
file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

header('Location: ../market_dashboard.php');
?>
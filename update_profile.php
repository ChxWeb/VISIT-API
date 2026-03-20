<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

// Get user identifier from session
$sessionPhoneNumber = $_SESSION['phone_number'] ?? null;
if (!$sessionPhoneNumber) {
    echo json_encode(['success' => false, 'message' => 'User phone number not found in session.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$newName = $input['name'] ?? '';
$newEmail = $input['email'] ?? '';

if (empty($newName) || empty($newEmail)) {
    echo json_encode(['success' => false, 'message' => 'Name and Email are required.']);
    exit;
}

// Basic email format validation
if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address format.']);
    exit;
}

$filePath = '../data/user.json';
$users = [];

if (file_exists($filePath)) {
    $jsonContent = file_get_contents($filePath);
    $users = json_decode($jsonContent, true);
    if (!is_array($users)) {
        $users = []; // Ensure it's an array even if JSON is malformed
    }
}

$userFound = false;
$updatedUsers = [];

foreach ($users as $key => $user) {
    if (isset($user['phoneNumber']) && $user['phoneNumber'] === $sessionPhoneNumber) {
        // Check if the new email is already taken by another user
        foreach ($users as $otherKey => $otherUser) {
            if ($key !== $otherKey && isset($otherUser['email']) && strtolower($otherUser['email']) === strtolower($newEmail)) {
                echo json_encode(['success' => false, 'message' => 'This email address is already registered by another user.']);
                exit;
            }
        }

        // Update user data
        $user['name'] = $newName;
        $user['email'] = $newEmail;
        $userFound = true;
        // Also update session with new details
        $_SESSION['name'] = $newName;
        $_SESSION['email'] = $newEmail;
    }
    $updatedUsers[] = $user;
}

if (!$userFound) {
    echo json_encode(['success' => false, 'message' => 'User not found for update.']);
    exit;
}

// Save updated data back to JSON file
if (file_put_contents($filePath, json_encode($updatedUsers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
} else {
    error_log("Error writing to user.json during profile update: Check permissions for $filePath");
    echo json_encode(['success' => false, 'message' => 'Failed to save profile. Server error.']);
}
?>
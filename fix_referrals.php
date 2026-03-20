<?php
// api/fix_referrals.php

header('Content-Type: text/html; charset=utf-8');

$filePath = __DIR__ . '/../data/user.json';

// Check if file exists
if (!file_exists($filePath)) {
    die("Error: user.json file not found at $filePath");
}

// Read users
$jsonContent = file_get_contents($filePath);
$users = json_decode($jsonContent, true);

if (!is_array($users)) {
    die("Error: User data is corrupted or empty.");
}

$updatedCount = 0;
$totalUsers = count($users);

// Helper function to generate code
function generateUniqueCode($name, $existingUsers) {
    // Clean name (remove non-letters)
    $cleanName = preg_replace('/[^a-zA-Z]/', '', $name);
    $prefix = strtoupper(substr($cleanName, 0, 3));
    if(strlen($prefix) < 3) $prefix = "NEX";
    
    do {
        $code = $prefix . rand(1000, 9999);
        $isDuplicate = false;
        // Check against all users to ensure uniqueness
        foreach ($existingUsers as $u) {
            if (isset($u['referral_code']) && $u['referral_code'] === $code) {
                $isDuplicate = true;
                break;
            }
        }
    } while ($isDuplicate);
    
    return $code;
}

echo "<h2>Starting Database Update...</h2>";
echo "<ul>";

foreach ($users as $key => &$user) {
    $changesMade = false;

    // 1. Fix Referral Code
    if (!isset($user['referral_code']) || empty($user['referral_code'])) {
        $newCode = generateUniqueCode($user['name'], $users);
        $user['referral_code'] = $newCode;
        $changesMade = true;
        echo "<li>Generated code <b>$newCode</b> for user: " . htmlspecialchars($user['name']) . "</li>";
    }

    // 2. Fix Reward Balance
    if (!isset($user['reward_balance'])) {
        $user['reward_balance'] = 0;
        $changesMade = true;
    }

    if ($changesMade) {
        $updatedCount++;
    }
}
unset($user); // Break reference

// Save back to file
if ($updatedCount > 0) {
    if (file_put_contents($filePath, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        echo "</ul>";
        echo "<h3 style='color: green;'>✅ Success! Updated $updatedCount users out of $totalUsers.</h3>";
        echo "<p>Now all existing users have a referral code.</p>";
        echo "<a href='../dash.php'>Go to Dashboard</a>";
    } else {
        echo "<h3 style='color: red;'>❌ Failed to save data. Check file permissions.</h3>";
    }
} else {
    echo "</ul>";
    echo "<h3 style='color: blue;'>ℹ️ No updates needed. All users already have codes.</h3>";
    echo "<a href='../dash.php'>Go to Dashboard</a>";
}
?>
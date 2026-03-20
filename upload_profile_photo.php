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

// Check if a file was uploaded
if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
    exit;
}

$file = $_FILES['profile_photo'];
$fileName = $file['name'];
$fileTmpName = $file['tmp_name'];
$fileSize = $file['size'];
$fileError = $file['error'];
$fileType = $file['type'];

$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];

// Validate file extension
if (!in_array($fileExt, $allowedExtensions)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, JPEG, PNG, GIF are allowed.']);
    exit;
}

// Validate file size (e.g., max 2MB)
if ($fileSize > 2 * 1024 * 1024) { // 2MB
    echo json_encode(['success' => false, 'message' => 'File size exceeds 2MB limit.']);
    exit;
}

$uploadDir = '../uploads/profile_pics/';
// Ensure upload directory exists and is writable
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true); // Create recursively with proper permissions
}
if (!is_writable($uploadDir)) {
    error_log("Upload directory is not writable: " . $uploadDir);
    echo json_encode(['success' => false, 'message' => 'Server error: Upload directory not writable.']);
    exit;
}

// Generate a unique filename
$newFileName = uniqid('profile_', true) . '.' . $fileExt;
$destination = $uploadDir . $newFileName;
$relativePhotoPath = 'uploads/profile_pics/' . $newFileName; // Path relative to PROGRESS directory

// Move the uploaded file
if (move_uploaded_file($fileTmpName, $destination)) {
    $filePath = '../data/user.json';
    $users = [];

    if (file_exists($filePath)) {
        $jsonContent = file_get_contents($filePath);
        $users = json_decode($jsonContent, true);
        if (!is_array($users)) {
            $users = [];
        }
    }

    $userFound = false;
    $updatedUsers = [];

    foreach ($users as $key => $user) {
        if (isset($user['phoneNumber']) && $user['phoneNumber'] === $sessionPhoneNumber) {
            // Delete old profile photo if it exists
            if (!empty($user['profile_photo']) && file_exists(__DIR__ . '/../' . $user['profile_photo'])) {
                unlink(__DIR__ . '/../' . $user['profile_photo']);
            }
            $user['profile_photo'] = $relativePhotoPath;
            $userFound = true;
            $_SESSION['profile_photo'] = $relativePhotoPath; // Update session
        }
        $updatedUsers[] = $user;
    }

    if (!$userFound) {
        // This case should ideally not happen if user is logged in
        // Clean up the uploaded file if user not found in JSON
        unlink($destination);
        echo json_encode(['success' => false, 'message' => 'User not found in database after upload.']);
        exit;
    }

    if (file_put_contents($filePath, json_encode($updatedUsers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
        echo json_encode(['success' => true, 'message' => 'Profile photo uploaded successfully!', 'photo_url' => $relativePhotoPath]);
    } else {
        error_log("Error writing to user.json after profile photo upload: Check permissions for $filePath");
        unlink($destination); // Clean up uploaded file if DB update fails
        echo json_encode(['success' => false, 'message' => 'Failed to update profile photo in database.']);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file. Check server permissions.']);
}
?>```

### **3. Updated: `PROGRESS/edit_profile.php`**

```php
<?php
session_start();

// Redirect to login page if not logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

$userName = $_SESSION['name'] ?? 'NexusPay User';
$userEmail = $_SESSION['email'] ?? 'user@example.com';
$userPhoneNumber = $_SESSION['phone_number'] ?? '+910000000000'; // Default if not set
$profilePhoto = $_SESSION['profile_photo'] ?? ''; // Get profile photo path from session

// Derive 10-digit number for UPI from phone number
$phoneNumberDigits = str_replace('+91', '', $userPhoneNumber);
$upiVpa = $phoneNumberDigits . '@nexiopay';

// Generate profile picture initial (fallback if no photo)
$profileFirstNameInitial = '';
$nameParts = explode(' ', $userName);
if (!empty($nameParts[0])) {
    $profileFirstNameInitial = strtoupper(substr($nameParts[0], 0, 1));
}

// Define colors for profile icon background (feel free to adjust)
$profileColors = ['#FF5733', '#33FF57', '#3357FF', '#FF33DA', '#33DAFF', '#DAFF33', '#FFA500', '#8A2BE2', '#00CED1'];
$randomColor = $profileColors[array_rand($profileColors)]; // Random color for each load
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NexusPay | Edit Profile</title>
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
            --success-color: #2ecc71; /* Added for snackbar */
            --error-color: #e74c3c;   /* Added for snackbar */
            --glass-bg: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(255, 255, 255, 0.1);
            --input-bg: rgba(255, 255, 255, 0.03);
            --focus-border-color: rgba(0, 170, 255, 0.7);
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
            align-items: flex-start;
            padding: 0;
            overflow-x: hidden;
        }
        @media (max-width: 600px) {
            body {
                padding: 0;
            }
        }

        .edit-profile-container {
            max-width: 420px;
            width: 100%;
            background: linear-gradient(135deg, var(--dark-blue) 0%, #27354d 100%);
            border-radius: 20px;
            padding: 0;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(10px);
            text-align: center;
            margin-top: 20px;
            position: relative;
            overflow: hidden;
        }
        @media (max-width: 600px) {
            .edit-profile-container {
                border-radius: 0;
                margin-top: 0;
                min-height: 100vh;
            }
        }

        /* Header Bar with Back Button */
        .header-bar {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            background: var(--dark-blue);
            border-bottom: 1px solid var(--glass-border);
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .header-bar .back-btn {
            color: var(--white);
            font-size: 20px;
            cursor: pointer;
            margin-right: 15px;
            padding: 5px;
            text-decoration: none;
        }
        .header-bar .page-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--white);
            flex-grow: 1;
            text-align: center;
            transform: translateX(-15px);
        }

        /* Profile Photo Section */
        .profile-photo-section {
            padding: 30px 20px;
            background: #1e293b;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 20px;
        }
        .profile-photo-display {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: 700;
            color: var(--white);
            border: 3px solid var(--neon-blue);
            box-shadow: 0 0 20px var(--neon-blue-glow);
            margin-bottom: 20px;
            text-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
            background-size: cover; /* For image */
            background-position: center; /* For image */
        }
        .profile-photo-display img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            display: block; /* Remove extra space below image */
        }
        .upload-photo-btn {
            background: var(--glass-bg);
            color: var(--neon-blue);
            border: 1px solid var(--neon-blue);
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .upload-photo-btn:hover {
            background: rgba(14, 165, 233, 0.2);
            box-shadow: 0 0 15px var(--neon-blue-glow);
            transform: translateY(-2px);
        }

        /* Form Fields Section */
        .form-fields-section {
            padding: 0 20px 30px;
            text-align: left;
        }
        .input-group {
            position: relative;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            background-color: var(--input-bg);
            border-radius: 15px;
            border: 1px solid var(--glass-border);
            box-shadow: inset 0 3px 6px rgba(0, 0, 0, 0.25);
            transition: all 0.3s ease;
        }
        .input-group:focus-within {
            border-color: var(--focus-border-color);
            box-shadow: inset 0 3px 8px rgba(0, 0, 0, 0.35), 0 0 12px var(--neon-blue-glow);
        }
        .input-group input {
            flex-grow: 1;
            padding: 15px 18px;
            background: transparent;
            border: none;
            border-radius: 15px;
            color: var(--white);
            font-size: 1.08em;
            outline: none;
            transition: all 0.3s ease;
        }
        .input-group input::placeholder {
            color: rgba(var(--white), 0.55);
        }
        .input-group .input-icon {
            color: rgba(var(--neon-blue), 0.75);
            margin-left: 18px;
            font-size: 1.3em;
        }
        .input-group:focus-within .input-icon {
            color: var(--neon-blue);
        }
        .input-group.locked {
            background-color: rgba(255, 255, 255, 0.01);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .input-group.locked input {
            color: #7f8c8d;
            cursor: not-allowed;
        }
        .input-group.locked .input-icon {
             color: rgba(127, 140, 141, 0.7);
        }
        .upi-note {
            font-size: 12px;
            color: #94a3b8;
            margin-top: -15px; /* Adjust spacing */
            margin-bottom: 25px;
            padding-left: 15px;
        }


        /* Action Buttons */
        .action-buttons {
            padding: 20px;
            border-top: 1px solid var(--glass-border);
            display: flex;
            gap: 15px;
        }
        .action-button {
            flex: 1;
            padding: 12px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 16px;
        }
        .action-button.primary {
            background: linear-gradient(135deg, var(--neon-blue) 0%, #0284c7 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(14, 165, 233, 0.3);
        }
        .action-button.secondary {
            background: var(--glass-bg);
            color: var(--white);
            border: 1px solid var(--glass-border);
        }
        .action-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }

        /* Snackbar for notifications */
        #snackbar {
            visibility: hidden;
            min-width: 300px;
            background-color: var(--dark-blue);
            color: var(--white);
            text-align: center;
            border-radius: 12px;
            padding: 20px;
            position: fixed;
            z-index: 100;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%) translateY(70px);
            font-size: 1.1em;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.7);
            opacity: 0;
            transition: all 0.45s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        #snackbar.show {
            visibility: visible;
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        #snackbar.error {
            background-color: var(--red);
            box-shadow: 0 10px 30px rgba(231, 76, 60, 0.6);
        }
        #snackbar.success {
            background-color: var(--success-color);
            box-shadow: 0 10px 30px rgba(46, 204, 113, 0.6);
        }
        @media (max-width: 600px) {
            #snackbar {
                min-width: unset;
                width: 90%;
                left: 5%;
                transform: translateX(0%) translateY(70px);
                font-size: 1em;
                padding: 15px;
            }
            #snackbar.show {
                transform: translateX(0%) translateY(0);
            }
        }
    </style>
</head>
<body>
    <div class="edit-profile-container">
        <!-- Header Bar with Back Button -->
        <div class="header-bar">
            <a href="profile.php" class="back-btn"><i class="fas fa-arrow-left"></i></a>
            <span class="page-title">Edit Profile</span>
        </div>

        <form id="editProfileForm">
            <div class="profile-photo-section">
                <div class="profile-photo-display" style="background-color: <?= $randomColor ?>;">
                    <?php if (!empty($profilePhoto)): ?>
                        <img id="profilePreview" src="<?= htmlspecialchars($profilePhoto) ?>" alt="Profile Photo">
                    <?php else: ?>
                        <span id="profileInitialPreview"><?= htmlspecialchars($profileFirstNameInitial) ?></span>
                    <?php endif; ?>
                </div>
                <input type="file" id="photoUploadInput" accept="image/png, image/jpeg, image/gif" style="display: none;">
                <button type="button" class="upload-photo-btn" id="triggerPhotoUpload">
                    <i class="fas fa-camera"></i> Upload Photo
                </button>
            </div>

            <div class="form-fields-section">
                <div class="input-group">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" id="editUserName" name="name" placeholder="Full Name" value="<?= htmlspecialchars($userName) ?>" autocomplete="name" required>
                </div>
                <div class="input-group">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" id="editUserEmail" name="email" placeholder="Email ID" value="<?= htmlspecialchars($userEmail) ?>" autocomplete="email" required>
                </div>
                <div class="input-group locked">
                    <i class="fas fa-phone-alt input-icon"></i>
                    <input type="tel" id="editPhoneNumber" value="<?= htmlspecialchars($userPhoneNumber) ?>" readonly>
                </div>
                <div class="input-group locked">
                    <i class="fas fa-qrcode input-icon"></i>
                    <input type="text" id="editUpiVpa" value="<?= htmlspecialchars($upiVpa) ?>" readonly>
                </div>
                <div class="upi-note">Note: UPI VPA can be changed after 29₹ payment.</div>
            </div>

            <div class="action-buttons">
                <button type="submit" class="action-button primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <button type="button" class="action-button secondary" onclick="window.location.href = 'profile.php';">
                    <i class="fas fa-times-circle"></i> Cancel
                </button>
            </div>
        </form>
    </div>

    <!-- Snackbar for notifications -->
    <div id="snackbar"></div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const editProfileForm = document.getElementById('editProfileForm');
            const userNameInput = document.getElementById('editUserName');
            const userEmailInput = document.getElementById('editUserEmail');
            const snackbar = document.getElementById('snackbar');

            const photoUploadInput = document.getElementById('photoUploadInput');
            const triggerPhotoUploadBtn = document.getElementById('triggerPhotoUpload');
            const profilePhotoDisplay = document.querySelector('.profile-photo-display');
            const profileInitialPreview = document.getElementById('profileInitialPreview'); // To hide initial if photo is shown

            // Function to display snackbar notifications
            function showSnackbar(message, type = 'info') {
                snackbar.textContent = message;
                snackbar.className = 'show'; // Reset classes
                if (type === 'error') {
                    snackbar.classList.add('error');
                } else if (type === 'success') {
                    snackbar.classList.add('success');
                } else {
                    // Default info styling
                }

                setTimeout(() => {
                    snackbar.className = snackbar.className.replace('show', '');
                }, 3000);
            }

            // --- Handle Name/Email Save ---
            editProfileForm.addEventListener('submit', async (event) => {
                event.preventDefault(); // Prevent default form submission

                const name = userNameInput.value.trim();
                const email = userEmailInput.value.trim();

                if (!name || !email) {
                    showSnackbar('Name and Email cannot be empty.', 'error');
                    return;
                }

                if (!/\S+@\S+\.\S+/.test(email)) { // Basic email regex validation
                    showSnackbar('Please enter a valid email address.', 'error');
                    return;
                }

                const saveButton = editProfileForm.querySelector('.primary');
                saveButton.disabled = true;
                saveButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; // Show loading spinner
                showSnackbar('Saving changes...', 'info');

                try {
                    const response = await fetch('api/update_profile.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ name: name, email: email })
                    });
                    const data = await response.json();

                    if (data.success) {
                        showSnackbar(data.message, 'success');
                        // No redirect, just update the session and possibly display new name/email
                        // This would typically involve reloading the PHP session or fetching fresh data
                        // For this demo, we can just allow the user to continue on the page
                        // or redirect to profile.php after a slight delay
                        setTimeout(() => {
                            window.location.href = 'profile.php'; // Redirect to see updated data
                        }, 1500);
                    } else {
                        showSnackbar(data.message, 'error');
                    }
                } catch (error) {
                    console.error('Error updating profile:', error);
                    showSnackbar('An unexpected error occurred. Please try again.', 'error');
                } finally {
                    saveButton.disabled = false;
                    saveButton.innerHTML = '<i class="fas fa-save"></i> Save Changes'; // Restore button text
                }
            });

            // --- Handle Photo Upload ---
            triggerPhotoUploadBtn.addEventListener('click', () => {
                photoUploadInput.click(); // Trigger the hidden file input click
            });

            photoUploadInput.addEventListener('change', async (event) => {
                const file = event.target.files[0];
                if (!file) {
                    return;
                }

                // File type validation
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    showSnackbar('Invalid file type. Please upload a JPG, PNG, or GIF image.', 'error');
                    return;
                }

                // File size validation (max 2MB)
                if (file.size > 2 * 1024 * 1024) {
                    showSnackbar('File size exceeds 2MB limit.', 'error');
                    return;
                }

                // Display image preview
                const reader = new FileReader();
                reader.onload = (e) => {
                    // Remove initial if present, add img tag
                    if (profileInitialPreview) {
                        profileInitialPreview.style.display = 'none';
                    }
                    let imgElement = profilePhotoDisplay.querySelector('img');
                    if (!imgElement) {
                        imgElement = document.createElement('img');
                        imgElement.id = 'profilePreview';
                        imgElement.alt = 'Profile Photo';
                        profilePhotoDisplay.appendChild(imgElement);
                    }
                    imgElement.src = e.target.result;
                    // Ensure the div's background-image is cleared if an img is used
                    profilePhotoDisplay.style.backgroundImage = 'none';
                };
                reader.readAsDataURL(file);

                // Prepare FormData for upload
                const formData = new FormData();
                formData.append('profile_photo', file);

                triggerPhotoUploadBtn.disabled = true;
                triggerPhotoUploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
                showSnackbar('Uploading photo...', 'info');

                try {
                    const response = await fetch('api/upload_profile_photo.php', {
                        method: 'POST',
                        body: formData // FormData handles Content-Type header automatically
                    });
                    const data = await response.json();

                    if (data.success) {
                        showSnackbar(data.message, 'success');
                        // Update the profilePhoto variable for immediate display on profile.php
                        // and refresh current session data.
                        // For robust update, you might want to reload the page or update PHP session explicitly.
                        // window.location.reload(); // A simple way to reflect changes immediately
                        setTimeout(() => {
                           window.location.href = 'profile.php'; // Redirect to see updated data
                        }, 1500);
                    } else {
                        showSnackbar(data.message, 'error');
                        // If upload fails, revert preview or keep initial
                        if (!imgElement || !imgElement.src) { // If no actual image was loaded from previous session
                             if (profileInitialPreview) profileInitialPreview.style.display = 'block';
                             if (profilePhotoDisplay.querySelector('img')) profilePhotoDisplay.querySelector('img').remove();
                             profilePhotoDisplay.style.backgroundColor = '<?= $randomColor ?>'; // Revert background if initial fallback
                        }
                    }
                } catch (error) {
                    console.error('Error uploading photo:', error);
                    showSnackbar('An unexpected error occurred during photo upload. Please try again.', 'error');
                     // If upload fails, revert preview
                     if (!profilePhotoDisplay.querySelector('img')) { // Only if it was showing initial
                         if (profileInitialPreview) profileInitialPreview.style.display = 'block';
                         profilePhotoDisplay.style.backgroundColor = '<?= $randomColor ?>';
                     }
                } finally {
                    triggerPhotoUploadBtn.disabled = false;
                    triggerPhotoUploadBtn.innerHTML = '<i class="fas fa-camera"></i> Upload Photo';
                    photoUploadInput.value = ''; // Clear file input
                }
            });
        });
    </script>
</body>
</html>
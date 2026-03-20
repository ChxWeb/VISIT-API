<?php
session_start();
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$phoneNumber = $input['phone_number'] ?? null;
$name = $input['name'] ?? null;
$email = $input['email'] ?? null;
$balance = $input['balance'] ?? 0.00;
$profilePhoto = $input['profile_photo'] ?? '';

if (!$phoneNumber || !$name || !$email) {
    echo json_encode(['success' => false, 'message' => 'Missing data for session revalidation.']);
    exit;
}

// --- IMPORTANT SECURITY NOTE ---
// For a production application, you should perform a more robust check here,
// e.g., querying your database (user.json in this case) to ensure the
// phone number/email still exists and details match. This prevents a user
// from tampering with local storage to impersonate another user.
// For this demo, we'll assume local storage data is sufficient for revalidation.

// Re-populate PHP session
$_SESSION['logged_in'] = true;
$_SESSION['phone_number'] = $phoneNumber;
$_SESSION['name'] = $name;
$_SESSION['email'] = $email;
$_SESSION['balance'] = (float) $balance; // Ensure balance is float
$_SESSION['profile_photo'] = $profilePhoto;

echo json_encode(['success' => true, 'message' => 'Session revalidated.']);
?>```

---

### **4. Updated: `PROGRESS/js/script.js`**

Add functions to save and clear user data from local storage, and integrate them into the login/registration success flows.

```javascript
// Add these functions at the top of your script.js file
function saveUserDataToLocalStorage(userData) {
    localStorage.setItem('logged_in', 'true');
    localStorage.setItem('phone_number', userData.phone_number);
    localStorage.setItem('name', userData.name);
    localStorage.setItem('email', userData.email);
    localStorage.setItem('balance', userData.balance);
    localStorage.setItem('profile_photo', userData.profile_photo);
}

function clearUserDataFromLocalStorage() {
    localStorage.removeItem('logged_in');
    localStorage.removeItem('phone_number');
    localStorage.removeItem('name');
    localStorage.removeItem('email');
    localStorage.removeItem('balance');
    localStorage.removeItem('profile_photo');
}

document.addEventListener('DOMContentLoaded', () => {
    const mobileNumberInput = document.getElementById('mobileNumber');
    const requestOtpBtn = document.getElementById('requestOtpBtn');
    const otpVerificationScreen = document.getElementById('otp-verification-screen');
    const otpInput = document.getElementById('otpInput');
    const verifyOtpBtn = document.getElementById('verifyOtpBtn');
    const resendOtpBtn = document.getElementById('resendOtpBtn');
    const countdownSpan = document.getElementById('countdown');
    const userRegistrationScreen = document.getElementById('user-registration-screen');
    const userNameInput = document.getElementById('userName');
    const userEmailInput = document.getElementById('userEmail');
    const userPasswordInput = document.getElementById('userPassword');
    const saveUserBtn = document.getElementById('saveUserBtn');
    const snackbar = document.getElementById('snackbar');
    
    // Global variable to store recipient type and value
    let currentOtpRecipientType = '';
    let currentOtpRecipient = '';

    // Function to switch between screens
    function switchScreen(screenId) {
        document.querySelectorAll('.screen').forEach(screen => {
            screen.classList.remove('active');
        });
        document.getElementById(screenId).classList.add('active');
    }

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

    // Countdown for resend OTP button
    let countdownInterval;
    function startCountdown(seconds) {
        let timer = seconds;
        resendOtpBtn.disabled = true;
        countdownSpan.textContent = timer;

        countdownInterval = setInterval(() => {
            timer--;
            countdownSpan.textContent = timer;
            if (timer <= 0) {
                clearInterval(countdownInterval);
                resendOtpBtn.disabled = false;
                countdownSpan.textContent = '0'; // Display 0 when done
            }
        }, 1000);
    }

    // Request OTP Button Click
    requestOtpBtn.addEventListener('click', async () => {
        const mobileNumber = mobileNumberInput.value.trim();
        if (mobileNumber.length !== 10 || !/^\d+$/.test(mobileNumber)) {
            showSnackbar('Please enter a valid 10-digit mobile number.', 'error');
            return;
        }

        requestOtpBtn.disabled = true;
        requestOtpBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Requesting...';
        showSnackbar('Requesting OTP...', 'info');

        try {
            const response = await fetch('api/request-otp.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ phoneNumber: `+91${mobileNumber}` })
            });
            const data = await response.json();

            if (data.success) {
                showSnackbar(data.message, 'success');
                currentOtpRecipientType = data.otpRecipientType; // 'mobile' or 'email'
                currentOtpRecipient = data.otpRecipient; // The actual recipient (phone number or email)

                // Update description text based on recipient type
                const otpDescription = otpVerificationScreen.querySelector('.description');
                if (currentOtpRecipientType === 'email') {
                    otpDescription.textContent = `An OTP has been sent to your registered email: ${currentOtpRecipient}`;
                } else {
                    otpDescription.textContent = `An OTP has been sent to your mobile number: ${currentOtpRecipient}`;
                }
                
                switchScreen('otp-verification-screen');
                startCountdown(30);
            } else {
                showSnackbar(data.message, 'error');
            }
        } catch (error) {
            console.error('Error requesting OTP:', error);
            showSnackbar('An unexpected error occurred. Please try again.', 'error');
        } finally {
            requestOtpBtn.disabled = false;
            requestOtpBtn.innerHTML = 'Request OTP';
        }
    });

    // Resend OTP Button Click
    resendOtpBtn.addEventListener('click', async () => {
        // This button only appears when timer is 0, so no need to disable it here.
        resendOtpBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resending...';
        showSnackbar('Resending OTP...', 'info');

        // Reuse the logic from requestOtpBtn based on currentOtpRecipientType
        let payload = {};
        let apiUrl = '';
        if (currentOtpRecipientType === 'email') {
            payload = { email: currentOtpRecipient };
            apiUrl = 'api/request-otp.php'; // This endpoint now intelligently sends to email if user exists
        } else {
            payload = { phoneNumber: currentOtpRecipient };
            apiUrl = 'api/request-otp.php';
        }

        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await response.json();

            if (data.success) {
                showSnackbar(data.message, 'success');
                startCountdown(30);
            } else {
                showSnackbar(data.message, 'error');
            }
        } catch (error) {
            console.error('Error resending OTP:', error);
            showSnackbar('An unexpected error occurred while resending OTP.', 'error');
        } finally {
            resendOtpBtn.innerHTML = '<i class="fas fa-redo"></i> Resend OTP (<span id="countdown">30</span>s)';
        }
    });

    // Verify OTP Button Click
    verifyOtpBtn.addEventListener('click', async () => {
        const otp = otpInput.value.trim();
        if (otp.length === 0) {
            showSnackbar('Please enter the OTP.', 'error');
            return;
        }

        verifyOtpBtn.disabled = true;
        verifyOtpBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
        showSnackbar('Verifying OTP...', 'info');

        try {
            const response = await fetch('api/verify-otp.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    otp: otp,
                    otpRecipientType: currentOtpRecipientType, // Send type
                    otpRecipient: currentOtpRecipient // Send recipient
                })
            });
            const data = await response.json();

            if (data.success) {
                showSnackbar(data.message, 'success');
                otpInput.value = ''; // Clear OTP input

                if (data.userExists) {
                    // Existing user logged in
                    clearInterval(countdownInterval);
                    // Save user data to local storage for persistent login
                    saveUserDataToLocalStorage(data.user_data);
                    setTimeout(() => {
                        window.location.href = 'dash.php';
                    }, 1500);
                } else {
                    // New user, proceed to registration screen
                    // Pre-fill email if OTP was sent to email for new user registration
                    if (currentOtpRecipientType === 'email') {
                        userEmailInput.value = currentOtpRecipient;
                    }
                    // Temporarily store phone number if mobile was used for OTP
                    if (currentOtpRecipientType === 'mobile') {
                        localStorage.setItem('temp_phone_number', currentOtpRecipient); // Store the full +91 number
                    }
                    
                    switchScreen('user-registration-screen');
                    clearInterval(countdownInterval); // Stop countdown
                }
            } else {
                showSnackbar(data.message, 'error');
            }
        } catch (error) {
            console.error('Error verifying OTP:', error);
            showSnackbar('An unexpected error occurred. Please try again.', 'error');
        } finally {
            verifyOtpBtn.disabled = false;
            verifyOtpBtn.innerHTML = 'Verify OTP';
        }
    });

    // Save User Details (Registration)
    saveUserBtn.addEventListener('click', async () => {
        const name = userNameInput.value.trim();
        const email = userEmailInput.value.trim();
        const password = userPasswordInput.value.trim();
        const phoneNumber = localStorage.getItem('temp_phone_number'); // Get phone number from temp storage

        if (!name || !email || !password || !phoneNumber) {
            showSnackbar('All fields are required. Please go back and try again.', 'error');
            return;
        }

        if (!/\S+@\S+\.\S+/.test(email)) {
            showSnackbar('Please enter a valid email address.', 'error');
            return;
        }

        if (password.length < 6) { // Basic password length validation
            showSnackbar('Password must be at least 6 characters long.', 'error');
            return;
        }

        saveUserBtn.disabled = true;
        saveUserBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering...';
        showSnackbar('Registering user...', 'info');

        try {
            const response = await fetch('api/save-user-data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    phoneNumber: phoneNumber,
                    name: name,
                    email: email,
                    password: password
                })
            });
            const data = await response.json();

            if (data.success) {
                showSnackbar(data.message, 'success');
                localStorage.removeItem('temp_phone_number'); // Clear temporary phone number
                // Save new user data to local storage
                saveUserDataToLocalStorage(data.user_data);
                setTimeout(() => {
                    window.location.href = 'dash.php';
                }, 1500);
            } else {
                showSnackbar(data.message, 'error');
            }
        } catch (error) {
            console.error('Error saving user data:', error);
            showSnackbar('An unexpected error occurred during registration. Please try again.', 'error');
        } finally {
            saveUserBtn.disabled = false;
            saveUserBtn.innerHTML = 'Register';
        }
    });
});
<?php
session_start();
include 'db.php'; // Database connection

// Check if user is logged in
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header("Location: login.php");
    exit();
}

// Fetch user info
$stmt = $conn->prepare("SELECT password, force_password_change, force_security_setup, role 
                        FROM users WHERE id=?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

$msg = '';

// Handle password change
if (isset($_POST['change_password'])) {
    $newPass = trim($_POST['new_password']);
    $confirmPass = trim($_POST['confirm_password']);
    $question = trim($_POST['security_question']);
    $answerPlain = trim($_POST['security_answer']);
    $answer = password_hash($answerPlain, PASSWORD_DEFAULT);

    if ($newPass !== $confirmPass) {
        $msg = '<div class="alert error">Passwords do not match</div>';
    } elseif (strlen($newPass) < 8) {
        $msg = '<div class="alert error">Password must be at least 8 characters</div>';
    } elseif (password_verify($newPass, $user['password'])) {
        $msg = '<div class="alert error">New password cannot be the same as the old password</div>';
    } else {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);

        // ✅ Reset both force flags
        $stmt = $conn->prepare("UPDATE users 
            SET password=?, force_password_change=0, force_security_setup=0, 
                security_question=?, security_answer=? 
            WHERE id=?");
        $stmt->bind_param("sssi", $hash, $question, $answer, $userId);

        if ($stmt->execute()) {
            $_SESSION['success_msg'] = "Your password and security question have been updated!";
            header("Location: change_password.php"); // reload to show success
            exit();
        } else {
            $msg = '<div class="alert error">Failed to update password. Please try again.</div>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Change Password | BENE MediCon</title>    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI','Roboto',sans-serif; }
        body { background-color: #d8f5c8; min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .container { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 500px; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { color: #03d32c; font-size: 24px; margin-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #333; font-weight: 500; }
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; transition: border-color 0.3s; }
        .form-group input:focus, .form-group select:focus { border-color: #1a73e8; outline: none; }
        .btn { background: #f4e35f; color: #333; padding: 12px 20px; border: none; border-radius: 6px; width: 100%; font-size: 16px; cursor: pointer; transition: background 0.3s; }
        .btn:hover { background: #f0d400; }
        .back-btn { display: inline-block; padding: 8px 16px; color: #666; text-decoration: none; margin-top: 20px; transition: color 0.3s; }
        .back-btn:hover { color: #1a73e8; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; font-weight: 500; }
        .alert.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert ul { margin: 10px 0 0 0; padding-left: 20px; /* space for bullets */}
        .alert li {margin-bottom: 5px; /* spacing between items */}
        .icon, .header h1 i { color: #000; margin-right: 8px; }
        .password-wrapper { position: relative; }
        .password-wrapper input { padding-right: 40px; }
        .toggle-password { position: absolute; top: 70%; right: 12px; transform: translateY(-50%); cursor: pointer; color: #888; font-size: 18px; }
        .toggle-password:hover { color: #1a73e8; }
        /* 🎯 Special rules for the NEW PASSWORD only */ .new-password-wrapper {position: relative; margin-bottom: 30px; /* extra spacing for strength message */}
        .new-password-wrapper input {padding-right: 40px; /* space for eye icon */}
        .new-password-wrapper .toggle-password {position: absolute; top: 55%;  /* lowered slightly so it doesn’t overlap strength message */ right: 12px; transform: translateY(-50%);}
        #strengthMessage { font-size: 0.9em; margin-top: 5px; }
        #strengthMessage.weak { color: red; }
        #strengthMessage.medium { color: orange; }
        #strengthMessage.strong { color: green; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1><i class="fas fa-key"></i> Change Password</h1>
        <p>Change your password regularly for security.</p>
    </div>

    <?php
    // ✅ Success message
    if (isset($_SESSION['success_msg'])) {
        echo "<div class='alert success'>{$_SESSION['success_msg']}</div>";
        unset($_SESSION['success_msg']);
    }

    // ✅ Unified banner for forced actions
    $alerts = [];
    if ($user['force_password_change'] == 1) {
        $alerts[] = "You are using a default password. Please change it immediately.";
    }
    if ($user['force_security_setup'] == 1) {
        $alerts[] = "Please set your security question for account recovery.";
    }
    if (!empty($alerts)) {
        echo "<div class='alert error'><strong>⚠️ Action required:</strong><ul style='margin-top:10px;'>";
        foreach ($alerts as $a) {
            echo "<li>$a</li>";
        }
        echo "</ul></div>";
    }

    echo $msg;
    ?>

    <form method="POST">
        <div class="form-group new-password-wrapper">
            <label for="new_password"><i class="fas fa-lock icon"></i> New Password</label>
            <input type="password" name="new_password" id="new_password" placeholder="Enter new password" required>
            <i class="fas fa-eye toggle-password" onclick="togglePassword('new_password', this)"></i>
            <div id="strengthMessage"></div>
        </div>

        <div class="form-group password-wrapper">
            <label for="confirm_password"><i class="fas fa-lock icon"></i> Confirm Password</label>
            <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" required>
            <i class="fas fa-eye toggle-password" onclick="togglePassword('confirm_password', this)"></i>
        </div>

        <div class="form-group">
            <label for="security_question"><i class="fas fa-question-circle icon"></i> Security Question</label>
            <select name="security_question" id="security_question" required>
                <option value="">-- Select a question --</option>
                <option value="What is your favorite color?">What is your favorite color?</option>
                <option value="What city were you born in?">What city were you born in?</option>
                <option value="What is your pet's name?">What is your pet's name?</option>
                <option value="What is your mother's maiden name?">What is your mother's maiden name?</option>
            </select>
        </div>

        <div class="form-group password-wrapper">
            <label for="security_answer"><i class="fas fa-pen icon"></i> Answer</label>
            <input type="password" name="security_answer" id="security_answer" placeholder="Enter your answer" required>
            <i class="fas fa-eye toggle-password" onclick="togglePassword('security_answer', this)"></i>
        </div>

        <button type="submit" name="change_password" class="btn">
            <i class="fas fa-save icon"></i> Change Password
        </button>

        <a href="<?php echo $user['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php'; ?>" class="back-btn">
            <i class="fas fa-arrow-left icon"></i> Back to Dashboard
        </a>
    </form>
</div>

<script>
function togglePassword(fieldId, icon) {
    const field = document.getElementById(fieldId);
    if (field.type === "password") {
        field.type = "text";
        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");
    } else {
        field.type = "password";
        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");
    }
}

// Password strength checker
const passwordInput = document.getElementById('new_password');
const strengthMessage = document.getElementById('strengthMessage');

passwordInput.addEventListener('input', () => {
    const val = passwordInput.value;
    let strength = '';
    if (val.length === 0) {
        strengthMessage.textContent = '';
        strengthMessage.className = '';
        return;
    }
    if (val.length < 8) {
        strength = 'weak';
        strengthMessage.textContent = 'Weak (too short)';
    } else if (/[A-Z]/.test(val) && /[a-z]/.test(val) && /[0-9]/.test(val) && /[\W]/.test(val)) {
        strength = 'strong';
        strengthMessage.textContent = 'Strong';
    } else {
        strength = 'medium';
        strengthMessage.textContent = 'Medium';
    }
    strengthMessage.className = strength;
});
</script>
</body>
</html>

<?php
session_start();
include('db.php');

$error_message = '';
$success_message = '';

// Only allow if user passed verification
if (!isset($_SESSION['verified_user'])) {
    header("Location: forgot_password.php");
    exit();
}

$username = $_SESSION['verified_user'];

if (isset($_POST['reset_password'])) {
    $newPass = trim($_POST['new_password']);
    $confirmPass = trim($_POST['confirm_password']);

    if ($newPass !== $confirmPass) {
        $error_message = "Passwords do not match!";
    } elseif (strlen($newPass) < 8) {
        $error_message = "Password must be at least 8 characters!";
    } else {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password=? WHERE username=?");
        if ($stmt) {
            $stmt->bind_param("ss", $hash, $username);
            if ($stmt->execute()) {
                $success_message = "✅ Password reset successful! You can now <a href='login.php'>login</a>.";

                // Unset session after successful reset
                unset($_SESSION['verified_user'], $_SESSION['reset_user']);
            } else {
                $error_message = "Error updating password.";
            }
            $stmt->close();
        } else {
            $error_message = "Database error.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password | BENE MediCon</title>
    <link rel="stylesheet" href="forgotpw-style.css">
</head>
<body>
<div class="container">
    <h2>Reset Password</h2>

    <?php if (!empty($error_message)): ?>
        <p class="error"><?php echo htmlspecialchars($error_message); ?></p>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <p class="success"><?php echo $success_message; ?></p>
    <?php else: ?>
        <form method="POST">
            <label>New Password</label>
            <input type="password" name="new_password" placeholder="Enter new password" required>
            <small>Password must be at least 8 characters</small>

            <label>Confirm Password</label>
            <input type="password" name="confirm_password" placeholder="Confirm new password" required>

            <button type="submit" name="reset_password">Reset Password</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>

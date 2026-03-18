<?php
session_start();
include 'db.php';

// Only allow admins
if ($_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

if (isset($_POST['reset_user'])) {
    $userId = intval($_POST['user_id']);
    $tempPassword = "Temp" . rand(1000, 9999); // generate temp password
    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE users 
        SET password=?, force_password_change=1, force_security_setup=1,
            security_question=NULL, security_answer=NULL
        WHERE id=?");
    $stmt->bind_param("si", $hash, $userId);

    if ($stmt->execute()) {
        echo "<div class='alert success'>
                ✅ Password reset successfully.<br>
                Temporary password: <strong>$tempPassword</strong><br>
                User will be required to set a new password and security question on next login.
              </div>";
    } else {
        echo "<div class='alert error'>❌ Failed to reset password. Try again.</div>";
    }
}
?>

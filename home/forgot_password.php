<?php
session_start();
include('../db.php');

$error_message = '';
$question      = '';
$username      = '';

// Step 1: username submitted
if (isset($_POST['submit_username'])) {
    $username = trim($_POST['username']);

    $stmt = $conn->prepare("SELECT role, security_question FROM users WHERE username = ?");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user     = $result->fetch_assoc();
            $role     = $user['role'];
            $question = $user['security_question'];

            if ($role === 'admin') {
                $error_message = 'Admins cannot reset their password here. <a href="notify_admin.php">Contact the system super admin.</a>';
                $question = '';
            } elseif (empty($question)) {
                $error_message = 'This account has no security question set. <a href="javascript:void(0)" onclick="notifyAdmin()">Notify admin.</a>';
                $question = '';
            } else {
                $_SESSION['reset_user'] = $username;
            }
        } else {
            $error_message = "No account found with that username.";
        }
        $stmt->close();
    } else {
        $error_message = "Database error (Step 1).";
    }
}

// Step 2: security answer submitted
if (isset($_POST['submit_answer'])) {
    if (!isset($_SESSION['reset_user'])) {
        $error_message = "Session expired. Please start again.";
    } else {
        $username = $_SESSION['reset_user'];
        $answer   = trim($_POST['answer']);

        $stmt = $conn->prepare("SELECT security_answer FROM users WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $user     = $result->fetch_assoc();
                $dbAnswer = $user['security_answer'];

                if (password_verify($answer, $dbAnswer) || $answer === $dbAnswer) {
                    $_SESSION['verified_user'] = $username;
                    header("Location: reset_password.php");
                    exit();
                } else {
                    $error_message = "Incorrect answer. Please try again.";
                }
            } else {
                $error_message = "User not found.";
            }
            $stmt->close();
        } else {
            $error_message = "Database error (Step 2).";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | BENE MediCon</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/home.css">
</head>
<body>

<!-- BACKGROUND GOLD ACCENTS -->
<div class="bg">
    <div class="bg-gold-bar"></div>
    <div class="bg-gold-bar-bottom"></div>
    <div class="bg-blob bg-blob-tl"></div>
    <div class="bg-blob bg-blob-br"></div>
    <div class="bg-gold-glow-tr"></div>
    <div class="bg-gold-glow-bl"></div>
    <div class="bg-ring bg-ring-1"></div>
    <div class="bg-ring bg-ring-2"></div>
    <div class="bg-ring bg-ring-3"></div>
    <div class="bg-ring bg-ring-4"></div>
    <div class="bg-ring bg-ring-5"></div>
    <div class="bg-ring bg-ring-6"></div>
</div>

<div class="fp-card">
    <div class="fp-body">

        <!-- mini logo lockup -->
        <div class="fp-logo">
            <img src="../images/bene_medicon_logo.png" alt="BENE MediCon">
            <span class="fp-logo-name">BENE <span>MediCon</span></span>
        </div>

        <?php if (empty($question) && !isset($_POST['submit_answer'])): ?>
            <!-- ── STEP 1: enter username ── -->
            <h2 class="fp-title">Forgot Password</h2>
            <p class="fp-tagline">Enter your username and we'll find your security question.</p>

            <?php if (!empty($error_message)): ?>
                <div class="error-msg"><?= $error_message ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="input-group">
                    <span class="icon"><i class="fas fa-user"></i></span>
                    <input type="text" name="username" placeholder="Enter your username" required>
                </div>
                <button type="submit" name="submit_username" class="btn-primary">Next &rarr;</button>
            </form>

        <?php else: ?>
            <!-- ── STEP 2: answer security question ── -->
            <h2 class="fp-title">Security Question</h2>
            <p class="fp-tagline">Answer correctly to proceed to password reset.</p>

            <?php if (!empty($error_message)): ?>
                <div class="error-msg"><?= $error_message ?></div>
            <?php endif; ?>

            <div class="fp-question">
                <strong>Your Question</strong>
                <?= htmlspecialchars($question) ?>
            </div>

            <form method="POST">
                <div class="input-group">
                    <span class="icon"><i class="fas fa-shield-alt"></i></span>
                    <input type="text" name="answer" placeholder="Enter your answer" required>
                </div>
                <button type="submit" name="submit_answer" class="btn-primary">Verify &rarr;</button>
            </form>

        <?php endif; ?>

        <a href="login.php" class="fp-back">
            <i class="fas fa-arrow-left"></i> Back to Login
        </a>

    </div>
</div>

<div id="toast" class="toast"></div>

<script>
function notifyAdmin() {
    fetch("notify_admin.php")
        .then(r => r.text())
        .then(data => {
            showToast(data.trim() === "ok"
                ? "Notification sent to admin."
                : "Unexpected response from server.", data.trim() === "ok" ? "success" : "error");
        })
        .catch(() => showToast("Error notifying admin.", "error"));
}

function showToast(message, type) {
    const toast = document.getElementById("toast");
    toast.textContent = message;
    toast.className = "toast " + type + " show";
    setTimeout(() => { toast.className = "toast"; }, 7000);
}
</script>

</body>
</html>
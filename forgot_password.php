<?php
session_start();
include('db.php'); // db.php must use mysqli_connect()

$error_message = '';
$question = '';
$username = '';

// Step 1: Check if username is submitted
if (isset($_POST['submit_username'])) {
    $username = trim($_POST['username']);

    $stmt = $conn->prepare("SELECT role, security_question FROM users WHERE username = ?");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $role = $user['role'];
            $question = $user['security_question'];

            if ($role === 'admin') {
                $error_message = 'Admins cannot reset their password here. <a href="notify_admin.php">Please contact the system super admin</a>.';
                $question = ''; // do not proceed
            } elseif (empty($question)) {
                $error_message = 'This account does not have a security question set. <a href="javascript:void(0)" onclick="notifyAdmin()">Please contact admin</a>.';
                $question = ''; // stay in step 1
            } else {
                // ✅ Store username for Step 2
                $_SESSION['reset_user'] = $username;
            }

        } else {
            $error_message = "User not found!";
        }

        $stmt->close();
    } else {
        $error_message = "Database error (Step 1).";
    }
}

// Step 2: Check if security answer is submitted
if (isset($_POST['submit_answer'])) {
    if (!isset($_SESSION['reset_user'])) {
        $error_message = "Session expired. Please start again.";
    } else {
        $username = $_SESSION['reset_user'];
        $answer = trim($_POST['answer']);

        $stmt = $conn->prepare("SELECT security_answer FROM users WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $dbAnswer = $user['security_answer'];

                // ✅ Check both hashed and plain-text answers
                if (password_verify($answer, $dbAnswer) || $answer === $dbAnswer) {
                    $_SESSION['verified_user'] = $username;
                    header("Location: reset_password.php");
                    exit();
                } else {
                    $error_message = "Incorrect answer!";
                }
            } else {
                $error_message = "User not found!";
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
    <title>Forgot Password | BENE MediCon</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="forgotpw-style.css">
<style>
    /* Header styling - Website Name */
    .site-name {
        font-size: 32px;            /* Larger, bold website name */
        color: #555;
        font-weight: bold;
        letter-spacing: 1px;
        margin-bottom: 15px;        /* Add spacing between site name and title */
    }

    /* Highlight Style for "MediCon" */
    .highlight {
        color: #03d32c;             /* Green color for contrast */
    }
    .back-btn {
            display: inline-block;
            padding: 8px 16px;
            color: #666;
            text-decoration: none;
            margin-top: 20px;
            transition: color 0.3s;
        }

        .back-btn:hover {
            color: #1a73e8;
        }
        .toast {
            visibility: hidden;
            min-width: 250px;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 12px;
            position: fixed;
            z-index: 9999;
            right: 20px;
            bottom: 30px;
            font-size: 14px;
            opacity: 0;
            transition: opacity 0.5s, bottom 0.5s;
        }

        /* Default position when hidden */
        .toast.show {
            visibility: visible;
            opacity: 1;
            bottom: 50px;
        }

        /* Success = green background */
        .toast.success {
            background-color: #28a745;
        }

        /* Error = red background */
        .toast.error {
            background-color: #dc3545;
        }
</style>
</head>
<body>
<div class="container">
<h1 class="site-name">BENE MediCon</span></h1>
<h2 class="highlight"> Forgot Password</span></h2>
    <?php if (!empty($error_message)): ?>
        <p class="error"><?php echo $error_message; ?></p>
    <?php endif; ?>

    <?php if (empty($question) && !isset($_POST['submit_answer'])): ?>
    <!-- Step 1: Ask for username -->
    <form method="POST">
        <label>Username</label>
        <input type="text" name="username" placeholder="Enter your username" required>
        <button type="submit" name="submit_username">Next</button>
        <a href="login.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Login
        </a>
    </form>
    <?php else: ?>
        <!-- Step 2: Ask security question -->
        <form method="POST">
            <p><strong>Security Question:</strong> <?php echo htmlspecialchars($question); ?></p>
            <input type="text" name="answer" placeholder="Enter your answer" required>
            <button type="submit" name="submit_answer">Verify</button>
            <a href="login.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </form>
    <?php endif; ?>

</div>
<script>
function notifyAdmin() {
    fetch("notify_admin.php")
        .then(response => response.text())
        .then(data => {
            if (data.trim() === "ok") {
                showToast("Notification sent to admin.", "success");
            } else {
                showToast("Unexpected response from server.", "error");
            }
        })
        .catch(err => {
            showToast("Error notifying admin.", "error");
        });
}

function showToast(message, type) {
    const toast = document.getElementById("toast");
    toast.innerText = message;

    // Reset classes
    toast.className = "toast";

    // Add type (success or error) + show
    toast.classList.add(type, "show");

    setTimeout(() => {
        toast.classList.remove("show", "success", "error");
    }, 7000); // hide after 7s
}
</script>

<div id="toast" class="toast"></div>

</body>
</html>

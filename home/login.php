<?php
session_start();
include "../db.php";
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error_message = "Please fill in all fields.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role, profile_pic, password_changed, force_password_change, force_security_setup 
                                FROM users WHERE LOWER(TRIM(username)) = ?");
        $lower_username = strtolower($username);
        $stmt->bind_param("s", $lower_username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                session_unset();
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['profile_pic'] = !empty($user['profile_pic']) ? $user['profile_pic'] : 'default.jpg';

                if (empty($user['profile_pic'])) {
                    $stmtUpdate = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                    $stmtUpdate->bind_param("si", $_SESSION['profile_pic'], $user['id']);
                    $stmtUpdate->execute();
                    $stmtUpdate->close();
                }
                $update = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $update->bind_param("i", $user['id']);
                $update->execute();
                $update->close();

                switch ($user['role']) {
                    case 'admin':
                        $redirect = '../user/admin/dashboard.php';
                        break;
                    default:
                        $redirect = '../user/staff/dashboard.php';
                        break;
                }
                header("Location: $redirect");
                exit();
            } else {
                $error_message = "Invalid username or password.";
            }
        } else {
            $error_message = "Invalid username or password.";
        }
        $stmt->close();
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | BENE MediCon</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;500;600&family=DM+Sans:wght@300;400;500;600&display=swap"
        rel="stylesheet">
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

    <div class="card">

        <!-- LEFT -->
        <div class="left">
            <div class="blob blob-1"></div>
            <div class="blob blob-2"></div>
            <div class="blob blob-3"></div>
            <div class="blob blob-4"></div>

            <!-- <a href="login.php" class="home-icon">
            <img src="images/bene_medicon_home_icon.png" alt="Home">
        </a> -->

            <div class="left-content">
                <img src="../images/bene_medicon_logo.png" alt="BENE MediCon" class="medicine-img">
                <h1 class="left-title">BENE <span>MediCon</span></h1>
                <p class="left-subtitle">San Beda College Alabang</p>
                <!-- <p class="motto">Fides · Scientia · Virtus</p> -->
                <a href="about.php" class="about-link">Learn more <span>about us →</span></a>
            </div>
        </div>

        <!-- RIGHT -->
        <div class="right">
            <h2>Sign in</h2>
            <p class="tagline">Enter your credentials to access your account.</p>

            <?php if (!empty($error_message)): ?>
                <div class="error-msg"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <div class="input-group">
                    <span class="icon">&#9998;</span>
                    <input type="text" name="username" placeholder="Username" required>
                </div>

                <div class="input-group">
                    <span class="icon">&#128274;</span>
                    <input type="password" name="password" id="pwdField" placeholder="Password" required>
                    <button type="button" class="show-btn" onclick="togglePwd()">SHOW</button>
                </div>

                <div class="row-between">
                    <!-- <label class="remember">
                    <input type="checkbox" name="remember"> Remember me
                </label> -->
                    <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit" class="btn-primary">Log In</button>

                <!-- <div class="or-divider">Or</div> -->
            </form>
        </div>

    </div>

    <script>
        function togglePwd() {
            const f = document.getElementById('pwdField');
            const b = f.nextElementSibling;
            if (f.type === 'password') { f.type = 'text'; b.textContent = 'HIDE'; }
            else { f.type = 'password'; b.textContent = 'SHOW'; }
        }
    </script>
</body>

</html>
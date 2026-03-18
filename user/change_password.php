<?php
session_start();
include '../db.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header("Location: ../home_pages/login.php");
    exit();
}

$stmt = $conn->prepare("SELECT password, force_password_change, force_security_setup, role FROM users WHERE id=?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$msg = '';
$step = isset($_SESSION['pw_step2']) ? 2 : 1;

// ── STEP 1: validate password ──
if (isset($_POST['submit_password'])) {
    $newPass = trim($_POST['new_password']);
    $confirmPass = trim($_POST['confirm_password']);

    if ($newPass !== $confirmPass) {
        $msg = 'error:Passwords do not match.';
    } elseif (strlen($newPass) < 8) {
        $msg = 'error:Password must be at least 8 characters.';
    } elseif (password_verify($newPass, $user['password'])) {
        $msg = 'error:New password cannot be the same as your current password.';
    } else {
        // store hashed password in session and move to step 2
        $_SESSION['pw_step2'] = password_hash($newPass, PASSWORD_DEFAULT);
        header("Location: change_password.php");
        exit();
    }
}

// ── STEP 2: save security question ──
if (isset($_POST['submit_security'])) {
    if (empty($_SESSION['pw_step2'])) {
        // session expired, restart
        header("Location: change_password.php");
        exit();
    }
    $hash = $_SESSION['pw_step2'];
    $question = trim($_POST['security_question']);
    $answer = password_hash(trim($_POST['security_answer']), PASSWORD_DEFAULT);

    if (empty($question) || empty(trim($_POST['security_answer']))) {
        $msg = 'error:Please fill in both the question and answer.';
        $step = 2;
    } else {
        $stmt = $conn->prepare("UPDATE users SET password=?, force_password_change=0, force_security_setup=0, security_question=?, security_answer=? WHERE id=?");
        $stmt->bind_param("sssi", $hash, $question, $answer, $userId);
        if ($stmt->execute()) {
            unset($_SESSION['pw_step2']);
            $_SESSION['success_msg'] = "Password and security question updated successfully.";
            $_SESSION['redirect_to'] = $user['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php';
            header("Location: change_password.php");
            exit();
        } else {
            $msg = 'error:Failed to save. Please try again.';
            $step = 2;
        }
    }
}

// ── BACK to step 1 ──
if (isset($_GET['back'])) {
    unset($_SESSION['pw_step2']);
    header("Location: change_password.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | BENE MediCon</title>

    <?php if (isset($_SESSION['success_msg']) && isset($_SESSION['redirect_to'])): ?>
        <meta http-equiv="refresh" content="2;url=<?= $_SESSION['redirect_to'] ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap"
        rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/c_pw.css">
</head>

<body>

    <!-- background -->
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
        <div class="card-body">

            <!-- logo lockup -->
            <div class="logo-row">
                <img src="../images/bene_medicon_logo.png" alt="BENE MediCon" onerror="this.style.display='none'">
                <span class="logo-name">BENE <span>MediCon</span></span>
            </div>

            <h1 class="card-title">
                <?= $step === 1 ? 'Change Password' : 'Security Question' ?>
            </h1>
            <p class="card-sub">
                <?= $step === 1
                    ? 'Choose a strong new password for your account.'
                    : 'Set a security question so you can recover your account.' ?>
            </p>

            <!-- step indicator -->
            <div class="step-indicator">
                <div class="step-dot <?= $step === 1 ? 'active' : 'done' ?>">
                    <?= $step === 1 ? '1' : '<i class="fas fa-check"></i>' ?>
                </div>
                <div class="step-line <?= $step === 2 ? 'done' : '' ?>"></div>
                <div class="step-dot <?= $step === 2 ? 'active' : '' ?>">2</div>
                <div style="margin-left:8px;font-size:0.72rem;color:var(--text-muted);">
                    Step <?= $step ?> of 2
                </div>
            </div>

            <?php
            if (isset($_SESSION['success_msg'])): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle" style="margin-right:6px;"></i>
                    <?= htmlspecialchars($_SESSION['success_msg']) ?>
                    <div style="font-size:0.75rem;margin-top:4px;opacity:0.8;">
                        Redirecting to dashboard in 2 seconds...
                    </div>
                </div>
                <?php unset($_SESSION['success_msg']); endif;

            $alerts = [];
            if ($user['force_password_change'] == 1)
                $alerts[] = "You are using a temporary password. Please change it now.";
            if ($user['force_security_setup'] == 1)
                $alerts[] = "Please set a security question for account recovery.";
            if (!empty($alerts)): ?>
                <div class="alert warning">
                    <strong>&#9888; Action required:</strong>
                    <ul><?php foreach ($alerts as $a)
                        echo "<li>$a</li>"; ?></ul>
                </div>
            <?php endif;

            if ($msg):
                [$type, $text] = explode(':', $msg, 2); ?>
                <div class="alert <?= $type ?>">
                    <i class="fas fa-exclamation-circle" style="margin-right:6px;"></i>
                    <?= htmlspecialchars($text) ?>
                </div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
                <!-- ── STEP 1: Password ── -->
                <form method="POST">
                    <div class="form-group">
                        <label for="new_password"><i class="fas fa-lock"></i>New Password</label>
                        <div class="input-wrap">
                            <input type="password" name="new_password" id="new_password" placeholder="Enter new password"
                                required>
                            <i class="fas fa-eye toggle-password" onclick="togglePassword('new_password', this)"></i>
                        </div>
                        <div class="strength-bar">
                            <div class="strength-fill" id="strengthFill"></div>
                        </div>
                        <div id="strengthMessage"></div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password"><i class="fas fa-lock"></i>Confirm Password</label>
                        <div class="input-wrap">
                            <input type="password" name="confirm_password" id="confirm_password"
                                placeholder="Confirm new password" required>
                            <i class="fas fa-eye toggle-password" onclick="togglePassword('confirm_password', this)"></i>
                        </div>
                        <div id="matchMessage"></div>
                    </div>

                    <button type="submit" name="submit_password" class="btn-submit">
                        Next &rarr;
                    </button>
                </form>

            <?php else: ?>
                <!-- ── STEP 2: Security Question ── -->
                <form method="POST">
                    <div class="form-group">
                        <label for="security_question"><i class="fas fa-question-circle"></i>Security Question</label>
                        <div class="input-wrap">
                            <select name="security_question" id="security_question" required>
                                <option value="">— Select a question —</option>
                                <option value="What is your favorite color?">What is your favorite color?</option>
                                <option value="What city were you born in?">What city were you born in?</option>
                                <option value="What is your pet's name?">What is your pet's name?</option>
                                <option value="What is your mother's maiden name?">What is your mother's maiden name?
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="security_answer"><i class="fas fa-pen"></i>Answer</label>
                        <div class="input-wrap">
                            <input type="password" name="security_answer" id="security_answer"
                                placeholder="Enter your answer" required>
                            <i class="fas fa-eye toggle-password" onclick="togglePassword('security_answer', this)"></i>
                        </div>
                    </div>

                    <button type="submit" name="submit_security" class="btn-submit">
                        <i class="fas fa-save" style="margin-right:7px;"></i>Save &amp; Finish
                    </button>
                </form>

                <a href="change_password.php?back=1" class="back-link" style="margin-top:0.8rem;">
                    <i class="fas fa-arrow-left"></i> Back to password step
                </a>
            <?php endif; ?>

            <a href="<?= $user['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php' ?>"
                class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>

        </div>
    </div>

    <script>
        function togglePassword(fieldId, icon) {
            const f = document.getElementById(fieldId);
            const isHidden = f.type === 'password';
            f.type = isHidden ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !isHidden);
            icon.classList.toggle('fa-eye-slash', isHidden);
        }

        // strength bar (step 1 only)
        const pwInput = document.getElementById('new_password');
        const fillBar = document.getElementById('strengthFill');
        const fillMsg = document.getElementById('strengthMessage');
        const confInput = document.getElementById('confirm_password');
        const matchMsg = document.getElementById('matchMessage');

        if (pwInput) {
            pwInput.addEventListener('input', () => {
                const v = pwInput.value;
                if (!v) {
                    fillBar.style.width = '0';
                    fillMsg.textContent = '';
                    fillMsg.className = '';
                    return;
                }
                const strong = /[A-Z]/.test(v) && /[a-z]/.test(v) && /[0-9]/.test(v) && /[\W]/.test(v);
                if (v.length < 8) {
                    fillBar.style.width = '30%';
                    fillBar.style.background = '#c62828';
                    fillMsg.textContent = 'Weak — too short';
                    fillMsg.className = 'weak';
                } else if (strong) {
                    fillBar.style.width = '100%';
                    fillBar.style.background = '#059669';
                    fillMsg.textContent = 'Strong';
                    fillMsg.className = 'strong';
                } else {
                    fillBar.style.width = '65%';
                    fillBar.style.background = '#d97706';
                    fillMsg.textContent = 'Medium — add symbols or mixed case';
                    fillMsg.className = 'medium';
                }
                checkMatch();
            });
        }

        if (confInput) {
            confInput.addEventListener('input', checkMatch);
        }

        function checkMatch() {
            if (!matchMsg || !confInput || !pwInput) return;
            const v = confInput.value;
            if (!v) { matchMsg.textContent = ''; matchMsg.className = ''; return; }
            if (v === pwInput.value) {
                matchMsg.textContent = '✓ Passwords match';
                matchMsg.className = 'match';
            } else {
                matchMsg.textContent = '✗ Passwords do not match';
                matchMsg.className = 'no-match';
            }
        }
    </script>
</body>

</html>
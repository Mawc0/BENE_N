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

$msg  = '';
$step = isset($_SESSION['pw_step2']) ? 2 : 1;

// ── STEP 1: validate password ──
if (isset($_POST['submit_password'])) {
    $newPass     = trim($_POST['new_password']);
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
    $hash     = $_SESSION['pw_step2'];
    $question = trim($_POST['security_question']);
    $answer   = password_hash(trim($_POST['security_answer']), PASSWORD_DEFAULT);

    if (empty($question) || empty(trim($_POST['security_answer']))) {
        $msg  = 'error:Please fill in both the question and answer.';
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
            $msg  = 'error:Failed to save. Please try again.';
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
    <link href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --red:        #9b1c1c;
            --red-dark:   #7b1010;
            --red-deeper: #5c0a0a;
            --red-light:  #c62828;
            --gold:       #c9a84c;
            --gold-light: #e8c96a;
        }

        html, body { height: 100%; min-height: 100vh; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--red-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2rem;
        }

        /* ── background layer ── */
        .bg {
            position: fixed; inset: 0;
            pointer-events: none; z-index: 0; overflow: hidden;
        }
        .bg-gold-bar {
            position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, transparent 0%, var(--gold) 30%, var(--gold-light) 50%, var(--gold) 70%, transparent 100%);
        }
        .bg-gold-bar-bottom {
            position: absolute; bottom: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent 0%, rgba(201,168,76,0.4) 40%, rgba(232,201,106,0.5) 50%, rgba(201,168,76,0.4) 60%, transparent 100%);
        }
        .bg-blob { position: absolute; border-radius: 50%; }
        .bg-blob-tl { width: 500px; height: 500px; top: -180px; left: -120px; background: radial-gradient(circle, rgba(197,40,40,0.45) 0%, transparent 70%); }
        .bg-blob-br { width: 400px; height: 400px; bottom: -140px; right: -100px; background: radial-gradient(circle, rgba(92,10,10,0.55) 0%, transparent 70%); }
        .bg-gold-glow-tr { position: absolute; width: 340px; height: 340px; top: -100px; right: -80px; background: radial-gradient(circle, rgba(201,168,76,0.18) 0%, transparent 65%); }
        .bg-gold-glow-bl { position: absolute; width: 280px; height: 280px; bottom: -80px; left: -60px; background: radial-gradient(circle, rgba(201,168,76,0.14) 0%, transparent 65%); }
        .bg-ring { position: absolute; border-radius: 50%; border: 1px solid rgba(201,168,76,0.2); }
        .bg-ring-1 { width: 420px; height: 420px; top: -160px; left: -140px; }
        .bg-ring-2 { width: 260px; height: 260px; top: -60px;  left: -40px;  border-color: rgba(201,168,76,0.12); }
        .bg-ring-3 { width: 380px; height: 380px; bottom: -150px; right: -120px; }
        .bg-ring-4 { width: 200px; height: 200px; bottom: -50px;  right: -40px;  border-color: rgba(201,168,76,0.12); }
        .bg-ring-5 { width: 120px; height: 120px; top: 50%; right: 30px; transform: translateY(-50%); border-color: rgba(201,168,76,0.1); }
        .bg-ring-6 { width: 80px;  height: 80px;  top: 20%; left: 40px; border-color: rgba(201,168,76,0.09); }

        /* ── card ── */
        .card {
            width: 100%; max-width: 480px;
            background: #fff;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 24px 80px rgba(0,0,0,0.35);
            position: relative; z-index: 1;
        }
        .card::before {
            content: ''; display: block; height: 4px;
            background: linear-gradient(90deg, var(--red-light), var(--red-deeper));
        }

        .card-body { padding: 2.2rem 2.4rem 2.6rem; }

        /* logo lockup */
        .logo-row {
            display: flex; align-items: center; gap: 0.65rem;
            margin-bottom: 1.8rem;
        }
        .logo-row img {
            width: 38px; height: 38px; border-radius: 50%; object-fit: cover;
            border: 2px solid rgba(201,168,76,0.5);
        }
        .logo-name {
            font-family: 'EB Garamond', serif;
            font-size: 1.15rem; font-weight: 600; color: var(--red-deeper);
        }
        .logo-name span { color: var(--red-light); }

        .card-title {
            font-family: 'EB Garamond', serif;
            font-size: 1.8rem; font-weight: 600;
            color: #1a1a2e; margin-bottom: 0.25rem;
        }
        .card-sub {
            font-size: 0.82rem; color: #9a8a85; margin-bottom: 1.8rem;
        }

        /* alerts */
        .alert {
            padding: 0.7rem 1rem; border-radius: 8px;
            font-size: 0.82rem; margin-bottom: 1.2rem; border-left: 3px solid;
        }
        .alert.success { background: #f0fdf4; border-color: #10b981; color: #065f46; }
        .alert.error   { background: #fef2f2; border-color: var(--red-light); color: var(--red-deeper); }
        .alert.warning { background: #fffbeb; border-color: #f59e0b; color: #92400e; }
        .alert strong { display: block; margin-bottom: 4px; }
        .alert ul { margin: 4px 0 0 1rem; }
        .alert li { margin-bottom: 3px; }

        /* form */
        .form-group { margin-bottom: 1.1rem; }

        .form-group label {
            display: block;
            font-size: 0.68rem; font-weight: 600;
            letter-spacing: 0.1em; text-transform: uppercase;
            color: #7a8da0; margin-bottom: 0.38rem;
        }
        .form-group label i { margin-right: 5px; color: var(--red-light); font-size: 0.72rem; }

        .input-wrap { position: relative; }

        .form-group input,
        .form-group select {
            width: 100%; height: 46px;
            padding: 0 2.8rem 0 1rem;
            background: #f8fafc;
            border: 1.5px solid #dce3ec;
            border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.88rem; color: #1a1a2e;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            appearance: none;
        }
        .form-group select { padding-right: 2.2rem; cursor: pointer; }
        .form-group input::placeholder { color: #b5c1ce; }
        .form-group input:focus,
        .form-group select:focus {
            background: #fff;
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(155,28,28,0.1);
        }

        .toggle-password {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            cursor: pointer; color: #b0bac8; font-size: 0.9rem;
            transition: color 0.2s;
        }
        .toggle-password:hover { color: var(--red-dark); }

        /* strength bar */
        .strength-bar {
            height: 3px; border-radius: 2px;
            margin-top: 6px; background: #e5e7eb;
            overflow: hidden;
        }
        .strength-fill {
            height: 100%; width: 0;
            border-radius: 2px;
            transition: width 0.3s, background 0.3s;
        }
        #strengthMessage {
            font-size: 0.72rem; margin-top: 4px;
            font-weight: 500;
        }
        #strengthMessage.weak   { color: var(--red-light); }
        #strengthMessage.medium { color: #d97706; }
        #strengthMessage.strong { color: #059669; }

        /* step indicator */
        .step-indicator {
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 1.6rem;
        }
        .step-dot {
            width: 26px; height: 26px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 700; flex-shrink: 0;
            border: 2px solid #dce3ec;
            color: #b0bac8; background: #f8fafc;
            transition: all 0.3s;
        }
        .step-dot.active {
            background: var(--red-dark); border-color: var(--red-dark);
            color: #fff;
        }
        .step-dot.done {
            background: #059669; border-color: #059669; color: #fff;
        }
        .step-line {
            flex: 1; height: 2px; background: #dce3ec;
            border-radius: 2px; transition: background 0.3s;
        }
        .step-line.done { background: #059669; }

        /* match message */
        #matchMessage {
            font-size: 0.72rem; margin-top: 4px; font-weight: 500;
        }
        #matchMessage.match    { color: #059669; }
        #matchMessage.no-match { color: var(--red-light); }

        /* submit button */
        .btn-submit {
            width: 100%; height: 48px;
            background: linear-gradient(135deg, var(--red-light) 0%, var(--red-deeper) 100%);
            color: #fff; border: none; border-radius: 10px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.92rem; font-weight: 600;
            cursor: pointer; letter-spacing: 0.02em;
            transition: opacity 0.2s, transform 0.1s;
            box-shadow: 0 4px 16px rgba(155,28,28,0.35);
            margin-top: 0.4rem;
        }
        .btn-submit:hover { opacity: 0.9; }
        .btn-submit:active { transform: scale(0.99); }

        /* back link */
        .back-link {
            display: inline-flex; align-items: center; gap: 0.4rem;
            margin-top: 1rem; font-size: 0.8rem;
            color: #9a8a85; text-decoration: none;
            transition: color 0.2s;
        }
        .back-link:hover { color: var(--red-dark); }

        @media (max-width: 520px) {
            body { padding: 1rem; }
            .card-body { padding: 1.8rem 1.4rem 2rem; }
        }
    </style>
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
            <img src="../images/bene_medicon_logo.png" alt="BENE MediCon"
                 onerror="this.style.display='none'">
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
                <ul><?php foreach ($alerts as $a) echo "<li>$a</li>"; ?></ul>
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
                    <input type="password" name="new_password" id="new_password"
                           placeholder="Enter new password" required>
                    <i class="fas fa-eye toggle-password"
                       onclick="togglePassword('new_password', this)"></i>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                <div id="strengthMessage"></div>
            </div>

            <div class="form-group">
                <label for="confirm_password"><i class="fas fa-lock"></i>Confirm Password</label>
                <div class="input-wrap">
                    <input type="password" name="confirm_password" id="confirm_password"
                           placeholder="Confirm new password" required>
                    <i class="fas fa-eye toggle-password"
                       onclick="togglePassword('confirm_password', this)"></i>
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
                        <option value="What is your mother's maiden name?">What is your mother's maiden name?</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="security_answer"><i class="fas fa-pen"></i>Answer</label>
                <div class="input-wrap">
                    <input type="password" name="security_answer" id="security_answer"
                           placeholder="Enter your answer" required>
                    <i class="fas fa-eye toggle-password"
                       onclick="togglePassword('security_answer', this)"></i>
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
const pwInput  = document.getElementById('new_password');
const fillBar  = document.getElementById('strengthFill');
const fillMsg  = document.getElementById('strengthMessage');
const confInput = document.getElementById('confirm_password');
const matchMsg  = document.getElementById('matchMessage');

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
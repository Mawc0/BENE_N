<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include "../db.php";

$userId = $_SESSION['user_id'];
$message = '';

$stmt = $conn->prepare("SELECT username, role, profile_pic FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $current_password = $_POST['current_password'];

    $stmt = $conn->prepare("SELECT password, profile_pic FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();

    if (password_verify($current_password, $user_data['password'])) {
        $profilePic = $user_data['profile_pic'];

        if (!empty($_POST['selected_avatar'])) {
            $profilePic = $_POST['selected_avatar'];
        }

        if (!empty($_FILES['profile_pic']['name'])) {
            $targetDir = "../uploads/avatars/";
            if (!is_dir($targetDir))
                mkdir($targetDir, 0777, true);
            $newFileName = time() . "_" . basename($_FILES['profile_pic']['name']);
            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $targetDir . $newFileName)) {
                $profilePic = $newFileName;
                $_SESSION['profile_pic'] = $profilePic;
            }
        }

        $stmt = $conn->prepare("UPDATE users SET username = ?, profile_pic = ? WHERE id = ?");
        $stmt->bind_param("ssi", $username, $profilePic, $userId);
        if ($stmt->execute()) {
            $_SESSION['username'] = $username;
            $_SESSION['profile_pic'] = $profilePic;
            $message = 'success:Profile updated successfully!';
            $user['username'] = $username;
            $user['profile_pic'] = $profilePic;
        } else {
            $message = 'error:Error updating profile. Please try again.';
        }
    } else {
        $message = 'error:Current password is incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile | BENE MediCon</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap"
        rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../styles/edit_p.css">

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

            <h1 class="card-title">Edit Profile</h1>
            <p class="card-sub">Update your username, avatar, or profile picture.</p>

            <?php if ($message):
                [$type, $text] = explode(':', $message, 2); ?>
                <div class="alert <?= $type ?>">
                    <i class="fas fa-<?= $type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"
                        style="margin-right:6px;"></i>
                    <?= htmlspecialchars($text) ?>
                </div>
            <?php endif; ?>

            <!-- current avatar preview -->
            <div class="avatar-preview-wrap">
                <img id="currentPreview"
                    src="../uploads/avatars/<?= htmlspecialchars($user['profile_pic'] ?? 'default.jpg') ?>"
                    alt="Profile Picture" class="avatar-preview" onerror="this.src='../uploads/avatars/default.jpg'">
                <div class="avatar-name"><?= htmlspecialchars($user['username']) ?></div>
                <div class="avatar-role"><?= ucfirst($user['role']) ?></div>
            </div>

            <form method="POST" action="" enctype="multipart/form-data">

                <!-- username -->
                <div class="form-group">
                    <label for="username"><i class="fas fa-id-card"></i>Username</label>
                    <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>"
                        required>
                </div>

                <hr class="form-divider">

                <!-- avatar gallery -->
                <span class="section-label"><i class="fas fa-image"></i>Choose an Avatar</span>
                <div class="avatar-gallery">
                    <?php
                    $avatars = ['avatar1.jpg', 'avatar2.jpg', 'avatar3.jpg', 'avatar4.jpg'];
                    foreach ($avatars as $avatar): ?>
                        <label class="avatar-option" title="<?= $avatar ?>">
                            <input type="radio" name="selected_avatar" value="<?= $avatar ?>"
                                <?= ($user['profile_pic'] === $avatar) ? 'checked' : '' ?>>
                            <img src="../uploads/avatars/<?= $avatar ?>" alt="<?= $avatar ?>">
                        </label>
                    <?php endforeach; ?>
                </div>

                <!-- file upload -->
                <div class="or-divider">or upload your own</div>
                <div class="file-upload-wrap">
                    <i class="fas fa-upload"></i>
                    <input type="file" name="profile_pic" accept="image/*">
                </div>

                <hr class="form-divider">

                <!-- current password -->
                <div class="form-group">
                    <label for="current_password">
                        <i class="fas fa-lock"></i>Current Password
                        <span style="text-transform:none;font-weight:400;color:#b5c1ce;margin-left:4px;">(required to
                            save)</span>
                    </label>
                    <input type="password" id="current_password" name="current_password"
                        placeholder="Enter your current password" required>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-save" style="margin-right:7px;"></i>Save Changes
                </button>
            </form>

            <a href="<?= $user['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php' ?>"
                class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>

        </div>
    </div>

    <script>
        // file upload → update preview
        document.querySelector('input[name="profile_pic"]').addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = ev => {
                document.getElementById('currentPreview').src = ev.target.result;
            };
            reader.readAsDataURL(file);
            // uncheck avatar gallery
            document.querySelectorAll('input[name="selected_avatar"]').forEach(r => r.checked = false);
        });

        // avatar gallery → update preview
        document.querySelectorAll('input[name="selected_avatar"]').forEach(radio => {
            radio.addEventListener('change', function () {
                if (this.checked) {
                    document.getElementById('currentPreview').src = '../uploads/avatars/' + this.value;
                    document.querySelector('input[name="profile_pic"]').value = '';
                }
            });
        });
    </script>

</body>

</html>
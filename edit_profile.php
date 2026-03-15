<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Database connection
include "db.php";

$userId = $_SESSION['user_id'];
$message = '';

// Fetch current user data
$stmt = $conn->prepare("SELECT username, role, profile_pic FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $current_password = $_POST['current_password'];

    // Verify current password
    $stmt = $conn->prepare("SELECT password, profile_pic FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_data = $result->fetch_assoc();

    if (password_verify($current_password, $user_data['password'])) {
        // Keep the old profile picture by default
        $profilePic = $user_data['profile_pic'];
    
        // Case 1: User chose an avatar from gallery
        if (!empty($_POST['selected_avatar'])) {
            $profilePic = $_POST['selected_avatar'];
        }
    
        // Case 2: User uploaded a new file
        if (!empty($_FILES['profile_pic']['name'])) {
            $targetDir = "uploads/avatars/";
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }

            $newFileName = time() . "_" . basename($_FILES['profile_pic']['name']);
            $targetFile = $targetDir . $newFileName;

            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $targetFile)) {
                // ✅ File upload successful, update profile picture variable
                $profilePic = $newFileName;

                // ✅ Also update session immediately so the new pic shows instantly
                $_SESSION['profile_pic'] = $profilePic;
            }
        }

        // Update profile with new username and profile picture
        $stmt = $conn->prepare("UPDATE users SET username = ?, profile_pic = ? WHERE id = ?");
        $stmt->bind_param("ssi", $username, $profilePic, $userId);
    
        if ($stmt->execute()) {
            $_SESSION['username'] = $username;
            $_SESSION['profile_pic'] = $profilePic;
            $message = '<div class="alert success">Profile updated successfully!</div>';
            $user['username'] = $username;
            $user['profile_pic'] = $profilePic;
        } else {
            $message = '<div class="alert error">Error updating profile. Please try again.</div>';
        }    
    } else {
        $message = '<div class="alert error">Current password is incorrect.</div>';
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile | BENE MediCon</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', 'Roboto', sans-serif;
        }

        body {
            background-color: #d8f5c8;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color:  #03d32c;             /* Green color for contrast */
            font-size: 24px;
            margin-bottom: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            border-color: #1a73e8;
            outline: none;
        }
        .avatar-option img {
            border-radius: 50%;
            border: 2px solid #ccc;
            transition: border-color 0.3s, transform 0.2s;
            cursor: pointer;
        }

        .avatar-option input[type="radio"]:checked + img {
            border-color: #03d32c; /* green border if selected */
            transform: scale(1.1); /* slight zoom */
        }
        .btn {
            background: #f4e35f; /* Yellow button */
            color: 333;
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            width: 100%;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #f0d400; /* Darker yellow on hover */
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

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .icon, .header h1 i {
            color: #000;  /* black */
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-edit"></i> Edit Profile</h1>
            <p>Update your profile information</p>
        </div>

        <?php echo $message; ?>

        <form method="POST" action="" enctype="multipart/form-data">
            <div class="form-group">
                <label for="username">
                    <i class="fas fa-id-card icon"></i> Username
                </label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
            </div>

            <div class="form-group" style="text-align:center;">
                <label>
                    <i class="fas fa-image icon"></i> Profile Picture
                </label><br>

                <!-- Current profile picture -->
                <img id="currentPreview" 
                    src="uploads/avatars/<?php echo htmlspecialchars($user['profile_pic']); ?>" 
                    alt="Profile Picture" width="100" height="100" 
                    style="border-radius:50%; margin-bottom:10px; border:2px solid #ccc;">

                <!-- Avatar Gallery -->
                <p>Choose an avatar:</p>
                <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-bottom:10px;">
                    <?php 
                    $avatars = ['avatar1.jpg','avatar2.jpg','avatar3.jpg','avatar4.jpg']; 
                    foreach ($avatars as $avatar): ?>
                        <label class="avatar-option">
                            <input type="radio" name="selected_avatar" value="<?php echo $avatar; ?>" style="display:none;"
                                <?php echo ($user['profile_pic'] === $avatar) ? 'checked' : ''; ?>>
                            <img src="uploads/avatars/<?php echo $avatar; ?>" width="60" height="60">
                        </label>
                    <?php endforeach; ?>
                </div>
                <!-- Upload option -->
                <p>Or upload your own:</p>
                <input type="file" name="profile_pic" accept="image/*">
            </div>

            <div class="form-group">
                <label for="current_password">
                    <i class="fas fa-lock icon"></i> Current Password (required to save changes)
                </label>
                <input type="password" id="current_password" name="current_password" required>
            </div>

            <button type="submit" class="btn">
                <i class="fas fa-save icon"></i> Save Changes
            </button>
        </form>

        <a href="<?php echo $user['role'] === 'admin' ? 'admin_dashboard.php' : 'staff_dashboard.php'; ?>" class="back-btn">
            <i class="fas fa-arrow-left icon"></i> Back to Dashboard
        </a>
    </div>
    <script>
document.querySelector('input[name="profile_pic"]').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(ev) {
            // Replace the preview image
            const img = document.querySelector('#currentPreview');
            img.src = ev.target.result;
        }
        reader.readAsDataURL(file);

        // Uncheck avatars if they had one selected
        document.querySelectorAll('input[name="selected_avatar"]').forEach(r => r.checked = false);
    }
});

// For avatar clicks → update current preview
document.querySelectorAll('input[name="selected_avatar"]').forEach(radio => {
    radio.addEventListener('change', function() {
        if (this.checked) {
            const img = document.querySelector('#currentPreview');
            img.src = 'uploads/avatars/' + this.value;

            // Clear file input
            document.querySelector('input[name="profile_pic"]').value = '';
        }
    });
});
</script>

</body>
</html>
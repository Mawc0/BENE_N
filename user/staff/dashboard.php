<?php
session_start();
date_default_timezone_set('Asia/Manila');
// Database connection
include "../../db.php";

$userId = $_SESSION['user_id'] ?? null;
if ($userId) {
    $stmt = $conn->prepare("SELECT username, role, profile_pic, force_password_change, force_security_setup FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $_SESSION['username'] = $row['username'];
        $_SESSION['role'] = $row['role'];
        $_SESSION['profile_pic'] = !empty($row['profile_pic']) ? $row['profile_pic'] : 'default.jpg';
        $forcePasswordChange = ($row['force_password_change'] == 1);
        $forceSecuritySetup = ($row['force_security_setup'] == 1);
    }
    $stmt->close();
}
$forcePasswordChange = false;
$forceSecuritySetup = false;
if ($userId) {
    $stmt = $conn->prepare("SELECT force_password_change, force_security_setup FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $forcePasswordChange = ($row['force_password_change'] == 1);
        $forceSecuritySetup = ($row['force_security_setup'] == 1);
    }
    $stmt->close();
}

$isGuest = ($_SESSION['role'] ?? '') === 'guest';


// =============== 🎁 DONATION REQUEST HANDLER ===============
if (isset($_GET['donate']) && $userId) {
    if ($isGuest) {
        $_SESSION['toast'] = ['message' => "⚠️ Guests cannot submit donation requests.", 'type' => 'error'];
        header("Location: staff_dashboard.php?page=donate");
        exit();
    }

    $medicineId = (int)$_GET['donate'];
    $today = date('Y-m-d');
    $tenMonths = date('Y-m-d', strtotime('+10 months'));
    $twelveMonths = date('Y-m-d', strtotime('+12 months'));

    // Only allow donation if expiry is >10 months AND ≤12 months from today
    $medCheck = $conn->prepare("
        SELECT name, expired_date
        FROM medicines
        WHERE id = ?
          AND expired_date > ?
          AND expired_date <= ?
    ");
    $medCheck->bind_param("iss", $medicineId, $tenMonths, $twelveMonths);
    $medCheck->execute();
    $med = $medCheck->get_result()->fetch_assoc();

    if ($med) {
        // Check if already requested
        $existing = $conn->prepare("SELECT id FROM donation_requests WHERE medicine_id = ? AND staff_id = ? AND status = 'pending'");
        $existing->bind_param("ii", $medicineId, $userId);
        $existing->execute();
        if ($existing->get_result()->num_rows > 0) {
            $_SESSION['toast'] = ['message' => "⚠️ You already have a pending request for {$med['name']}.", 'type' => 'warning'];
        } else {
            $stmt = $conn->prepare("INSERT INTO donation_requests (medicine_id, staff_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $medicineId, $userId);
            if ($stmt->execute()) {
                // Notify all admins
                $notify = $conn->prepare("
                    INSERT INTO notifications (user_id, message, is_read, created_at)
                    SELECT id, CONCAT(?, ' requested donation for medicine \"', ?, '\".'), 0, NOW()
                    FROM users WHERE role = 'admin'
                ");
                $notify->bind_param("ss", $_SESSION['username'], $med['name']);
                $notify->execute();
                $_SESSION['toast'] = ['message' => "✅ Donation request sent for {$med['name']}!", 'type' => 'success'];
            } else {
                $_SESSION['toast'] = ['message' => "❌ Failed to send request.", 'type' => 'error'];
            }
        }
    } else {
        $_SESSION['toast'] = ['message' => "⚠️ Only medicines expiring in 10–12 months are eligible for donation.", 'type' => 'warning'];
    }
    header("Location: staff_dashboard.php?page=donate");
    exit();
}
// ==========================================================

// =============== 🗑️ DISPOSAL REQUEST HANDLER ===============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_disposal']) && $userId) {
    if ($isGuest) {
        $_SESSION['toast'] = ['message' => "⚠️ Guests cannot dispose of items.", 'type' => 'error'];
        header("Location: staff_dashboard.php?page=donate");
        exit();
    }

    $medicineId = (int)$_POST['medicine_id'];
    $disposalMethod = trim($_POST['disposal_method']);
    
    if (empty($disposalMethod)) {
        $_SESSION['toast'] = ['message' => "⚠️ Please specify how you will dispose of the item.", 'type' => 'warning'];
        header("Location: staff_dashboard.php?page=donate");
        exit();
    }

    // Fetch medicine to confirm it's eligible (≤10 months, not expired)
    $today = date('Y-m-d');
    $tenMonths = date('Y-m-d', strtotime('+10 months'));
    $medCheck = $conn->prepare("
        SELECT id, name FROM medicines
        WHERE id = ?
          AND expired_date > ?
          AND expired_date <= ?
          AND status != 'disposed'
    ");
    $medCheck->bind_param("iss", $medicineId, $today, $tenMonths);
    $medCheck->execute();
    $med = $medCheck->get_result()->fetch_assoc();

    if (!$med) {
        $_SESSION['toast'] = ['message' => "⚠️ This item is not eligible for disposal.", 'type' => 'warning'];
        header("Location: staff_dashboard.php?page=donate");
        exit();
    }

    // Start transaction
    $conn->autocommit(FALSE);

    try {
        // 1. Insert into disposal_requests
        $stmt = $conn->prepare("INSERT INTO disposal_requests (medicine_id, staff_id, disposal_method, disposed_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $medicineId, $userId, $disposalMethod);
        $stmt->execute();

        // 2. Mark medicine as disposed
        $update = $conn->prepare("UPDATE medicines SET status = 'disposed', last_updated = NOW() WHERE id = ?");
        $update->bind_param("i", $medicineId);
        $update->execute();

        // 3. 🔔 NOTIFY ALL ADMINS
        $notify = $conn->prepare("
            INSERT INTO notifications (user_id, message, is_read, created_at)
            SELECT id, CONCAT(?, ' has disposed of medicine \"', ?, '\".'), 0, NOW()
            FROM users WHERE role = 'admin'
        ");
        $notify->bind_param("ss", $_SESSION['username'], $med['name']);
        $notify->execute();

        // 4. 📝 LOG THE ACTION FOR AUDIT
        $logMsg = "Staff {$_SESSION['username']} disposed of \"{$med['name']}\" via: " . substr($disposalMethod, 0, 100);
        $logStmt = $conn->prepare("INSERT INTO logs (user, action) VALUES ('admin', ?)");
        $logStmt->bind_param("s", $logMsg);
        $logStmt->execute();
        $logStmt->close();

        $conn->commit();
        $_SESSION['toast'] = ['message' => "✅ Disposal recorded for {$med['name']}!", 'type' => 'success'];
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['toast'] = ['message' => "❌ Failed to process disposal.", 'type' => 'error'];
    }

    $conn->autocommit(TRUE);
    header("Location: staff_dashboard.php?page=donate");
    exit();
}
// ==========================================================

// Fetch categories from database
$categoryResult = $conn->query("SELECT name FROM categories ORDER BY id");
$categories = [];
if ($categoryResult && $categoryResult->num_rows > 0) {
    while ($row = $categoryResult->fetch_assoc()) {
        $categories[] = $row['name'];
    }
} else {
    // Fallback if table is empty
    $categories = ['Antibiotic', 'Pain Reliever', 'Vitamins', 'Antiseptic', 'Injection', 'Other'];
}

// Count expiring medicines (within 1 day)
$expiring_counts = [];
foreach ($categories as $cat) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM medicines WHERE type = ? AND expired_date <= CURDATE() + INTERVAL 1 DAY AND expired_date >= CURDATE()");
    $stmt->bind_param("s", $cat);
    $stmt->execute();
    $result_count = $stmt->get_result()->fetch_assoc();
    $expiring_counts[$cat] = (int)$result_count['count'];
    $stmt->close();
}
if (isset($_SESSION['toast'])) {
    $msg = addslashes($_SESSION['toast']['message']);
    $type = $_SESSION['toast']['type'];
    echo "<script>document.addEventListener('DOMContentLoaded', () => showToast('$msg', '$type'));</script>";
    unset($_SESSION['toast']);
}
// Add Medicine
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_medicine'])) {
    if ($isGuest) {
        $_SESSION['toast'] = ['message' => "⚠️ Guests cannot add medicines.", 'type' => 'error'];
        header("Location: staff_dashboard.php");
        exit();
    }

    $name = $_POST['name'];
    $type = trim($_POST['type']);
    $batch_date = $_POST['batch_date'];
    $expired_date = $_POST['expired_date'];
    $target_dir = "uploads/medicines/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['toast'] = ['message' => "Upload failed with error code " . $_FILES['image']['error'], 'type' => 'error'];
        header("Location: staff_dashboard.php");
        exit();
    }
    $image = time() . "_" . basename($_FILES['image']['name']);
    $target_file = $target_dir . $image;
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 100;
    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
        $sql = "INSERT INTO medicines (image, name, type, batch_date, expired_date, quantity, last_updated)
        VALUES ('$image', '$name', '$type', '$batch_date', '$expired_date', $quantity, NOW())";
        if ($conn->query($sql) === TRUE) {
            $_SESSION['toast'] = ['message' => "Medicine added successfully!", 'type' => 'success'];
        } else {
        $_SESSION['toast'] = ['message' => "Error: " . $conn->error, 'type' => 'error'];
    }
} else {
$_SESSION['toast'] = ['message' => "Failed to upload image.", 'type' => 'error'];
}
header("Location: staff_dashboard.php");
exit();
}
// Update Medicine
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_medicine'])) {
    if ($isGuest) {
        $_SESSION['toast'] = ['message' => "⚠️ Guests cannot edit medicines.", 'type' => 'error'];
        header("Location: staff_dashboard.php");
        exit();
    }

    $id = $_POST['id'];
    $name = $_POST['name'];
    $type = $_POST['type'];
    $batch_date = $_POST['batch_date'];
    $expired_date = $_POST['expired_date'];
    $image_query = "";
    $quantity = (int)$_POST['quantity'];
    if (!empty($_FILES['image']['name'])) {
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['toast'] = ['message' => "Upload failed with error code " . $_FILES['image']['error'], 'type' => 'error'];
            header("Location: staff_dashboard.php");
            exit();
        }
        $target_dir = "uploads/medicines/";
        $image = time() . "_" . basename($_FILES['image']['name']);
        $target_file = $target_dir . $image;
        if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
            $image_query = ", image='$image'";
        }
    }
    $sql = "UPDATE medicines
    SET name='$name', type='$type', batch_date='$batch_date', expired_date='$expired_date',
    quantity=$quantity$image_query, last_updated = NOW()
    WHERE id=$id";
    if ($conn->query($sql) === TRUE) {
        $_SESSION['toast'] = ['message' => "Medicine updated successfully!", 'type' => 'success'];
    } else {
    $_SESSION['toast'] = ['message' => "Failed to update: " . $conn->error, 'type' => 'error'];
}
header("Location: staff_dashboard.php");
exit();
}
// Adjust Stock (Add or Use)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['adjust_stock'])) {
    if ($isGuest) {
        $_SESSION['toast'] = ['message' => "⚠️ Guests cannot adjust stock.", 'type' => 'error'];
        header("Location: staff_dashboard.php");
        exit();
    }

    $id = (int)$_POST['id'];
    $change = (int)$_POST['change'];
    $action = $_POST['action'];
    $result = $conn->query("SELECT name, quantity FROM medicines WHERE id = $id");
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $old_quantity = $row['quantity'];
        if ($action === 'use' && $change > $old_quantity) {
            $_SESSION['toast'] = ['message' => "❌ Cannot use $change units. Only {$old_quantity} available.", 'type' => 'error'];
            header("Location: staff_dashboard.php");
            exit();
        }
        $new_quantity = ($action === 'use') ? $old_quantity - $change : $old_quantity + $change;
        $verb = ($action === 'use') ? 'used' : 'added';
        $conn->query("UPDATE medicines SET quantity = $new_quantity, last_updated = NOW() WHERE id = $id");
        $_SESSION['toast'] = ['message' => "✅ $change unit(s) $verb from {$row['name']}. Stock: $old_quantity → $new_quantity", 'type' => 'success'];
        header("Location: staff_dashboard.php");
        exit();
    }
}
// Delete Medicine
if (isset($_GET['delete'])) {
    if ($isGuest) {
        $_SESSION['toast'] = ['message' => "⚠️ Guests cannot delete medicines.", 'type' => 'error'];
        header("Location: staff_dashboard.php");
        exit();
    }

    $id = $_GET['delete'];
    $current_time = date('Y-m-d H:i:s');
    $conn->query("UPDATE medicines SET last_updated = '$current_time' WHERE 1");
    $result = $conn->query("SELECT image FROM medicines WHERE id = $id");
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $image_path = "uploads/medicines/" . $row['image'];
        if (file_exists($image_path)) unlink($image_path);
    }
    $conn->query("DELETE FROM medicines WHERE id = $id");
    $_SESSION['toast'] = ['message' => "Medicine deleted successfully!", 'type' => 'success'];
    header("Location: staff_dashboard.php");
    exit();
}
// Edit Data
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $edit_result = $conn->query("SELECT * FROM medicines WHERE id = $edit_id");
    if ($edit_result->num_rows > 0) {
        $edit_data = $edit_result->fetch_assoc();
    }
}
// === FUNCTION: Log expired medicines automatically ===
function logExpiredMedicines($conn) {
    $today = date('Y-m-d');
    $stmt = $conn->prepare("
    SELECT id, name, type, batch_date, expired_date, quantity, image
    FROM medicines
    WHERE expired_date < ? AND status != 'inactive'
    ");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // ✅ Insert into expired_logs WITH medicine_id
        // ❌ DO NOT insert into 'id' — let it auto-increment!
        $insert = $conn->prepare("
        INSERT INTO expired_logs
        (medicine_id, name, type, batch_date, expired_date, quantity_at_expiry, image, recorded_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $insert->bind_param(
            "issssis",
            $row['id'],               // ← this goes into medicine_id
            $row['name'],
            $row['type'],
            $row['batch_date'],
            $row['expired_date'],
            $row['quantity'],
            $row['image']
        );
        $insert->execute();
        $insert->close();

        // Mark medicine as inactive
        $update = $conn->prepare("
        UPDATE medicines
        SET status = 'inactive', removed_on = NOW(), last_updated = NOW()
        WHERE id = ?
        ");
        $update->bind_param("i", $row['id']);
        $update->execute();
        $update->close();
    }
    $stmt->close();
}
logExpiredMedicines($conn);
$result = $conn->query("SELECT * FROM medicines");
$expiring_meds = $conn->query("SELECT * FROM medicines WHERE expired_date <= CURDATE() + INTERVAL 1 DAY AND expired_date >= CURDATE()");
$expired_count = $expiring_meds->num_rows;
$low_stock_count = 0;
$result_low = $conn->query("SELECT quantity, expired_date FROM medicines");
while ($row = $result_low->fetch_assoc()) {
    $exp = new DateTime($row['expired_date']);
    $today = new DateTime();
    if ($exp >= $today && $row['quantity'] <= 20) {
        $low_stock_count++;
    }
}
$last_updated_query = $conn->query("SELECT MAX(last_updated) as latest_update FROM medicines");
$last_updated = $last_updated_query->fetch_assoc()['latest_update'];
$formatted_date = $last_updated ? date('M d, Y g:i A', strtotime($last_updated)) : 'No updates';

// Unread notifications count for current user
$unreadCount = 0;
if ($userId) {
    $unreadRes = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = $userId AND is_read = 0");
    if ($unreadRes) $unreadCount = (int)$unreadRes->fetch_assoc()['c'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Staff Dashboard | BENE MediCon</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../styles/s_dashboard.css">
<style>
/* ── Inventory pagination ── */
.inv-pagination {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.8rem 1rem;
    border-top: 1px solid var(--border);
    font-size: 0.82rem; color: var(--text-muted);
}
.inv-pages { display: flex; gap: 4px; }
.inv-page-btn {
    width: 32px; height: 32px; border-radius: 7px;
    border: 1.5px solid var(--border); background: #fff;
    font-family: 'DM Sans', sans-serif; font-size: 0.8rem;
    color: var(--text-muted); cursor: pointer;
    transition: all 0.15s;
}
.inv-page-btn:hover { border-color: #f0d8d8; color: var(--red-dark); }
.inv-page-btn.active { background: var(--red-dark); border-color: var(--red-dark); color: #fff; }
.inv-page-btn:disabled { opacity: 0.35; cursor: not-allowed; }

/* ── Inventory category pills ── */
.inv-pill {
    height: 32px; padding: 0 14px;
    border: 1.5px solid #e5e7eb;
    border-radius: 20px;
    background: #fff;
    color: #6b7280;
    font-family: 'DM Sans', sans-serif;
    font-size: 0.8rem; font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}
.inv-pill:hover  { border-color: #f0d8d8; color: #7b1010; background: #fdf4f4; }
.inv-pill.active { background: #7b1010; border-color: #7b1010; color: #fff; }
</style>
</head>
<body>
<div id="logoutModal" class="modal" style="display:none;">
<div class="modal-content" style="max-width: 400px; border-radius: 16px; padding: 1.6rem; box-shadow: 0 20px 60px rgba(0,0,0,0.25);">
<div class="modal-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;padding-bottom:0.75rem;border-bottom:1px solid #e5e7eb;">
<h3 style="font-family:'EB Garamond',serif;font-size:1.15rem;color:#5c0a0a;"><i class="fas fa-sign-out-alt" style="color:#c62828;margin-right:8px;"></i> Confirm Logout</h3>
<button onclick="closeLogoutModal()" style="width:28px;height:28px;border-radius:6px;background:#f3f4f6;border:none;cursor:pointer;font-size:1rem;color:#6b7280;">&times;</button>
</div>
<p style="font-size:0.88rem;color:#374151;margin-bottom:1.2rem;">Are you sure you want to log out?</p>
<div style="display:flex;gap:8px;justify-content:flex-end;">
<button onclick="closeLogoutModal()" style="height:36px;padding:0 14px;background:#6b7280;color:#fff;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.82rem;font-weight:500;cursor:pointer;">Cancel</button>
<button onclick="window.location.href='../../logout.php'" style="height:36px;padding:0 14px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.82rem;font-weight:500;cursor:pointer;"><i class="fas fa-sign-out-alt"></i> Yes, Logout</button>
</div>
</div>
</div>
<!-- ══ SIDEBAR ══ -->
<div class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <button class="hamburger-btn" id="hamburger">
      <svg class="hamburger-svg" viewBox="0 0 24 24">
        <line class="line-1" x1="4" y1="6"  x2="20" y2="6"/>
        <line class="line-2" x1="4" y1="12" x2="20" y2="12"/>
        <line class="line-3" x1="4" y1="18" x2="20" y2="18"/>
      </svg>
    </button>
    <span class="sidebar-brand">BENE <span>MediCon</span></span>
  </div>
  <nav>
    <div class="nav-section-label">Overview</div>
    <button class="nav-item active" id="btn-dashboard"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></button>

    <div class="nav-section-label">Inventory</div>
    <button class="nav-item" id="btn-inventory"><i class="fas fa-boxes"></i><span>Inventory</span></button>
    <button class="nav-item" id="btn-expiration"><i class="fas fa-calendar-times"></i><span>Expiration</span></button>

    <?php if (!$isGuest): ?>
    <div class="nav-section-label">Requests</div>
    <button class="nav-item" id="btn-donate"><i class="fas fa-hand-holding-medical"></i><span>Donate or Dispose</span></button>
    <button class="nav-item" id="btn-donation-history"><i class="fas fa-clipboard-list"></i><span>Donation Requests</span></button>
    <button class="nav-item" id="btn-disposal-history"><i class="fas fa-trash-alt"></i><span>Disposal Requests</span></button>
    <?php endif; ?>
  </nav>
  <div class="sidebar-footer">
    <button class="nav-item" onclick="openLogoutModal()" style="color:rgba(255,255,255,0.6);"><i class="fas fa-sign-out-alt"></i><span>Logout</span></button>
  </div>
</div>

<!-- ══ TOPBAR ══ -->
<div class="topbar" id="topbar">
  <span class="topbar-title" id="topbar-title">Dashboard</span>
  <div class="topbar-right">
    <button class="topbar-btn" onclick="openModal()" title="Expiring medicines alert">
      <i class="fas fa-bell"></i>
      <?php if ($expired_count > 0): ?>
        <span class="topbar-badge"><?= $expired_count ?></span>
      <?php endif; ?>
    </button>
    <div class="topbar-divider"></div>
    <div class="profile-menu">
      <button class="profile-btn" onclick="toggleProfileMenu()" type="button">
        <img src="uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic'] ?? 'default.jpg') ?>"
             alt="Profile" class="profile-avatar"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="profile-avatar-initials" style="display:none;">
          <?= strtoupper(substr($_SESSION['username'] ?? 'S', 0, 1)) ?>
        </div>
        <div class="profile-info">
          <div class="p-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Staff') ?></div>
          <div class="p-role"><?= $isGuest ? 'Guest' : 'Staff Member' ?></div>
        </div>
        <i class="fas fa-chevron-down profile-chevron"></i>
      </button>
      <div class="profile-dropdown" id="profileDropdown">
        <div class="dropdown-header">
          <img src="uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic'] ?? 'default.jpg') ?>"
               alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
          <div class="dh-initials" style="display:none;"><?= strtoupper(substr($_SESSION['username'] ?? 'S', 0, 1)) ?></div>
          <div>
            <div class="dh-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Staff') ?></div>
            <div class="dh-role"><?= $isGuest ? 'Guest' : 'Staff Member' ?> &mdash; BENE MediCon</div>
          </div>
        </div>
        <?php if (!$isGuest): ?>
        <a href="../edit_profile.php" class="dropdown-item"><i class="fas fa-user-edit"></i> Edit Profile</a>
        <a href="../change_password.php" class="dropdown-item"><i class="fas fa-key"></i> Change Password</a>
        <?php endif; ?>
        <button onclick="openLogoutModal()" class="dropdown-item danger"><i class="fas fa-sign-out-alt"></i> Logout</button>
      </div>
    </div>
  </div>
</div>

<!-- Main Content -->
<div class="main-content" id="main-content">
<?php if ($forcePasswordChange || $forceSecuritySetup): ?>
<div class="password-alert" id="securityNotice">
<i class="fas fa-exclamation-triangle"></i>
<span>
Action required:
<?php if ($forcePasswordChange): ?> You must update your password.<?php endif; ?>
<?php if ($forceSecuritySetup): ?> You must set your security question.<?php endif; ?>
<a href="../change_password.php">Click here to continue</a>.
</span>
<button class="close-btn" onclick="this.closest('.password-alert').remove();">&times;</button>
</div>
<?php endif; ?>

<div id="content-dashboard" class="content active">
<h1>Staff Dashboard</h1>
<div class="dashboard-cards">
  <div class="stat-card stat-card-1">
    <div class="stat-icon"><i class="fas fa-pills"></i></div>
    <div class="stat-value"><?php echo $result->num_rows; ?></div>
    <div class="stat-label">Total Medicines</div>
  </div>
  <div class="stat-card stat-card-2">
    <div class="stat-icon"><i class="fas fa-calendar-times"></i></div>
    <div class="stat-value"><?php echo $expired_count; ?></div>
    <div class="stat-label">Expiring Soon</div>
  </div>
  <div class="stat-card stat-card-3">
    <div class="stat-icon"><i class="fas fa-tags"></i></div>
    <div class="stat-value"><?php echo count($categories); ?></div>
    <div class="stat-label">Categories</div>
  </div>
  <div class="stat-card stat-card-4">
    <div class="stat-icon"><i class="fas fa-clock"></i></div>
    <div class="stat-value" style="font-size:1rem;margin-top:0.3rem;"><?php echo $formatted_date; ?></div>
    <div class="stat-label">Last Updated</div>
  </div>
  <div class="stat-card stat-card-5">
    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="stat-value"><?php echo $low_stock_count; ?></div>
    <div class="stat-label">Low Stock Alerts</div>
  </div>
</div>
<p>Welcome to the <strong>BENE MediCon Inventory System</strong>. Use the sidebar to manage medicines, check expirations, and more.</p>
<!-- Charts Grid -->
<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin: 1.4rem 0;">
<div style="background: var(--surface); padding: 16px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.04); height: 280px;">
<canvas id="categoryChart"></canvas>
</div>
<div style="background: var(--surface); padding: 16px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.04); height: 280px;">
<canvas id="expiryChart"></canvas>
</div>
<div style="background: var(--surface); padding: 16px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.04); height: 280px;">
<canvas id="stockLevelsChart"></canvas>
</div>
<div style="background: var(--surface); padding: 16px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.04); height: 280px;">
<canvas id="expiryTrendChart"></canvas>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php
$categoryData = [];
foreach($categories as $category) {
    $query = $conn->query("SELECT COUNT(*) as count FROM medicines WHERE type='$category'");
    $categoryData[] = $query->fetch_assoc()['count'];
}
$today = date('Y-m-d');
$expired = $conn->query("SELECT COUNT(*) as count FROM medicines WHERE expired_date < '$today'")->fetch_assoc()['count'];
$valid = $conn->query("SELECT COUNT(*) as count FROM medicines WHERE expired_date >= '$today'")->fetch_assoc()['count'];
$lowStock = $conn->query("SELECT COUNT(*) as count FROM medicines WHERE quantity <= 20")->fetch_assoc()['count'];
$normalStock = $conn->query("SELECT COUNT(*) as count FROM medicines WHERE quantity > 20 AND quantity <= 50")->fetch_assoc()['count'];
$highStock = $conn->query("SELECT COUNT(*) as count FROM medicines WHERE quantity > 50")->fetch_assoc()['count'];
$trendQuery = $conn->query("
SELECT DATE_FORMAT(expired_date, '%Y-%m') as month,
COUNT(*) as count
FROM medicines
WHERE expired_date BETWEEN DATE_SUB(NOW(), INTERVAL 6 MONTH) AND DATE_ADD(NOW(), INTERVAL 6 MONTH)
GROUP BY month
ORDER BY month
");
$months = [];
$counts = [];
while($row = $trendQuery->fetch_assoc()) {
    $months[] = $row['month'];
    $counts[] = $row['count'];
}
?>
const commonOptions = {
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'bottom',
            labels: {
                boxWidth: 10,
                padding: 10,
                font: { size: 11, family: "'DM Sans', sans-serif" },
                color: '#6b7280'
            }
        },
        title: {
            display: true,
            font: { size: 12, weight: '600', family: "'DM Sans', sans-serif" },
            color: '#5c0a0a',
            padding: { top: 6, bottom: 8 }
        }
    }
};

// ── 1. Category Distribution (Bar) ──
new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($categories); ?>,
        datasets: [{
            label: 'Medicines',
            data: <?php echo json_encode($categoryData); ?>,
            backgroundColor: [
                'rgba(155,28,28,0.75)',
                'rgba(198,40,40,0.65)',
                'rgba(92,10,10,0.7)',
                'rgba(201,168,76,0.7)',
                'rgba(232,201,106,0.65)',
                'rgba(123,16,16,0.6)'
            ],
            borderColor: [
                '#9b1c1c','#c62828','#5c0a0a','#c9a84c','#e8c96a','#7b1010'
            ],
            borderWidth: 1.5,
            borderRadius: 6,
            borderSkipped: false        
        }]
    },
    options: {
        ...commonOptions,
        plugins: {
            ...commonOptions.plugins,
            title: { ...commonOptions.plugins.title, text: 'Medicines by Category' }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(0,0,0,0.05)' },
                ticks: { font: { size: 11 }, color: '#9a8a85' }
            },
            x: {
                grid: { display: false },
                ticks: { font: { size: 11 }, color: '#9a8a85' }
            }
        }
    }
});

// ── 2. Expiry Status (Pie) ──
new Chart(document.getElementById('expiryChart'), {
    type: 'pie',
    data: {
        labels: ['Valid', 'Expired'],
        datasets: [{
            data: [<?php echo $valid; ?>, <?php echo $expired; ?>],
            backgroundColor: ['rgba(5,150,105,0.8)', 'rgba(198,40,40,0.8)'],
            borderColor: ['#059669', '#c62828'],
            borderWidth: 2,
            hoverOffset: 6
        }]
    },
    options: {
        ...commonOptions,
        plugins: {
            ...commonOptions.plugins,
            title: { ...commonOptions.plugins.title, text: 'Medicine Expiry Status' }
        }
    }
});

// ── 3. Stock Levels (Doughnut) ──
new Chart(document.getElementById('stockLevelsChart'), {
    type: 'doughnut',
    data: {
        labels: ['Low Stock (≤20)', 'Normal (21–50)', 'High (>50)'],
        datasets: [{
            data: [<?php echo $lowStock; ?>, <?php echo $normalStock; ?>, <?php echo $highStock; ?>],
            backgroundColor: [
                'rgba(198,40,40,0.82)',
                'rgba(201,168,76,0.8)',
                'rgba(5,150,105,0.78)'
            ],
            borderColor: ['#c62828', '#c9a84c', '#059669'],
            borderWidth: 2,
            hoverOffset: 6
        }]
    },
    options: {
        ...commonOptions,
        plugins: {
            ...commonOptions.plugins,
            title: { ...commonOptions.plugins.title, text: 'Stock Level Distribution' }
        }
    }
});

// ── 4. Monthly Expiry Trend (Line) ──
new Chart(document.getElementById('expiryTrendChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months); ?>,
        datasets: [{
            label: 'Expiring Medicines',
            data: <?php echo json_encode($counts); ?>,
            borderColor: '#9b1c1c',
            backgroundColor: 'rgba(155,28,28,0.08)',
            pointBackgroundColor: '#c62828',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 4,
            pointHoverRadius: 6,
            tension: 0.35,
            fill: true
        }]
    },
    options: {
        ...commonOptions,
        plugins: {
            ...commonOptions.plugins,
            title: { ...commonOptions.plugins.title, text: 'Monthly Expiry Trend' }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(0,0,0,0.05)' },
                ticks: { font: { size: 11 }, color: '#9a8a85' }
            },
            x: {
                grid: { display: false },
                ticks: { font: { size: 11 }, color: '#9a8a85', maxRotation: 45, minRotation: 45 }
            }
        }
    }
});
</script>
</div>



<div id="content-inventory" class="content">
  <h1>Inventory</h1>

  <!-- ── toolbar: search + add ── -->
  <div style="display:flex; gap:10px; align-items:center; margin-bottom:1rem; flex-wrap:wrap;">
    <input type="text" id="inventory-search" placeholder="&#128269; Search by name..."
           style="flex:1; min-width:180px; height:38px; padding:0 12px;
                  border:1.5px solid var(--border); border-radius:8px;
                  font-family:'DM Sans',sans-serif; font-size:0.88rem; outline:none;
                  transition:border-color 0.2s;"
           onfocus="this.style.borderColor='var(--red)'"
           onblur="this.style.borderColor='var(--border)'">
    <?php if (!$isGuest): ?>
    <button onclick="openAddMedicineModal()" class="btn btn-add">
      <i class="fas fa-plus"></i> Add Medicine
    </button>
    <?php endif; ?>
  </div>

  <!-- ── category filter pills ── -->
  <div id="inv-category-pills" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:1.2rem;">
    <button class="inv-pill active" onclick="filterInventory('all', this)">All</button>
    <?php foreach ($categories as $cat): ?>
      <button class="inv-pill" onclick="filterInventory('<?= addslashes(htmlspecialchars($cat)) ?>', this)">
        <?= htmlspecialchars($cat) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- ── unified table ── -->
  <div class="table-wrap">
    <table id="inventory-table">
      <thead>
        <tr>
          <th>Image</th>
          <th>Name</th>
          <th>Category</th>
          <th>Batch Date</th>
          <th>Expiry Date</th>
          <th style="text-align:center;">Qty</th>
          <th>Status</th>
          <?php if (!$isGuest): ?><th>Actions</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php
        $invResult = $conn->query("
            SELECT *,
            CASE WHEN expired_date < CURDATE() THEN 3
                 WHEN quantity <= 20 THEN 1
                 ELSE 2 END AS sort_order
            FROM medicines
            ORDER BY sort_order ASC, expired_date ASC
        ");
        while ($row = $invResult->fetch_assoc()):
            $expDate   = new DateTime($row['expired_date']);
            $todayDt   = new DateTime();
            $isExpired = $expDate < $todayDt;
            $isLow     = !$isExpired && $row['quantity'] <= 20;
            $status    = $isExpired ? '<span class="badge-expired">&#128308; Expired</span>'
                       : ($isLow    ? '<span class="badge-low">&#9888; Low Stock</span>'
                                    : '<span class="badge-good">&#10003; In Stock</span>');
            $rowClass  = $isExpired ? 'expiring-soon' : ($isLow ? 'warning' : '');
        ?>
        <tr class="<?= $rowClass ?>" data-category="<?= htmlspecialchars($row['type']) ?>" data-name="<?= strtolower(htmlspecialchars($row['name'])) ?>">
          <td><img src="../../uploads/medicines/<?= htmlspecialchars($row['image']) ?>" width="40" height="40" style="border-radius:6px;object-fit:cover;" alt=""></td>
          <td><?= htmlspecialchars($row['name']) ?></td>
          <td><span style="background:#fef2f2;color:var(--red-dark);padding:2px 8px;border-radius:10px;font-size:0.75rem;font-weight:600;"><?= htmlspecialchars($row['type']) ?></span></td>
          <td><?= htmlspecialchars($row['batch_date']) ?></td>
          <td><?= htmlspecialchars($row['expired_date']) ?></td>
          <td style="text-align:center;font-weight:600;"><?= (int)$row['quantity'] ?></td>
          <td><?= $status ?></td>
          <?php if (!$isGuest): ?>
          <td>
            <?php if (!$isExpired): ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
              <!-- add stock -->
              <form method="POST" style="display:inline-flex;align-items:center;gap:4px;">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="action" value="add">
                <input type="number" name="change" placeholder="Qty" min="1" required
                       style="width:52px;height:30px;padding:0 6px;border:1.5px solid var(--border);border-radius:6px;font-size:0.78rem;outline:none;">
                <button type="submit" name="adjust_stock" class="btn btn-add" style="height:30px;padding:0 8px;font-size:0.75rem;">+ Add</button>
              </form>
              <!-- use stock -->
              <form method="POST" style="display:inline-flex;align-items:center;gap:4px;">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="action" value="use">
                <input type="number" name="change" placeholder="Qty" min="1" required
                       style="width:52px;height:30px;padding:0 6px;border:1.5px solid var(--border);border-radius:6px;font-size:0.78rem;outline:none;">
                <button type="submit" name="adjust_stock" class="btn btn-del" style="height:30px;padding:0 8px;font-size:0.75rem;"
                        onclick="return confirm('Use this stock?')">− Use</button>
              </form>
              <!-- edit & delete -->
              <button onclick="openEditModal(<?= (int)$row['id'] ?>)" class="btn btn-info" style="height:30px;padding:0 8px;font-size:0.75rem;background:#0288d1;">
                <i class="fas fa-edit"></i>
              </button>
              <button onclick="openDeleteModal(<?= (int)$row['id'] ?>, '<?= addslashes(htmlspecialchars($row['name'])) ?>')" class="btn btn-del" style="height:30px;padding:0 8px;font-size:0.75rem;">
                <i class="fas fa-trash"></i>
              </button>
            </div>
            <?php else: ?>
              <span style="color:#9a8a85;font-style:italic;font-size:0.8rem;">Expired</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
    <div class="inv-pagination" id="inv-pagination">
    <span id="inv-page-info">Showing 1–10</span>
    <div class="inv-pages" id="inv-pages"></div>
</div>
  </div>
  <p id="inv-no-results" style="display:none;color:var(--text-muted);text-align:center;padding:1.5rem 0;font-size:0.88rem;">
    No medicines found matching your search.
  </p>

  <!-- Add Medicine Modal -->
  <div id="addMedicineModal" class="modal">
    <div class="modal-content" style="max-width:500px;">
      <div class="modal-header">
        <h3><i class="fas fa-plus-circle" style="margin-right:8px;color:var(--red-light);"></i>Add New Medicine</h3>
        <span class="modal-close" onclick="closeAddMedicineModal()">&times;</span>
      </div>
      <div class="modal-body" style="padding-top:1rem;">
        <form method="POST" action="staff_dashboard.php" enctype="multipart/form-data">
          <label style="display:block;font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">Medicine Name</label>
          <input type="text" name="name" required
                 style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;margin-bottom:12px;outline:none;">
          <label style="display:block;font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">Category</label>
          <select name="type" required
                  style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;margin-bottom:12px;outline:none;">
            <option value="" disabled selected>Select Category</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat ?>"><?= $cat ?></option>
            <?php endforeach; ?>
          </select>
          <label style="display:block;font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">Batch Date</label>
          <input type="date" name="batch_date" required
                 style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;margin-bottom:12px;outline:none;">
          <label style="display:block;font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">Expiration Date</label>
          <input type="date" name="expired_date" required
                 style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;margin-bottom:12px;outline:none;">
          <label style="display:block;font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">Quantity</label>
          <input type="number" name="quantity" required min="1" value="100"
                 style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;margin-bottom:12px;outline:none;">
          <label style="display:block;font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">Image</label>
          <input type="file" name="image" required style="margin-bottom:16px;font-size:0.85rem;">
          <button type="submit" name="add_medicine" class="btn btn-add" style="width:100%;height:44px;font-size:0.9rem;">
            <i class="fas fa-pills"></i> Add Medicine
          </button>
        </form>
      </div>
    </div>
  </div>

</div>

<div id="content-expiration" class="content">
  <h1>🩺 Medicine Expiry Tracker</h1>
  <p>View medicines expiring within 7 days and those already expired.</p>

  <!-- Export & Print -->
  <div style="margin: 20px 0; text-align: right;">
    <button onclick="printReport('expiry-full-table')" class="stock-btn" style="background:#2196F3;">
      🖨️ Print Report
    </button>
    <a href="export_expiration.php?format=excel" class="stock-btn" style="background:#4CAF50;">
      📊 Export to Excel
    </a>
  </div>

  <!-- Unified Table -->
  <table id="expiry-full-table">
    <thead>
      <tr>
        <th>Image</th>
        <th>Name</th>
        <th>Type</th>
        <th>Batch Date</th>
        <th>Expiry Date</th>
        <th>Days Left</th>
        <th>Quantity</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php
      // Fetch ALL medicines that are either:
      // - Expiring within 7 days (but not expired), OR
      // - Already expired
      $result = $conn->query("
        SELECT *,
          CASE
            WHEN expired_date < CURDATE() THEN 3          -- Expired (last)
            WHEN quantity <= 20 THEN 1                    -- Low stock (first)
            ELSE 2                                        -- Normal stock (middle)
          END AS sort_order
        FROM medicines
        WHERE expired_date <= CURDATE() + INTERVAL 7 DAY
        ORDER BY sort_order ASC, expired_date ASC
      ");

      while ($row = $result->fetch_assoc()):
        $expiryDate = new DateTime($row['expired_date']);
        $today = new DateTime();
        $isExpired = $expiryDate < $today;
        $interval = $today->diff($expiryDate);
        $daysLeft = $isExpired ? -$interval->days : $interval->days;

        $balance = $isExpired ? 0 : $row['quantity'];
        $isLowStock = !$isExpired && $balance <= 20;

        $status = $isExpired 
          ? '🔴 Expired' 
          : ($isLowStock ? '⚠️ Low Stock' : '✅ Valid');

        $rowClass = $isExpired 
          ? 'expiring-soon' 
          : ($isLowStock ? 'warning' : '');

        $daysDisplay = $isExpired 
          ? "Expired" 
          : ($daysLeft === 0 ? "Today" : "$daysLeft day" . ($daysLeft != 1 ? 's' : ''));
      ?>
      <tr class="<?= $rowClass ?>">
        <td><img src="uploads/medicines/<?php echo htmlspecialchars($row['image']); ?>" width="50" alt="Medicine"></td>
        <td><?php echo htmlspecialchars($row['name']); ?></td>
        <td><?php echo htmlspecialchars($row['type']); ?></td>
        <td><?php echo htmlspecialchars($row['batch_date']); ?></td>
        <td><?php echo htmlspecialchars($row['expired_date']); ?></td>
        <td style="font-weight: bold; <?php echo $isExpired ? 'color: #d32f2f;' : ''; ?>">
          <?= $daysDisplay ?>
        </td>
        <td><?php echo (int)$row['quantity']; ?></td>
        <td><?= $status ?></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>

  <div style="margin: 15px 0;">
  <label>Filter by Category:</label>
  <select id="expiry-category-filter" onchange="filterExpiryTable()">
    <option value="">All Categories</option>
    <?php foreach ($categories as $cat): ?>
      <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
    <?php endforeach; ?>
  </select>
</div>

  <?php if ($result->num_rows == 0): ?>
    <p style="color: #34a853; font-style: italic; margin-top: 20px;">
      No medicines expiring within 7 days or already expired.
    </p>
  <?php endif; ?>
</div>


<!-- =============== 🎁 DONATION PAGE =============== -->
<div id="content-donate" class="content">
<h1>♻️ Donate or Dispose Expiring Supplies</h1>
<p>Manage expiring medical supplies: <strong>Donate</strong> items expiring in 10–12 months, or <strong>Dispose</strong> those expiring within 10 months.</p>
<div style="margin: 20px 0; padding: 15px; background:#e8f5e9; border-radius:8px; border-left:4px solid #4caf50;">
<h3>♻️ Disposal & Repurposing Tips</h3>
<ul style="margin: 10px 0; padding-left: 20px;">
<li>
    <strong class="video-hover" data-video="https://www.youtube.com/embed/dzc46-tLwSw?si=1BECjeCXBXFYW9hw">
        Antibiotic / Pain Reliever / Vitamins
    </strong>:
    Mix with coffee grounds or cat litter, seal in bag, dispose in trash.
</li>
<li>
    <strong class="video-hover" data-video="https://www.youtube.com/embed/_2rIPBO8oQQ?si=_X8IFelf1WzJs45_">
        Injection
    </strong>: 
    Use sharps container. Do not reuse.
</li>
<li>
    <strong class="video-hover" data-video="https://www.youtube.com/embed/wTIhYUWX-p0?si=WBMFNMK99YUOMTrk">
        Antiseptic (liquid)
    </strong>: 
    Small amounts down drain. Bottles → rinse & recycle.
</li>
<li>
    <strong class="video-hover" data-video="https://www.youtube.com/embed/WcYEbAc4Cl0?si=gEAxWnzTFrWy6WhP">
        Other / Bottles
    </strong>: 
    Rinse thoroughly. Recycle per local rules.
</li>
</ul>
<p style="font-size:0.9em; color:#555;">⚠️ Always follow local regulations for medical waste disposal.</p>
</div>
<!-- Toggle Buttons -->
<div style="margin: 20px 0; display: flex; gap: 10px;">
  <button id="showDonateBtn" class="stock-btn" style="background:#9c27b0;" onclick="showDonateTable()">🎁 Show Donation-Eligible (10–12 mo)</button>
  <button id="showDisposeBtn" class="stock-btn" style="background:#e53935;" onclick="showDisposeTable()">🗑️ Show Disposal-Eligible (≤10 mo)</button>
</div>

<!-- Donation Table (Default) -->
<table id="donate-table">
  <tr>
    <th>Image</th><th>Name</th><th>Category</th><th>Expiry Date</th><th>Time Left</th><th>Quantity</th><th>Action</th>
  </tr>
  <?php
  $today = date('Y-m-d');
  $twelveMonths = date('Y-m-d', strtotime('+12 months'));
  $tenMonths = date('Y-m-d', strtotime('+10 months'));

  // Donation: >10 months AND ≤12 months from now
  $donateQuery = $conn->query("
    SELECT * FROM medicines
    WHERE expired_date > '$tenMonths'
      AND expired_date <= '$twelveMonths'
    ORDER BY expired_date ASC
  ");
  if ($donateQuery && $donateQuery->num_rows > 0):
    while ($med = $donateQuery->fetch_assoc()):
      $expDate = new DateTime($med['expired_date']);
      $now = new DateTime();
      $interval = $now->diff($expDate);
      $totalMonths = $interval->y * 12 + $interval->m;
      $days = $interval->d;
      $displayTime = "$totalMonths month" . ($totalMonths != 1 ? 's' : '');
      if ($days > 0) $displayTime .= " $days day" . ($days != 1 ? 's' : '');
  ?>
  <tr>
    <td><img src="uploads/medicines/<?= htmlspecialchars($med['image']) ?>" width="50" alt="Medicine"></td>
    <td><?= htmlspecialchars($med['name']) ?></td>
    <td><?= htmlspecialchars($med['type']) ?></td>
    <td><?= htmlspecialchars($med['expired_date']) ?></td>
    <td style="color: #9c27b0; font-weight: bold;"><?= $displayTime ?></td>
    <td><?= (int)$med['quantity'] ?></td>
    <td>
<?php
// Check if a pending donation request already exists
$pendingCheck = $conn->prepare("
    SELECT id FROM donation_requests 
    WHERE medicine_id = ? AND staff_id = ? AND status = 'pending'
");
$pendingCheck->bind_param("ii", $med['id'], $userId);
$pendingCheck->execute();
$isPending = $pendingCheck->get_result()->num_rows > 0;
$pendingCheck->close();

if ($isPending): ?>
  <span class="donate-btn" style="background: #9e9e9e; cursor: not-allowed;" title="Request already pending">
    ⏳ Request Pending
  </span>
<?php else: ?>
  <a href="staff_dashboard.php?donate=<?= (int)$med['id'] ?>"
     class="donate-btn"
     onclick="return confirm('Request donation for \"<?= htmlspecialchars($med['name']) ?>\"? Admin will review your request.')">
    🎁 Donate
  </a>
<?php endif; ?>
</td>
  </tr>
  <?php endwhile; ?>
  <?php else: ?>
  <tr><td colspan="7" style="text-align:center; color:#666; padding:20px;">No supplies eligible for donation.</td></tr>
  <?php endif; ?>
</table>

<!-- Disposal Table (Hidden by default) -->
<table id="dispose-table" style="display:none;">
  <tr>
    <th>Image</th><th>Name</th><th>Category</th><th>Expiry Date</th><th>Time Left</th><th>Quantity</th><th>Action</th>
  </tr>
  <?php
  // Disposal: > today AND ≤10 months from now
  $disposeQuery = $conn->query("
    SELECT * FROM medicines
    WHERE expired_date > '$today'
      AND expired_date <= '$tenMonths'
    ORDER BY expired_date ASC
  ");
  if ($disposeQuery && $disposeQuery->num_rows > 0):
    while ($med = $disposeQuery->fetch_assoc()):
      $expDate = new DateTime($med['expired_date']);
      $now = new DateTime();
      $interval = $now->diff($expDate);
      $totalMonths = $interval->y * 12 + $interval->m;
      $days = $interval->d;
      $displayTime = "$totalMonths month" . ($totalMonths != 1 ? 's' : '');
      if ($days > 0) $displayTime .= " $days day" . ($days != 1 ? 's' : '');
      $color = ($totalMonths == 0 && $days <= 1) ? '#d32f2f' : '#e53935';
  ?>
  <tr>
    <td><img src="uploads/medicines/<?= htmlspecialchars($med['image']) ?>" width="50" alt="Medicine"></td>
    <td><?= htmlspecialchars($med['name']) ?></td>
    <td><?= htmlspecialchars($med['type']) ?></td>
    <td><?= htmlspecialchars($med['expired_date']) ?></td>
    <td style="color: <?= $color ?>; font-weight: bold;"><?= $displayTime ?></td>
    <td><?= (int)$med['quantity'] ?></td>
    <td>
  <button class="donate-btn" style="background:#e53935;" 
          onclick="openDisposalModal(<?= (int)$med['id'] ?>, '<?= addslashes(htmlspecialchars($med['name'])) ?>')">
    🗑️ Dispose
  </button>
</td>
  </tr>
  <?php endwhile; ?>
  <?php else: ?>
  <tr><td colspan="7" style="text-align:center; color:#666; padding:20px;">No supplies eligible for disposal.</td></tr>
  <?php endif; ?>
</table>
</div>



<!-- Popup Video Container -->
<div id="videoPopup" class="video-popup">
  <iframe id="popupFrame" src="" frameborder="0" allowfullscreen></iframe>
</div>

<style>
  .video-hover {
    position: relative;
    cursor: pointer;
    color: #2e7d32;
  }

  .video-hover:hover {
    text-decoration: underline;
  }

  /* Popup styling */
  .video-popup {
    display: none;
    position: absolute;
    z-index: 1000;
    background: #fff;
    border: 2px solid #4caf50;
    border-radius: 10px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    width: 320px;
    height: 180px;
    overflow: hidden;
  }

  .video-popup iframe {
    width: 100%;
    height: 100%;
    border: none;
  }
</style>

<script>
  const isGuest = <?php echo json_encode($isGuest); ?>;

  const popup = document.getElementById('videoPopup');
  const frame = document.getElementById('popupFrame');
  let hideTimeout = null;

  document.querySelectorAll('.video-hover').forEach(item => {
    item.addEventListener('mouseenter', e => {
      clearTimeout(hideTimeout);
      const videoURL = e.target.getAttribute('data-video');
      frame.src = videoURL + "?autoplay=1&mute=1"; // auto-play muted
      popup.style.display = 'block';
      popup.style.opacity = '1';
      const rect = e.target.getBoundingClientRect();
      popup.style.top = (window.scrollY + rect.bottom + 5) + 'px';
      popup.style.left = (rect.left) + 'px';
    });

    item.addEventListener('mouseleave', () => {
      // Delay hiding to allow user to hover over popup
      hideTimeout = setTimeout(() => {
        popup.style.display = 'none';
        frame.src = '';
      }, 400); // 400ms delay
    });
  });

  popup.addEventListener('mouseenter', () => {
    clearTimeout(hideTimeout); // stay visible while hovered
  });

  popup.addEventListener('mouseleave', () => {
    hideTimeout = setTimeout(() => {
      popup.style.display = 'none';
      frame.src = '';
    }, 400);
  });
</script>








<!-- =============== 📦 DONATION HISTORY =============== -->
<div id="content-donation-history" class="content">
<h1>📦 Donation Requests</h1>
<p>Track the status of your medicine donation requests.</p>
<table>
<tr>
<th>Medicine</th>
<th>Category</th>
<th>Requested On</th>
<th>Status</th>
<th>Admin Response</th>
</tr>
<?php
$historyQuery = $conn->query("
SELECT dr.*, m.name AS med_name, m.type AS med_type
FROM donation_requests dr
JOIN medicines m ON dr.medicine_id = m.id
WHERE dr.staff_id = $userId
ORDER BY dr.requested_at DESC
");
if ($historyQuery && $historyQuery->num_rows > 0):
while ($req = $historyQuery->fetch_assoc()):
$statusClass = '';
$statusText = '';
switch ($req['status']) {
    case 'approved':
    $statusClass = 'status-approved';
    $statusText = '✅ Approved';
    break;
    case 'rejected':
    $statusClass = 'status-rejected';
    $statusText = '❌ Rejected';
    break;
    default:
    $statusClass = 'status-pending';
    $statusText = '⏳ Pending';
}
?>
<tr>
<td><?= htmlspecialchars($req['med_name']) ?></td>
<td><?= htmlspecialchars($req['med_type']) ?></td>
<td><?= htmlspecialchars($req['requested_at']) ?></td>
<td class="<?= $statusClass ?>"><?= $statusText ?></td>
<td>
<?php if ($req['status'] !== 'pending'): ?>
<small>Responded on: <?= htmlspecialchars($req['approved_at']) ?></small>
<?php else: ?>
<em>Awaiting review</em>
<?php endif; ?>
</td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr>
<td colspan="5" style="text-align:center; padding:20px; color:#666;">
You haven't submitted any donation requests yet.
</td>
</tr>
<?php endif; ?>
</table>
</div>

<!-- =============== 🗑️ DISPOSAL HISTORY =============== -->
<div id="content-disposal-history" class="content">
  <h1>🗑️ Disposed Supplies </h1>
  <p>Track how you disposed of expiring supplies.</p>
  <table>
    <tr>
      <th>Medicine</th>
      <th>Category</th>
      <th>Disposed On</th>
      <th>Disposal Method</th>
    </tr>
    <?php
    $disposalQuery = $conn->query("
        SELECT dr.*, m.name AS med_name, m.type AS med_type
        FROM disposal_requests dr
        JOIN medicines m ON dr.medicine_id = m.id
        WHERE dr.staff_id = $userId
        ORDER BY dr.disposed_at DESC
    ");
    if ($disposalQuery && $disposalQuery->num_rows > 0):
        while ($req = $disposalQuery->fetch_assoc()):
    ?>
    <tr>
      <td><?= htmlspecialchars($req['med_name']) ?></td>
      <td><?= htmlspecialchars($req['med_type']) ?></td>
      <td><?= htmlspecialchars($req['disposed_at']) ?></td>
      <td style="max-width:300px; word-wrap:break-word;"><?= nl2br(htmlspecialchars($req['disposal_method'])) ?></td>
    </tr>
    <?php endwhile; ?>
    <?php else: ?>
    <tr>
      <td colspan="4" style="text-align:center; padding:20px; color:#666;">
        You haven't disposed of any items yet.
      </td>
    </tr>
    <?php endif; ?>
  </table>
</div>

</div>

<!-- Profile Menu -->
<!-- Expiring Medicines Modal -->
<div id="notificationModal" class="modal">
<div class="modal-content">
<div class="modal-header">
<h3>⚠️ Medicines Expiring Within 1 Day</h3>
<span class="modal-close" onclick="closeModal()">&times;</span>
</div>
<div class="modal-body">
<?php if ($expired_count > 0): ?>
<table>
<tr>
<th>Image</th>
<th>Name</th>
<th>Type</th>
<th>Batch Date</th>
<th>Expiry Date</th>
<th>Quantity</th>
<th>Status</th>
</tr>
<?php
$expiring_meds = $conn->query("SELECT * FROM medicines WHERE expired_date <= CURDATE() + INTERVAL 1 DAY AND expired_date >= CURDATE()");
while ($med = $expiring_meds->fetch_assoc()):
$balance = $med['quantity'];
$status = $balance <= 20 ? '⚠️ Low Stock' : '✅ In Stock';
?>
<tr>
<td><img src="uploads/medicines/<?php echo htmlspecialchars($med['image']); ?>" width="50"></td>
<td><?php echo htmlspecialchars($med['name']); ?></td>
<td><?php echo htmlspecialchars($med['type']); ?></td>
<td><?php echo htmlspecialchars($med['batch_date']); ?></td>
<td><?php echo htmlspecialchars($med['expired_date']); ?></td>
<td><?php echo (int)$med['quantity']; ?></td>
<td><?= $status ?></td>
</tr>
<?php endwhile; ?>
</table>
<?php else: ?>
<p>No medicines expiring within 1 day.</p>
<?php endif; ?>
</div>
</div>
</div>

<!-- Disposal Request Modal -->
<div id="disposalModal" class="modal">
  <div class="modal-content" style="max-width: 500px;">
    <div class="modal-header">
      <h3>🗑️ Dispose of <span id="disposalMedName"></span></h3>
      <span class="modal-close" onclick="closeDisposalModal()">&times;</span>
    </div>
    <form method="POST" action="staff_dashboard.php">
      <input type="hidden" name="medicine_id" id="disposalMedId">
      <div style="padding: 20px;">
        <p><strong>How will you dispose of this item?</strong></p>
        <label style="display:block; margin:10px 0;">
          <input type="radio" name="disposal_method" value="Mixed with coffee grounds/cat litter, sealed in bag, trashed" required>
          Antibiotic / Pain Reliever / Vitamins
        </label>
        <label style="display:block; margin:10px 0;">
          <input type="radio" name="disposal_method" value="Used sharps container" required>
          Injection (sharps)
        </label>
        <label style="display:block; margin:10px 0;">
          <input type="radio" name="disposal_method" value="Small amounts poured down drain; bottle rinsed & recycled" required>
          Antiseptic (liquid)
        </label>
        <label style="display:block; margin:10px 0;">
          <input type="radio" name="disposal_method" value="Rinsed thoroughly and recycled per local rules" required>
          Other / Bottles
        </label>
        <label style="display:block; margin:15px 0 5px; font-weight:bold;">
          Or describe your method:
        </label>
        <textarea name="disposal_method" rows="3" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;"
                  placeholder="Describe how you will safely dispose of this item..."></textarea>
      </div>
      <div style="text-align:right; padding:0 20px 20px;">
        <button type="button" onclick="closeDisposalModal()" style="padding:8px 16px; margin-right:10px; background:#9e9e9e; color:white; border:none; border-radius:4px;">Cancel</button>
        <button type="submit" name="request_disposal" style="padding:8px 16px; background:#e53935; color:white; border:none; border-radius:4px;">Confirm Disposal</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
<div class="modal-content">
<div class="modal-header">
<h3>✏️ Edit Medicine</h3>
<span class="modal-close" onclick="closeEditModal()">&times;</span>
</div>
<div class="modal-body">
<form id="editForm" method="POST" action="staff_dashboard.php" enctype="multipart/form-data">
<input type="hidden" name="id" id="edit_id">
<label>Medicine Name</label>
<input type="text" name="name" id="edit_name" required style="width:100%;margin-bottom:10px;">
<label>Category</label>
<select name="type" id="edit_type" required style="width:100%;margin-bottom:10px;">
<?php foreach ($categories as $cat): ?>
<option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
<?php endforeach; ?>
</select>
<label>Batch Date</label>
<input type="date" name="batch_date" id="edit_batch_date" required style="width:100%;margin-bottom:10px;">
<label>Expiration Date</label>
<input type="date" name="expired_date" id="edit_expired_date" required style="width:100%;margin-bottom:10px;">
<label>Quantity</label>
<input type="number" name="quantity" id="edit_quantity" required min="1" style="width:100%;margin-bottom:10px;">
<label>Change Image (Optional)</label>
<input type="file" name="image" style="margin-bottom:10px;">
<button type="submit" name="update_medicine"
style="background:#1a73e8;color:white;padding:10px;width:100%;border:none;border-radius:5px;">
💾 Update Medicine
</button>
</form>
</div>
</div>
</div>



<!-- Chatbot -->
<div class="chat-head" id="chatHead"><i class="fas fa-robot"></i></div>
<div class="chat-container" id="chatContainer">
<div class="chat-header">
<i class="fas fa-clinic-medical"></i> BENE Assist
<span class="chat-close" id="chatClose">&times;</span>
</div>
<div class="disclaimer">Powered by Groq, Model: llama-3.1-8b-instant</div>
<div id="chatbox">
<div class="message bot">
<img src="https://ui-avatars.com/api/?name=MedBot&background=007BFF&color=fff" class="avatar" alt="Bot">
<div class="message-text">
Welcome to Bene MediCon 👋 Your trusted partner in medical inventory management. How may I assist you today?
</div>
</div>
</div>
<div id="user-input">
<button id="micBtn" title="Click to speak">
<i class="fas fa-microphone"></i>
</button>
<input type="text" id="chatMessage" placeholder="Ask about medicine...">
<button id="sendBtn" onclick="sendChatbotMessage()">
<i class="fas fa-paper-plane"></i>
</button>
</div>
</div>

<script>
const sidebar     = document.getElementById("sidebar");
const topbar      = document.getElementById("topbar");
const mainContent = document.getElementById("main-content");
const hamburger   = document.getElementById("hamburger");
const buttons = {
    dashboard: document.getElementById("btn-dashboard"),
    inventory: document.getElementById("btn-inventory"),
    expiration: document.getElementById("btn-expiration"),
    donate: document.getElementById("btn-donate"),
    donationHistory: document.getElementById("btn-donation-history"),
    disposalHistory: document.getElementById("btn-disposal-history"),
};
const contents = {
    dashboard: document.getElementById("content-dashboard"),
    add: document.getElementById("content-add"),
    inventory: document.getElementById("content-inventory"),
    expiration: document.getElementById("content-expiration"),
    donate: document.getElementById("content-donate"),
    donationHistory: document.getElementById("content-donation-history"),
    disposalHistory: document.getElementById("content-disposal-history"),
};

// Sidebar expand/collapse with topbar + main sync
function applyExpanded(expanded) {
    if (expanded) {
        sidebar.classList.add('expanded');
        if (topbar) topbar.style.left = 'var(--sidebar-exp)';
        if (mainContent) mainContent.style.marginLeft = 'var(--sidebar-exp)';
    } else {
        sidebar.classList.remove('expanded');
        if (topbar) topbar.style.left = 'var(--sidebar-w)';
        if (mainContent) mainContent.style.marginLeft = 'var(--sidebar-w)';
    }
}
if (localStorage.getItem('sidebarExpanded') === 'true') applyExpanded(true);
hamburger.addEventListener("click", () => {
    const exp = !sidebar.classList.contains('expanded');
    applyExpanded(exp);
    localStorage.setItem('sidebarExpanded', exp);
});

// Section titles for topbar
const sectionTitles = {
    dashboard:       'Dashboard',
    inventory:       'Inventory',
    expiration:      'Expiration Tracker',
    donate:          'Donate or Dispose',
    donationHistory: 'Donation Requests',
    disposalHistory: 'Disposal Requests',
};

function showSection(name) {
    Object.keys(contents).forEach(key => {
        if (contents[key]) contents[key].classList.remove("active");
    });
    Object.keys(buttons).forEach(key => {
        if (buttons[key]) buttons[key].classList.remove("active");
    });

    if (contents[name]) {
        contents[name].classList.add("active");
        if (buttons[name]) buttons[name].classList.add("active");
        if (name === "history") loadHistoryCategories();
    }

    // Update topbar title
    const titleEl = document.getElementById('topbar-title');
    if (titleEl && sectionTitles[name]) titleEl.textContent = sectionTitles[name];
}

// ── Unified inventory filter (category pill + search bar) ──
let invActiveCategory = 'all';
const INV_PAGE_SIZE = 6;
let invCurrentPage  = 1;

function filterInventory(category, btn) {
    invActiveCategory = category;
    invCurrentPage    = 1;
    document.querySelectorAll('.inv-pill').forEach(p => p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    applyInventoryFilter();
}

function applyInventoryFilter() {
    const search = (document.getElementById('inventory-search')?.value || '').toLowerCase();
    const usePaging = invActiveCategory === 'all' && !search;

    // collect matching rows
    const allRows = [...document.querySelectorAll('#inventory-table tbody tr')];
    const matched = allRows.filter(row => {
        const cat      = row.dataset.category || '';
        const catMatch = invActiveCategory === 'all' || cat === invActiveCategory;
        const nameMatch = !search || row.textContent.toLowerCase().includes(search);
        return catMatch && nameMatch;
    });

    // hide all first
    allRows.forEach(r => r.style.display = 'none');

    if (usePaging) {
        // paginate
        const total     = matched.length;
        const totalPages = Math.ceil(total / INV_PAGE_SIZE);
        invCurrentPage  = Math.min(invCurrentPage, totalPages || 1);
        const start     = (invCurrentPage - 1) * INV_PAGE_SIZE;
        const end       = Math.min(start + INV_PAGE_SIZE, total);
        matched.slice(start, end).forEach(r => r.style.display = '');
        renderPagination(total, totalPages, start + 1, end);
    } else {
        // no paging when filtering by category or searching
        matched.forEach(r => r.style.display = '');
        hidePagination();
    }

    const noResults = document.getElementById('inv-no-results');
    if (noResults) noResults.style.display = matched.length === 0 ? 'block' : 'none';
}

function renderPagination(total, totalPages, start, end) {
    const pag   = document.getElementById('inv-pagination');
    const info  = document.getElementById('inv-page-info');
    const pages = document.getElementById('inv-pages');
    if (!pag) return;
    pag.style.display = total > INV_PAGE_SIZE ? 'flex' : 'none';
    info.textContent  = `Showing ${start}–${end} of ${total}`;

    pages.innerHTML = '';

    // prev button
    const prev = document.createElement('button');
    prev.className  = 'inv-page-btn';
    prev.innerHTML  = '&#8249;';
    prev.disabled   = invCurrentPage === 1;
    prev.onclick    = () => goToPage(invCurrentPage - 1);
    pages.appendChild(prev);

    // page number buttons (show max 5 around current)
    const range = 2;
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= invCurrentPage - range && i <= invCurrentPage + range)) {
            const btn = document.createElement('button');
            btn.className  = 'inv-page-btn' + (i === invCurrentPage ? ' active' : '');
            btn.textContent = i;
            btn.onclick    = () => goToPage(i);
            pages.appendChild(btn);
        } else if (
            (i === invCurrentPage - range - 1 && i > 1) ||
            (i === invCurrentPage + range + 1 && i < totalPages)
        ) {
            const dots = document.createElement('button');
            dots.className  = 'inv-page-btn';
            dots.textContent = '…';
            dots.disabled   = true;
            pages.appendChild(dots);
        }
    }

    // next button
    const next = document.createElement('button');
    next.className  = 'inv-page-btn';
    next.innerHTML  = '&#8250;';
    next.disabled   = invCurrentPage === totalPages;
    next.onclick    = () => goToPage(invCurrentPage + 1);
    pages.appendChild(next);
}

function goToPage(page) {
    invCurrentPage = page;
    applyInventoryFilter();
    // scroll table into view
    document.getElementById('inventory-table')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function hidePagination() {
    const pag = document.getElementById('inv-pagination');
    if (pag) pag.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('inventory-search');
    if (searchInput) searchInput.addEventListener('input', applyInventoryFilter);
    applyInventoryFilter(); // ← add this line
});

Object.keys(buttons).forEach(key => {
    if (buttons[key]) buttons[key].addEventListener("click", () => showSection(key));
});

function openHistoryCategory(category) {
    if (isGuest) {
        showToast("Guests cannot edit medicines.", "error");
        return;
    }

    fetch('get_medicine.php?id=' + id)
    .then(r => r.json())
    .then(data => {
        document.getElementById('edit_id').value = data.id;
        document.getElementById('edit_name').value = data.name;
        document.getElementById('edit_type').value = data.type;
        document.getElementById('edit_batch_date').value = data.batch_date;
        document.getElementById('edit_expired_date').value = data.expired_date;
        document.getElementById('edit_quantity').value = data.quantity;
        document.getElementById('editModal').style.display = 'block';
    });
}
function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

function openAddMedicineModal() {
    document.getElementById("addMedicineModal").style.display = "block";
}

function closeAddMedicineModal() {
    document.getElementById("addMedicineModal").style.display = "none";
}

function openDeleteModal(id, name) {
    if (isGuest) {
        showToast("Guests cannot delete medicines.", "error");
        return;
    }
    document.getElementById("deleteMedicineName").textContent = name;
    document.getElementById("confirmDelete").href = "staff_dashboard.php?delete=" + id;
    document.getElementById("deleteModal").style.display = "block";
}

function closeDeleteModal() {
    document.getElementById("deleteModal").style.display = "none";
}

function openCategory(category) {
    document.getElementById("categoryTitle").textContent = category + "";
    document.getElementById("categoryMedicines").innerHTML = "<p>Loading...</p>";
    fetch("get_medicines_by_category.php?category=" + encodeURIComponent(category))
    .then(response => response.text())
    .then(data => document.getElementById("categoryMedicines").innerHTML = data);
    document.getElementById("categoryModal").style.display = "block";
}
function closeCategoryModal() {
    document.getElementById("categoryModal").style.display = "none";
}

function filterExpiryTable() {
    const category = document.getElementById('expiry-category-filter').value;
    const rows = document.querySelectorAll("#expiry-full-table tbody tr");
    rows.forEach(row => {
        const typeCell = row.cells[2]; // "Type" column
        row.style.display = !category || typeCell.textContent === category ? "" : "none";
    });
}

function openHistoryCategory(category) {
    document.getElementById('historyCategoryTitle').textContent = `Expired: ${category}`;
    document.getElementById('historyMedicines').innerHTML = '<p>Loading...</p>';
    document.getElementById('historyModal').style.display = 'block';
    fetch('history.php?action=get_category&category=' + encodeURIComponent(category))
    .then(r => r.text())
    .then(html => document.getElementById('historyMedicines').innerHTML = html);
}
function closeHistoryModal() {
    document.getElementById('historyModal').style.display = 'none';
}

function loadHistoryCategories() {
    fetch('history.php?action=get_counts')
    .then(r => r.json())
    .then(data => {
        const container = document.getElementById('history-categories');
        container.innerHTML = '';
        Object.keys(data).forEach(cat => {
            const count = data[cat];
            const card = document.createElement('div');
            card.className = 'category-card';
            card.style.background = '#e53935';
            card.onclick = () => openHistoryCategory(cat);
            card.innerHTML = `<h3>${cat}</h3>${count ? `<span class="category-badge">${count}</span>` : ''}`;
            container.appendChild(card);
        });
    });
}

function openModal() { document.getElementById("notificationModal").style.display = "block"; }
function closeModal() { document.getElementById("notificationModal").style.display = "none"; }

function showToast(message, type = "success") {
    const container = document.getElementById("toast-container");
    const toast = document.createElement("div");
    toast.className = `toast ${type}`;
    toast.innerHTML = message;
    container.appendChild(toast);
    setTimeout(() => toast.classList.add("show"), 100);
    setTimeout(() => {
        toast.classList.remove("show");
        setTimeout(() => toast.remove(), 400);
    }, 7000);
}

// Chatbot
const chatHead = document.getElementById("chatHead");
const chatContainer = document.getElementById("chatContainer");
const chatClose = document.getElementById("chatClose");
const chatbox = document.getElementById("chatbox");
const chatInput = document.getElementById("chatMessage");
chatHead.addEventListener("click", () => {
    chatContainer.style.display = "flex";
    chatHead.style.display = "none";
});
chatClose.addEventListener("click", () => {
    chatContainer.style.display = "none";
    chatHead.style.display = "flex";
});
function appendMsg(sender, text) {
    const msgDiv = document.createElement("div");
    msgDiv.classList.add("message", sender);
    const avatar = document.createElement("img");
    avatar.className = "avatar";
    avatar.src = sender === "user"
    ? "https://ui-avatars.com/api/?name=You&background=6c757d&color=fff"
    : "https://ui-avatars.com/api/?name=MedBot&background=007BFF&color=fff";
    avatar.alt = sender;
    const textDiv = document.createElement("div");
    textDiv.className = "message-text";
    textDiv.textContent = text;
    msgDiv.appendChild(avatar);
    msgDiv.appendChild(textDiv);
    chatbox.appendChild(msgDiv);
    chatbox.scrollTop = chatbox.scrollHeight;
}
function sendChatbotMessage() {
    const message = chatInput.value.trim();
    if (!message) return;
    appendMsg("user", message);
    chatInput.value = "";
    fetch('chatbot.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'message=' + encodeURIComponent(message)
    }).then(r => r.json()).then(data => appendMsg("bot", data.reply));
}
chatInput.addEventListener("keypress", e => { if (e.key === "Enter") sendChatbotMessage(); });

const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
if (SpeechRecognition) {
    const recognition = new SpeechRecognition();
    recognition.lang = 'en-US';
    document.getElementById("micBtn").addEventListener("click", () => {
        document.getElementById("micBtn").innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        recognition.start();
    });
    recognition.addEventListener("result", e => chatInput.value = e.results[0][0].transcript);
    recognition.addEventListener("end", () => {
        document.getElementById("micBtn").innerHTML = '<i class="fas fa-microphone"></i>';
        sendChatbotMessage();
    });
    recognition.onerror = () => {
        document.getElementById("micBtn").innerHTML = '<i class="fas fa-microphone"></i>';
        appendMsg("bot", "🎙️ Voice error. Try again.");
    };
}

function printReport(tableId) {
    const printContent = document.getElementById(tableId).outerHTML;
    const originalContent = document.body.innerHTML;
    document.body.innerHTML = `
    <h2>BENE MediCon - Expiration Inventory Report</h2>
    <p><strong>Generated on:</strong> ${new Date().toLocaleString()}</p>
    ${printContent}
    `;
    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}

function toggleProfileMenu() {
    const dropdown = document.getElementById('profileDropdown');
    dropdown.classList.toggle('show');
    document.addEventListener('click', function closeDropdown(e) {
        const profile = document.querySelector('.profile-menu');
        if (profile && !profile.contains(e.target)) {
            dropdown.classList.remove('show');
            document.removeEventListener('click', closeDropdown);
        }
    });
}

function openLogoutModal() {
    document.getElementById('logoutModal').style.display = 'block';
    document.getElementById('profileDropdown').classList.remove('show');
}
function closeLogoutModal() {
    document.getElementById('logoutModal').style.display = 'none';
}

function showDonateTable() {
    document.getElementById('donate-table').style.display = 'table';
    document.getElementById('dispose-table').style.display = 'none';
    document.getElementById('showDonateBtn').style.background = '#7b1fa2';
    document.getElementById('showDisposeBtn').style.background = '#e53935';
}

function showDisposeTable() {
    document.getElementById('donate-table').style.display = 'none';
    document.getElementById('dispose-table').style.display = 'table';
    document.getElementById('showDisposeBtn').style.background = '#c62828';
    document.getElementById('showDonateBtn').style.background = '#9c27b0';
}

function openDisposalModal(medId, medName) {
    if (isGuest) {
        showToast("Guests cannot dispose medicines.", "error");
        return;
    }
    document.getElementById('disposalMedId').value = medId;
    document.getElementById('disposalMedName').textContent = medName;
    document.getElementById('disposalModal').style.display = 'block';
}
function closeDisposalModal() {
    document.getElementById('disposalModal').style.display = 'none';
}
</script>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
<div class="modal-content" style="max-width: 400px;">
<div class="modal-header">
<h3 style="color: #e53935;">⚠️ Delete Medicine</h3>
<span class="modal-close" onclick="closeDeleteModal()">&times;</span>
</div>
<div class="modal-body">
<p style="margin-bottom: 20px;">Are you sure you want to delete "<span id="deleteMedicineName"></span>"?</p>
<div style="display: flex; gap: 10px; justify-content: flex-end;">
<button onclick="closeDeleteModal()" style="padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; background: #9e9e9e; color: white;">Cancel</button>
<a id="confirmDelete" href="#" style="padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; background: #e53935; color: white; text-decoration: none;">Delete</a>
</div>
</div>
</div>
</div>

<!-- Toast container -->
<div id="toast-container"></div>
<style>
#toast-container {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 9999;
}
.toast {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 250px;
    max-width: 350px;
    margin-top: 10px;
    padding: 15px 20px;
    border-radius: 8px;
    font-family: Arial, sans-serif;
    font-size: 14px;
    color: #fff;
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    animation: slideIn 0.5s, fadeOut 0.5s 6.5s forwards;
}
.toast.success { background: #28a745; }
.toast.error { background: #dc3545; }
.toast.info { background: #17a2b8; }
.toast.warning { background: #ffc107; color: #333; }
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@keyframes fadeOut {
    to { opacity: 0; transform: translateX(100%); }
}
</style>
<script>
function showToast(message, type = "info") {
    const container = document.getElementById("toast-container");
    const toast = document.createElement("div");
    toast.className = `toast ${type}`;
    toast.innerHTML = message;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 7000);
}
</script>
</body>
</html>
<?php $conn->close(); ?>
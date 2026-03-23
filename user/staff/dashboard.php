<?php
session_start();
date_default_timezone_set('Asia/Manila');

// Database connection
include '../../db.php';

// Access control: Only allow staff and guests
// Redirect guests to a limited dashboard
// Notifies new Staff to change password and set security question
$userId = $_SESSION['user_id'] ?? null;
if ($userId) {
  $stmt = $conn->prepare('SELECT username, role, profile_pic, force_password_change, force_security_setup FROM users WHERE id = ?');
  $stmt->bind_param('i', $userId);
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
  $stmt = $conn->prepare('SELECT force_password_change, force_security_setup FROM users WHERE id = ?');
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $result = $stmt->get_result();
  if ($row = $result->fetch_assoc()) {
    $forcePasswordChange = ($row['force_password_change'] == 1);
    $forceSecuritySetup = ($row['force_security_setup'] == 1);
  }
  $stmt->close();
}

$isGuest = ($_SESSION['role'] ?? '') === 'guest';

// Donation Request Handler
if (isset($_GET['donate']) && $userId) {
  if ($isGuest) {
    $_SESSION['toast'] = ['message' => '⚠️ Guests cannot submit donation requests.', 'type' => 'error'];
    header('Location: dashboard.php?page=donate');
    exit();
  }

  $medicineId = (int) $_GET['donate'];
  $today = date('Y-m-d');
  $tenMonths = date('Y-m-d', strtotime('+10 months'));
  $twelveMonths = date('Y-m-d', strtotime('+12 months'));

  // Only allow donation if expiry is >10 months AND ≤12 months from today
  $medCheck = $conn->prepare('
        SELECT name, expired_date
        FROM medicines
        WHERE id = ?
          AND expired_date > ?
          AND expired_date <= ?
    ');
  $medCheck->bind_param('iss', $medicineId, $tenMonths, $twelveMonths);
  $medCheck->execute();
  $med = $medCheck->get_result()->fetch_assoc();

  if ($med) {
    // Check if already requested
    $existing = $conn->prepare("SELECT id FROM donation_requests WHERE medicine_id = ? AND staff_id = ? AND status = 'pending'");
    $existing->bind_param('ii', $medicineId, $userId);
    $existing->execute();
    if ($existing->get_result()->num_rows > 0) {
      $_SESSION['toast'] = ['message' => "⚠️ You already have a pending request for {$med['name']}.", 'type' => 'warning'];
    } else {
      $stmt = $conn->prepare('INSERT INTO donation_requests (medicine_id, staff_id) VALUES (?, ?)');
      $stmt->bind_param('ii', $medicineId, $userId);
      if ($stmt->execute()) {
        // Notify all admins
        $notify = $conn->prepare("
                    INSERT INTO notifications (user_id, message, is_read, created_at)
                    SELECT id, CONCAT(?, ' requested donation for medicine \"', ?, '\".'), 0, NOW()
                    FROM users WHERE role = 'admin'
                ");
        $notify->bind_param('ss', $_SESSION['username'], $med['name']);
        $notify->execute();
        $_SESSION['toast'] = ['message' => "✅ Donation request sent for {$med['name']}!", 'type' => 'success'];
      } else {
        $_SESSION['toast'] = ['message' => '❌ Failed to send request.', 'type' => 'error'];
      }
    }
  } else {
    $_SESSION['toast'] = ['message' => '⚠️ Only medicines expiring in 10–12 months are eligible for donation.', 'type' => 'warning'];
  }
  header('Location: dashboard.php?page=donate');
  exit();
}

// Disposal Request Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_disposal']) && $userId) {
  if ($isGuest) {
    $_SESSION['toast'] = ['message' => '⚠️ Guests cannot dispose of items.', 'type' => 'error'];
    header('Location: dashboard.php?page=donate');
    exit();
  }

  $medicineId = (int) $_POST['medicine_id'];
  $disposalMethod = trim($_POST['disposal_method']);

  if (empty($disposalMethod)) {
    $_SESSION['toast'] = ['message' => '⚠️ Please specify how you will dispose of the item.', 'type' => 'warning'];
    header('Location: dashboard.php?page=donate');
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
  $medCheck->bind_param('iss', $medicineId, $today, $tenMonths);
  $medCheck->execute();
  $med = $medCheck->get_result()->fetch_assoc();

  if (!$med) {
    $_SESSION['toast'] = ['message' => '⚠️ This item is not eligible for disposal.', 'type' => 'warning'];
    header('Location: dashboard.php?page=donate');
    exit();
  }

  // Start transaction
  $conn->autocommit(FALSE);

  try {
    // 1. Insert into disposal_requests
    $stmt = $conn->prepare('INSERT INTO disposal_requests (medicine_id, staff_id, disposal_method, disposed_at) VALUES (?, ?, ?, NOW())');
    $stmt->bind_param('iis', $medicineId, $userId, $disposalMethod);
    $stmt->execute();

    // 2. Mark medicine as disposed
    $update = $conn->prepare("UPDATE medicines SET status = 'disposed', last_updated = NOW() WHERE id = ?");
    $update->bind_param('i', $medicineId);
    $update->execute();

    // 3. Notify all admins about the disposal
    $notify = $conn->prepare("
            INSERT INTO notifications (user_id, message, is_read, created_at)
            SELECT id, CONCAT(?, ' has disposed of medicine \"', ?, '\".'), 0, NOW()
            FROM users WHERE role = 'admin'
        ");
    $notify->bind_param('ss', $_SESSION['username'], $med['name']);
    $notify->execute();

    // 4. Log the action in logs table
    $logMsg = "Staff {$_SESSION['username']} disposed of \"{$med['name']}\" via: " . substr($disposalMethod, 0, 100);
    $logStmt = $conn->prepare("INSERT INTO logs (user, action) VALUES ('admin', ?)");
    $logStmt->bind_param('s', $logMsg);
    $logStmt->execute();
    $logStmt->close();

    $conn->commit();
    $_SESSION['toast'] = ['message' => "✅ Disposal recorded for {$med['name']}!", 'type' => 'success'];
  } catch (Exception $e) {
    $conn->rollback();
    $_SESSION['toast'] = ['message' => '❌ Failed to process disposal.', 'type' => 'error'];
  }

  $conn->autocommit(TRUE);
  header('Location: dashboard.php?page=donate');
  exit();
}

// Fetch categories from database
$categoryResult = $conn->query('SELECT name FROM categories ORDER BY id');
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
  $stmt = $conn->prepare('SELECT COUNT(*) AS count FROM medicines WHERE type = ? AND expired_date <= CURDATE() + INTERVAL 1 DAY AND expired_date >= CURDATE()');
  $stmt->bind_param('s', $cat);
  $stmt->execute();
  $result_count = $stmt->get_result()->fetch_assoc();
  $expiring_counts[$cat] = (int) $result_count['count'];
  $stmt->close();
}
if (isset($_SESSION['toast'])) {
  $msg = addslashes($_SESSION['toast']['message']);
  $type = $_SESSION['toast']['type'];
  echo "<script>document.addEventListener('DOMContentLoaded', () => showToast('$msg', '$type'));</script>";
  unset($_SESSION['toast']);
}
// Add Medicine
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_medicine'])) {
  if ($isGuest) {
    $_SESSION['toast'] = ['message' => '⚠️ Guests cannot add medicines.', 'type' => 'error'];
    header('Location: dashboard.php?section=inventory');
    exit();
  }

  $name = $_POST['name'];
  $type = trim($_POST['type']);
  $batch_date = $_POST['batch_date'];
  $expired_date = $_POST['expired_date'];
  $target_dir = '../../uploads/medicines/';
  if (!is_dir($target_dir))
    mkdir($target_dir, 0777, true);
  if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['toast'] = ['message' => 'Upload failed with error code ' . $_FILES['image']['error'], 'type' => 'error'];
    header('Location: dashboard.php?section=inventory');
    exit();
  }
  $image = basename($_FILES['image']['name']);
  $target_file = $target_dir . $image;
  $quantity = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 100;
  if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
    $sql = "INSERT INTO medicines (image, name, type, batch_date, expired_date, quantity, last_updated)
        VALUES ('$image', '$name', '$type', '$batch_date', '$expired_date', $quantity, NOW())";
    if ($conn->query($sql) === TRUE) {
      $_SESSION['toast'] = ['message' => 'Medicine added successfully!', 'type' => 'success'];
    } else {
      $_SESSION['toast'] = ['message' => 'Error: ' . $conn->error, 'type' => 'error'];
    }
  } else {
    $_SESSION['toast'] = ['message' => 'Failed to upload image.', 'type' => 'error'];
  }
  header('Location: dashboard.php?section=inventory');
  exit();
}
// Update Medicine
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_medicine'])) {
  if ($isGuest) {
    $_SESSION['toast'] = ['message' => '⚠️ Guests cannot edit medicines.', 'type' => 'error'];
    header('Location: dashboard.php?section=inventory');
    exit();
  }

  $id = $_POST['id'];
  $name = $_POST['name'];
  $type = $_POST['type'];
  $batch_date = $_POST['batch_date'];
  $expired_date = $_POST['expired_date'];
  $image_query = '';
  $quantity = (int) $_POST['quantity'];
  if (!empty($_FILES['image']['name'])) {
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
      $_SESSION['toast'] = ['message' => 'Upload failed with error code ' . $_FILES['image']['error'], 'type' => 'error'];
      header('Location: dashboard.php?section=inventory');
      exit();
    }
    $target_dir = '../../uploads/medicines/';
    $image = basename($_FILES['image']['name']);
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
    $_SESSION['toast'] = ['message' => 'Medicine updated successfully!', 'type' => 'success'];
  } else {
    $_SESSION['toast'] = ['message' => 'Failed to update: ' . $conn->error, 'type' => 'error'];
  }
  header('Location: dashboard.php?section=inventory');
  exit();
}

// Helper: determine unit label based on medicine category
function getMedicineUnit(string $type): string
{
  $liquidTypes = ['Injection', 'Antiseptic', 'Syrup', 'Solution', 'Drops', 'Suspension'];
  return in_array($type, $liquidTypes, true) ? 'mL' : 'pcs';
}

// Adjust Stock (Add or Use)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['adjust_stock'])) {
  if ($isGuest) {
    $_SESSION['toast'] = ['message' => '⚠️ Guests cannot adjust stock.', 'type' => 'error'];
    header('Location: dashboard.php?section=inventory');
    exit();
  }

  $id = (int) $_POST['id'];
  $change = (int) $_POST['change'];
  $action = $_POST['action'];
  $result = $conn->query("SELECT name, quantity, type FROM medicines WHERE id = $id");
  if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $old_quantity = $row['quantity'];
    $unit = getMedicineUnit($row['type']);
    if ($action === 'use' && $change > $old_quantity) {
      $_SESSION['toast'] = ['message' => "❌ Cannot use $change {$unit}. Only {$old_quantity} {$unit} available.", 'type' => 'error'];
      header('Location: dashboard.php?section=inventory');
      exit();
    }
    $new_quantity = ($action === 'use') ? $old_quantity - $change : $old_quantity + $change;
    $verb = ($action === 'use') ? 'used' : 'added';
    $conn->query("UPDATE medicines SET quantity = $new_quantity, last_updated = NOW() WHERE id = $id");
    $_SESSION['toast'] = ['message' => "✅ {$change} {$unit} $verb — {$row['name']}. Stock: {$old_quantity} → {$new_quantity} {$unit}", 'type' => 'success'];
    header('Location: dashboard.php?section=inventory');
    exit();
  }
}
// Delete Medicine
if (isset($_GET['delete'])) {
  if ($isGuest) {
    $_SESSION['toast'] = ['message' => '⚠️ Guests cannot delete medicines.', 'type' => 'error'];
    header('Location: dashboard.php?section=inventory');
    exit();
  }

  $id = $_GET['delete'];
  $current_time = date('Y-m-d H:i:s');
  $conn->query("UPDATE medicines SET last_updated = '$current_time' WHERE 1");
  $result = $conn->query("SELECT image FROM medicines WHERE id = $id");
  if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $image_path = '../../uploads/medicines/' . $row['image'];
    if (file_exists($image_path))
      unlink($image_path);
  }
  $conn->query("DELETE FROM medicines WHERE id = $id");
  $_SESSION['toast'] = ['message' => 'Medicine deleted successfully!', 'type' => 'success'];
  header('Location: dashboard.php?section=inventory');
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

// FUNCTION: Log expired medicines automatically
function logExpiredMedicines($conn)
{
  $today = date('Y-m-d');
  $stmt = $conn->prepare("
    SELECT id, name, type, batch_date, expired_date, quantity, image
    FROM medicines
    WHERE expired_date < ? AND status != 'inactive'
    ");
  $stmt->bind_param('s', $today);
  $stmt->execute();
  $result = $stmt->get_result();

  while ($row = $result->fetch_assoc()) {
    // ✅ Insert into expired_logs WITH medicine_id
    // ❌ DO NOT insert into 'id' — let it auto-increment!
    $insert = $conn->prepare('
        INSERT INTO expired_logs
        (medicine_id, name, type, batch_date, expired_date, quantity_at_expiry, image, recorded_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ');
    $insert->bind_param(
      'issssis',
      $row['id'],  // Goes into medicine_id
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
    $update->bind_param('i', $row['id']);
    $update->execute();
    $update->close();
  }
  $stmt->close();
}

logExpiredMedicines($conn);
$result = $conn->query('SELECT * FROM medicines');
$expiring_meds = $conn->query('SELECT * FROM medicines WHERE expired_date <= CURDATE() + INTERVAL 1 DAY AND expired_date >= CURDATE()');
$expired_count = $expiring_meds->num_rows;
$low_stock_count = 0;
$result_low = $conn->query('SELECT quantity, expired_date FROM medicines');
while ($row = $result_low->fetch_assoc()) {
  $exp = new DateTime($row['expired_date']);
  $today = new DateTime();
  if ($exp >= $today && $row['quantity'] <= 20) {
    $low_stock_count++;
  }
}
$last_updated_query = $conn->query('SELECT MAX(last_updated) as latest_update FROM medicines');
$last_updated = $last_updated_query->fetch_assoc()['latest_update'];
$formatted_date = $last_updated ? date('M d, Y g:i A', strtotime($last_updated)) : 'No updates';

// Unread notifications count for current user
$unreadCount = 0;
if ($userId) {
  $unreadRes = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = $userId AND is_read = 0");
  if ($unreadRes)
    $unreadCount = (int) $unreadRes->fetch_assoc()['c'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Staff Dashboard | BENE MediCon</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="../../styles/s_dashboard.css">
  <link rel="stylesheet" href="../../styles/s_dashboard_extra.css">
</head>

<body>
  <!-- Logout Confirmation Modal -->
  <div id="logoutModal" class="modal" style="display:none;">
    <div class="modal-content"
      style="max-width: 400px; border-radius: 16px; padding: 1.6rem; box-shadow: 0 20px 60px rgba(0,0,0,0.25);">
      <div class="modal-header"
        style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;padding-bottom:0.75rem;border-bottom:1px solid #e5e7eb;">
        <h3 style="font-family:'EB Garamond',serif;font-size:1.15rem;color:#5c0a0a;"><i class="fas fa-sign-out-alt"
            style="color:#c62828;margin-right:8px;"></i> Confirm Logout</h3>
        <button onclick="closeLogoutModal()"
          style="width:28px;height:28px;border-radius:6px;background:#f3f4f6;border:none;cursor:pointer;font-size:1rem;color:#6b7280;">&times;</button>
      </div>
      <p style="font-size:0.88rem;color:#374151;margin-bottom:1.2rem;">Are you sure you want to log out?</p>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button onclick="closeLogoutModal()"
          style="height:36px;padding:0 14px;background:#6b7280;color:#fff;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.82rem;font-weight:500;cursor:pointer;">Cancel</button>
        <button onclick="window.location.href='../../logout.php'"
          style="height:36px;padding:0 14px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.82rem;font-weight:500;cursor:pointer;"><i
            class="fas fa-sign-out-alt"></i> Yes, Logout</button>
      </div>
    </div>
  </div>

  <!-- Sidebar -->
  <div class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <button class="hamburger-btn" id="hamburger">
        <svg class="hamburger-svg" viewBox="0 0 24 24">
          <line class="line-1" x1="4" y1="6" x2="20" y2="6" />
          <line class="line-2" x1="4" y1="12" x2="20" y2="12" />
          <line class="line-3" x1="4" y1="18" x2="20" y2="18" />
        </svg>
      </button>
      <span class="sidebar-brand">BENE <span>MediCon</span></span>
    </div>
    <nav>
      <div class="nav-section-label">Overview</div>
      <button class="nav-item active" id="btn-dashboard"><i
          class="fas fa-tachometer-alt"></i><span>Dashboard</span></button>

      <div class="nav-section-label">Inventory</div>
      <button class="nav-item" id="btn-inventory"><i class="fas fa-boxes"></i><span>Medical Supplies</span></button>
      <button class="nav-item" id="btn-expiration"><i class="fas fa-calendar-times"></i><span>Expiration</span></button>

      <?php if (!$isGuest): ?>
        <div class="nav-section-label">Requests</div>
        <button class="nav-item" id="btn-donate"><i class="fas fa-hand-holding-medical"></i><span>Donate or
            Dispose</span></button>
        <button class="nav-item" id="btn-donation-history"><i class="fas fa-clipboard-list"></i><span>My
            Requests</span></button>

        <div class="nav-section-label">Records</div>
        <button class="nav-item" id="btn-expired-history"><i class="fas fa-history"></i><span>Expired Supply
            History</span></button>
      <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
      <button class="nav-item" onclick="openLogoutModal()" style="color:rgba(255,255,255,0.6);"><i
          class="fas fa-sign-out-alt"></i><span>Logout</span></button>
    </div>
  </div>

  <!-- Topbar -->
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

      <!-- Profile -->
      <?php
      $pic = $_SESSION['profile_pic'] ?? '';
      $role = $_SESSION['role'] ?? 'admin';
      $fallback = $role === 'admin' ? 'A' : ($role === 'guest' ? 'G' : 'S');
      ?>

      <div class="profile-menu">
        <button class="profile-btn" onclick="toggleProfileMenu()" type="button">
          <?php if (!empty($pic) && $pic !== 'default.jpg'): ?>
            <img src="../../uploads/avatars/<?= htmlspecialchars($pic) ?>" alt="Profile" class="profile-avatar"
              style="width:30px;height:30px;border-radius:8px;object-fit:cover;border:2px solid rgba(201,168,76,0.4);"
              onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
            <div class="profile-avatar" style="display:none;"><?= $fallback ?></div>
          <?php else: ?>
            <div class="profile-avatar"><?= $fallback ?></div>
          <?php endif; ?>
          <div class="profile-info">
            <div class="p-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div>
            <div class="p-role"><?= ucfirst($role) ?></div>
          </div>
          <i class="fas fa-chevron-down profile-chevron"></i>
        </button>
        <div class="profile-dropdown" id="profileDropdown">
          <div class="dropdown-header">
            <img src="uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic'] ?? 'default.jpg') ?>" alt=""
              onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
            <img src="../../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic'] ?? 'default.jpg') ?>" alt=""
              style="width:36px;height:36px;border-radius:8px;object-fit:cover;"
              onerror="this.style.display='none'">
            <div>
              <div class="dh-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Staff') ?></div>
              <div class="dh-role"><?= $isGuest ? 'Guest' : 'Staff Member' ?> &mdash; BENE MediCon</div>
            </div>
          </div>
          <?php if (!$isGuest): ?>
            <a href="../edit_profile.php" class="dropdown-item"><i class="fas fa-user-edit"></i> Edit Profile</a>
            <a href="../change_password.php" class="dropdown-item"><i class="fas fa-key"></i> Change Password</a>
          <?php endif; ?>
          <button onclick="openLogoutModal()" class="dropdown-item danger"><i class="fas fa-sign-out-alt"></i>
            Logout</button>
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
      <p>Welcome to the <strong>BENE MediCon Inventory System</strong>. Use the sidebar to manage medicines, check
        expirations, and more.</p>

      <!-- Charts Grid -->
      <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin: 1.4rem 0;">
        <div
          style="background: var(--surface); padding: 16px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.04); height: 280px;">
          <canvas id="categoryChart"></canvas>
        </div>
        <div
          style="background: var(--surface); padding: 16px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.04); height: 280px;">
          <canvas id="expiryChart"></canvas>
        </div>
        <div
          style="background: var(--surface); padding: 16px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.04); height: 280px;">
          <canvas id="stockLevelsChart"></canvas>
        </div>
        <div
          style="background: var(--surface); padding: 16px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 2px 8px rgba(0,0,0,0.04); height: 280px;">
          <canvas id="expiryTrendChart"></canvas>
        </div>
      </div>
      <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
      <script>
        <?php
        $categoryData = [];
        foreach ($categories as $category) {
          $query = $conn->query("SELECT COUNT(*) as count FROM medicines WHERE type='$category'");
          $categoryData[] = $query->fetch_assoc()['count'];
        }
        $today = date('Y-m-d');
        $expired = $conn->query("SELECT COUNT(*) as count FROM medicines WHERE expired_date < '$today'")->fetch_assoc()['count'];
        $valid = $conn->query("SELECT COUNT(*) as count FROM medicines WHERE expired_date >= '$today'")->fetch_assoc()['count'];
        $lowStock = $conn->query('SELECT COUNT(*) as count FROM medicines WHERE quantity <= 20')->fetch_assoc()['count'];
        $normalStock = $conn->query('SELECT COUNT(*) as count FROM medicines WHERE quantity > 20 AND quantity <= 50')->fetch_assoc()['count'];
        $highStock = $conn->query('SELECT COUNT(*) as count FROM medicines WHERE quantity > 50')->fetch_assoc()['count'];
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
        while ($row = $trendQuery->fetch_assoc()) {
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
                '#9b1c1c', '#c62828', '#5c0a0a', '#c9a84c', '#e8c96a', '#7b1010'
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
      <h1>Medical Supplies</h1>

      <!-- Toolbar: Search + Add -->
      <div style="display:flex; gap:10px; align-items:center; margin-bottom:1rem; flex-wrap:wrap;">
        <input type="text" id="inventory-search" placeholder="&#128269; Search by name..." style="flex:1; min-width:180px; height:38px; padding:0 12px;
                  border:1.5px solid var(--border); border-radius:8px;
                  font-family:'DM Sans',sans-serif; font-size:0.88rem; outline:none;
                  transition:border-color 0.2s;" onfocus="this.style.borderColor='var(--red)'"
          onblur="this.style.borderColor='var(--border)'">
        <?php if (!$isGuest): ?>
          <button onclick="openAddMedicineModal()" class="btn btn-add">
            <i class="fas fa-plus"></i> Add Medicine
          </button>
        <?php endif; ?>
      </div>

      <!-- Category Filter Pills -->
      <div id="inv-category-pills" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:1.2rem;">
        <button class="inv-pill active" onclick="filterInventory('all', this)">All</button>
        <?php foreach ($categories as $cat): ?>
          <button class="inv-pill" onclick="filterInventory('<?= addslashes(htmlspecialchars($cat)) ?>', this)">
            <?= htmlspecialchars($cat) ?>
          </button>
        <?php endforeach; ?>
      </div>

      <!-- Unified Table -->
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
              <?php if (!$isGuest): ?>
                <th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php
            $invResult = $conn->query('
            SELECT *,
            CASE WHEN expired_date < CURDATE() THEN 3
                 WHEN quantity <= 20 THEN 1
                 ELSE 2 END AS sort_order
            FROM medicines
            ORDER BY sort_order ASC, expired_date ASC
        ');
            while ($row = $invResult->fetch_assoc()):
              $expDate = new DateTime($row['expired_date']);
              $todayDt = new DateTime();
              $isExpired = $expDate < $todayDt;
              $isLow = !$isExpired && $row['quantity'] <= 20;
              $status = $isExpired
                ? '<span class="badge-expired">&#128308; Expired</span>'
                : ($isLow
                  ? '<span class="badge-low">&#9888; Low Stock</span>'
                  : '<span class="badge-good">&#10003; In Stock</span>');
              $rowClass = $isExpired ? 'expiring-soon' : ($isLow ? 'warning' : '');
              ?>
              <tr class="<?= $rowClass ?>" data-category="<?= htmlspecialchars($row['type']) ?>"
                data-name="<?= strtolower(htmlspecialchars($row['name'])) ?>">
                <td><img src="../../uploads/medicines/<?= htmlspecialchars($row['image']) ?>" width="40" height="40"
                    style="border-radius:6px;object-fit:cover;" alt=""></td>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><span
                    style="background:#fef2f2;color:var(--red-dark);padding:2px 8px;border-radius:10px;font-size:0.75rem;font-weight:600;"><?= htmlspecialchars($row['type']) ?></span>
                </td>
                <td><?= htmlspecialchars($row['batch_date']) ?></td>
                <td><?= htmlspecialchars($row['expired_date']) ?></td>
                <td style="text-align:center;font-weight:600;"><?= (int) $row['quantity'] ?><span style="font-size:0.68rem;font-weight:400;color:#6b7280;margin-left:2px;"><?= getMedicineUnit($row['type']) ?></span></td>
                <td><?= $status ?></td>
                <?php if (!$isGuest): ?>
                  <td>
                    <?php
                    if (!$isExpired):
                      $unit = getMedicineUnit($row['type']);
                      $isLiquid = ($unit === 'mL');
                      $unitIcon = $isLiquid ? '💧' : '💊';
                      $inputWidth = $isLiquid ? '64px' : '52px';
                      ?>
                      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                        <!-- Unit badge -->
                        <span title="Unit: <?= $unit ?>" style="display:inline-flex;align-items:center;gap:3px;background:<?= $isLiquid ? '#e0f2fe' : '#fef2f2' ?>;color:<?= $isLiquid ? '#0369a1' : 'var(--red-dark)' ?>;padding:2px 7px;border-radius:10px;font-size:0.7rem;font-weight:700;letter-spacing:0.03em;">
                          <?= $unitIcon ?> <?= $unit ?>
                        </span>
                        <!-- Add stock (not used for now) -->
                        <!-- <form method="POST" style="display:inline-flex;align-items:center;gap:4px;">
                          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                          <input type="hidden" name="action" value="add">
                          <input type="number" name="change" placeholder="<?= $unit ?>" min="1" required
                            style="width:<?= $inputWidth ?>;height:30px;padding:0 6px;border:1.5px solid var(--border);border-radius:6px;font-size:0.78rem;outline:none;"
                            title="Amount to add in <?= $unit ?>">
                          <button type="submit" name="adjust_stock" class="btn btn-add"
                            style="height:30px;padding:0 8px;font-size:0.75rem;">+ Add</button>
                        </form> -->
                        <!-- Use stock -->
                        <form method="POST" style="display:inline-flex;align-items:center;gap:4px;">
                          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                          <input type="hidden" name="action" value="use">
                          <input type="number" name="change" placeholder="<?= $unit ?>" min="1" required
                            style="width:<?= $inputWidth ?>;height:30px;padding:0 6px;border:1.5px solid var(--border);border-radius:6px;font-size:0.78rem;outline:none;"
                            title="Amount to use in <?= $unit ?>">
                          <button type="submit" name="adjust_stock" class="btn btn-del"
                            style="height:30px;padding:0 8px;font-size:0.75rem;" onclick="return confirm('Use this stock?')">−
                            Use</button>
                        </form>
                        <!-- Edit & Delete -->
                        <button onclick="openEditModal(<?= (int) $row['id'] ?>)" class="btn btn-info"
                          style="height:30px;padding:0 8px;font-size:0.75rem;background:#0288d1;">
                          <i class="fas fa-edit"></i>
                        </button>
                        <button
                          onclick="openDeleteModal(<?= (int) $row['id'] ?>, '<?= addslashes(htmlspecialchars($row['name'])) ?>')"
                          class="btn btn-del" style="height:30px;padding:0 8px;font-size:0.75rem;">
                          <i class="fas fa-trash"></i>
                        </button>
                      </div>
                    <?php else: ?>
                      <button
                        onclick="openDeleteModal(<?= (int) $row['id'] ?>, '<?= addslashes(htmlspecialchars($row['name'])) ?>')"
                        class="btn btn-del" style="height:30px;padding:0 8px;font-size:0.75rem;">
                        <i class="fas fa-trash"></i>
                      </button>
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
      <p id="inv-no-results"
        style="display:none;color:var(--text-muted);text-align:center;padding:1.5rem 0;font-size:0.88rem;">
        No medicines found matching your search.
      </p>

      <!-- Add Modal -->
      <div id="addMedicineModal" class="modal">
        <div class="modal-content" style="max-width:500px;">
          <div class="modal-header">
            <h3><i class="fas fa-plus-circle" style="margin-right:8px;color:var(--red-light);"></i>Add New Medicine</h3>
            <span class="modal-close" onclick="closeAddMedicineModal()">&times;</span>
          </div>
          <div class="modal-body" style="padding-top:1rem;">
            <form method="POST" action="dashboard.php" enctype="multipart/form-data">
              <label
                style="display:block;font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">Medicine
                Name</label>
              <input type="text" name="name" required
                style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;margin-bottom:12px;outline:none;">
              <label
                style="display:block;font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">Category</label>
              <select name="type" id="add-med-type" required onchange="updateAddMedUnit(this)"
                style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;margin-bottom:12px;outline:none;">
                <option value="" disabled selected>Select Category</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= $cat ?>"><?= $cat ?></option>
                <?php endforeach; ?>
              </select>
              <label
                style="display:block;font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">Batch
                Date</label>
              <input type="date" name="batch_date" required
                style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;margin-bottom:12px;outline:none;">
              <label
                style="display:block;font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">Expiration
                Date</label>
              <input type="date" name="expired_date" required
                style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;margin-bottom:12px;outline:none;">
              <label
                style="display:block;font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">
                Quantity <span id="add-med-unit-badge" style="display:none;margin-left:6px;background:#e0f2fe;color:#0369a1;padding:1px 7px;border-radius:8px;font-size:0.68rem;font-weight:700;text-transform:none;letter-spacing:0;">💧 mL</span>
              </label>
              <input type="number" name="quantity" id="add-med-qty" required min="1" value="100" placeholder="e.g. 100"
                style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;margin-bottom:12px;outline:none;">
              <label
                style="display:block;font-size:0.7rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">Image</label>
              <input type="file" name="image" required style="margin-bottom:16px;font-size:0.85rem;">
              <button type="submit" name="add_medicine" class="btn btn-add"
                style="width:100%;height:44px;font-size:0.9rem;">
                <i class="fas fa-pills"></i> Add Medicine
              </button>
            </form>
          </div>
        </div>
      </div>

    </div>

    <div id="content-expiration" class="content">
      <h1>Expiry Tracker</h1>

      <!-- Toolbar: Filters + Actions -->
      <div
        style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:1rem;">

        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
          <!-- Status Pills -->
          <button class="inv-pill active" id="exp-pill-all" onclick="expFilter('all',      this)">All</button>
          <button class="inv-pill" id="exp-pill-expiring" onclick="expFilter('expiring',  this)">Expiring Soon</button>
          <button class="inv-pill" id="exp-pill-expired" onclick="expFilter('expired',   this)">Expired</button>
          <button class="inv-pill" id="exp-pill-low" onclick="expFilter('low',       this)">Low Stock</button>

          <!-- Category Dropdown -->
          <select id="expiry-category-filter" onchange="applyExpiryFilter()" style="height:32px; padding:0 10px; border:1.5px solid var(--border);
                     border-radius:20px; font-family:'DM Sans',sans-serif;
                     font-size:0.8rem; color:var(--text-muted); outline:none; cursor:pointer;">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Print + Export -->
        <div style="display:flex; gap:8px;">
          <button onclick="printReport('expiry-full-table')" class="btn" style="background:#0288d1;">
            <i class="fas fa-print"></i> Print
          </button>
          <a href="export_expiration.php?format=excel" class="btn btn-add">
            <i class="fas fa-file-excel"></i> Export
          </a>
        </div>
      </div>

      <!-- Table -->
      <div class="table-wrap">
        <table id="expiry-full-table">
          <thead>
            <tr>
              <th>Image</th>
              <th>Name</th>
              <th>Category</th>
              <th>Batch Date</th>
              <th>Expiry Date</th>
              <th>Days Left</th>
              <th style="text-align:center;">Qty</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $expResult = $conn->query('
          SELECT *,
            CASE
              WHEN expired_date < CURDATE() THEN 3
              WHEN quantity <= 20            THEN 1
              ELSE 2
            END AS sort_order
          FROM medicines
          WHERE expired_date <= CURDATE() + INTERVAL 7 DAY
          ORDER BY sort_order ASC, expired_date ASC
        ');
            while ($row = $expResult->fetch_assoc()):
              $expiryDate = new DateTime($row['expired_date']);
              $todayDt = new DateTime();
              $isExpired = $expiryDate < $todayDt;
              $interval = $todayDt->diff($expiryDate);
              $daysLeft = $isExpired ? -$interval->days : $interval->days;
              $isLow = !$isExpired && $row['quantity'] <= 20;

              $statusText = $isExpired ? 'Expired' : ($isLow ? 'Low Stock' : 'Valid');
              $statusHtml = $isExpired
                ? '<span class="badge-expired">&#128308; Expired</span>'
                : ($isLow
                  ? '<span class="badge-low">&#9888; Low Stock</span>'
                  : '<span class="badge-good">&#10003; Valid</span>');

              $rowClass = $isExpired ? 'expiring-soon' : ($isLow ? 'warning' : '');
              $daysDisplay = $isExpired
                ? '<span style="color:var(--red-light);font-weight:600;">Expired</span>'
                : ($daysLeft === 0
                  ? '<span style="color:#d97706;font-weight:600;">Today</span>'
                  : '<span style="font-weight:600;">' . $daysLeft . ' day' . ($daysLeft != 1 ? 's' : '') . '</span>');
              ?>
              <tr class="<?= $rowClass ?>" data-status="<?= $isExpired ? 'expired' : ($isLow ? 'low' : 'expiring') ?>"
                data-category="<?= htmlspecialchars($row['type']) ?>">
                <td><img src="../../uploads/medicines/<?= htmlspecialchars($row['image']) ?>" width="44" height="44"
                    style="border-radius:6px;object-fit:cover;" alt=""></td>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><span
                    style="background:#fef2f2;color:var(--red-dark);padding:2px 8px;border-radius:10px;font-size:0.75rem;font-weight:600;"><?= htmlspecialchars($row['type']) ?></span>
                </td>
                <td><?= htmlspecialchars($row['batch_date']) ?></td>
                <td><?= htmlspecialchars($row['expired_date']) ?></td>
                <td><?= $daysDisplay ?></td>
                <td style="text-align:center;font-weight:600;"><?= (int) $row['quantity'] ?></td>
                <td><?= $statusHtml ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>

        <!-- Pagination -->
        <div class="inv-pagination" id="exp-pagination">
          <span id="exp-page-info">Showing 1–10</span>
          <div class="inv-pages" id="exp-pages"></div>
        </div>
      </div>

      <?php if ($expResult->num_rows == 0): ?>
        <p style="color:#059669;font-style:italic;margin-top:1rem;font-size:0.88rem;">
          &#10003; No medicines expiring within 7 days or already expired.
        </p>
      <?php endif; ?>
    </div>


    <!-- Donation Page -->
    <div id="content-donate" class="content">
      <h1>Donate or Dispose</h1>

      <!-- Collapsible Tips -->
      <details style="margin-bottom:1.2rem;">
        <summary style="cursor:pointer;font-size:0.85rem;font-weight:600;color:var(--red-dark);
                    padding:0.7rem 1rem;background:#fdf4f4;border:1px solid #f0d8d8;
                    border-radius:8px;list-style:none;display:flex;align-items:center;gap:8px;">
          <i class="fas fa-info-circle" style="color:var(--red-light);"></i>
          Disposal &amp; Repurposing Tips <span
            style="margin-left:auto;font-size:0.75rem;color:var(--text-muted);">click to expand</span>
        </summary>
        <div
          style="padding:1rem;background:#fdf9f9;border:1px solid #f0d8d8;border-top:none;border-radius:0 0 8px 8px;font-size:0.84rem;color:var(--text-muted);line-height:1.8;">
          <ul style="padding-left:1.2rem;">
            <li><strong class="video-hover" style="color:var(--red-dark);cursor:pointer;"
                data-video="https://www.youtube.com/embed/dzc46-tLwSw?si=1BECjeCXBXFYW9hw">Antibiotic / Pain Reliever /
                Vitamins</strong>: Mix with coffee grounds or cat litter, seal in bag, dispose in trash.</li>
            <li><strong class="video-hover" style="color:var(--red-dark);cursor:pointer;"
                data-video="https://www.youtube.com/embed/_2rIPBO8oQQ?si=_X8IFelf1WzJs45_">Injection</strong>: Use
              sharps container. Do not reuse.</li>
            <li><strong class="video-hover" style="color:var(--red-dark);cursor:pointer;"
                data-video="https://www.youtube.com/embed/wTIhYUWX-p0?si=WBMFNMK99YUOMTrk">Antiseptic (liquid)</strong>:
              Small amounts down drain. Bottles → rinse &amp; recycle.</li>
            <li><strong class="video-hover" style="color:var(--red-dark);cursor:pointer;"
                data-video="https://www.youtube.com/embed/WcYEbAc4Cl0?si=gEAxWnzTFrWy6WhP">Other / Bottles</strong>:
              Rinse thoroughly. Recycle per local rules.</li>
          </ul>
          <p style="margin-top:0.6rem;font-size:0.78rem;color:#b45309;">&#9888; Always follow local regulations for
            medical waste disposal.</p>
        </div>
      </details>

      <!-- Sub-tab Pills -->
      <div style="display:flex;gap:8px;margin-bottom:1.2rem;">
        <button class="inv-pill active" id="donate-pill" onclick="switchDonateView('donate', this)">
          <i class="fas fa-gift" style="margin-right:5px;"></i>Donate <span
            style="font-size:0.7rem;opacity:0.75;">(10–12 mo)</span>
        </button>
        <button class="inv-pill" id="dispose-pill" onclick="switchDonateView('dispose', this)">
          <i class="fas fa-trash-alt" style="margin-right:5px;"></i>Dispose <span
            style="font-size:0.7rem;opacity:0.75;">(≤10 mo)</span>
        </button>
      </div>

      <!-- Donation Table -->
      <div id="donate-view">
        <div class="table-wrap">
          <table id="donate-table">
            <thead>
              <tr>
                <th>Image</th>
                <th>Name</th>
                <th>Category</th>
                <th>Expiry Date</th>
                <th>Time Left</th>
                <th style="text-align:center;">Qty</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $today = date('Y-m-d');
              $twelveMonths = date('Y-m-d', strtotime('+12 months'));
              $tenMonths = date('Y-m-d', strtotime('+10 months'));
              $donateQuery = $conn->query("SELECT * FROM medicines WHERE expired_date > '$tenMonths' AND expired_date <= '$twelveMonths' ORDER BY expired_date ASC");
              if ($donateQuery && $donateQuery->num_rows > 0):
                while ($med = $donateQuery->fetch_assoc()):
                  $expDate = new DateTime($med['expired_date']);
                  $now = new DateTime();
                  $interval = $now->diff($expDate);
                  $totalMonths = $interval->y * 12 + $interval->m;
                  $days = $interval->d;
                  $displayTime = "$totalMonths mo" . ($days > 0 ? " $days d" : '');
                  $pendingCheck = $conn->prepare("SELECT id FROM donation_requests WHERE medicine_id = ? AND staff_id = ? AND status = 'pending'");
                  $pendingCheck->bind_param('ii', $med['id'], $userId);
                  $pendingCheck->execute();
                  $isPending = $pendingCheck->get_result()->num_rows > 0;
                  $pendingCheck->close();
                  ?>
                  <tr>
                    <td><img src="../../uploads/medicines/<?= htmlspecialchars($med['image']) ?>" width="44" height="44"
                        style="border-radius:6px;object-fit:cover;" alt=""></td>
                    <td><?= htmlspecialchars($med['name']) ?></td>
                    <td><span
                        style="background:#fef2f2;color:var(--red-dark);padding:2px 8px;border-radius:10px;font-size:0.75rem;font-weight:600;"><?= htmlspecialchars($med['type']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($med['expired_date']) ?></td>
                    <td style="color:#7b1fa2;font-weight:600;"><?= $displayTime ?></td>
                    <td style="text-align:center;font-weight:600;"><?= (int) $med['quantity'] ?></td>
                    <td>
                      <?php if ($isPending): ?>
                        <span class="btn btn-grey" style="cursor:not-allowed;opacity:0.7;" title="Already pending">
                          <i class="fas fa-clock"></i> Pending
                        </span>
                      <?php else: ?>
                        <a href="dashboard.php?donate=<?= (int) $med['id'] ?>" class="btn btn-purple"
                          onclick="return confirm('Request donation for &quot;<?= htmlspecialchars($med['name']) ?>&quot;?')">
                          <i class="fas fa-gift"></i> Donate
                        </a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endwhile;
              else: ?>
                <tr>
                  <td colspan="7" style="text-align:center;color:var(--text-muted);padding:1.5rem;">No supplies eligible
                    for donation.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Disposal Table -->
      <div id="dispose-view" style="display:none;">
        <div class="table-wrap">
          <table id="dispose-table">
            <thead>
              <tr>
                <th>Image</th>
                <th>Name</th>
                <th>Category</th>
                <th>Expiry Date</th>
                <th>Time Left</th>
                <th style="text-align:center;">Qty</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $disposeQuery = $conn->query("SELECT * FROM medicines WHERE expired_date > '$today' AND expired_date <= '$tenMonths' AND status != 'disposed' ORDER BY expired_date ASC");
              if ($disposeQuery && $disposeQuery->num_rows > 0):
                while ($med = $disposeQuery->fetch_assoc()):
                  $expDate = new DateTime($med['expired_date']);
                  $now = new DateTime();
                  $interval = $now->diff($expDate);
                  $totalMonths = $interval->y * 12 + $interval->m;
                  $days = $interval->d;
                  $displayTime = "$totalMonths mo" . ($days > 0 ? " $days d" : '');
                  $isUrgent = ($totalMonths == 0 && $days <= 1);
                  ?>
                  <tr>
                    <td><img src="../../uploads/medicines/<?= htmlspecialchars($med['image']) ?>" width="44" height="44"
                        style="border-radius:6px;object-fit:cover;" alt=""></td>
                    <td><?= htmlspecialchars($med['name']) ?></td>
                    <td><span
                        style="background:#fef2f2;color:var(--red-dark);padding:2px 8px;border-radius:10px;font-size:0.75rem;font-weight:600;"><?= htmlspecialchars($med['type']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($med['expired_date']) ?></td>
                    <td style="color:<?= $isUrgent ? 'var(--red-light)' : '#d97706' ?>;font-weight:600;"><?= $displayTime ?>
                    </td>
                    <td style="text-align:center;font-weight:600;"><?= (int) $med['quantity'] ?></td>
                    <td>
                      <button class="btn btn-del"
                        onclick="openDisposalModal(<?= (int) $med['id'] ?>, '<?= addslashes(htmlspecialchars($med['name'])) ?>')">
                        <i class="fas fa-trash-alt"></i> Dispose
                      </button>
                    </td>
                  </tr>
                <?php endwhile;
              else: ?>
                <tr>
                  <td colspan="7" style="text-align:center;color:var(--text-muted);padding:1.5rem;">No supplies eligible
                    for disposal.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>



    <!-- Popup Video Container -->
    <div id="videoPopup" class="video-popup">
      <iframe id="popupFrame" src="" frameborder="0" allowfullscreen></iframe>
    </div>














    <!-- My Requests -->
    <div id="content-donation-history" class="content">
      <h1>My Requests</h1>

      <!-- Sub-tab Pills -->
      <div style="display:flex;gap:8px;margin-bottom:1.2rem;">
        <button class="inv-pill active" id="req-pill-donations" onclick="switchRequestView('donations', this)">
          <i class="fas fa-gift" style="margin-right:5px;"></i>Donation Requests
        </button>
        <button class="inv-pill" id="req-pill-disposals" onclick="switchRequestView('disposals', this)">
          <i class="fas fa-trash-alt" style="margin-right:5px;"></i>Disposal Records
        </button>
      </div>

      <!-- Donation Requests -->
      <div id="req-view-donations">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Medicine</th>
                <th>Category</th>
                <th>Requested On</th>
                <th>Status</th>
                <th>Admin Response</th>
              </tr>
            </thead>
            <tbody>
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
                  switch ($req['status']) {
                    case 'approved':
                      $statusHtml = '<span class="badge-approved"><i class="fas fa-check"></i> Approved</span>';
                      break;
                    case 'rejected':
                      $statusHtml = '<span class="badge-expired"><i class="fas fa-times"></i> Rejected</span>';
                      break;
                    default:
                      $statusHtml = '<span class="badge-low"><i class="fas fa-clock"></i> Pending</span>';
                  }
                  ?>
                  <tr>
                    <td><?= htmlspecialchars($req['med_name']) ?></td>
                    <td><span
                        style="background:#fef2f2;color:var(--red-dark);padding:2px 8px;border-radius:10px;font-size:0.75rem;font-weight:600;"><?= htmlspecialchars($req['med_type']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($req['requested_at']) ?></td>
                    <td><?= $statusHtml ?></td>
                    <td style="font-size:0.82rem;color:var(--text-muted);">
                      <?= $req['status'] !== 'pending' ? 'Responded: ' . htmlspecialchars($req['approved_at']) : '<em>Awaiting review</em>' ?>
                    </td>
                  </tr>
                <?php endwhile;
              else: ?>
                <tr>
                  <td colspan="5" style="text-align:center;color:var(--text-muted);padding:1.5rem;">No donation requests
                    yet.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Disposal Records -->
      <div id="req-view-disposals" style="display:none;">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Medicine</th>
                <th>Category</th>
                <th>Disposed On</th>
                <th>Method</th>
              </tr>
            </thead>
            <tbody>
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
                    <td><span
                        style="background:#fef2f2;color:var(--red-dark);padding:2px 8px;border-radius:10px;font-size:0.75rem;font-weight:600;"><?= htmlspecialchars($req['med_type']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($req['disposed_at']) ?></td>
                    <td style="max-width:300px;word-wrap:break-word;font-size:0.82rem;color:var(--text-muted);">
                      <?= nl2br(htmlspecialchars($req['disposal_method'])) ?>
                    </td>
                  </tr>
                <?php endwhile;
              else: ?>
                <tr>
                  <td colspan="4" style="text-align:center;color:var(--text-muted);padding:1.5rem;">No disposal records
                    yet.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Expired Supply History -->
    <div id="content-expired-history" class="content">
      <h1>Expired Supply History</h1>
      <p style="color:var(--text-muted);font-size:0.88rem;margin-bottom:1.2rem;">
        A log of all medicines that have expired and been automatically removed from active inventory.
      </p>

      <!-- Toolbar: Search + Category Filter + Export -->
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:1rem;">
        <input type="text" id="exp-history-search" placeholder="&#128269; Search by name..." style="flex:1;min-width:180px;height:38px;padding:0 12px;
                    border:1.5px solid var(--border);border-radius:8px;
                    font-family:'DM Sans',sans-serif;font-size:0.88rem;outline:none;
                    transition:border-color 0.2s;" onfocus="this.style.borderColor='var(--red)'"
          onblur="this.style.borderColor='var(--border)'">

        <select id="exp-history-category" onchange="applyExpiredHistoryFilter()" style="height:38px;padding:0 10px;border:1.5px solid var(--border);
                     border-radius:8px;font-family:'DM Sans',sans-serif;
                     font-size:0.85rem;color:var(--text-muted);outline:none;cursor:pointer;">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
          <?php endforeach; ?>
        </select>

        <a href="export_expired_history.php?format=excel" class="btn btn-add">
          <i class="fas fa-file-excel"></i> Export
        </a>
      </div>

      <!-- Table -->
      <div class="table-wrap">
        <table id="expired-history-table">
          <thead>
            <tr>
              <th>Image</th>
              <th>Name</th>
              <th>Category</th>
              <th>Batch Date</th>
              <th>Expiry Date</th>
              <th style="text-align:center;">Qty at Expiry</th>
              <th>Date Recorded</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $expHistoryResult = $conn->query('
            SELECT * FROM expired_logs
            ORDER BY recorded_at DESC
          ');
            if ($expHistoryResult && $expHistoryResult->num_rows > 0):
              while ($row = $expHistoryResult->fetch_assoc()):
                ?>
                <tr data-name="<?= strtolower(htmlspecialchars($row['name'])) ?>"
                  data-category="<?= htmlspecialchars($row['type']) ?>">
                  <td>
                    <img src="../../uploads/medicines/<?= htmlspecialchars($row['image']) ?>" width="44" height="44"
                      style="border-radius:6px;object-fit:cover;" onerror="this.style.display='none'" alt="">
                  </td>
                  <td><?= htmlspecialchars($row['name']) ?></td>
                  <td>
                    <span style="background:#fef2f2;color:var(--red-dark);padding:2px 8px;
                           border-radius:10px;font-size:0.75rem;font-weight:600;">
                      <?= htmlspecialchars($row['type']) ?>
                    </span>
                  </td>
                  <td><?= htmlspecialchars($row['batch_date']) ?></td>
                  <td style="color:var(--red-light);font-weight:600;">
                    <?= htmlspecialchars($row['expired_date']) ?>
                  </td>
                  <td style="text-align:center;font-weight:600;">
                    <?= (int) $row['quantity_at_expiry'] ?>
                  </td>
                  <td style="color:var(--text-muted);font-size:0.83rem;">
                    <?= htmlspecialchars($row['recorded_at']) ?>
                  </td>
                </tr>
              <?php endwhile;
            else: ?>
              <tr>
                <td colspan="7" style="text-align:center;color:var(--text-muted);padding:1.5rem;">
                  No expired supply records yet.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>

        <!-- Pagination -->
        <div class="inv-pagination" id="exp-history-pagination">
          <span id="exp-history-page-info">Showing 1–10</span>
          <div class="inv-pages" id="exp-history-pages"></div>
        </div>
      </div>

      <p id="exp-history-no-results"
        style="display:none;color:var(--text-muted);text-align:center;padding:1.5rem 0;font-size:0.88rem;">
        No records match your search.
      </p>
    </div>

    <!-- Modals + Chatbot -->
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
              $expiring_meds = $conn->query('SELECT * FROM medicines WHERE expired_date <= CURDATE() + INTERVAL 1 DAY AND expired_date >= CURDATE()');
              while ($med = $expiring_meds->fetch_assoc()):
                $balance = $med['quantity'];
                $status = $balance <= 20 ? '⚠️ Low Stock' : '✅ In Stock';
                ?>
                <tr>
                  <td><img src="../../uploads/medicines/<?php echo htmlspecialchars($med['image']); ?>" width="50"></td>
                  <td><?php echo htmlspecialchars($med['name']); ?></td>
                  <td><?php echo htmlspecialchars($med['type']); ?></td>
                  <td><?php echo htmlspecialchars($med['batch_date']); ?></td>
                  <td><?php echo htmlspecialchars($med['expired_date']); ?></td>
                  <td><?php echo (int) $med['quantity']; ?></td>
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
        <form method="POST" action="dashboard.php">
          <input type="hidden" name="medicine_id" id="disposalMedId">
          <div style="padding: 20px;">
            <p><strong>How will you dispose of this item?</strong></p>
            <label style="display:block; margin:10px 0;">
              <input type="radio" name="disposal_method"
                value="Mixed with coffee grounds/cat litter, sealed in bag, trashed" required>
              Antibiotic / Pain Reliever / Vitamins
            </label>
            <label style="display:block; margin:10px 0;">
              <input type="radio" name="disposal_method" value="Used sharps container" required>
              Injection (sharps)
            </label>
            <label style="display:block; margin:10px 0;">
              <input type="radio" name="disposal_method"
                value="Small amounts poured down drain; bottle rinsed & recycled" required>
              Antiseptic (liquid)
            </label>
            <label style="display:block; margin:10px 0;">
              <input type="radio" name="disposal_method" value="Rinsed thoroughly and recycled per local rules"
                required>
              Other / Bottles
            </label>
            <label style="display:block; margin:15px 0 5px; font-weight:bold;">
              Or describe your method:
            </label>
            <textarea name="disposal_method" rows="3"
              style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;"
              placeholder="Describe how you will safely dispose of this item..."></textarea>
          </div>
          <div style="text-align:right; padding:0 20px 20px;">
            <button type="button" onclick="closeDisposalModal()"
              style="padding:8px 16px; margin-right:10px; background:#9e9e9e; color:white; border:none; border-radius:4px;">Cancel</button>
            <button type="submit" name="request_disposal"
              style="padding:8px 16px; background:#e53935; color:white; border:none; border-radius:4px;">Confirm
              Disposal</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
      <div class="modal-content" style="max-width:480px;">
        <div class="modal-header">
          <h3><i class="fas fa-edit" style="color:var(--red-light);margin-right:8px;"></i>Edit Medicine</h3>
          <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form id="editForm" method="POST" action="dashboard.php" enctype="multipart/form-data"
              style="padding:1.2rem 0 0;">
          <input type="hidden" name="id" id="edit_id">

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">

            <!-- Medicine Name (full width) -->
            <div style="grid-column:1/-1;margin-bottom:1rem;">
              <label style="display:block;font-size:0.68rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">
                <i class="fas fa-pills" style="color:var(--red-light);margin-right:4px;"></i>Medicine Name
              </label>
              <input type="text" name="name" id="edit_name" required
                    style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;outline:none;transition:border-color 0.2s;"
                    onfocus="this.style.borderColor='var(--red)'"
                    onblur="this.style.borderColor='var(--border)'">
            </div>

            <!-- Category -->
            <div style="margin-bottom:1rem;">
              <label style="display:block;font-size:0.68rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">
                <i class="fas fa-tags" style="color:var(--red-light);margin-right:4px;"></i>Category
              </label>
              <select name="type" id="edit_type" required
                      style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;outline:none;appearance:none;background:#fff;transition:border-color 0.2s;"
                      onfocus="this.style.borderColor='var(--red)'"
                      onblur="this.style.borderColor='var(--border)'">
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= $cat ?>"><?= $cat ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Quantity -->
            <div style="margin-bottom:1rem;">
              <label style="display:block;font-size:0.68rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">
                <i class="fas fa-cubes" style="color:var(--red-light);margin-right:4px;"></i>Quantity
              </label>
              <input type="number" name="quantity" id="edit_quantity" required min="1"
                    style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;outline:none;transition:border-color 0.2s;"
                    onfocus="this.style.borderColor='var(--red)'"
                    onblur="this.style.borderColor='var(--border)'">
            </div>

            <!-- Batch Date -->
            <div style="margin-bottom:1rem;">
              <label style="display:block;font-size:0.68rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">
                <i class="fas fa-calendar" style="color:var(--red-light);margin-right:4px;"></i>Batch Date
              </label>
              <input type="date" name="batch_date" id="edit_batch_date" required
                    style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;outline:none;transition:border-color 0.2s;"
                    onfocus="this.style.borderColor='var(--red)'"
                    onblur="this.style.borderColor='var(--border)'">
            </div>

            <!-- Expiry Date -->
            <div style="margin-bottom:1rem;">
              <label style="display:block;font-size:0.68rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">
                <i class="fas fa-calendar-times" style="color:var(--red-light);margin-right:4px;"></i>Expiry Date
              </label>
              <input type="date" name="expired_date" id="edit_expired_date" required
                    style="width:100%;height:42px;padding:0 10px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;outline:none;transition:border-color 0.2s;"
                    onfocus="this.style.borderColor='var(--red)'"
                    onblur="this.style.borderColor='var(--border)'">
            </div>

            <!-- Image upload (full width) -->
            <div style="grid-column:1/-1;margin-bottom:1.2rem;">
              <label style="display:block;font-size:0.68rem;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#7a8da0;margin-bottom:4px;">
                <i class="fas fa-image" style="color:var(--red-light);margin-right:4px;"></i>Change Image <span style="text-transform:none;font-weight:400;color:#b5c1ce;">(optional)</span>
              </label>
              <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border:1.5px dashed var(--border);border-radius:8px;background:#f8fafc;">
                <i class="fas fa-upload" style="color:var(--red-light);"></i>
                <input type="file" name="image" accept="image/*" style="font-size:0.82rem;color:#6b7280;border:none;outline:none;background:none;flex:1;">
              </div>
            </div>

          </div>

          <!-- footer buttons -->
          <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:0.75rem;border-top:1px solid var(--border);">
            <button type="button" onclick="closeEditModal()" class="btn btn-grey">Cancel</button>
            <button type="submit" name="update_medicine" class="btn btn-add">
              <i class="fas fa-save"></i> Save Changes
            </button>
          </div>
        </form>
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
      const isGuest = <?php echo json_encode($isGuest); ?>;
    </script>
    <script src="../../scripts/s_dashboard.js"></script>
    <script>
      // Records section wiring
      (function () {
        const btnExpiredHistory = document.getElementById('btn-expired-history');
        const contentExpiredHistory = document.getElementById('content-expired-history');

        if (btnExpiredHistory && contentExpiredHistory) {
          // Register in existing maps
          buttons.expiredHistory = btnExpiredHistory;
          contents.expiredHistory = contentExpiredHistory;
          sectionTitles.expiredHistory = 'Expired Supply History';

          btnExpiredHistory.addEventListener('click', () => showSection('expiredHistory'));
        }

        // Expired History Filter + Pagination 
        const EH_PAGE_SIZE = 10;
        let ehCurrentPage = 1;

        function applyExpiredHistoryFilter() {
          const search = (document.getElementById('exp-history-search')?.value || '').toLowerCase();
          const category = (document.getElementById('exp-history-category')?.value || '');
          const allRows = [...document.querySelectorAll('#expired-history-table tbody tr')];

          const matched = allRows.filter(row => {
            const nameMatch = !search || (row.dataset.name || '').includes(search);
            const catMatch = !category || (row.dataset.category || '') === category;
            return nameMatch && catMatch;
          });

          allRows.forEach(r => r.style.display = 'none');

          const total = matched.length;
          const totalPages = Math.ceil(total / EH_PAGE_SIZE);
          ehCurrentPage = Math.min(ehCurrentPage, totalPages || 1);
          const start = (ehCurrentPage - 1) * EH_PAGE_SIZE;
          const end = Math.min(start + EH_PAGE_SIZE, total);
          matched.slice(start, end).forEach(r => r.style.display = '');

          renderEHPagination(total, totalPages, start + 1, end);

          const noResults = document.getElementById('exp-history-no-results');
          if (noResults) noResults.style.display = matched.length === 0 ? 'block' : 'none';
        }

        function renderEHPagination(total, totalPages, start, end) {
          const pag = document.getElementById('exp-history-pagination');
          const info = document.getElementById('exp-history-page-info');
          const pages = document.getElementById('exp-history-pages');
          if (!pag) return;
          pag.style.display = total > EH_PAGE_SIZE ? 'flex' : 'none';
          info.textContent = total === 0 ? 'No results' : `Showing ${start}–${end} of ${total}`;

          pages.innerHTML = '';

          const prev = document.createElement('button');
          prev.className = 'inv-page-btn';
          prev.innerHTML = '&#8249;';
          prev.disabled = ehCurrentPage === 1;
          prev.onclick = () => { ehCurrentPage--; applyExpiredHistoryFilter(); };
          pages.appendChild(prev);

          const range = 2;
          for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= ehCurrentPage - range && i <= ehCurrentPage + range)) {
              const btn = document.createElement('button');
              btn.className = 'inv-page-btn' + (i === ehCurrentPage ? ' active' : '');
              btn.textContent = i;
              btn.onclick = () => { ehCurrentPage = i; applyExpiredHistoryFilter(); };
              pages.appendChild(btn);
            } else if (
              (i === ehCurrentPage - range - 1 && i > 1) ||
              (i === ehCurrentPage + range + 1 && i < totalPages)
            ) {
              const dots = document.createElement('button');
              dots.className = 'inv-page-btn';
              dots.textContent = '…';
              dots.disabled = true;
              pages.appendChild(dots);
            }
          }

          const next = document.createElement('button');
          next.className = 'inv-page-btn';
          next.innerHTML = '&#8250;';
          next.disabled = ehCurrentPage === totalPages;
          next.onclick = () => { ehCurrentPage++; applyExpiredHistoryFilter(); };
          pages.appendChild(next);
        }

        // Expose for inline onchange on the category select
        window.applyExpiredHistoryFilter = applyExpiredHistoryFilter;

        // Hook up search input + run on load
        document.addEventListener('DOMContentLoaded', () => {
          const searchInput = document.getElementById('exp-history-search');
          if (searchInput) searchInput.addEventListener('input', () => {
            ehCurrentPage = 1;
            applyExpiredHistoryFilter();
          });
          applyExpiredHistoryFilter();
        });
      })();
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
            <button onclick="closeDeleteModal()"
              style="padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; background: #9e9e9e; color: white;">Cancel</button>
            <a id="confirmDelete" href="#"
              style="padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; background: #e53935; color: white; text-decoration: none;">Delete</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Toast container -->
    <div id="toast-container"></div>
</body>

</html>
<?php $conn->close(); ?>
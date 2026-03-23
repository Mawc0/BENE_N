<?php
session_start();

// Database connection
include ('../../db.php');

// Access control: Only allow admins
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
  header('Location: ../../home_pages/login.php');
  exit;
}

$page = $_GET['page'] ?? 'dashboard';
$error_message = '';
$success_message = '';
$recentLogs = null;
$notifications = null;

// Get the logged-in admin's user ID
$adminIdRes = $conn->query("SELECT id FROM users WHERE username = '" . $conn->real_escape_string($_SESSION['username']) . "' LIMIT 1");
$adminUserId = $adminIdRes ? (int) $adminIdRes->fetch_assoc()['id'] : 0;

// Unread count — only for this admin
$unreadCount = 0;
$unreadRes = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE user_id = $adminUserId AND is_read = 0");
if ($unreadRes) {
  $row = $unreadRes->fetch_assoc();
  $unreadCount = isset($row['c']) ? (int) $row['c'] : 0;
}

// Mark all read — only for this admin
if ($page === 'notifications' && isset($_POST['mark_all_read'])) {
  $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $adminUserId AND is_read = 0");
  $unreadCount = 0;
  header('Location: dashboard.php?page=notifications');
  exit();
}

// Fetch notifications — only for this admin
if ($page === 'notifications') {
  $notifications = $conn->query("
        SELECT n.*, u.username
        FROM notifications n
        LEFT JOIN users u ON n.user_id = u.id
        WHERE n.user_id = $adminUserId
        ORDER BY n.is_read ASC, n.created_at DESC
        LIMIT 50
    ");
}

// Add/update/delete users
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $passwordRaw = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'staff');
    if (!in_array($role, ['staff', 'guest']))
      $role = 'staff';
    if (empty($passwordRaw))
      $passwordRaw = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $check = $conn->prepare('SELECT id FROM users WHERE username = ?');
    $check->bind_param('s', $username);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
      header('Location: dashboard.php?page=manage_users&msg=Username already exists');
      exit();
    }
    $check->close();
    $password = password_hash($passwordRaw, PASSWORD_DEFAULT);
    $defaultPic = $role === 'guest' ? 'guest.avif' : 'staff1.png';
    $forceChange = 1;
    $stmt = $conn->prepare('INSERT INTO users (username, password, role, profile_pic, force_password_change) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('ssssi', $username, $password, $role, $defaultPic, $forceChange);
    if ($stmt->execute()) {
      $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Added new user $username as $role')");
      header("Location: dashboard.php?page=manage_users&msg=User added successfully. Temporary password: $passwordRaw");
      exit();
    } else {
      header('Location: dashboard.php?page=manage_users&msg=Error adding user: ' . $stmt->error);
      exit();
    }
    $stmt->close();
  }
  if (isset($_POST['update_user'])) {
    $id = $_POST['id'];
    $username = trim($_POST['username']);
    $role = $_POST['role'];
    $stmt = $conn->prepare('UPDATE users SET username=?, role=? WHERE id=?');
    $stmt->bind_param('ssi', $username, $role, $id);
    if ($stmt->execute())
      $success_message = 'User updated successfully.';
    else
      $error_message = 'Error: ' . $stmt->error;
    $stmt->close();
  }
  if (isset($_POST['delete_user'])) {
    $id = $_POST['id'];
    $stmt = $conn->prepare('SELECT role FROM users WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $roleRes = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($roleRes && $roleRes['role'] === 'admin') {
      $error_message = 'Cannot delete an admin account!';
    } else {
      $stmt = $conn->prepare('DELETE FROM users WHERE id=?');
      $stmt->bind_param('i', $id);
      if ($stmt->execute())
        $success_message = 'User deleted successfully.';
      else
        $error_message = 'Error: ' . $stmt->error;
      $stmt->close();
    }
  }
}

// Categories
if ($page === 'categories') {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $newCat = trim($_POST['category_name']);
    if (!empty($newCat)) {
      $stmt = $conn->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(?)');
      $stmt->bind_param('s', $newCat);
      $stmt->execute();
      if ($stmt->get_result()->num_rows === 0) {
        $stmt = $conn->prepare('INSERT INTO categories (name) VALUES (?)');
        $stmt->bind_param('s', $newCat);
        $stmt->execute();
        $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Added category: $newCat')");
        $success_message = 'Category added successfully.';
      } else {
        $error_message = 'Category already exists.';
      }
      $stmt->close();
    }
  }
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $catId = (int) $_POST['id'];
    $newName = trim($_POST['category_name']);
    if (!empty($newName)) {
      $stmt = $conn->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND id != ?');
      $stmt->bind_param('si', $newName, $catId);
      $stmt->execute();
      if ($stmt->get_result()->num_rows === 0) {
        $stmt = $conn->prepare('UPDATE categories SET name = ? WHERE id = ?');
        $stmt->bind_param('si', $newName, $catId);
        if ($stmt->execute()) {
          $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Edited category ID $catId to \"$newName\"')");
          $success_message = 'Category updated successfully.';
        } else {
          $error_message = 'Failed to update category.';
        }
      } else {
        $error_message = 'Category name already exists.';
      }
      $stmt->close();
    } else {
      $error_message = 'Category name cannot be empty.';
    }
    header('Location: dashboard.php?page=categories');
    exit();
  }
  if (isset($_GET['delete_cat'])) {
    $catId = (int) $_GET['delete_cat'];
    $res = $conn->query("SELECT name FROM categories WHERE id = $catId");
    if ($res && $res->num_rows > 0) {
      $catName = $res->fetch_assoc()['name'];
      $conn->query("DELETE FROM categories WHERE id = $catId");
      $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Deleted category: $catName')");
      header('Location: dashboard.php?page=categories');
      exit();
    }
  }
  $editCategory = null;
  if (isset($_GET['edit_cat'])) {
    $editId = (int) $_GET['edit_cat'];
    $res = $conn->query("SELECT * FROM categories WHERE id = $editId");
    if ($res && $res->num_rows > 0)
      $editCategory = $res->fetch_assoc();
  }
  $categories = $conn->query('SELECT * FROM categories ORDER BY id');
}

// Medicines
function getMedicineUnit(string $type): string
{
  $liquidTypes = ['Injection', 'Antiseptic', 'Syrup', 'Solution', 'Drops', 'Suspension'];
  return in_array($type, $liquidTypes, true) ? 'mL' : 'pcs';
}

$where = '1=1';
$search = '';
if ($page === 'medicines') {
  if (isset($_GET['filter']) && $_GET['filter'] == 'low_stock')
    $where = 'quantity <= 20 AND expired_date > CURDATE()';
  elseif (isset($_GET['filter']) && $_GET['filter'] == 'expiring')
    $where = 'expired_date <= CURDATE() + INTERVAL 7 DAY AND expired_date >= CURDATE()';
  if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
    $where .= " AND (name LIKE '%$search%' OR type LIKE '%$search%' OR CAST(quantity AS CHAR) LIKE '%$search%' OR DATE_FORMAT(expired_date, '%Y-%m-%d') LIKE '%$search%')";
  }
  $meds = $conn->query("SELECT *,
    CASE WHEN expired_date < CURDATE() THEN 3
         WHEN quantity <= 20 THEN 1
         ELSE 2 END AS sort_order
    FROM medicines WHERE $where ORDER BY sort_order ASC, expired_date ASC");
  $medCatResult = $conn->query('SELECT name FROM categories ORDER BY id');
  $medCategories = [];
  while ($c = $medCatResult->fetch_assoc())
    $medCategories[] = $c['name'];
}

// Dashboard stats
if ($page === 'dashboard') {
  $totalUsers = $conn->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc()['total'] ?? 0;
  $totalMeds = $conn->query('SELECT COUNT(*) AS total FROM medicines')->fetch_assoc()['total'] ?? 0;
  $lowStockMeds = $conn->query('SELECT COUNT(*) AS total FROM medicines WHERE quantity <= 20 AND expired_date > CURDATE()')->fetch_assoc()['total'] ?? 0;
  $expiringSoonMeds = $conn->query('SELECT COUNT(*) AS total FROM medicines WHERE expired_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)')->fetch_assoc()['total'] ?? 0;
  $recentLogs = $conn->query('SELECT * FROM logs ORDER BY timestamp DESC LIMIT 5');
}

// Donations
if ($page === 'donations') {
  if (isset($_GET['approve'])) {
    $reqId = (int) $_GET['approve'];
    $info = $conn->query("SELECT dr.staff_id, m.name AS med_name, u.username AS staff_name FROM donation_requests dr JOIN medicines m ON dr.medicine_id = m.id JOIN users u ON dr.staff_id = u.id WHERE dr.id = $reqId AND dr.status = 'pending'")->fetch_assoc();
    if ($info) {
      $conn->query("UPDATE donation_requests SET status='approved', approved_at=NOW() WHERE id=$reqId");
      $stmt = $conn->prepare('INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())');
      $msg = "Your donation request for \"{$info['med_name']}\" has been approved by admin.";
      $stmt->bind_param('is', $info['staff_id'], $msg);
      $stmt->execute();
      $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Approved donation request for {$info['med_name']} by {$info['staff_name']}')");
      header('Location: dashboard.php?page=donations');
      exit();
    }
  }
  if (isset($_GET['reject'])) {
    $reqId = (int) $_GET['reject'];
    $info = $conn->query("SELECT dr.staff_id, m.name AS med_name, u.username AS staff_name FROM donation_requests dr JOIN medicines m ON dr.medicine_id = m.id JOIN users u ON dr.staff_id = u.id WHERE dr.id = $reqId AND dr.status = 'pending'")->fetch_assoc();
    if ($info) {
      $conn->query("UPDATE donation_requests SET status='rejected', approved_at=NOW() WHERE id=$reqId");
      $stmt = $conn->prepare('INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())');
      $msg = "Your donation request for \"{$info['med_name']}\" was rejected by admin.";
      $stmt->bind_param('is', $info['staff_id'], $msg);
      $stmt->execute();
      $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Rejected donation request for {$info['med_name']} by {$info['staff_name']}')");
      header('Location: dashboard.php?page=donations');
      exit();
    }
  }
}

// Manage Users
if ($page === 'manage_users') {
  if (isset($_GET['reset'])) {
    $id = (int) $_GET['reset'];
    $res = $conn->query("SELECT username, role FROM users WHERE id=$id");
    if ($res && $res->num_rows > 0) {
      $row = $res->fetch_assoc();
      if ($row['role'] === 'admin') {
        header('Location: dashboard.php?page=manage_users&msg=Cannot reset admin password');
        exit;
      }
      $tempPassword = 'Temp' . rand(1000, 9999);
      $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
      $stmt = $conn->prepare('UPDATE users SET password=?, force_password_change=1, force_security_setup=1, security_question=NULL, security_answer=NULL WHERE id=?');
      $stmt->bind_param('si', $hash, $id);
      $stmt->execute();
      $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Reset password for " . $row['username'] . "')");
      header('Location: dashboard.php?page=manage_users&msg=Password reset for ' . $row['username'] . ". Temporary password: $tempPassword");
      exit;
    }
  }
  $editUser = null;
  if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $res = $conn->query("SELECT * FROM users WHERE id=$id");
    $editUser = $res ? $res->fetch_assoc() : null;
  }
  $search = $_GET['search'] ?? '';
  if (trim($search) !== '') {
    $stmt = $conn->prepare('SELECT * FROM users WHERE username LIKE ? OR role LIKE ? ORDER BY created_at DESC');
    $like = "%$search%";
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $users = $stmt->get_result();
  } else {
    $users = $conn->query('SELECT * FROM users ORDER BY created_at DESC');
  }
}

// Delete User
if (isset($_GET['delete'])) {
  $id = (int) $_GET['delete'];
  $stmt = $conn->prepare('SELECT role FROM users WHERE id=?');
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $roleRes = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($roleRes && $roleRes['role'] === 'admin') {
    header('Location: dashboard.php?page=manage_users&msg=Cannot delete an admin account.');
    exit;
  }
  $stmt = $conn->prepare('DELETE FROM users WHERE id=?');
  $stmt->bind_param('i', $id);
  if ($stmt->execute()) {
    header('Location: dashboard.php?page=manage_users&msg=User deleted successfully.');
  } else {
    header('Location: dashboard.php?page=manage_users&msg=Error: ' . $stmt->error);
  }
  $stmt->close();
  exit;
}

// Schedules
if ($page === 'schedules') {
  if (isset($_POST['create_schedule'])) {
    $schedule_name = $_POST['schedule_name'];
    $schedule_type = $_POST['schedule_type'];
    $schedule_time = $_POST['schedule_time'];
    $schedule_day = $_POST['schedule_day'] ?? null;
    $next_check = date('Y-m-d H:i:s');
    if ($schedule_type == 'daily')
      $next_check = date('Y-m-d', strtotime('+1 day')) . ' ' . $schedule_time;
    elseif ($schedule_type == 'weekly') {
      $schedule_day = $schedule_day ?? date('N');
      $day_name = date('l', strtotime("next Sunday + {$schedule_day} days"));
      $next_check = date('Y-m-d H:i:s', strtotime("next {$day_name} " . $schedule_time));
    } elseif ($schedule_type == 'monthly') {
      $schedule_day = $schedule_day ?? date('j');
      $next_check = date('Y-m-d H:i:s', strtotime('first day of next month +' . ((int) $schedule_day - 1) . ' days ' . $schedule_time));
    }
    $admin_id = $conn->query("SELECT id FROM users WHERE username = '{$_SESSION['username']}'")->fetch_assoc()['id'];
    $stmt = $conn->prepare('INSERT INTO admin_schedules (admin_id, schedule_name, schedule_type, schedule_time, schedule_day, next_check) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssis', $admin_id, $schedule_name, $schedule_type, $schedule_time, $schedule_day, $next_check);
    $stmt->execute();
    header('Location: dashboard.php?page=schedules');
    exit();
  }
  if (isset($_GET['toggle_schedule'])) {
    $id = (int) $_GET['toggle_schedule'];
    $stmt = $conn->prepare('UPDATE admin_schedules SET is_active = NOT is_active WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    header('Location: dashboard.php?page=schedules');
    exit();
  }
  if (isset($_GET['delete_schedule'])) {
    $id = (int) $_GET['delete_schedule'];
    $stmt = $conn->prepare('DELETE FROM admin_schedules WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    header('Location: dashboard.php?page=schedules');
    exit();
  }
  if (isset($_POST['edit_schedule'])) {
    $id = (int) $_POST['id'];
    $schedule_name = $_POST['schedule_name'];
    $schedule_type = $_POST['schedule_type'];
    $schedule_time = $_POST['schedule_time'];
    $schedule_day = $_POST['schedule_day'] ?? null;
    $next_check = date('Y-m-d H:i:s');
    if ($schedule_type == 'daily')
      $next_check = date('Y-m-d', strtotime('+1 day')) . ' ' . $schedule_time;
    elseif ($schedule_type == 'weekly') {
      $schedule_day = $schedule_day ?? date('N');
      $day_name = date('l', strtotime("next Sunday + {$schedule_day} days"));
      $next_check = date('Y-m-d H:i:s', strtotime("next {$day_name} " . $schedule_time));
    } elseif ($schedule_type == 'monthly') {
      $schedule_day = $schedule_day ?? date('j');
      $next_check = date('Y-m-d H:i:s', strtotime('first day of next month +' . ($schedule_day - 1) . ' days ' . $schedule_time));
    }
    $stmt = $conn->prepare('UPDATE admin_schedules SET schedule_name=?, schedule_type=?, schedule_time=?, schedule_day=?, next_check=? WHERE id=?');
    $stmt->bind_param('sssssi', $schedule_name, $schedule_type, $schedule_time, $schedule_day, $next_check, $id);
    $stmt->execute();
    header('Location: dashboard.php?page=schedules');
    exit();
  }
  $editSchedule = null;
  if (isset($_GET['edit_schedule'])) {
    $id = (int) $_GET['edit_schedule'];
    $stmt = $conn->prepare('SELECT * FROM admin_schedules WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $editSchedule = $stmt->get_result()->fetch_assoc();
  }
  $schedules = $conn->query('
        SELECT s.*, u.username AS created_by
        FROM admin_schedules s
        LEFT JOIN users u ON s.admin_id = u.id
        ORDER BY s.next_check ASC
    ');
}

// FUNCTION: Check schedules and create notifications
function checkSchedules()
{
  global $conn;
  date_default_timezone_set('Asia/Manila');
  $tableCheck = $conn->query("SHOW TABLES LIKE 'admin_schedules'");
  if ($tableCheck->num_rows == 0)
    return;
  $now = date('Y-m-d H:i:s');
  $stmt = $conn->prepare('SELECT * FROM admin_schedules WHERE next_check <= ? AND is_active = 1');
  if (!$stmt)
    return;
  $stmt->bind_param('s', $now);
  if (!$stmt->execute()) {
    $stmt->close();
    return;
  }
  $result = $stmt->get_result();
  while ($sched = $result->fetch_assoc()) {
    $msg = 'Time to check: ' . $sched['schedule_name'];
    $stmt2 = $conn->prepare('INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)');
    if (!$stmt2)
      continue;
    $stmt2->bind_param('is', $sched['admin_id'], $msg);
    $stmt2->execute();
    $stmt2->close();
    updateNextCheck($sched['id'], $sched['schedule_type'], $sched['schedule_time'], $sched['schedule_day']);
  }
  $stmt->close();
}

function updateNextCheck($id, $type, $time, $day = null)
{
  global $conn;
  switch ($type) {
    case 'daily':
      $next = date('Y-m-d', strtotime('+1 day')) . ' ' . $time;
      break;
    case 'weekly':
      $day_name = $day ? date('l', strtotime("next Sunday + {$day} days")) : null;
      $next = $day_name ? date('Y-m-d H:i:s', strtotime("next {$day_name} " . $time)) : date('Y-m-d', strtotime('+7 days')) . ' ' . $time;
      break;
    case 'monthly':
      $next = $day ? date('Y-m-d H:i:s', strtotime('first day of next month +' . ($day - 1) . ' days ' . $time)) : date('Y-m-d', strtotime('+30 days')) . ' ' . $time;
      break;
    default:
      return;
  }
  $stmt = $conn->prepare('UPDATE admin_schedules SET next_check = ? WHERE id = ?');
  $stmt->bind_param('si', $next, $id);
  $stmt->execute();
}

checkSchedules();

$isGuest = ($_SESSION['role'] ?? '') === 'guest';

$search = $_GET['search'] ?? '';
$role_filter = $_GET['role_filter'] ?? '';

$where = "WHERE username LIKE '%" . $conn->real_escape_string($search) . "%'";

if (!empty($role_filter)) {
  $where .= " AND role = '" . $conn->real_escape_string($role_filter) . "'";
}

$order = "ORDER BY FIELD(role, 'guest', 'staff', 'admin')";

$sql = "SELECT * FROM users $where $order";
$users = $conn->query($sql);

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard | BENE MediCon</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap"
    rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../../styles/a_dashboard.css">
</head>

<body>

  <!-- Modals -->
  <div id="logoutModal" class="modal" style="display:none;">
    <div class="modal-content">
      <div class="modal-header">
        <h3><i class="fas fa-sign-out-alt" style="color:var(--red-light);margin-right:8px;"></i> Confirm Logout</h3>
        <button class="modal-close" onclick="closeLogoutModal()">&#215;</button>
      </div>
      <p>Are you sure you want to log out of your admin account?</p>
      <div class="modal-footer">
        <button onclick="closeLogoutModal()" class="btn btn-grey">Cancel</button>
        <a href="../../logout.php" class="btn btn-del"><i class="fas fa-sign-out-alt"></i> Yes, Logout</a>
      </div>
    </div>
  </div>

  <div id="deleteCategoryModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>&#9888; Delete Category</h3>
        <button class="modal-close" onclick="closeDeleteCategoryModal()">&#215;</button>
      </div>
      <p>Are you sure you want to delete "<strong><span id="deleteCategoryName"></span></strong>"? <br><span
          style="color:var(--red-light);">This cannot be undone.</span></p>
      <div class="modal-footer">
        <button onclick="closeDeleteCategoryModal()" class="btn btn-grey">Cancel</button>
        <a id="confirmDeleteCategory" href="#" class="btn btn-del"><i class="fas fa-trash"></i> Delete</a>
      </div>
    </div>
  </div>

  <div id="deleteScheduleModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>&#9888; Delete Schedule</h3>
        <button class="modal-close" onclick="closeDeleteScheduleModal()">&#215;</button>
      </div>
      <p>Are you sure you want to delete "<strong><span id="deleteScheduleName"></span></strong>"?<br><span
          style="color:var(--red-light);">This cannot be undone.</span></p>
      <div class="modal-footer">
        <button onclick="closeDeleteScheduleModal()" class="btn btn-grey">Cancel</button>
        <a id="confirmDeleteSchedule" href="#" class="btn btn-del"><i class="fas fa-trash"></i> Delete</a>
      </div>
    </div>
  </div>

  <div id="editScheduleModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Edit Schedule</h3>
        <button class="modal-close" onclick="closeEditScheduleModal()">&#215;</button>
      </div>
      <form method="POST" id="editScheduleForm">
        <input type="hidden" name="id" id="editScheduleId">
        <label>Schedule Name</label>
        <input type="text" name="schedule_name" id="editScheduleName" required>
        <label>Schedule Type</label>
        <select name="schedule_type" id="editScheduleType" required>
          <option value="daily">Daily</option>
          <option value="weekly">Weekly</option>
          <option value="monthly">Monthly</option>
        </select>
        <label>Time</label>
        <input type="time" name="schedule_time" id="editScheduleTime" required>
        <p id="editDayField" style="display:none;">
          <label for="editScheduleDay" id="editDayLabel">Day</label>
          <input type="number" name="schedule_day" id="editScheduleDay" min="1" max="31">
        </p>
        <div class="modal-footer">
          <button type="button" onclick="closeEditScheduleModal()" class="btn btn-grey">Cancel</button>
          <button type="submit" name="edit_schedule" class="btn">Update Schedule</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Add User Modal -->
  <div id="addUserModal" class="modal" style="display:none;">
    <div class="modal-content">
      <div class="modal-header">
        <h3><i class="fas fa-user-plus" style="color:var(--red-light);margin-right:8px;"></i> Add New User</h3>
        <button class="modal-close" onclick="closeAddUserModal()">&#215;</button>
      </div>
      <form method="POST">
        <label>Username</label>
        <input type="text" name="username" placeholder="Enter username" required>
        <label>Temporary Password <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
        <input type="text" name="password" placeholder="Leave blank to auto-generate">
        <p class="form-hint">User will be required to change it on first login.</p>
        <label>Role</label>
        <select name="role" required>
          <option value="staff">Staff</option>
          <option value="guest">Guest</option>
        </select>
        <div class="modal-footer">
          <button type="button" onclick="closeAddUserModal()" class="btn btn-grey">Cancel</button>
          <button type="submit" name="add_user" class="btn btn-add"><i class="fas fa-plus"></i> Add User</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Sidebar -->
  <div class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <button id="hamburger" class="hamburger-btn" title="Toggle menu">
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
      <a class="nav-item <?= $page === 'dashboard' ? 'active' : '' ?>" href="dashboard.php?page=dashboard"><i
          class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
      <div class="nav-section-label">Inventory</div>
      <a class="nav-item <?= $page === 'medicines' ? 'active' : '' ?>" href="dashboard.php?page=medicines"><i
          class="fas fa-pills"></i><span>Medicines</span></a>
      <a class="nav-item <?= $page === 'categories' ? 'active' : '' ?>" href="dashboard.php?page=categories"><i
          class="fas fa-tags"></i><span>Categories</span></a>
      <a class="nav-item <?= $page === 'donations' ? 'active' : '' ?>" href="dashboard.php?page=donations"><i
          class="fas fa-hand-holding-medical"></i><span>Donations</span></a>
      <div class="nav-section-label">Administration</div>
      <a class="nav-item <?= $page === 'manage_users' ? 'active' : '' ?>" href="dashboard.php?page=manage_users"><i
          class="fas fa-users"></i><span>Manage Users</span></a>
      <a class="nav-item <?= $page === 'schedules' ? 'active' : '' ?>" href="dashboard.php?page=schedules"><i
          class="fas fa-clock"></i><span>Schedules</span></a>
      <div class="nav-section-label">Activity</div>
      <a class="nav-item <?= $page === 'logs' ? 'active' : '' ?>" href="dashboard.php?page=logs"><i
          class="fas fa-history"></i><span>Logs</span></a>
      <a class="nav-item <?= $page === 'notifications' ? 'active' : '' ?>" href="dashboard.php?page=notifications"><i
          class="fas fa-bell"></i><span>Notifications <?php if ($unreadCount > 0): ?><span
              style="background:var(--gold);color:var(--red-deeper);border-radius:10px;padding:1px 7px;font-size:0.7rem;margin-left:4px;"><?= $unreadCount ?></span><?php endif; ?></span></a>
    </nav>

    <div class="sidebar-footer">
      <button class="nav-item" onclick="openLogoutModal()" style="color:rgba(255,255,255,0.6);">
        <i class="fas fa-sign-out-alt"></i><span>Logout</span>
      </button>
    </div>
  </div>

  <!-- Topbar  -->
  <div class="topbar" id="topbar">
    <span class="topbar-title">
      <?php
      $titles = [
        'dashboard' => 'Dashboard',
        'manage_users' => 'Manage Users',
        'medicines' => 'Medicine Overview',
        'categories' => 'Categories',
        'donations' => 'Donation Requests',
        'logs' => 'Activity Logs',
        'schedules' => 'Check Schedules',
        'notifications' => 'Notifications',
      ];
      echo $titles[$page] ?? 'Admin Panel';
      ?>
    </span>

    <div class="topbar-right">
      <!-- Notification Bell -->
      <a href="dashboard.php?page=notifications" class="topbar-notif" title="Notifications">
        <i class="fas fa-bell"></i>
        <?php if ($unreadCount > 0): ?>
          <span class="badge"><?= $unreadCount ?></span>
        <?php endif; ?>
      </a>

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
                <img src="../../uploads/avatars/<?= htmlspecialchars($_SESSION['profile_pic'] ?? 'default.jpg') ?>" alt=""
        style="width:36px;height:36px;border-radius:8px;object-fit:cover;"
        onerror="this.style.display='none'">
            <div class="dh-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div>
            <div class="dh-role"><?= ucfirst($_SESSION['role'] ?? 'Admin') ?> &mdash; BENE MediCon</div>
          </div>
          <!-- <a href="dashboard.php?page=notifications" class="dropdown-item">
          <i class="fas fa-bell"></i> Notifications
          <?php if ($unreadCount > 0): ?><span style="margin-left:auto;background:var(--red-light);color:#fff;border-radius:10px;padding:1px 8px;font-size:0.68rem;"><?= $unreadCount ?></span><?php endif; ?>
        </a> -->
          <?php if (!$isGuest): ?>
            <a href="../edit_profile.php" class="dropdown-item"><i class="fas fa-user-edit"></i> Edit Profile</a>
            <a href="../change_password.php" class="dropdown-item"><i class="fas fa-key"></i> Change Password</a>
          <?php endif; ?>
          <button onclick="openLogoutModal()" class="dropdown-item danger">
            <i class="fas fa-sign-out-alt"></i> Logout
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Main Content  -->
  <div class="main-content" id="mainContent">

    <?php if ($page === 'notifications'): ?>
      <h1 class="page-heading"> <i class="fas fa-bell" style="color:var(--red-light);margin-right:8px;"></i>Notifications</h1>
      <form method="POST" style="margin-bottom:1rem;">
        <button type="submit" name="mark_all_read" class="btn btn-add">
          <i class="fas fa-check-double"></i> Mark All as Read
        </button>
      </form>
      <div class="table-wrap">
        <table>
          <tr>
            <th>Message</th>
            <th>Status</th>
            <th>Received</th>
            <th>Action</th>
          </tr>
          <?php if ($notifications && $notifications->num_rows > 0): ?>
            <?php while ($n = $notifications->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($n['message']) ?></td>
                <td><?= $n['is_read'] ? '<span class="badge-read">Read</span>' : '<span class="badge-unread">Unread</span>' ?>
                </td>
                <td><?= htmlspecialchars($n['created_at']) ?></td>
                <td><?php if (!$n['is_read']): ?><a class="btn" href="mark_read.php?id=<?= (int) $n['id'] ?>">Mark as
                      Read</a><?php endif; ?></td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="4" style="text-align:center;color:var(--text-muted);">No notifications.</td>
            </tr>
          <?php endif; ?>
        </table>
      </div>

    <?php elseif ($page === 'dashboard'): ?>
      <h1 class="page-heading"> <i class="fas fa-tachometer-alt" style="color:var(--red-light);margin-right:8px;"></i>Admin Dashboard</h1>
      <div class="cards">
        <div class="stat-card stat-card-1">
          <div class="stat-icon"><i class="fas fa-users"></i></div>
          <div class="stat-value"><?= $totalUsers ?></div>
          <div class="stat-label">Total Users</div>
        </div>
        <div class="stat-card stat-card-2">
          <div class="stat-icon"><i class="fas fa-pills"></i></div>
          <div class="stat-value"><?= $totalMeds ?></div>
          <div class="stat-label">Total Medicines</div>
        </div>
        <div class="stat-card stat-card-3">
          <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
          <div class="stat-value"><?= $lowStockMeds ?></div>
          <div class="stat-label">Low Stock</div>
        </div>
        <div class="stat-card stat-card-4">
          <div class="stat-icon"><i class="fas fa-calendar-times"></i></div>
          <div class="stat-value"><?= $expiringSoonMeds ?></div>
          <div class="stat-label">Expiring Soon</div>
        </div>
      </div>
      <div class="table-wrap">
        <div class="table-wrap-header">Recent Activity</div>
        <table>
          <tr>
            <th>User</th>
            <th>Action</th>
            <th>Timestamp</th>
          </tr>
          <?php if ($recentLogs && $recentLogs->num_rows > 0): ?>
            <?php while ($log = $recentLogs->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($log['user']) ?></td>
                <td><?= htmlspecialchars($log['action']) ?></td>
                <td><?= htmlspecialchars($log['timestamp']) ?></td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="3" style="text-align:center;color:var(--text-muted);">No recent logs.</td>
            </tr>
          <?php endif; ?>
        </table>
      </div>

    <?php elseif ($page === 'donations'): ?>
      <h1 class="page-heading"> <i class="fas fa-hand-holding-medical" style="color:var(--red-light);margin-right:8px;"></i>Donation Requests</h1>
      <?php if (!empty($success_message)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>
      <?php $donations = $conn->query('SELECT dr.id, dr.status, dr.requested_at, dr.approved_at, m.name AS med_name, m.type AS med_type, u.username AS staff_name FROM donation_requests dr JOIN medicines m ON dr.medicine_id = m.id JOIN users u ON dr.staff_id = u.id ORDER BY dr.requested_at DESC'); ?>
      <div class="table-wrap">
        <table>
          <tr>
            <th>Medicine</th>
            <th>Category</th>
            <th>Requested By</th>
            <th>Requested At</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
          <?php if ($donations && $donations->num_rows > 0): ?>
            <?php while ($d = $donations->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($d['med_name']) ?></td>
                <td><?= htmlspecialchars($d['med_type']) ?></td>
                <td><?= htmlspecialchars($d['staff_name']) ?></td>
                <td><?= htmlspecialchars($d['requested_at']) ?></td>
                <td>
                  <?php if ($d['status'] === 'approved'): ?><span class="badge-approved">&#10003; Approved</span>
                  <?php elseif ($d['status'] === 'pending'): ?><span class="badge-pending">&#8987; Pending</span>
                  <?php else: ?><span class="badge-rejected">&#10007; Rejected</span><?php endif; ?>
                </td>
                <td>
                  <?php if ($d['status'] === 'pending'): ?>
                    <a class="btn btn-add" href="dashboard.php?page=donations&approve=<?= (int) $d['id'] ?>"
                      onclick="return confirm('Approve this request?')"><i class="fas fa-check"></i> Approve</a>
                    <a class="btn btn-del" href="dashboard.php?page=donations&reject=<?= (int) $d['id'] ?>"
                      onclick="return confirm('Reject this request?')"><i class="fas fa-times"></i> Reject</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" style="text-align:center;color:var(--text-muted);">No donation requests.</td>
            </tr>
          <?php endif; ?>
        </table>
      </div>

    <?php elseif ($page === 'manage_users'): ?>
      <h1 class="page-heading"> <i class="fas fa-users" style="color:var(--red-light);margin-right:8px;"></i>Manage Users</h1>
      <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-info"><?= htmlspecialchars($_GET['msg']) ?></div><?php endif; ?>
      <?php if (!empty($error_message)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>
      <?php if (!empty($success_message)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>

      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:0.75rem;">
        <input
          type="text"
          id="userSearchInput"
          placeholder="&#128269; Search users..."
          value="<?= htmlspecialchars($search ?? '') ?>"
          style="flex:1;min-width:200px;padding:0.45rem 0.75rem;border:1px solid var(--border-color,#d1d5db);border-radius:6px;font-family:inherit;font-size:0.9rem;"
        >
        <button class="btn btn-add" onclick="openAddUserModal()" style="white-space:nowrap;">
          <i class="fas fa-user-plus"></i> Add User
        </button>
      </div>

      <!-- Role filter pills -->
      <div id="userRolePills" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1rem;">
        <button class="user-pill active" data-role="all" onclick="setUserPill(this,'all')">All</button>
        <button class="user-pill" data-role="guest" onclick="setUserPill(this,'guest')">&#128100; Guest</button>
        <button class="user-pill" data-role="staff" onclick="setUserPill(this,'staff')">&#128203; Staff</button>
        <button class="user-pill" data-role="admin" onclick="setUserPill(this,'admin')">&#128737; Admin</button>
      </div>

      <style>
        .user-pill {
          padding: 0.3rem 1rem;
          border-radius: 999px;
          border: 1px solid var(--border-color, #d1d5db);
          background: transparent;
          color: var(--text-muted, #6b7280);
          font-size: 0.82rem;
          font-family: inherit;
          cursor: pointer;
          transition: background 0.15s, color 0.15s, border-color 0.15s;
        }
        .user-pill:hover {
          border-color: var(--red-light, #c0392b);
          color: var(--red-light, #c0392b);
        }
        .user-pill.active {
          background: var(--red-light, #c0392b);
          color: #fff;
          border-color: var(--red-light, #c0392b);
        }
      </style>

      <?php if (!empty($editUser)): ?>
      <div class="form-card">
        <h3>Edit User</h3>
        <form method="POST">
          <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
          <label>Username</label>
          <input type="text" name="username" value="<?= htmlspecialchars($editUser['username']) ?>" required>
          <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:1rem;">Role:
            <strong><?= ucfirst($editUser['role']) ?></strong>
          </p>
          <button type="submit" name="update_user" class="btn"><i class="fas fa-save"></i> Update User</button>
          <a class="btn btn-grey" href="dashboard.php?page=manage_users" style="margin-left:6px;">Cancel</a>
        </form>
      </div>
      <?php endif; ?>

      <div class="table-wrap">
        <table id="usersTable">
          <thead>
          <tr>
            <th>Username</th>
            <th>Role</th>
            <th>Created</th>
            <th>Last Login</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
          </thead>
          <tbody id="usersTableBody">
          <?php if (!empty($users) && $users->num_rows > 0): ?>
            <?php while ($row = $users->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($row['username']) ?></td>
                <td><?= ucfirst($row['role']) ?></td>
                <td><?= htmlspecialchars($row['created_at']) ?></td>
                <td><?= $row['last_login'] ? htmlspecialchars($row['last_login']) : '&mdash;' ?></td>
                <td>
                  <?= $row['force_password_change'] ? '<span class="badge-must-change">Must change password</span>' : '<span class="badge-active">Active</span>' ?>
                </td>
                <td>
                  <a class="btn" href="?page=manage_users&edit=<?= (int) $row['id'] ?>"><i class="fas fa-edit"></i> Edit</a>
                  <?php if ($row['role'] === 'admin'): ?>
                    <button class="btn btn-del" disabled style="opacity:0.4;cursor:not-allowed;">Protected</button>
                  <?php else: ?>
                    <a class="btn btn-del" href="?page=manage_users&delete=<?= (int) $row['id'] ?>"
                      onclick="return confirm('Delete this user?')"><i class="fas fa-trash"></i></a>
                    <a class="btn" href="?page=manage_users&reset=<?= (int) $row['id'] ?>"
                      onclick="return confirm('Reset password for this user?')"><i class="fas fa-key"></i> Reset</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" style="text-align:center;color:var(--text-muted);">No users found.</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
        <div id="usersPagination" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid var(--border-color,#e5e7eb);flex-wrap:wrap;gap:8px;">
          <span id="paginationInfo" style="font-size:0.82rem;color:var(--text-muted);"></span>
          <div id="paginationControls" style="display:flex;gap:6px;align-items:center;"></div>
        </div>
      </div>

      <script>
      (function() {
        const ROWS_PER_PAGE = 7;
        const tbody    = document.getElementById('usersTableBody');
        const info     = document.getElementById('paginationInfo');
        const controls = document.getElementById('paginationControls');
        const searchInput = document.getElementById('userSearchInput');
        if (!tbody) return;

        const allRows = Array.from(tbody.querySelectorAll('tr'));
        let currentPage  = 1;
        let activeRole   = 'all';
        let searchTerm   = (searchInput ? searchInput.value : '').toLowerCase();

        // Attach live search listener
        if (searchInput) {
          searchInput.addEventListener('input', function() {
            searchTerm  = this.value.toLowerCase();
            currentPage = 1;
            render();
          });
        }

        window.setUserPill = function(btn, role) {
          document.querySelectorAll('#userRolePills .user-pill').forEach(p => p.classList.remove('active'));
          btn.classList.add('active');
          activeRole  = role;
          currentPage = 1;
          render();
        };

        function getVisible() {
          return allRows.filter(r => {
            const username = (r.cells[0] ? r.cells[0].textContent : '').toLowerCase();
            const role     = (r.cells[1] ? r.cells[1].textContent : '').toLowerCase().trim();
            const matchSearch = !searchTerm || username.includes(searchTerm);
            const matchRole   = activeRole === 'all' || role === activeRole;
            return matchSearch && matchRole;
          });
        }

        function totalPages(visible) {
          return Math.max(1, Math.ceil(visible.length / ROWS_PER_PAGE));
        }

        function render() {
          const visible = getVisible();
          const tp    = totalPages(visible);
          currentPage = Math.min(currentPage, tp);
          const start = (currentPage - 1) * ROWS_PER_PAGE;
          const end   = start + ROWS_PER_PAGE;

          // Hide all rows first
          allRows.forEach(r => r.style.display = 'none');
          // Show only the current page slice of matched rows
          visible.slice(start, end).forEach(r => r.style.display = '');

          info.textContent = visible.length === 0
            ? 'No users found'
            : `Showing ${start + 1}–${Math.min(end, visible.length)} of ${visible.length} users`;

          buildControls(tp);
        }

        function buildControls(tp) {
          controls.innerHTML = '';
          const btnStyle = (active) =>
            `display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 10px;border-radius:6px;border:1px solid var(--border-color,#e0e0e0);background:${active ? 'var(--red-light,#c0392b)' : 'transparent'};color:${active ? '#fff' : 'var(--text-muted)'};font-size:0.82rem;cursor:pointer;font-family:inherit;`;

          const prev = document.createElement('button');
          prev.innerHTML = '&#8592;';
          prev.style.cssText = btnStyle(false);
          prev.disabled = currentPage === 1;
          prev.onclick = () => { if (currentPage > 1) { currentPage--; render(); } };
          controls.appendChild(prev);

          for (let p = 1; p <= tp; p++) {
            const btn = document.createElement('button');
            btn.textContent = p;
            btn.style.cssText = btnStyle(p === currentPage);
            btn.onclick = ((page) => () => { currentPage = page; render(); })(p);
            controls.appendChild(btn);
          }

          const next = document.createElement('button');
          next.innerHTML = '&#8594;';
          next.style.cssText = btnStyle(false);
          next.disabled = currentPage === tp;
          next.onclick = () => { if (currentPage < tp) { currentPage++; render(); } };
          controls.appendChild(next);
        }

        render();
      })();
      </script>

    <?php elseif ($page === 'categories'): ?>
      <h1 class="page-heading"><i class="fas fa-tags" style="color:var(--red-light);margin-right:8px;"></i>Medicine Categories</h1>
      <?php if (!empty($error_message)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>
      <?php if (!empty($success_message)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>

      <div class="form-card">
        <?php if ($editCategory): ?>
          <h3>Edit: <?= htmlspecialchars($editCategory['name']) ?></h3>
          <form method="POST">
            <input type="hidden" name="id" value="<?= (int) $editCategory['id'] ?>">
            <label>Category Name</label>
            <input type="text" name="category_name" value="<?= htmlspecialchars($editCategory['name']) ?>" required>
            <button type="submit" name="edit_category" class="btn"><i class="fas fa-save"></i> Save Changes</button>
            <a href="dashboard.php?page=categories" class="btn btn-grey" style="margin-left:6px;">Cancel</a>
          </form>
        <?php else: ?>
          <h3>Add New Category</h3>
          <form method="POST">
            <label>Category Name</label>
            <input type="text" name="category_name" placeholder="Enter category name" required>
            <button type="submit" name="add_category" class="btn btn-add"><i class="fas fa-plus"></i> Add Category</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="table-wrap">
        <div class="table-wrap-header">Existing Categories</div>
        <table>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Action</th>
          </tr>
          <?php if ($categories && $categories->num_rows > 0): ?>
            <?php while ($cat = $categories->fetch_assoc()): ?>
              <tr>
                <td><?= (int) $cat['id'] ?></td>
                <td><?= htmlspecialchars($cat['name']) ?></td>
                <td>
                  <a class="btn" href="dashboard.php?page=categories&edit_cat=<?= (int) $cat['id'] ?>"><i
                      class="fas fa-edit"></i> Edit</a>
                  <button class="btn btn-del"
                    onclick="openDeleteCategoryModal(<?= (int) $cat['id'] ?>, '<?= addslashes(htmlspecialchars($cat['name'])) ?>')"><i
                      class="fas fa-trash"></i> Delete</button>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="3" style="text-align:center;color:var(--text-muted);">No categories found.</td>
            </tr>
          <?php endif; ?>
        </table>
      </div>

    <?php elseif ($page === 'medicines'): ?>
      <h1 class="page-heading"><i class="fas fa-pills" style="color:var(--red-light);margin-right:8px;"></i>Medicine Overview</h1>

      <!-- Toolbar: Search + Category Pills -->
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:1rem;flex-wrap:wrap;">
        <input type="text" id="inv-search" placeholder="&#128269; Search by name..."
          style="flex:1;min-width:180px;height:38px;padding:0 12px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:0.88rem;outline:none;"
          oninput="adminFilterMeds()">
      </div>

      <!-- Category Pills -->
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:1.2rem;" id="admin-med-pills">
        <button class="inv-pill active" onclick="adminSetCat('all',this)">All</button>
        <button class="inv-pill" onclick="adminSetCat('low_stock',this)" style="border-color:#f59e0b;color:#92400e;">&#9888; Low Stock</button>
        <button class="inv-pill" onclick="adminSetCat('expiring',this)" style="border-color:#ef4444;color:#7f1d1d;">&#128308; Expiring Soon</button>
        <?php foreach ($medCategories as $cat): ?>
          <button class="inv-pill" onclick="adminSetCat('cat:<?= addslashes(htmlspecialchars($cat)) ?>',this)"><?= htmlspecialchars($cat) ?></button>
        <?php endforeach; ?>
      </div>

      <div class="table-wrap">
        <table id="admin-medicines-table">
          <thead>
            <tr>
              <th>Image</th>
              <th>Name</th>
              <th>Category</th>
              <th>Batch Date</th>
              <th>Expiry Date</th>
              <th style="text-align:center;">Qty</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
          <?php
          if (!empty($meds) && $meds->num_rows > 0):
            while ($row = $meds->fetch_assoc()):
              $expDate = new DateTime($row['expired_date']);
              $todayDt = new DateTime();
              $isExpired = $expDate < $todayDt;
              $isLow = !$isExpired && $row['quantity'] <= 20;
              $isExpiringSoon = !$isExpired && $expDate <= new DateTime('+7 days');
              $statusHtml = $isExpired
                ? '<span class="badge-expired">&#128308; Expired</span>'
                : ($isLow
                  ? '<span class="badge-low">&#9888; Low Stock</span>'
                  : ($isExpiringSoon
                    ? '<span class="badge-low">&#9888; Expiring Soon</span>'
                    : '<span class="badge-good">&#10003; In Stock</span>'));
              $rowClass = $isExpired ? 'expiring-soon' : ($isLow ? 'warning' : '');
              $statusKey = $isExpired ? 'expired' : ($isLow ? 'low_stock' : ($isExpiringSoon ? 'expiring' : 'good'));
              $unit = getMedicineUnit($row['type']);
              ?>
              <tr class="<?= $rowClass ?>"
                data-name="<?= strtolower(htmlspecialchars($row['name'])) ?>"
                data-category="<?= htmlspecialchars($row['type']) ?>"
                data-status="<?= $statusKey ?>">
                <td><img src="../../uploads/medicines/<?= htmlspecialchars($row['image']) ?>" width="40" height="40"
                    style="border-radius:6px;object-fit:cover;" alt=""></td>
                <td><?= htmlspecialchars($row['name']) ?></td>
                <td><span style="background:#fef2f2;color:var(--red-dark);padding:2px 8px;border-radius:10px;font-size:0.75rem;font-weight:600;"><?= htmlspecialchars($row['type']) ?></span></td>
                <td><?= htmlspecialchars($row['batch_date']) ?></td>
                <td><?= htmlspecialchars($row['expired_date']) ?></td>
                <td style="text-align:center;font-weight:600;"><?= (int) $row['quantity'] ?> <span style="font-size:0.7rem;font-weight:400;color:#6b7280;"><?= $unit ?></span></td>
                <td><?= $statusHtml ?></td>
              </tr>
            <?php endwhile;
          else: ?>
            <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:1.5rem 0;">No medicines found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
        <div class="inv-pagination" id="admin-inv-pagination" style="display:none;">
          <span id="admin-inv-page-info"></span>
          <div class="inv-pages" id="admin-inv-pages"></div>
        </div>
      </div>
      <p id="admin-inv-no-results" style="display:none;color:var(--text-muted);text-align:center;padding:1.5rem 0;font-size:0.88rem;">No medicines found matching your search.</p>

      <script>
        let adminActiveCat = 'all';
        let adminCurrentPage = 1;
        const ADMIN_PAGE_SIZE = 6;

        function adminSetCat(cat, btn) {
          adminActiveCat = cat;
          adminCurrentPage = 1;
          document.querySelectorAll('#admin-med-pills .inv-pill').forEach(p => p.classList.remove('active'));
          if (btn) btn.classList.add('active');
          adminFilterMeds();
        }

        function adminFilterMeds() {
          const search = (document.getElementById('inv-search')?.value || '').toLowerCase();
          const rows = [...document.querySelectorAll('#admin-medicines-table tbody tr')];

          const matched = rows.filter(r => {
            const nameMatch = !search || (r.dataset.name || '').includes(search);
            let catMatch = true;
            if (adminActiveCat === 'low_stock') catMatch = r.dataset.status === 'low_stock';
            else if (adminActiveCat === 'expiring') catMatch = r.dataset.status === 'expiring';
            else if (adminActiveCat.startsWith('cat:')) catMatch = r.dataset.category === adminActiveCat.slice(4);
            return nameMatch && catMatch;
          });

          rows.forEach(r => r.style.display = 'none');

          const total = matched.length;
          const totalPages = Math.ceil(total / ADMIN_PAGE_SIZE) || 1;
          adminCurrentPage = Math.min(adminCurrentPage, totalPages);
          const start = (adminCurrentPage - 1) * ADMIN_PAGE_SIZE;
          const end = Math.min(start + ADMIN_PAGE_SIZE, total);
          matched.slice(start, end).forEach(r => r.style.display = '');

          const noResults = document.getElementById('admin-inv-no-results');
          if (noResults) noResults.style.display = matched.length === 0 ? 'block' : 'none';

          const pag = document.getElementById('admin-inv-pagination');
          const info = document.getElementById('admin-inv-page-info');
          const pages = document.getElementById('admin-inv-pages');
          pag.style.display = total > ADMIN_PAGE_SIZE ? 'flex' : 'none';
          if (info) info.textContent = total === 0 ? 'No results' : `Showing ${start+1}–${end} of ${total}`;
          if (pages) {
            pages.innerHTML = '';
            const prev = document.createElement('button');
            prev.className = 'inv-page-btn'; prev.innerHTML = '&#8249;';
            prev.disabled = adminCurrentPage === 1;
            prev.onclick = () => { adminCurrentPage--; adminFilterMeds(); };
            pages.appendChild(prev);
            for (let i = 1; i <= totalPages; i++) {
              if (i === 1 || i === totalPages || Math.abs(i - adminCurrentPage) <= 2) {
                const btn = document.createElement('button');
                btn.className = 'inv-page-btn' + (i === adminCurrentPage ? ' active' : '');
                btn.textContent = i;
                btn.onclick = () => { adminCurrentPage = i; adminFilterMeds(); };
                pages.appendChild(btn);
              }
            }
            const next = document.createElement('button');
            next.className = 'inv-page-btn'; next.innerHTML = '&#8250;';
            next.disabled = adminCurrentPage === totalPages;
            next.onclick = () => { adminCurrentPage++; adminFilterMeds(); };
            pages.appendChild(next);
          }
        }

        document.addEventListener('DOMContentLoaded', adminFilterMeds);
      </script>

    <?php elseif ($page === 'logs'): ?>
      <h1 class="page-heading"><i class="fas fa-history" style="color:var(--red-light);margin-right:8px;"></i>Activity Logs</h1>
      <div class="table-wrap">
        <table>
          <tr>
            <th>ID</th>
            <th>User</th>
            <th>Action</th>
            <th>Timestamp</th>
          </tr>
          <?php $logs = $conn->query('SELECT * FROM logs ORDER BY timestamp DESC');
          while ($log = $logs->fetch_assoc()): ?>
            <tr>
              <td><?= (int) $log['id'] ?></td>
              <td><?= htmlspecialchars($log['user']) ?></td>
              <td><?= htmlspecialchars($log['action']) ?></td>
              <td><?= htmlspecialchars($log['timestamp']) ?></td>
            </tr>
          <?php endwhile; ?>
        </table>
      </div>

    <?php elseif ($page === 'schedules'): ?>
      <h1 class="page-heading"><i class="fas fa-clock" style="color:var(--red-light);margin-right:8px;"></i>Check Schedules</h1>
      <div class="form-card" style="max-width:520px;">
        <h3>Create New Schedule</h3>
        <form method="POST">
          <label>Schedule Name</label>
          <input type="text" name="schedule_name" placeholder="e.g. Weekly Medicine Check" required>
          <label>Type</label>
          <select name="schedule_type" id="schedule_type" required>
            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
            <option value="monthly">Monthly</option>
          </select>
          <label>Time</label>
          <input type="time" name="schedule_time" required>
          <p id="dayField" style="display:none;">
            <label for="schedule_day" id="dayLabel">Day</label>
            <input type="number" name="schedule_day" id="schedule_day" min="1" max="31">
          </p>
          <input type="submit" name="create_schedule" value="Create Schedule" class="btn btn-add" style="margin-top:4px;">
        </form>
      </div>

      <div class="table-wrap">
        <div class="table-wrap-header">Active Schedules</div>
        <table>
          <tr>
            <th>Name</th>
            <th>Created By</th>
            <th>Type</th>
            <th>Time</th>
            <th>Next Check</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
          <?php if ($schedules && $schedules->num_rows > 0): ?>
            <?php while ($sched = $schedules->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($sched['schedule_name']) ?></td>
                <td>
                  <span style="display:inline-flex;align-items:center;gap:5px;">
                    <span
                      style="width:22px;height:22px;border-radius:6px;background:linear-gradient(135deg,var(--red-light),var(--red-deeper));display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:0.65rem;font-weight:600;flex-shrink:0;">
                      <?= strtoupper(substr($sched['created_by'] ?? 'A', 0, 1)) ?>
                    </span>
                    <?= htmlspecialchars($sched['created_by'] ?? 'Unknown') ?>
                  </span>
                </td>
                <td><?= ucfirst($sched['schedule_type']) ?></td>
                <td><?= $sched['schedule_time'] ?></td>
                <td><?= $sched['next_check'] ?></td>
                <td>
                  <?= $sched['is_active'] ? '<span class="badge-active">Active</span>' : '<span class="badge-inactive">Inactive</span>' ?>
                </td>
                <td>
                  <button class="btn"
                    onclick="toggleSchedule(<?= $sched['id'] ?>)"><?= $sched['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                  <button class="btn"
                    onclick="openEditScheduleModal(<?= $sched['id'] ?>, '<?= addslashes(htmlspecialchars($sched['schedule_name'])) ?>', '<?= $sched['schedule_type'] ?>', '<?= $sched['schedule_time'] ?>', <?= $sched['schedule_day'] ?>)"><i
                      class="fas fa-edit"></i> Edit</button>
                  <button class="btn btn-del"
                    onclick="openDeleteScheduleModal(<?= $sched['id'] ?>, '<?= addslashes(htmlspecialchars($sched['schedule_name'])) ?>')"><i
                      class="fas fa-trash"></i></button>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" style="text-align:center;color:var(--text-muted);">No schedules found.</td>
            </tr>
          <?php endif; ?>
        </table>
      </div>

    <?php else: ?>
      <h1 class="page-heading">Page Not Found</h1>
      <p style="color:var(--text-muted);">Unknown page requested.</p>
    <?php endif; ?>

  </div><!-- /main-content -->

  <script src="../../scripts/a_dashboard.js"></script>
  <script>
    function openAddUserModal() {
      document.getElementById('addUserModal').style.display = 'flex';
    }
    function closeAddUserModal() {
      document.getElementById('addUserModal').style.display = 'none';
    }
    // Close modal on backdrop click
    document.getElementById('addUserModal').addEventListener('click', function(e) {
      if (e.target === this) closeAddUserModal();
    });
  </script>
</body>

</html>
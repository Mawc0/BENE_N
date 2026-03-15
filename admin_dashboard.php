<?php
session_start();
include('db.php');
// ✅ Security check
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}
// Page handling
$page = $_GET['page'] ?? 'dashboard';
$error_message = '';
$success_message = '';
$recentLogs = null;
$notifications = null;
// unread count
$unreadCount = 0;
$unreadRes = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE is_read = 0");
if ($unreadRes) {
    $row = $unreadRes->fetch_assoc();
    $unreadCount = isset($row['c']) ? (int)$row['c'] : 0;
}
// Mark all notifications as read
if ($page === 'notifications' && isset($_POST['mark_all_read'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
    $unreadCount = 0;
    header("Location: admin_dashboard.php?page=notifications");
    exit();
}
// Fetch notifications only when needed
if ($page === 'notifications') {
    $notifications = $conn->query("
        SELECT n.*, u.username
        FROM notifications n
        LEFT JOIN users u ON n.user_id = u.id
        ORDER BY n.is_read ASC, n.created_at DESC
        LIMIT 50
    ");
}
// Handle add/update/delete users
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $username = trim($_POST['username']);
        $passwordRaw = trim($_POST['password'] ?? '');
        $role = trim($_POST['role'] ?? 'staff');
        if (!in_array($role, ['staff', 'guest'])) {
            $role = 'staff';
        }
        if (empty($passwordRaw)) {
            $passwordRaw = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        }
        // Validate username uniqueness
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header("Location: admin_dashboard.php?page=manage_users&msg=Username already exists");
            exit();
        }
        $check->close();
        $password = password_hash($passwordRaw, PASSWORD_DEFAULT);
        $defaultPic = 'default.jpg';
        $forceChange = 1; // 👈 Define it!
        $stmt = $conn->prepare("INSERT INTO users (username, password, role, profile_pic, force_password_change) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $username, $password, $role, $defaultPic, $forceChange); // 👈 'i' for integer
        if ($stmt->execute()) {
            $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Added new user $username as $role')");
            header("Location: admin_dashboard.php?page=manage_users&msg=User added successfully. Temporary password: $passwordRaw");
            exit();
        } else {
            header("Location: admin_dashboard.php?page=manage_users&msg=Error adding user: " . $stmt->error);
            exit();
        }
        $stmt->close();
    }
    if (isset($_POST['update_user'])) {
        $id = $_POST['id'];
        $username = trim($_POST['username']);
        $role = $_POST['role'];
        $stmt = $conn->prepare("UPDATE users SET username=?, role=? WHERE id=?");
        $stmt->bind_param("ssi", $username, $role, $id);
        if ($stmt->execute()) {
            $success_message = "User updated successfully.";
        } else {
            $error_message = "Error: " . $stmt->error;
        }
        $stmt->close();
    }
    if (isset($_POST['delete_user'])) {
        $id = $_POST['id'];
        $stmt = $conn->prepare("SELECT role FROM users WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $roleRes = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($roleRes && $roleRes['role'] === 'admin') {
            $error_message = "Cannot delete an admin account!";
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $success_message = "User deleted successfully.";
            } else {
                $error_message = "Error: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
// Handle categories
if ($page === 'categories') {
    // Add new category
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
        $newCat = trim($_POST['category_name']);
        if (!empty($newCat)) {
            $stmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?)");
            $stmt->bind_param("s", $newCat);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                $stmt->bind_param("s", $newCat);
                $stmt->execute();
                $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Added category: $newCat')");
                $success_message = "Category added successfully.";
            } else {
                $error_message = "Category already exists.";
            }
            $stmt->close();
        }
    }
    // Edit category
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
        $catId = (int)$_POST['id'];
        $newName = trim($_POST['category_name']);
        if (!empty($newName)) {
            $stmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND id != ?");
            $stmt->bind_param("si", $newName, $catId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
                $stmt->bind_param("si", $newName, $catId);
                if ($stmt->execute()) {
                    $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Edited category ID $catId to \"$newName\"')");
                    $success_message = "Category updated successfully.";
                } else {
                    $error_message = "Failed to update category.";
                }
            } else {
                $error_message = "Category name already exists.";
            }
            $stmt->close();
        } else {
            $error_message = "Category name cannot be empty.";
        }
        header("Location: admin_dashboard.php?page=categories");
        exit();
    }
    // Delete category
    if (isset($_GET['delete_cat'])) {
        $catId = (int)$_GET['delete_cat'];
        $res = $conn->query("SELECT name FROM categories WHERE id = $catId");
        if ($res && $res->num_rows > 0) {
            $catName = $res->fetch_assoc()['name'];
            $conn->query("DELETE FROM categories WHERE id = $catId");
            $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Deleted category: $catName')");
            header("Location: admin_dashboard.php?page=categories");
            exit();
        }
    }
    // Edit mode
    $editCategory = null;
    if (isset($_GET['edit_cat'])) {
        $editId = (int)$_GET['edit_cat'];
        $res = $conn->query("SELECT * FROM categories WHERE id = $editId");
        if ($res && $res->num_rows > 0) {
            $editCategory = $res->fetch_assoc();
        }
    }
    $categories = $conn->query("SELECT * FROM categories ORDER BY id");
}
// Handle filters for Medicines
$where = "1=1";
$search = "";
if ($page === 'medicines') {
    if (isset($_GET['filter']) && $_GET['filter'] == "low_stock") {
        $where = "quantity <= 20 AND expired_date > CURDATE()";
    } elseif (isset($_GET['filter']) && $_GET['filter'] == "expiring") {
        $where = "expired_date <= CURDATE() + INTERVAL 7 DAY AND expired_date >= CURDATE()";
    }
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $search = $conn->real_escape_string($_GET['search']);
        $where .= " AND (
            name LIKE '%$search%' 
            OR type LIKE '%$search%' 
            OR CAST(quantity AS CHAR) LIKE '%$search%'
            OR DATE_FORMAT(expired_date, '%Y-%m-%d') LIKE '%$search%'
        )";
    }
    $meds = $conn->query("SELECT * FROM medicines WHERE $where ORDER BY expired_date ASC");
}
// Dashboard data
if ($page === 'dashboard') {
    $res = $conn->query("SELECT COUNT(*) AS total FROM users");
    $totalUsers = $res->fetch_assoc()['total'] ?? 0;
    $res = $conn->query("SELECT COUNT(*) AS total FROM medicines");
    $totalMeds = $res->fetch_assoc()['total'] ?? 0;
    $res = $conn->query("SELECT COUNT(*) AS total FROM medicines WHERE quantity <= 20 AND expired_date > CURDATE()");
    $lowStockMeds = $res->fetch_assoc()['total'] ?? 0;
    $res = $conn->query("SELECT COUNT(*) AS total FROM medicines WHERE expired_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
    $expiringSoonMeds = $res->fetch_assoc()['total'] ?? 0;
    $recentLogs = $conn->query("SELECT * FROM logs ORDER BY timestamp DESC LIMIT 5");
}
// Donation request handling
if ($page === 'donations') {
    if (isset($_GET['approve'])) {
        $reqId = (int)$_GET['approve'];
        $info = $conn->query("
            SELECT dr.staff_id, m.name AS med_name, u.username AS staff_name
            FROM donation_requests dr
            JOIN medicines m ON dr.medicine_id = m.id
            JOIN users u ON dr.staff_id = u.id
            WHERE dr.id = $reqId AND dr.status = 'pending'
        ")->fetch_assoc();
        if ($info) {
            $conn->query("UPDATE donation_requests SET status='approved', approved_at=NOW() WHERE id=$reqId");
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $msg = "Your donation request for \"{$info['med_name']}\" has been approved by admin.";
            $stmt->bind_param("is", $info['staff_id'], $msg);
            $stmt->execute();
            $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Approved donation request for {$info['med_name']} by {$info['staff_name']}')");
            header("Location: admin_dashboard.php?page=donations");
            exit();
        }
    }
    if (isset($_GET['reject'])) {
        $reqId = (int)$_GET['reject'];
        $info = $conn->query("
            SELECT dr.staff_id, m.name AS med_name, u.username AS staff_name
            FROM donation_requests dr
            JOIN medicines m ON dr.medicine_id = m.id
            JOIN users u ON dr.staff_id = u.id
            WHERE dr.id = $reqId AND dr.status = 'pending'
        ")->fetch_assoc();
        if ($info) {
            $conn->query("UPDATE donation_requests SET status='rejected', approved_at=NOW() WHERE id=$reqId");
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $msg = "Your donation request for \"{$info['med_name']}\" was rejected by admin.";
            $stmt->bind_param("is", $info['staff_id'], $msg);
            $stmt->execute();
            $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Rejected donation request for {$info['med_name']} by {$info['staff_name']}')");
            header("Location: admin_dashboard.php?page=donations");
            exit();
        }
    }
}
// Manage Users
if ($page === 'manage_users') {
    if (isset($_GET['reset'])) {
        $id = (int)$_GET['reset'];
        $res = $conn->query("SELECT username, role FROM users WHERE id=$id");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if ($row['role'] === 'admin') {
                header("Location: admin_dashboard.php?page=manage_users&msg=Cannot reset admin password");
                exit;
            }
            $tempPassword = "Temp" . rand(1000, 9999);
            $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users 
                SET password=?, force_password_change=1, force_security_setup=1, 
                    security_question=NULL, security_answer=NULL 
                WHERE id=?");
            $stmt->bind_param("si", $hash, $id);
            $stmt->execute();
            $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Reset password for ".$row['username']."')");
            header("Location: admin_dashboard.php?page=manage_users&msg=Password reset for ".$row['username'].". Temporary password: $tempPassword");
            exit;
        }
    }
    $editUser = null;
    if (isset($_GET['edit'])) {
        $id = (int)$_GET['edit'];
        $res = $conn->query("SELECT * FROM users WHERE id=$id");
        $editUser = $res ? $res->fetch_assoc() : null;
    }
    $search = $_GET['search'] ?? '';
    if (trim($search) !== '') {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username LIKE ? OR role LIKE ? ORDER BY created_at DESC");
        $like = "%$search%";
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $users = $stmt->get_result();
    } else {
        $users = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
    }
}
// Handle schedules
if ($page === 'schedules') {
    if (isset($_POST['create_schedule'])) {
        $schedule_name = $_POST['schedule_name'];
        $schedule_type = $_POST['schedule_type'];
        $schedule_time = $_POST['schedule_time'];
        $schedule_day = $_POST['schedule_day'] ?? null;
        $next_check = date('Y-m-d H:i:s');
        if ($schedule_type == 'daily') {
            // For daily schedules, always set the next check to tomorrow at the specified time.
            $next_check = date('Y-m-d', strtotime('+1 day')) . ' ' . $schedule_time;
        } elseif ($schedule_type == 'weekly') {
            $schedule_day = $schedule_day ?? date('N');
            $day_name = date('l', strtotime("next Sunday + {$schedule_day} days"));
            $next_check = date('Y-m-d H:i:s', strtotime("next {$day_name} " . $schedule_time));
        } elseif ($schedule_type == 'monthly') {
            $schedule_day = $schedule_day ?? date('j');
            $next_check = date('Y-m-d H:i:s', strtotime("first day of next month +" . ((int)$schedule_day - 1) . " days " . $schedule_time));
        }
        $admin_id = $conn->query("SELECT id FROM users WHERE username = '{$_SESSION['username']}'")->fetch_assoc()['id'];
        $stmt = $conn->prepare("INSERT INTO admin_schedules (admin_id, schedule_name, schedule_type, schedule_time, schedule_day, next_check) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssis", $admin_id, $schedule_name, $schedule_type, $schedule_time, $schedule_day, $next_check);
        $stmt->execute();
        header("Location: admin_dashboard.php?page=schedules");
        exit();
    }
    if (isset($_GET['toggle_schedule'])) {
        $id = (int)$_GET['toggle_schedule'];
        $stmt = $conn->prepare("UPDATE admin_schedules SET is_active = NOT is_active WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header("Location: admin_dashboard.php?page=schedules");
        exit();
    }
    // Delete schedule
    if (isset($_GET['delete_schedule'])) {
        $id = (int)$_GET['delete_schedule'];
        $stmt = $conn->prepare("DELETE FROM admin_schedules WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header("Location: admin_dashboard.php?page=schedules");
        exit();
    }
    // Edit schedule
    if (isset($_POST['edit_schedule'])) {
        $id = (int)$_POST['id'];
        $schedule_name = $_POST['schedule_name'];
        $schedule_type = $_POST['schedule_type'];
        $schedule_time = $_POST['schedule_time'];
        $schedule_day = $_POST['schedule_day'] ?? null;
        $next_check = date('Y-m-d H:i:s');
        if ($schedule_type == 'daily') {
            // For daily schedules, always set the next check to tomorrow at the specified time.
            $next_check = date('Y-m-d', strtotime('+1 day')) . ' ' . $schedule_time;
        } elseif ($schedule_type == 'weekly') {
            $schedule_day = $schedule_day ?? date('N');
            $day_name = date('l', strtotime("next Sunday + {$schedule_day} days"));
            $next_check = date('Y-m-d H:i:s', strtotime("next {$day_name} " . $schedule_time));
        } elseif ($schedule_type == 'monthly') {
            $schedule_day = $schedule_day ?? date('j');
            $next_check = date('Y-m-d H:i:s', strtotime("first day of next month +".($schedule_day-1)." days " . $schedule_time));
        }
        $stmt = $conn->prepare("UPDATE admin_schedules SET schedule_name=?, schedule_type=?, schedule_time=?, schedule_day=?, next_check=? WHERE id=?");
        $stmt->bind_param("sssssi", $schedule_name, $schedule_type, $schedule_time, $schedule_day, $next_check, $id);
        $stmt->execute();
        header("Location: admin_dashboard.php?page=schedules");
        exit();
    }
    // Get schedule for editing
    $editSchedule = null;
    if (isset($_GET['edit_schedule'])) {
        $id = (int)$_GET['edit_schedule'];
        $stmt = $conn->prepare("SELECT * FROM admin_schedules WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $editSchedule = $result->fetch_assoc();
    }
    $schedules = $conn->query("
        SELECT * FROM admin_schedules 
        WHERE admin_id = (SELECT id FROM users WHERE username = '{$_SESSION['username']}')
        ORDER BY next_check ASC
    ");
}
// Function to check schedules (only if table exists)
function checkSchedules() {
    global $conn;
    // Ensure timezone consistency
    date_default_timezone_set('Asia/Manila');
    // Check if table exists first
    $tableCheck = $conn->query("SHOW TABLES LIKE 'admin_schedules'");
    if ($tableCheck->num_rows == 0) {
        error_log("admin_schedules table does not exist. Skipping schedule check.");
        return;
    }
    $now = date('Y-m-d H:i:s');
    error_log("Current Server Time for Schedule Check: $now");
    // Prepare the schedule selection
    $stmt = $conn->prepare("SELECT * FROM admin_schedules WHERE next_check <= ? AND is_active = 1");
    if (!$stmt) {
        error_log("Prepare failed for schedule check: " . $conn->error);
        return;
    }
    $stmt->bind_param("s", $now);
    if (!$stmt->execute()) {
        error_log("Execute failed for schedule check: " . $stmt->error);
        $stmt->close();
        return;
    }
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        error_log("Found " . $result->num_rows . " schedules needing checks.");
    } else {
        error_log("No active schedules found that are due for checking. NOW: $now");
    }
    while ($sched = $result->fetch_assoc()) {
        error_log("Processing schedule ID: {$sched['id']}, Name: '{$sched['schedule_name']}', Next Check: {$sched['next_check']}");
        // Create notification
        $msg = "Time to check: " . $sched['schedule_name'];
        $stmt2 = $conn->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
        if (!$stmt2) {
            error_log("Prepare INSERT notification failed: " . $conn->error);
            continue;
        }
        $stmt2->bind_param("is", $sched['admin_id'], $msg);
        if (!$stmt2->execute()) {
            error_log("Execute INSERT notification failed for schedule '{$sched['schedule_name']}' (ID: {$sched['id']}): " . $stmt2->error);
        } else {
            error_log("✅ Notification created for schedule '{$sched['schedule_name']}' (ID: {$sched['id']}) for admin ID: {$sched['admin_id']}");
        }
        $stmt2->close();
        // Update next check
        updateNextCheck($sched['id'], $sched['schedule_type'], $sched['schedule_time'], $sched['schedule_day']);
    }
    $stmt->close();
}
function updateNextCheck($id, $type, $time, $day = null) {
    global $conn;
    $next_check = '';
    switch($type) {
        case 'daily':
            $next_check = date('Y-m-d', strtotime('+1 day')) . ' ' . $time;
            break;
        case 'weekly':
            if ($day) {
                $day_name = date('l', strtotime("next Sunday + {$day} days"));
                $next_check = date('Y-m-d H:i:s', strtotime("next {$day_name} " . $time));
            } else {
                $next_check = date('Y-m-d', strtotime('+7 days')) . ' ' . $time;
            }
            break;
        case 'monthly':
            if ($day) {
                $next_check = date('Y-m-d H:i:s', strtotime("first day of next month +".($day-1)." days " . $time));
            } else {
                $next_check = date('Y-m-d', strtotime('+30 days')) . ' ' . $time;
            }
            break;
    }
    $stmt = $conn->prepare("UPDATE admin_schedules SET next_check = ? WHERE id = ?");
    $stmt->bind_param("si", $next_check, $id);
    $stmt->execute();
}
// Check schedules on page load
checkSchedules();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Dashboard | BENE MediCon</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css  " rel="stylesheet">
  <style>
    /* Profile Menu */
    .profile-menu {
      position: fixed;
      top: 20px;
      right: 80px;
      z-index: 1000;
    }
    .profile-icon {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: #f4e35f;
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 20px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.2);
      transition: background 0.3s ease, color 0.3s ease;
    }
    .profile-icon:hover {
      background: #073774;
      color: white;
    }
    .profile-dropdown {
      position: absolute;
      top: 50px;
      right: 0;
      background: white;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      min-width: 200px;
      display: none;
      animation: slideDown 0.3s ease;
    }
    @keyframes slideDown {
      from { transform: translateY(-10px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }
    .profile-dropdown.show {
      display: block;
    }
    .profile-header {
      padding: 15px;
      border-bottom: 1px solid #eee;
      text-align: center;
    }
    .profile-name {
      font-weight: 600;
      color: #333;
      margin: 0;
    }
    .profile-role {
      color: #666;
      font-size: 0.9em;
      margin: 5px 0 0;
    }
    .profile-dropdown a {
      display: flex;
      align-items: center;
      padding: 12px 15px;
      color: #333;
      text-decoration: none;
      transition: background 0.2s;
    }
    .profile-dropdown a:hover {
      background: #f5f5f5;
    }
    .profile-dropdown i {
      margin-right: 10px;
      width: 20px;
      color: #666;
    }
    /* Notifications icon */
    .notif-icon {
      position: relative;
      margin-right: 12px;
      font-size: 20px;
      color: #073774;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
    }
    .notif-icon .badge {
      position: absolute;
      top: -6px;
      right: -8px;
      background: red;
      color: white;
      border-radius: 50%;
      padding: 2px 6px;
      font-size: 0.75em;
      font-weight: 600;
    }
    .notif-icon:hover { color: #1a73e8; }
    * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI','Roboto',sans-serif; }
    body { background:#f5f7fa; min-height:100vh; }
    .sidebar {
      position: fixed; top: 0; left: 0;
      width: 80px; height: 100%;
      background: #BC2605;
      color: #fff;
      padding-top: 20px;
      transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
      overflow: hidden;
      box-shadow: 3px 0 15px rgba(0,0,0,0.1);
      z-index: 1000;
    }
    .sidebar.expanded { width: 240px; }
    .sidebar-header {
      display: flex; align-items: center;
      padding: 0 15px 20px 15px;
    }
    .hamburger-btn {
      width: 40px;
      height: 40px;
      background-color: rgba(255, 255, 255, 0.15);
      border: none;
      border-radius: 8px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.3s ease;
    }
    .hamburger-btn:hover {
      background-color: rgba(255, 255, 255, 0.25);
    }
    .hamburger-svg {
      width: 22px;
      height: 22px;
      position: relative;
    }
    .hamburger-svg line {
      stroke: white;
      stroke-width: 2.5;
      stroke-linecap: round;
      transition: transform 0.4s ease, opacity 0.3s ease;
      transform-origin: center;
    }
    .line-1 { y1: 6; y2: 6; x1: 4; x2: 20; }
    .line-2 { y1: 12; y2: 12; x1: 4; x2: 20; }
    .line-3 { y1: 18; y2: 18; x1: 4; x2: 20; }
    .sidebar.expanded .line-1 { transform: rotate(45deg) translate(3px,3px); }
    .sidebar.expanded .line-2 { opacity:0; }
    .sidebar.expanded .line-3 { transform: rotate(-45deg) translate(4px,-4px); }
    .sidebar h2 {
      font-size:1.2rem; margin-left:15px;
      white-space:nowrap; opacity:0;
      transition: opacity .3s .1s ease;
    }
    .sidebar.expanded h2 { opacity:1; }
    .sidebar button {
      display:flex; align-items:center;
      padding:12px 20px; width:100%;
      border:none; background:none; color:white;
      font-size:1rem; cursor:pointer; text-align:left;
      border-radius:0 20px 20px 0;
    }
    .sidebar button i { min-width:30px; text-align:center; font-size:1.3rem; }
    .sidebar button span {
      opacity:0; margin-left:12px; transition:opacity .3s .1s ease;
    }
    .sidebar.expanded button span { opacity:1; }
    .sidebar button:hover { background:#073774; }
    .main-content {
      margin-left:80px; padding:30px; transition:margin-left .4s cubic-bezier(0.4,0,0.2,1);
    }
    .sidebar.expanded ~ .main-content { margin-left:240px; }
    h1 { color:#1a73e8; margin-bottom:20px; font-weight:600; }
    .cards { display:flex; gap:20px; flex-wrap:wrap; margin-bottom:30px; }
    .card {
      flex:1; min-width:200px; padding:20px; border-radius:12px;
      color:#fff; text-align:center;
      box-shadow:0 4px 8px rgba(0,0,0,0.1);
    }
    .card h3 { margin-bottom:10px; font-size:1.1rem; }
    .card p { font-size:2rem; font-weight:bold; margin:0; }
    .card-1 { background:linear-gradient(135deg,#1a73e8,#4285f4); }
    .card-2 { background:linear-gradient(135deg,#34a853,#66bb6a); }
    .card-3 { background:linear-gradient(135deg,#ea4335,#ef5350); }
    .card-4 { background:linear-gradient(135deg,#fbbc05,#ffb300); }
    table { width:100%; border-collapse:collapse; margin-top:20px; background:#fff; border-radius:8px; overflow:hidden; }
    th,td { padding:12px; border-bottom:1px solid #ddd; text-align:left; }
    th { background:#f0f4f8; color:#333; }
    tr:hover { background:#f9fafa; }
    .good { color: green; font-weight: 600; }
    .low { color: orange; font-weight: 600; }
    .expired { color: red; font-weight: 600; }
    button, .btn {
      background: #0a4c9e;
      color: white;
      border: none;
      padding: 10px 18px;
      border-radius: 8px;
      cursor: pointer;
      font-size: 0.95rem;
      font-weight: 500;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
    }
    button:hover, .btn:hover {
      background: #073774;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }
    .btn-add { background: #2ecc71; }
    .btn-add:hover { background: #27ae60; }
    .btn-del { background: #e74c3c; }
    .btn-del:hover { background: #c0392b; }
    .filters a {
      margin: 0 5px;
      text-decoration: none;
      padding: 8px 14px;
      border-radius: 8px;
      background: #0a4c9e;
      color: white;
      font-size: 0.9rem;
      font-weight: 500;
      transition: all 0.3s ease;
      display: inline-block;
    }
    .filters a:hover {
      background: #073774;
      transform: translateY(-2px);
      box-shadow: 0 3px 6px rgba(0,0,0,0.15);
    }
    .status-pending { color: #f57c00; font-weight: bold; }
    .status-approved { color: #388e3c; font-weight: bold; }
    .status-rejected { color: #d32f2f; font-weight: bold; }
    /* Delete Confirmation Modal */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
    }
    .modal-content {
      background-color: #fff;
      margin: 15% auto;
      padding: 20px;
      border-radius: 10px;
      width: 90%;
      max-width: 400px;
      text-align: center;
    }
    .modal-header {
      margin-bottom: 15px;
      padding-bottom: 10px;
      border-bottom: 1px solid #eee;
    }
    .modal-header h3 {
      color: #d32f2f;
      margin: 0;
    }
    .modal-close {
      float: right;
      font-size: 24px;
      cursor: pointer;
      color: #aaa;
    }
    .modal-close:hover {
      color: #000;
    }
  </style>
</head>
<body>
<!-- Logout Modal -->
<div id="logoutModal" class="modal" style="display:none;">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-sign-out-alt"></i> Confirm Logout</h3>
      <span class="modal-close" onclick="closeLogoutModal()">&times;</span>
    </div>
    <p>Are you sure you want to log out of your admin account?</p>
    <div style="margin-top:20px;">
      <a href="logout.php" style="background:#d32f2f; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; margin-right:10px;">Yes, Logout</a>
      <button onclick="closeLogoutModal()" style="background:#757575; color:white; border:none; padding:10px 20px; border-radius:5px;">Cancel</button>
    </div>
  </div>
</div>
<!-- Category Delete Confirmation Modal -->
<div id="deleteCategoryModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3 style="color: #e53935;">⚠️ Delete Category</h3>
      <span class="modal-close" onclick="closeDeleteCategoryModal()">&times;</span>
    </div>
    <p>Are you sure you want to delete the category "<span id="deleteCategoryName"></span>"?<br><strong>This cannot be undone.</strong></p>
    <div style="margin-top:20px;">
      <button onclick="closeDeleteCategoryModal()" style="padding:8px 16px; background:#9e9e9e; color:white; border:none; border-radius:4px; margin-right:10px;">Cancel</button>
      <a id="confirmDeleteCategory" href="#" style="padding:8px 16px; background:#e53935; color:white; text-decoration:none; border-radius:4px;">Delete</a>
    </div>
  </div>
</div>
<!-- Schedule Delete Confirmation Modal -->
<div id="deleteScheduleModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3 style="color: #e53935;">⚠️ Delete Schedule</h3>
      <span class="modal-close" onclick="closeDeleteScheduleModal()">&times;</span>
    </div>
    <p>Are you sure you want to delete the schedule "<span id="deleteScheduleName"></span>"?<br><strong>This cannot be undone.</strong></p>
    <div style="margin-top:20px;">
      <button onclick="closeDeleteScheduleModal()" style="padding:8px 16px; background:#9e9e9e; color:white; border:none; border-radius:4px; margin-right:10px;">Cancel</button>
      <a id="confirmDeleteSchedule" href="#" style="padding:8px 16px; background:#e53935; color:white; text-decoration:none; border-radius:4px;">Delete</a>
    </div>
  </div>
</div>
<!-- Schedule Edit Modal -->
<div id="editScheduleModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3 style="color: #0a4c9e;">Edit Schedule</h3>
      <span class="modal-close" onclick="closeEditScheduleModal()">&times;</span>
    </div>
    <form method="POST" id="editScheduleForm">
      <input type="hidden" name="id" id="editScheduleId">
      <p>
        <label for="editScheduleName">Schedule Name:</label>
        <input type="text" name="schedule_name" id="editScheduleName" required style="width:100%; padding:8px; margin:5px 0; border:1px solid #ccc; border-radius:4px;">
      </p>
      <p>
        <label for="editScheduleType">Schedule Type:</label>
        <select name="schedule_type" id="editScheduleType" required style="width:100%; padding:8px; margin:5px 0; border:1px solid #ccc; border-radius:4px;">
          <option value="daily">Daily</option>
          <option value="weekly">Weekly</option>
          <option value="monthly">Monthly</option>
        </select>
      </p>
      <p>
        <label for="editScheduleTime">Time:</label>
        <input type="time" name="schedule_time" id="editScheduleTime" required style="width:100%; padding:8px; margin:5px 0; border:1px solid #ccc; border-radius:4px;">
      </p>
      <!-- Conditional Day Field -->
      <p id="editDayField" style="display:none;">
        <label for="editScheduleDay" id="editDayLabel">Day (1-7 for weekly, 1-31 for monthly):</label>
        <input type="number" name="schedule_day" id="editScheduleDay" min="1" max="31" style="width:100%; padding:8px; margin:5px 0; border:1px solid #ccc; border-radius:4px;">
      </p>
      <div style="margin-top:20px; text-align:center;">
        <button type="submit" name="edit_schedule" class="btn" style="background:#0a4c9e; color:white; padding:10px 20px; border:none; border-radius:5px; margin-right:10px;">Update Schedule</button>
        <button type="button" onclick="closeEditScheduleModal()" class="btn" style="background:#757575; color:white; padding:10px 20px; border:none; border-radius:5px;">Cancel</button>
      </div>
    </form>
  </div>
</div>
<div class="profile-menu">
  <a href="admin_dashboard.php?page=notifications" class="notif-icon" title="Notifications">
    <i class="fas fa-bell"></i>
    <?php if ($unreadCount > 0): ?>
      <span class="badge"><?= $unreadCount ?></span>
    <?php endif; ?>
  </a>
  <div class="profile-icon" onclick="toggleProfileMenu()">
    <i class="fas fa-user"></i>
  </div>
  <div class="profile-dropdown" id="profileDropdown">
    <div class="profile-header">
      <p class="profile-name"><?php echo $_SESSION['username'] ?? 'User'; ?></p>
      <p class="profile-role"><?= ucfirst($_SESSION['role'] ?? 'User'); ?></p>
    </div>
    <a href="#" onclick="openLogoutModal()">
      <i class="fas fa-sign-out-alt"></i>
      Logout
    </a>
  </div>
</div>
<div class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <button id="hamburger" class="hamburger-btn">
      <svg class="hamburger-svg" viewBox="0 0 24 24">
        <line class="line-1" x1="4" y1="6" x2="20" y2="6" />
        <line class="line-2" x1="4" y1="12" x2="20" y2="12" />
        <line class="line-3" x1="4" y1="18" x2="20" y2="18" />
      </svg>
    </button>
    <h2>Admin Panel</h2>
  </div>
  <button onclick="window.location.href='admin_dashboard.php?page=dashboard'"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></button>
  <button onclick="window.location.href='admin_dashboard.php?page=manage_users'"><i class="fas fa-users"></i><span>Manage Users</span></button>
  <button onclick="window.location.href='admin_dashboard.php?page=medicines'"><i class="fas fa-pills"></i><span>Medicines</span></button>
  <button onclick="window.location.href='admin_dashboard.php?page=categories'"><i class="fas fa-tags"></i><span>Categories</span></button>
  <button onclick="window.location.href='admin_dashboard.php?page=donations'"><i class="fas fa-hand-holding-medical"></i><span>Donation Requests</span></button>
  <button onclick="window.location.href='admin_dashboard.php?page=logs'"><i class="fas fa-history"></i><span>Logs</span></button>
  <button onclick="window.location.href='admin_dashboard.php?page=schedules'"><i class="fas fa-clock"></i><span>Check Schedules</span></button>
</div>
<div class="main-content">
<?php if ($page === 'notifications'): ?>
  <h1>Notifications</h1>
  <form method="POST" style="margin-bottom:15px;">
    <button type="submit" name="mark_all_read" class="btn" style="background:#4CAF50;">
      <i class="fas fa-check-double"></i> Mark All as Read
    </button>
  </form>
  <table>
    <tr><th>Message</th><th>Status</th><th>Received</th><th>Action</th></tr>
    <?php if ($notifications && $notifications->num_rows > 0): ?>
        <?php while($n = $notifications->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($n['message']) ?></td>
            <td>
              <?php if ($n['is_read']): ?>
                <span style="color:green;">Read</span>
              <?php else: ?>
                <span style="color:orange; font-weight:600;">Unread</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($n['created_at']) ?></td>
            <td>
              <?php if (!$n['is_read']): ?>
                <a class="btn" href="mark_read.php?id=<?= (int)$n['id'] ?>">Mark as Read</a>
              <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr><td colspan="4" style="text-align:center;">No notifications.</td></tr>
    <?php endif; ?>
  </table>
<?php elseif ($page === 'dashboard'): ?>
  <h1>Dashboard</h1>
  <div class="cards">
    <div class="card card-1"><h3>Total Users</h3><p><?= $totalUsers ?></p></div>
    <div class="card card-2"><h3>Total Medicines</h3><p><?= $totalMeds ?></p></div>
    <div class="card card-3"><h3>Low Stock</h3><p><?= $lowStockMeds ?></p></div>
    <div class="card card-4"><h3>Expiring Soon</h3><p><?= $expiringSoonMeds ?></p></div>
  </div>
  <h2>Recent Activities</h2>
  <table>
    <tr><th>User</th><th>Action</th><th>Timestamp</th></tr>
    <?php if ($recentLogs && $recentLogs->num_rows > 0): ?>
        <?php while($log = $recentLogs->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($log['user']) ?></td>
            <td><?= htmlspecialchars($log['action']) ?></td>
            <td><?= htmlspecialchars($log['timestamp']) ?></td>
        </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr><td colspan="3" style="text-align:center;">No recent logs.</td></tr>
    <?php endif; ?>
  </table>
<?php elseif ($page === 'donations'): ?>
  <h1>Donation Requests</h1>
  <?php if (!empty($success_message)): ?>
    <p style="color:green; margin-bottom:15px;"><?= htmlspecialchars($success_message) ?></p>
  <?php endif; ?>
  <?php
  $donations = $conn->query("
      SELECT dr.id, dr.status, dr.requested_at, dr.approved_at,
             m.name AS med_name, m.type AS med_type,
             u.username AS staff_name
      FROM donation_requests dr
      JOIN medicines m ON dr.medicine_id = m.id
      JOIN users u ON dr.staff_id = u.id
      ORDER BY dr.requested_at DESC
  ");
  ?>
  <table>
    <tr><th>Medicine</th><th>Category</th><th>Requested By</th><th>Requested At</th><th>Status</th><th>Action</th></tr>
    <?php if ($donations && $donations->num_rows > 0): ?>
      <?php while($d = $donations->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($d['med_name']) ?></td>
        <td><?= htmlspecialchars($d['med_type']) ?></td>
        <td><?= htmlspecialchars($d['staff_name']) ?></td>
        <td><?= htmlspecialchars($d['requested_at']) ?></td>
        <td>
          <?php if ($d['status'] === 'approved'): ?>
            <span class="status-approved">✅ Approved</span>
          <?php elseif ($d['status'] === 'pending'): ?>
            <span class="status-pending">⏳ Pending</span>
          <?php else: ?>
            <span class="status-rejected">❌ Rejected</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($d['status'] === 'pending'): ?>
            <a class="btn btn-add" href="admin_dashboard.php?page=donations&approve=<?= (int)$d['id'] ?>" 
               onclick="return confirm('Approve this donation request for \"<?= htmlspecialchars($d['med_name']) ?>\" by <?= htmlspecialchars($d['staff_name']) ?>?')">
              Approve
            </a>
            <a class="btn btn-del" href="admin_dashboard.php?page=donations&reject=<?= (int)$d['id'] ?>" 
               onclick="return confirm('Reject this donation request for \"<?= htmlspecialchars($d['med_name']) ?>\" by <?= htmlspecialchars($d['staff_name']) ?>?')">
              Reject
            </a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endwhile; ?>
    <?php else: ?>
      <tr><td colspan="6" style="text-align:center;">No donation requests.</td></tr>
    <?php endif; ?>
  </table>
<?php elseif ($page === 'manage_users'): ?>
  <h1>Manage Users</h1>
  <?php if(isset($_GET['msg'])) echo "<p style='color:green;'>".htmlspecialchars($_GET['msg'])."</p>"; ?>
  <form method="GET">
    <input type="hidden" name="page" value="manage_users">
    <input type="text" name="search" placeholder="Search users..." value="<?= htmlspecialchars($search ?? '') ?>">
    <button type="submit">Search</button>
  </form>
  <form method="POST" style="margin-top:15px;">
    <?php if (!empty($editUser)): ?>
        <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">
        <input type="text" name="username" value="<?= htmlspecialchars($editUser['username']) ?>" required>
        <p>Role: <strong><?= ucfirst($editUser['role']) ?></strong></p>
        <button type="submit" name="update_user">Update User</button>
        <a class="btn" href="admin_dashboard.php?page=manage_users">Cancel</a>
    <?php else: ?>
        <input type="text" name="username" placeholder="Username" required>
        <input type="text" name="password" placeholder="Temporary Password (optional)">
        <p style="font-size:0.9rem; color:#555;">The password is temporary. User will be required to change it on first login.</p>
        <!-- Role selector -->
        <label for="role">Role:</label>
        <select name="role" id="role" required style="padding:6px; margin:8px 0; border:1px solid #ccc; border-radius:4px;">
            <option value="staff">Staff</option>
            <option value="guest">Guest</option>
        </select>
        <button type="submit" name="add_user">Add User</button>
    <?php endif; ?>
  </form>
  <table>
    <tr><th>ID</th><th>Username</th><th>Role</th><th>Created</th><th>Last Login</th><th>Action</th><th>Status</th></tr>
    <?php if (!empty($users) && $users->num_rows > 0): ?>
        <?php while($row = $users->fetch_assoc()): ?>
        <tr>
            <td><?= (int)$row['id'] ?></td>
            <td><?= htmlspecialchars($row['username']) ?></td>
            <td><?= ucfirst($row['role']) ?></td>
            <td><?= htmlspecialchars($row['created_at']) ?></td>
            <td><?= $row['last_login'] ? htmlspecialchars($row['last_login']) : '-' ?></td>
            <td>
              <a class="btn" href="?page=manage_users&edit=<?= (int)$row['id'] ?>">Edit</a>
              <?php if ($row['role'] === 'admin'): ?>
                  <button class='btn-del' disabled title='Cannot modify admin'>Cannot modify admin</button>
              <?php else: ?>
                  <a class='btn btn-del' href='?page=manage_users&delete=<?= (int)$row['id'] ?>' onclick='return confirm("Delete this user?");'>Delete</a>
                  <a class='btn' href='?page=manage_users&reset=<?= (int)$row['id'] ?>' onclick='return confirm("Reset password for this user?");'>Reset Password</a>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($row['force_password_change']): ?>
                <span style='color:orange; font-weight:600;'>Must change password</span>
              <?php else: ?>
                <span style='color:green;'>Active</span>
              <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr><td colspan="7" style="text-align:center; color: #555;">No users found.</td></tr>
    <?php endif; ?>
  </table>
<?php elseif ($page === 'categories'): ?>
  <h1>Manage Medicine Categories</h1>
  <?php if (!empty($error_message)): ?>
    <p style="color:red; margin-bottom:15px;"><?= htmlspecialchars($error_message) ?></p>
  <?php endif; ?>
  <?php if (!empty($success_message)): ?>
    <p style="color:green; margin-bottom:15px;"><?= htmlspecialchars($success_message) ?></p>
  <?php endif; ?>
  <?php if ($editCategory): ?>
    <!-- Edit Form -->
    <form method="POST" style="margin-bottom:25px; padding:15px; background:#f9f9f9; border-radius:8px; max-width:400px;">
      <h3>Edit Category: <?= htmlspecialchars($editCategory['name']) ?></h3>
      <input type="hidden" name="id" value="<?= (int)$editCategory['id'] ?>">
      <input type="text" name="category_name" value="<?= htmlspecialchars($editCategory['name']) ?>" required
             style="padding:8px; width:100%; margin:8px 0; border:1px solid #ccc; border-radius:4px;">
      <button type="submit" name="edit_category" class="btn" style="background:#0a4c9e;">
        <i class="fas fa-save"></i> Save Changes
      </button>
      <a href="admin_dashboard.php?page=categories" class="btn" style="background:#757575; text-decoration:none; margin-left:8px;">
        Cancel
      </a>
    </form>
  <?php else: ?>
    <!-- Add Form -->
    <form method="POST" style="margin-bottom:25px; padding:15px; background:#f9f9f9; border-radius:8px; max-width:400px;">
      <h3>Add New Category</h3>
      <input type="text" name="category_name" placeholder="Enter category name" required
             style="padding:8px; width:100%; margin:8px 0; border:1px solid #ccc; border-radius:4px;">
      <button type="submit" name="add_category" class="btn btn-add">
        <i class="fas fa-plus"></i> Add Category
      </button>
    </form>
  <?php endif; ?>
  <h3>Existing Categories</h3>
  <table>
    <tr><th>ID</th><th>Name</th><th>Action</th></tr>
    <?php if ($categories && $categories->num_rows > 0): ?>
      <?php while($cat = $categories->fetch_assoc()): ?>
        <tr>
          <td><?= (int)$cat['id'] ?></td>
          <td><?= htmlspecialchars($cat['name']) ?></td>
          <td>
            <a class="btn" href="admin_dashboard.php?page=categories&edit_cat=<?= (int)$cat['id'] ?>"
               style="background:#0a4c9e; margin-right:6px;">
              <i class="fas fa-edit"></i> Edit
            </a>
            <button class="btn btn-del" 
                    onclick="openDeleteCategoryModal(<?= (int)$cat['id'] ?>, '<?= addslashes(htmlspecialchars($cat['name'])) ?>')">
              <i class="fas fa-trash"></i> Delete
            </button>
          </td>
        </tr>
      <?php endwhile; ?>
    <?php else: ?>
      <tr><td colspan="3" style="text-align:center;">No categories found.</td></tr>
    <?php endif; ?>
  </table>
<?php elseif ($page === 'medicines'): ?>
  <h1>Medicine Overview</h1>
  <div class="filters">
    <a href="?page=medicines">All</a>
    <a href="?page=medicines&filter=low_stock">Low Stock</a>
    <a href="?page=medicines&filter=expiring">Expiring Soon</a>
  </div>
  <form method="GET">
    <input type="hidden" name="page" value="medicines">
    <input type="text" name="search" placeholder="Search medicine..." value="<?= htmlspecialchars($search ?? '') ?>">
    <button type="submit">Search</button>
  </form>
  <table id="medicines-table">
    <tr><th>ID</th><th>Medicine</th><th>Category</th><th>Qty</th><th>Expiry</th><th>Status</th></tr>
    <?php if (!empty($meds) && $meds->num_rows > 0): while($row = $meds->fetch_assoc()): 
      $status = "Good"; $class="good";
      if ($row['quantity'] <= 20 && $row['expired_date'] > date("Y-m-d")) { $status="Low Stock"; $class="low"; }
      if ($row['expired_date'] <= date("Y-m-d")) { $status="Expired"; $class="expired"; }
      elseif ($row['expired_date'] <= date("Y-m-d", strtotime("+7 days"))) { $status="Expiring Soon"; $class="low"; }
    ?>
      <tr>
        <td><?= (int)$row['id'] ?></td>
        <td><?= htmlspecialchars($row['name']) ?></td>
        <td><?= htmlspecialchars($row['type']) ?></td>
        <td><?= (int)$row['quantity'] ?></td>
        <td><?= htmlspecialchars($row['expired_date']) ?></td>
        <td class="<?= $class ?>"><?= $status ?></td>
      </tr>
    <?php endwhile; else: ?>
      <tr><td colspan="6">No results</td></tr>
    <?php endif; ?>
  </table>
<?php elseif ($page === 'logs'): ?>
  <h1>Logs</h1>
  <table>
    <tr><th>ID</th><th>User</th><th>Action</th><th>Timestamp</th></tr>
    <?php $logs = $conn->query("SELECT * FROM logs ORDER BY timestamp DESC");
    while($log = $logs->fetch_assoc()): ?>
      <tr>
        <td><?= (int)$log['id'] ?></td>
        <td><?= htmlspecialchars($log['user']) ?></td>
        <td><?= htmlspecialchars($log['action']) ?></td>
        <td><?= htmlspecialchars($log['timestamp']) ?></td>
      </tr>
    <?php endwhile; ?>
  </table>
<?php elseif ($page === 'schedules'): ?>
  <h1>Admin Check Schedules</h1>
  <form method="POST" style="margin-bottom:25px; padding:15px; background:#f9f9f9; border-radius:8px; max-width:500px;">
    <h3>Create New Schedule</h3>
    <input type="text" name="schedule_name" placeholder="Schedule Name" required
           style="padding:8px; width:100%; margin:8px 0; border:1px solid #ccc; border-radius:4px;">
    <select name="schedule_type" id="schedule_type" required style="padding:8px; width:100%; margin:8px 0; border:1px solid #ccc; border-radius:4px;">
        <option value="daily">Daily</option>
        <option value="weekly">Weekly</option>
        <option value="monthly">Monthly</option>
    </select>
    <input type="time" name="schedule_time" required
           style="padding:8px; width:100%; margin:8px 0; border:1px solid #ccc; border-radius:4px;">
    <!-- Conditional Day Field -->
    <p id="dayField" style="display:none;">
      <label for="schedule_day" id="dayLabel">Day (1-7 for weekly, 1-31 for monthly):</label>
      <input type="number" name="schedule_day" id="schedule_day" min="1" max="31"
             style="width:100%; padding:8px; margin:8px 0; border:1px solid #ccc; border-radius:4px;">
    </p>
    <input type="submit" name="create_schedule" value="Create Schedule" class="btn btn-add">
  </form>
  <?php if ($editSchedule): ?>
  <h3>Edit Schedule</h3>
  <form method="POST" style="margin-bottom:25px; padding:15px; background:#e3f2fd; border-radius:8px; max-width:500px;">
    <input type="hidden" name="id" value="<?= (int)$editSchedule['id'] ?>">
    <input type="text" name="schedule_name" value="<?= htmlspecialchars($editSchedule['schedule_name']) ?>" required
           style="padding:8px; width:100%; margin:8px 0; border:1px solid #ccc; border-radius:4px;">
    <select name="schedule_type" id="edit_schedule_type" required style="padding:8px; width:100%; margin:8px 0; border:1px solid #ccc; border-radius:4px;">
        <option value="daily" <?= $editSchedule['schedule_type'] === 'daily' ? 'selected' : '' ?>>Daily</option>
        <option value="weekly" <?= $editSchedule['schedule_type'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
        <option value="monthly" <?= $editSchedule['schedule_type'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
    </select>
    <input type="time" name="schedule_time" value="<?= $editSchedule['schedule_time'] ?>" required
           style="padding:8px; width:100%; margin:8px 0; border:1px solid #ccc; border-radius:4px;">
    <!-- Conditional Day Field -->
    <p id="editDayField" style="display:none;">
      <label for="edit_schedule_day" id="editDayLabel">Day (1-7 for weekly, 1-31 for monthly):</label>
      <input type="number" name="schedule_day" id="edit_schedule_day" min="1" max="31" value="<?= $editSchedule['schedule_day'] ?>"
             style="width:100%; padding:8px; margin:8px 0; border:1px solid #ccc; border-radius:4px;">
    </p>
    <input type="submit" name="edit_schedule" value="Update Schedule" class="btn btn-add">
    <a href="admin_dashboard.php?page=schedules" class="btn" style="background:#757575; text-decoration:none; margin-left:8px;">Cancel</a>
  </form>
  <?php endif; ?>
  <h3>Active Schedules</h3>
  <table>
    <tr><th>Name</th><th>Type</th><th>Time</th><th>Next Check</th><th>Status</th><th>Action</th></tr>
    <?php if ($schedules && $schedules->num_rows > 0): ?>
      <?php while($sched = $schedules->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($sched['schedule_name']) ?></td>
        <td><?= ucfirst($sched['schedule_type']) ?></td>
        <td><?= $sched['schedule_time'] ?></td>
        <td><?= $sched['next_check'] ?></td>
        <td>
          <?php if ($sched['is_active']): ?>
            <span style="color:green;">Active</span>
          <?php else: ?>
            <span style="color:red;">Inactive</span>
          <?php endif; ?>
        </td>
        <td>
          <button class="btn" onclick="toggleSchedule(<?= $sched['id'] ?>)">
            <?= $sched['is_active'] ? 'Deactivate' : 'Activate' ?>
          </button>
          <button class="btn" onclick="openEditScheduleModal(<?= $sched['id'] ?>, '<?= addslashes(htmlspecialchars($sched['schedule_name'])) ?>', '<?= $sched['schedule_type'] ?>', '<?= $sched['schedule_time'] ?>', <?= $sched['schedule_day'] ?>)">
            <i class="fas fa-edit"></i> Edit
          </button>
          <button class="btn btn-del" 
                  onclick="openDeleteScheduleModal(<?= $sched['id'] ?>, '<?= addslashes(htmlspecialchars($sched['schedule_name'])) ?>')">
            <i class="fas fa-trash"></i> Delete
          </button>
        </td>
      </tr>
      <?php endwhile; ?>
    <?php else: ?>
      <tr><td colspan="6" style="text-align:center;">No schedules found.</td></tr>
    <?php endif; ?>
  </table>
<?php else: ?>
  <h1>Not found</h1>
  <p>Unknown page.</p>
<?php endif; ?>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const hamburger = document.getElementById('hamburger');
    if (sidebar && localStorage.getItem('sidebarExpanded') === 'true') {
      sidebar.classList.add('expanded');
    }
    if (hamburger && sidebar) {
      hamburger.addEventListener('click', () => {
        sidebar.classList.toggle('expanded');
        localStorage.setItem('sidebarExpanded', sidebar.classList.contains('expanded'));
      });
    }
  });
  function toggleProfileMenu() {
    const dropdown = document.getElementById('profileDropdown');
    if (dropdown) dropdown.classList.toggle('show');
    document.addEventListener('click', function closeDropdown(e) {
      const profile = document.querySelector('.profile-menu');
      if (profile && dropdown && !profile.contains(e.target)) {
        dropdown.classList.remove('show');
        document.removeEventListener('click', closeDropdown);
      }
    });
  }
  function openLogoutModal() {
    document.getElementById('logoutModal').style.display = 'block';
  }
  function closeLogoutModal() {
    document.getElementById('logoutModal').style.display = 'none';
  }
  // Category Delete Modal
  function openDeleteCategoryModal(catId, catName) {
    document.getElementById('deleteCategoryName').textContent = catName;
    document.getElementById('confirmDeleteCategory').href = 'admin_dashboard.php?page=categories&delete_cat=' + catId;
    document.getElementById('deleteCategoryModal').style.display = 'block';
  }
  function closeDeleteCategoryModal() {
    document.getElementById('deleteCategoryModal').style.display = 'none';
  }
  // Schedule Delete Modal
  function openDeleteScheduleModal(scheduleId, scheduleName) {
    document.getElementById('deleteScheduleName').textContent = scheduleName;
    document.getElementById('confirmDeleteSchedule').href = 'admin_dashboard.php?page=schedules&delete_schedule=' + scheduleId;
    document.getElementById('deleteScheduleModal').style.display = 'block';
  }
  function closeDeleteScheduleModal() {
    document.getElementById('deleteScheduleModal').style.display = 'none';
  }
  // Schedule Edit Modal
  function openEditScheduleModal(id, name, type, time, day) {
    document.getElementById('editScheduleId').value = id;
    document.getElementById('editScheduleName').value = name;
    document.getElementById('editScheduleType').value = type;
    document.getElementById('editScheduleTime').value = time;
    document.getElementById('editScheduleDay').value = day || '';
    // Show the modal
    document.getElementById('editScheduleModal').style.display = 'block';
    // Call updateDayInput to set the correct max value and visibility when opening the modal
    updateDayInput(document.getElementById('editScheduleType'), document.getElementById('editScheduleDay'), document.getElementById('editDayField'), document.getElementById('editDayLabel'));
  }
  function closeEditScheduleModal() {
    document.getElementById('editScheduleModal').style.display = 'none';
  }
  window.onclick = function(event) {
    const logoutModal = document.getElementById('logoutModal');
    const deleteCategoryModal = document.getElementById('deleteCategoryModal');
    const deleteScheduleModal = document.getElementById('deleteScheduleModal');
    const editScheduleModal = document.getElementById('editScheduleModal');
    if (logoutModal && event.target === logoutModal) closeLogoutModal();
    if (deleteCategoryModal && event.target === deleteCategoryModal) closeDeleteCategoryModal();
    if (deleteScheduleModal && event.target === deleteScheduleModal) closeDeleteScheduleModal();
    if (editScheduleModal && event.target === editScheduleModal) closeEditScheduleModal();
  }
  // API Integration Functions
  function fetchMedicinesAPI() {
    fetch('api/medicines.php')
        .then(response => response.json())
        .then(data => {
            console.log('Medicines API Response:', data);
            // Update UI with API data
            updateMedicineTable(data);
        })
        .catch(error => console.error('API Error:', error));
  }
  function updateMedicineTable(data) {
    // Update your medicine table with API data
    const tableBody = document.querySelector('#medicines-table tbody');
    if (tableBody) {
        tableBody.innerHTML = '';
        data.forEach(med => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${med.id}</td>
                <td>${med.name}</td>
                <td>${med.type}</td>
                <td>${med.quantity}</td>
                <td>${med.expired_date}</td>
                <td class="${getStatusClass(med)}">${getStatusText(med)}</td>
            `;
            tableBody.appendChild(row);
        });
    }
  }
  function getStatusClass(med) {
    if (med.quantity <= 20 && new Date(med.expired_date) > new Date()) return 'low';
    if (new Date(med.expired_date) <= new Date()) return 'expired';
    if (new Date(med.expired_date) <= new Date(Date.now() + 7*24*60*60*1000)) return 'low';
    return 'good';
  }
  function getStatusText(med) {
    if (med.quantity <= 20 && new Date(med.expired_date) > new Date()) return 'Low Stock';
    if (new Date(med.expired_date) <= new Date()) return 'Expired';
    if (new Date(med.expired_date) <= new Date(Date.now() + 7*24*60*60*1000)) return 'Expiring Soon';
    return 'Good';
  }
  // Auto-refresh data every 5 minutes
  setInterval(fetchMedicinesAPI, 300000);
  // Schedule functions
  function toggleSchedule(scheduleId) {
    window.location.href = `admin_dashboard.php?page=schedules&toggle_schedule=${scheduleId}`;
  }
  // Dynamic Day Input Max & Visibility
  function updateDayInput(selectElement, inputElement, containerElement, labelElement) {
    const selectedType = selectElement.value;
    let maxVal = 31;
    let labelText = "Day (1-7 for weekly, 1-31 for monthly):";
    if (selectedType === 'weekly') {
        maxVal = 7;
        labelText = "Day of week (1-7):";
    } else if (selectedType === 'monthly') {
        maxVal = 31;
        labelText = "Day of month (1-31):";
    }
    // Set the max value
    inputElement.max = maxVal;
    // Update the label text
    labelElement.textContent = labelText;
    // Show or hide the container based on the type
    if (selectedType === 'daily') {
        containerElement.style.display = 'none';
    } else {
        containerElement.style.display = 'block';
    }
  }
  // Initialize on page load
  document.addEventListener('DOMContentLoaded', function() {
    const createTypeSelect = document.getElementById('schedule_type');
    const createDayInput = document.getElementById('schedule_day');
    const createDayContainer = document.getElementById('dayField');
    const createDayLabel = document.getElementById('dayLabel');
    const editTypeSelect = document.getElementById('editScheduleType');
    const editDayInput = document.getElementById('editScheduleDay');
    const editDayContainer = document.getElementById('editDayField');
    const editDayLabel = document.getElementById('editDayLabel');
    // Attach listeners to Create form
    if (createTypeSelect && createDayInput && createDayContainer && createDayLabel) {
      createTypeSelect.addEventListener('change', function() {
        updateDayInput(createTypeSelect, createDayInput, createDayContainer, createDayLabel);
      });
      // Set initial state
      updateDayInput(createTypeSelect, createDayInput, createDayContainer, createDayLabel);
    }
    // Attach listeners to Edit form
    if (editTypeSelect && editDayInput && editDayContainer && editDayLabel) {
      editTypeSelect.addEventListener('change', function() {
        updateDayInput(editTypeSelect, editDayInput, editDayContainer, editDayLabel);
      });
      // Note: Initial state for edit is set when the modal opens via openEditScheduleModal
    }
  });
</script>
</body>
</html>
<?php
session_start();
include('../../db.php');

if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../home_pages/login.php");
    exit;
}

$page = $_GET['page'] ?? 'dashboard';
$error_message   = '';
$success_message = '';
$recentLogs      = null;
$notifications   = null;

// Unread count
$unreadCount = 0;
$unreadRes = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE is_read = 0");
if ($unreadRes) {
    $row = $unreadRes->fetch_assoc();
    $unreadCount = isset($row['c']) ? (int)$row['c'] : 0;
}

// Mark all read
if ($page === 'notifications' && isset($_POST['mark_all_read'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
    $unreadCount = 0;
    header("Location: dashboard.php?page=notifications");
    exit();
}

// Fetch notifications
if ($page === 'notifications') {
    $notifications = $conn->query("
        SELECT n.*, u.username
        FROM notifications n
        LEFT JOIN users u ON n.user_id = u.id
        ORDER BY n.is_read ASC, n.created_at DESC
        LIMIT 50
    ");
}

// Add/update/delete users
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $username    = trim($_POST['username']);
        $passwordRaw = trim($_POST['password'] ?? '');
        $role        = trim($_POST['role'] ?? 'staff');
        if (!in_array($role, ['staff', 'guest'])) $role = 'staff';
        if (empty($passwordRaw))
            $passwordRaw = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header("Location: dashboard.php?page=manage_users&msg=Username already exists");
            exit();
        }
        $check->close();
        $password   = password_hash($passwordRaw, PASSWORD_DEFAULT);
        $defaultPic = 'default.jpg';
        $forceChange = 1;
        $stmt = $conn->prepare("INSERT INTO users (username, password, role, profile_pic, force_password_change) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $username, $password, $role, $defaultPic, $forceChange);
        if ($stmt->execute()) {
            $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Added new user $username as $role')");
            header("Location: dashboard.php?page=manage_users&msg=User added successfully. Temporary password: $passwordRaw");
            exit();
        } else {
            header("Location: dashboard.php?page=manage_users&msg=Error adding user: " . $stmt->error);
            exit();
        }
        $stmt->close();
    }
    if (isset($_POST['update_user'])) {
        $id = $_POST['id']; $username = trim($_POST['username']); $role = $_POST['role'];
        $stmt = $conn->prepare("UPDATE users SET username=?, role=? WHERE id=?");
        $stmt->bind_param("ssi", $username, $role, $id);
        if ($stmt->execute()) $success_message = "User updated successfully.";
        else $error_message = "Error: " . $stmt->error;
        $stmt->close();
    }
    if (isset($_POST['delete_user'])) {
        $id = $_POST['id'];
        $stmt = $conn->prepare("SELECT role FROM users WHERE id=?");
        $stmt->bind_param("i", $id); $stmt->execute();
        $roleRes = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($roleRes && $roleRes['role'] === 'admin') {
            $error_message = "Cannot delete an admin account!";
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) $success_message = "User deleted successfully.";
            else $error_message = "Error: " . $stmt->error;
            $stmt->close();
        }
    }
}

// Categories
if ($page === 'categories') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
        $newCat = trim($_POST['category_name']);
        if (!empty($newCat)) {
            $stmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?)");
            $stmt->bind_param("s", $newCat); $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                $stmt->bind_param("s", $newCat); $stmt->execute();
                $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Added category: $newCat')");
                $success_message = "Category added successfully.";
            } else { $error_message = "Category already exists."; }
            $stmt->close();
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
        $catId = (int)$_POST['id']; $newName = trim($_POST['category_name']);
        if (!empty($newName)) {
            $stmt = $conn->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND id != ?");
            $stmt->bind_param("si", $newName, $catId); $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
                $stmt->bind_param("si", $newName, $catId);
                if ($stmt->execute()) {
                    $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Edited category ID $catId to \"$newName\"')");
                    $success_message = "Category updated successfully.";
                } else { $error_message = "Failed to update category."; }
            } else { $error_message = "Category name already exists."; }
            $stmt->close();
        } else { $error_message = "Category name cannot be empty."; }
        header("Location: dashboard.php?page=categories"); exit();
    }
    if (isset($_GET['delete_cat'])) {
        $catId = (int)$_GET['delete_cat'];
        $res = $conn->query("SELECT name FROM categories WHERE id = $catId");
        if ($res && $res->num_rows > 0) {
            $catName = $res->fetch_assoc()['name'];
            $conn->query("DELETE FROM categories WHERE id = $catId");
            $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Deleted category: $catName')");
            header("Location: dashboard.php?page=categories"); exit();
        }
    }
    $editCategory = null;
    if (isset($_GET['edit_cat'])) {
        $editId = (int)$_GET['edit_cat'];
        $res = $conn->query("SELECT * FROM categories WHERE id = $editId");
        if ($res && $res->num_rows > 0) $editCategory = $res->fetch_assoc();
    }
    $categories = $conn->query("SELECT * FROM categories ORDER BY id");
}

// Medicines
$where = "1=1"; $search = "";
if ($page === 'medicines') {
    if (isset($_GET['filter']) && $_GET['filter'] == "low_stock")
        $where = "quantity <= 20 AND expired_date > CURDATE()";
    elseif (isset($_GET['filter']) && $_GET['filter'] == "expiring")
        $where = "expired_date <= CURDATE() + INTERVAL 7 DAY AND expired_date >= CURDATE()";
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $search = $conn->real_escape_string($_GET['search']);
        $where .= " AND (name LIKE '%$search%' OR type LIKE '%$search%' OR CAST(quantity AS CHAR) LIKE '%$search%' OR DATE_FORMAT(expired_date, '%Y-%m-%d') LIKE '%$search%')";
    }
    $meds = $conn->query("SELECT * FROM medicines WHERE $where ORDER BY expired_date ASC");
}

// Dashboard stats
if ($page === 'dashboard') {
    $totalUsers      = $conn->query("SELECT COUNT(*) AS total FROM users")->fetch_assoc()['total'] ?? 0;
    $totalMeds       = $conn->query("SELECT COUNT(*) AS total FROM medicines")->fetch_assoc()['total'] ?? 0;
    $lowStockMeds    = $conn->query("SELECT COUNT(*) AS total FROM medicines WHERE quantity <= 20 AND expired_date > CURDATE()")->fetch_assoc()['total'] ?? 0;
    $expiringSoonMeds= $conn->query("SELECT COUNT(*) AS total FROM medicines WHERE expired_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['total'] ?? 0;
    $recentLogs      = $conn->query("SELECT * FROM logs ORDER BY timestamp DESC LIMIT 5");
}

// Donations
if ($page === 'donations') {
    if (isset($_GET['approve'])) {
        $reqId = (int)$_GET['approve'];
        $info = $conn->query("SELECT dr.staff_id, m.name AS med_name, u.username AS staff_name FROM donation_requests dr JOIN medicines m ON dr.medicine_id = m.id JOIN users u ON dr.staff_id = u.id WHERE dr.id = $reqId AND dr.status = 'pending'")->fetch_assoc();
        if ($info) {
            $conn->query("UPDATE donation_requests SET status='approved', approved_at=NOW() WHERE id=$reqId");
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $msg = "Your donation request for \"{$info['med_name']}\" has been approved by admin.";
            $stmt->bind_param("is", $info['staff_id'], $msg); $stmt->execute();
            $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Approved donation request for {$info['med_name']} by {$info['staff_name']}')");
            header("Location: dashboard.php?page=donations"); exit();
        }
    }
    if (isset($_GET['reject'])) {
        $reqId = (int)$_GET['reject'];
        $info = $conn->query("SELECT dr.staff_id, m.name AS med_name, u.username AS staff_name FROM donation_requests dr JOIN medicines m ON dr.medicine_id = m.id JOIN users u ON dr.staff_id = u.id WHERE dr.id = $reqId AND dr.status = 'pending'")->fetch_assoc();
        if ($info) {
            $conn->query("UPDATE donation_requests SET status='rejected', approved_at=NOW() WHERE id=$reqId");
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $msg = "Your donation request for \"{$info['med_name']}\" was rejected by admin.";
            $stmt->bind_param("is", $info['staff_id'], $msg); $stmt->execute();
            $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Rejected donation request for {$info['med_name']} by {$info['staff_name']}')");
            header("Location: dashboard.php?page=donations"); exit();
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
            if ($row['role'] === 'admin') { header("Location: dashboard.php?page=manage_users&msg=Cannot reset admin password"); exit; }
            $tempPassword = "Temp" . rand(1000, 9999);
            $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password=?, force_password_change=1, force_security_setup=1, security_question=NULL, security_answer=NULL WHERE id=?");
            $stmt->bind_param("si", $hash, $id); $stmt->execute();
            $conn->query("INSERT INTO logs (user, action) VALUES ('admin', 'Reset password for ".$row['username']."')");
            header("Location: dashboard.php?page=manage_users&msg=Password reset for ".$row['username'].". Temporary password: $tempPassword"); exit;
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
        $like = "%$search%"; $stmt->bind_param("ss", $like, $like); $stmt->execute();
        $users = $stmt->get_result();
    } else {
        $users = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
    }
}

// Schedules
if ($page === 'schedules') {
    if (isset($_POST['create_schedule'])) {
        $schedule_name = $_POST['schedule_name']; $schedule_type = $_POST['schedule_type'];
        $schedule_time = $_POST['schedule_time']; $schedule_day  = $_POST['schedule_day'] ?? null;
        $next_check = date('Y-m-d H:i:s');
        if ($schedule_type == 'daily') $next_check = date('Y-m-d', strtotime('+1 day')) . ' ' . $schedule_time;
        elseif ($schedule_type == 'weekly') { $schedule_day = $schedule_day ?? date('N'); $day_name = date('l', strtotime("next Sunday + {$schedule_day} days")); $next_check = date('Y-m-d H:i:s', strtotime("next {$day_name} " . $schedule_time)); }
        elseif ($schedule_type == 'monthly') { $schedule_day = $schedule_day ?? date('j'); $next_check = date('Y-m-d H:i:s', strtotime("first day of next month +" . ((int)$schedule_day - 1) . " days " . $schedule_time)); }
        $admin_id = $conn->query("SELECT id FROM users WHERE username = '{$_SESSION['username']}'")->fetch_assoc()['id'];
        $stmt = $conn->prepare("INSERT INTO admin_schedules (admin_id, schedule_name, schedule_type, schedule_time, schedule_day, next_check) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssis", $admin_id, $schedule_name, $schedule_type, $schedule_time, $schedule_day, $next_check); $stmt->execute();
        header("Location: dashboard.php?page=schedules"); exit();
    }
    if (isset($_GET['toggle_schedule'])) { $id = (int)$_GET['toggle_schedule']; $stmt = $conn->prepare("UPDATE admin_schedules SET is_active = NOT is_active WHERE id = ?"); $stmt->bind_param("i", $id); $stmt->execute(); header("Location: dashboard.php?page=schedules"); exit(); }
    if (isset($_GET['delete_schedule'])) { $id = (int)$_GET['delete_schedule']; $stmt = $conn->prepare("DELETE FROM admin_schedules WHERE id = ?"); $stmt->bind_param("i", $id); $stmt->execute(); header("Location: dashboard.php?page=schedules"); exit(); }
    if (isset($_POST['edit_schedule'])) {
        $id = (int)$_POST['id']; $schedule_name = $_POST['schedule_name']; $schedule_type = $_POST['schedule_type'];
        $schedule_time = $_POST['schedule_time']; $schedule_day = $_POST['schedule_day'] ?? null;
        $next_check = date('Y-m-d H:i:s');
        if ($schedule_type == 'daily') $next_check = date('Y-m-d', strtotime('+1 day')) . ' ' . $schedule_time;
        elseif ($schedule_type == 'weekly') { $schedule_day = $schedule_day ?? date('N'); $day_name = date('l', strtotime("next Sunday + {$schedule_day} days")); $next_check = date('Y-m-d H:i:s', strtotime("next {$day_name} " . $schedule_time)); }
        elseif ($schedule_type == 'monthly') { $schedule_day = $schedule_day ?? date('j'); $next_check = date('Y-m-d H:i:s', strtotime("first day of next month +".($schedule_day-1)." days " . $schedule_time)); }
        $stmt = $conn->prepare("UPDATE admin_schedules SET schedule_name=?, schedule_type=?, schedule_time=?, schedule_day=?, next_check=? WHERE id=?");
        $stmt->bind_param("sssssi", $schedule_name, $schedule_type, $schedule_time, $schedule_day, $next_check, $id); $stmt->execute();
        header("Location: dashboard.php?page=schedules"); exit();
    }
    $editSchedule = null;
    if (isset($_GET['edit_schedule'])) {
        $id = (int)$_GET['edit_schedule'];
        $stmt = $conn->prepare("SELECT * FROM admin_schedules WHERE id = ?"); $stmt->bind_param("i", $id); $stmt->execute();
        $editSchedule = $stmt->get_result()->fetch_assoc();
    }
    $schedules = $conn->query("SELECT * FROM admin_schedules WHERE admin_id = (SELECT id FROM users WHERE username = '{$_SESSION['username']}') ORDER BY next_check ASC");
}

function checkSchedules() {
    global $conn;
    date_default_timezone_set('Asia/Manila');
    $tableCheck = $conn->query("SHOW TABLES LIKE 'admin_schedules'");
    if ($tableCheck->num_rows == 0) return;
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("SELECT * FROM admin_schedules WHERE next_check <= ? AND is_active = 1");
    if (!$stmt) return;
    $stmt->bind_param("s", $now);
    if (!$stmt->execute()) { $stmt->close(); return; }
    $result = $stmt->get_result();
    while ($sched = $result->fetch_assoc()) {
        $msg = "Time to check: " . $sched['schedule_name'];
        $stmt2 = $conn->prepare("INSERT INTO notifications (user_id, message, is_read) VALUES (?, ?, 0)");
        if (!$stmt2) continue;
        $stmt2->bind_param("is", $sched['admin_id'], $msg); $stmt2->execute(); $stmt2->close();
        updateNextCheck($sched['id'], $sched['schedule_type'], $sched['schedule_time'], $sched['schedule_day']);
    }
    $stmt->close();
}
function updateNextCheck($id, $type, $time, $day = null) {
    global $conn;
    switch($type) {
        case 'daily':   $next = date('Y-m-d', strtotime('+1 day')) . ' ' . $time; break;
        case 'weekly':  $day_name = $day ? date('l', strtotime("next Sunday + {$day} days")) : null; $next = $day_name ? date('Y-m-d H:i:s', strtotime("next {$day_name} " . $time)) : date('Y-m-d', strtotime('+7 days')) . ' ' . $time; break;
        case 'monthly': $next = $day ? date('Y-m-d H:i:s', strtotime("first day of next month +".($day-1)." days " . $time)) : date('Y-m-d', strtotime('+30 days')) . ' ' . $time; break;
        default: return;
    }
    $stmt = $conn->prepare("UPDATE admin_schedules SET next_check = ? WHERE id = ?");
    $stmt->bind_param("si", $next, $id); $stmt->execute();
}
checkSchedules();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard | BENE MediCon</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    /* ── RESET & TOKENS ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --red:        #9b1c1c;
      --red-dark:   #7b1010;
      --red-deeper: #5c0a0a;
      --red-light:  #c62828;
      --gold:       #c9a84c;
      --gold-light: #e8c96a;
      --bg:         #f5f6fa;
      --surface:    #ffffff;
      --text:       #1a1a2e;
      --text-muted: #6b7280;
      --border:     #e5e7eb;
      --sidebar-w:  72px;
      --sidebar-expanded: 230px;
      --topbar-h:   62px;
    }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
    }

    /* ══════════════════════════════
       SIDEBAR
    ══════════════════════════════ */
    .sidebar {
      position: fixed;
      top: 0; left: 0;
      width: var(--sidebar-w);
      height: 100%;
      background: linear-gradient(175deg, var(--red-light) 0%, var(--red-deeper) 100%);
      display: flex;
      flex-direction: column;
      transition: width 0.35s cubic-bezier(0.4,0,0.2,1);
      overflow: hidden;
      z-index: 200;
      box-shadow: 3px 0 20px rgba(0,0,0,0.18);
    }
    .sidebar.expanded { width: var(--sidebar-expanded); }

    /* gold top accent on sidebar */
    .sidebar::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--gold), var(--gold-light), var(--gold));
    }

    .sidebar-header {
      display: flex;
      align-items: center;
      padding: 18px 16px 16px;
      gap: 12px;
      min-height: var(--topbar-h);
    }

    .hamburger-btn {
      width: 38px; height: 38px;
      background: rgba(255,255,255,0.12);
      border: none;
      border-radius: 8px;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      transition: background 0.2s;
    }
    .hamburger-btn:hover { background: rgba(255,255,255,0.22); }

    .hamburger-svg { width: 20px; height: 20px; }
    .hamburger-svg line {
      stroke: white; stroke-width: 2.5; stroke-linecap: round;
      transition: transform 0.35s ease, opacity 0.25s ease;
      transform-origin: center;
    }
    .line-1 { y1: 6;  y2: 6;  x1: 4; x2: 20; }
    .line-2 { y1: 12; y2: 12; x1: 4; x2: 20; }
    .line-3 { y1: 18; y2: 18; x1: 4; x2: 20; }
    .sidebar.expanded .line-1 { transform: rotate(45deg) translate(3px,3px); }
    .sidebar.expanded .line-2 { opacity: 0; }
    .sidebar.expanded .line-3 { transform: rotate(-45deg) translate(4px,-4px); }

    .sidebar-brand {
      font-family: 'EB Garamond', serif;
      font-size: 1.1rem;
      font-weight: 600;
      color: #fff;
      white-space: nowrap;
      opacity: 0;
      transition: opacity 0.2s 0.1s;
      letter-spacing: 0.01em;
    }
    .sidebar-brand span { color: var(--gold-light); }
    .sidebar.expanded .sidebar-brand { opacity: 1; }

    /* nav items */
    .sidebar nav { flex: 1; padding: 8px 0; }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 11px 17px;
      width: 100%;
      border: none;
      background: none;
      color: rgba(255,255,255,0.75);
      font-family: 'DM Sans', sans-serif;
      font-size: 0.88rem;
      font-weight: 500;
      cursor: pointer;
      text-decoration: none;
      white-space: nowrap;
      transition: background 0.2s, color 0.2s;
      border-radius: 0 20px 20px 0;
      margin-right: 10px;
      text-align: left;
    }
    .nav-item i {
      font-size: 1.05rem;
      min-width: 22px;
      text-align: center;
      flex-shrink: 0;
    }
    .nav-item span {
      opacity: 0;
      transition: opacity 0.2s 0.05s;
    }
    .sidebar.expanded .nav-item span { opacity: 1; }
    .nav-item:hover { background: rgba(255,255,255,0.12); color: #fff; }
    .nav-item.active { background: rgba(255,255,255,0.18); color: #fff; }

    /* gold dot on active item */
    .nav-item.active::before {
      content: '';
      position: absolute;
      left: 0;
      width: 3px; height: 28px;
      background: var(--gold);
      border-radius: 0 3px 3px 0;
    }
    .nav-item { position: relative; }

    .nav-section-label {
      font-size: 0.62rem;
      font-weight: 600;
      letter-spacing: 0.13em;
      text-transform: uppercase;
      color: rgba(255, 255, 255, 0.35);
      padding: 12px 17px 4px;
      white-space: nowrap;
      overflow: hidden;
      opacity: 0;
      transition: opacity 0.2s;
    }
    .sidebar.expanded .nav-section-label {
      opacity: 1;
    }

    /* sidebar bottom */
    .sidebar-footer {
      padding: 12px 10px 16px;
      border-top: 1px solid rgba(255,255,255,0.1);
    }

    /* ══════════════════════════════
       TOPBAR
    ══════════════════════════════ */
    .topbar {
      position: fixed;
      top: 0;
      left: var(--sidebar-w);
      right: 0;
      height: var(--topbar-h);
      background: var(--surface);
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 1.5rem;
      z-index: 100;
      transition: left 0.35s cubic-bezier(0.4,0,0.2,1);
      box-shadow: 0 1px 8px rgba(0,0,0,0.06);
    }
    .sidebar.expanded ~ .topbar { left: var(--sidebar-expanded); }

    /* page title in topbar */
    .topbar-title {
      font-family: 'EB Garamond', serif;
      font-size: 1.3rem;
      font-weight: 600;
      color: var(--red-deeper);
    }

    /* right side of topbar */
    .topbar-right {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    /* notification bell */
    .topbar-notif {
      position: relative;
      width: 38px; height: 38px;
      border-radius: 10px;
      background: transparent;
      border: 1.5px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      text-decoration: none;
      color: var(--text-muted);
      font-size: 1rem;
      transition: background 0.2s, border-color 0.2s, color 0.2s;
    }
    .topbar-notif:hover {
      background: #fdf4f4;
      border-color: #f0d8d8;
      color: var(--red);
    }
    .topbar-notif .badge {
      position: absolute;
      top: -5px; right: -5px;
      background: var(--red-light);
      color: #fff;
      border-radius: 50%;
      width: 18px; height: 18px;
      font-size: 0.62rem;
      font-weight: 700;
      display: flex; align-items: center; justify-content: center;
      border: 2px solid var(--surface);
    }

    /* divider */
    .topbar-divider {
      width: 1px; height: 24px;
      background: var(--border);
      margin: 0 6px;
    }

    /* profile button */
    .profile-menu { position: relative; }

    .profile-btn {
      display: flex;
      align-items: center;
      gap: 9px;
      padding: 5px 10px 5px 5px;
      border: 1.5px solid var(--border);
      border-radius: 10px;
      background: transparent;
      cursor: pointer;
      transition: background 0.2s, border-color 0.2s;
      font-family: 'DM Sans', sans-serif;
    }
    .profile-btn:hover { background: #fdf4f4; border-color: #f0d8d8; }

    .profile-avatar {
      width: 30px; height: 30px;
      border-radius: 8px;
      background: linear-gradient(135deg, var(--red-light), var(--red-deeper));
      display: flex; align-items: center; justify-content: center;
      color: #fff;
      font-size: 0.85rem;
      font-weight: 600;
      flex-shrink: 0;
      border: 2px solid rgba(201,168,76,0.4);
    }

    .profile-info { text-align: left; }
    .profile-info .p-name {
      font-size: 0.8rem;
      font-weight: 600;
      color: var(--text);
      line-height: 1.2;
    }
    .profile-info .p-role {
      font-size: 0.68rem;
      color: var(--text-muted);
    }

    .profile-chevron {
      font-size: 0.7rem;
      color: var(--text-muted);
      margin-left: 2px;
    }

    /* dropdown */
    .profile-dropdown {
      position: absolute;
      top: calc(100% + 8px);
      right: 0;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.12);
      min-width: 200px;
      display: none;
      overflow: hidden;
      animation: dropDown 0.2s ease;
      z-index: 300;
    }
    @keyframes dropDown {
      from { transform: translateY(-8px); opacity: 0; }
      to   { transform: translateY(0);    opacity: 1; }
    }
    .profile-dropdown.show { display: block; }

    .dropdown-header {
      padding: 12px 14px 10px;
      border-bottom: 1px solid var(--border);
    }
    .dropdown-header .dh-name {
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text);
    }
    .dropdown-header .dh-role {
      font-size: 0.72rem;
      color: var(--text-muted);
      margin-top: 1px;
    }

    .dropdown-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 14px;
      font-size: 0.83rem;
      color: var(--text);
      text-decoration: none;
      background: none;
      border: none;
      width: 100%;
      cursor: pointer;
      font-family: 'DM Sans', sans-serif;
      transition: background 0.15s;
    }
    .dropdown-item i { width: 16px; text-align: center; color: var(--text-muted); font-size: 0.85rem; }
    .dropdown-item:hover { background: #f9fafb; }
    .dropdown-item.danger { color: var(--red); }
    .dropdown-item.danger i { color: var(--red); }
    .dropdown-item.danger:hover { background: #fdf4f4; }

    /* ══════════════════════════════
       MAIN CONTENT
    ══════════════════════════════ */
    .main-content {
      margin-left: var(--sidebar-w);
      margin-top: var(--topbar-h);
      padding: 2rem;
      transition: margin-left 0.35s cubic-bezier(0.4,0,0.2,1);
      min-height: calc(100vh - var(--topbar-h));
    }
    .sidebar.expanded ~ .topbar ~ .main-content { margin-left: var(--sidebar-expanded); }

    /* page heading */
    .page-heading {
      font-family: 'EB Garamond', serif;
      font-size: 1.7rem;
      font-weight: 600;
      color: var(--red-deeper);
      margin-bottom: 1.4rem;
    }

    /* ── STAT CARDS ── */
    .cards { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 1.8rem; }
    .stat-card {
      flex: 1; min-width: 160px;
      background: var(--surface);
      border-radius: 14px;
      padding: 1.2rem 1.4rem;
      border: 1px solid var(--border);
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
      position: relative;
      overflow: hidden;
    }
    .stat-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
    }
    .stat-card-1::before { background: linear-gradient(90deg,#3b82f6,#60a5fa); }
    .stat-card-2::before { background: linear-gradient(90deg,#10b981,#34d399); }
    .stat-card-3::before { background: linear-gradient(90deg,var(--red-light),var(--red-deeper)); }
    .stat-card-4::before { background: linear-gradient(90deg,#f59e0b,#fbbf24); }

    .stat-icon {
      width: 36px; height: 36px;
      border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem;
      margin-bottom: 0.9rem;
    }
    .stat-card-1 .stat-icon { background: #eff6ff; color: #3b82f6; }
    .stat-card-2 .stat-icon { background: #ecfdf5; color: #10b981; }
    .stat-card-3 .stat-icon { background: #fef2f2; color: var(--red-light); }
    .stat-card-4 .stat-icon { background: #fffbeb; color: #f59e0b; }

    .stat-value {
      font-size: 2rem;
      font-weight: 700;
      color: var(--text);
      line-height: 1;
      margin-bottom: 0.3rem;
    }
    .stat-label {
      font-size: 0.78rem;
      color: var(--text-muted);
      font-weight: 500;
    }

    /* ── TABLES ── */
    .table-wrap {
      background: var(--surface);
      border-radius: 14px;
      border: 1px solid var(--border);
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
      margin-bottom: 1.5rem;
    }
    .table-wrap-header {
      padding: 1rem 1.2rem 0.8rem;
      border-bottom: 1px solid var(--border);
      font-size: 0.9rem;
      font-weight: 600;
      color: var(--text);
    }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 11px 14px; text-align: left; font-size: 0.84rem; }
    th {
      background: #f8f9fb;
      color: var(--text-muted);
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      border-bottom: 1px solid var(--border);
    }
    td { border-bottom: 1px solid #f3f4f6; color: var(--text); }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafbff; }

    /* ── STATUS BADGES ── */
    .badge-good     { color: #059669; font-weight: 600; font-size: 0.78rem; }
    .badge-low      { color: #d97706; font-weight: 600; font-size: 0.78rem; }
    .badge-expired  { color: var(--red-light); font-weight: 600; font-size: 0.78rem; }
    .badge-pending  { color: #d97706; font-weight: 600; }
    .badge-approved { color: #059669; font-weight: 600; }
    .badge-rejected { color: var(--red-light); font-weight: 600; }
    .badge-unread   { color: #d97706; font-weight: 600; }
    .badge-read     { color: #059669; }
    .badge-active   { color: #059669; font-weight: 600; }
    .badge-inactive { color: var(--red-light); font-weight: 600; }
    .badge-must-change { color: #d97706; font-weight: 600; font-size: 0.78rem; }

    /* ── BUTTONS ── */
    .btn, button[type="submit"] {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      height: 36px;
      padding: 0 14px;
      background: var(--red-dark);
      color: #fff;
      border: none;
      border-radius: 8px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.82rem;
      font-weight: 500;
      cursor: pointer;
      text-decoration: none;
      transition: background 0.2s, transform 0.1s;
      white-space: nowrap;
    }
    .btn:hover, button[type="submit"]:hover { background: var(--red-light); }
    .btn:active { transform: scale(0.98); }
    .btn-add  { background: #059669; }
    .btn-add:hover  { background: #047857; }
    .btn-del  { background: #dc2626; }
    .btn-del:hover  { background: #b91c1c; }
    .btn-grey { background: #6b7280; }
    .btn-grey:hover { background: #4b5563; }
    .btn-gold { background: var(--red-dark); border: 1.5px solid var(--gold); color: var(--gold-light); }
    .btn-gold:hover { background: var(--red-deeper); }

    /* ── FORMS ── */
    .form-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 1.4rem 1.6rem;
      max-width: 480px;
      margin-bottom: 1.5rem;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .form-card h3 {
      font-family: 'EB Garamond', serif;
      font-size: 1.1rem;
      color: var(--red-deeper);
      margin-bottom: 1rem;
    }
    .form-card input[type="text"],
    .form-card input[type="password"],
    .form-card input[type="time"],
    .form-card input[type="number"],
    .form-card select {
      width: 100%;
      height: 40px;
      padding: 0 10px;
      margin: 5px 0 10px;
      border: 1.5px solid var(--border);
      border-radius: 8px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.85rem;
      color: var(--text);
      background: #fafafa;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .form-card input:focus, .form-card select:focus {
      border-color: var(--red);
      box-shadow: 0 0 0 3px rgba(155,28,28,0.08);
      background: #fff;
    }
    .form-card label {
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--text-muted);
      display: block;
      margin-bottom: 2px;
    }
    .form-hint {
      font-size: 0.75rem;
      color: var(--text-muted);
      margin: -6px 0 10px;
    }
    .inline-search {
      display: flex; gap: 8px; margin-bottom: 1rem; align-items: center;
    }
    .inline-search input {
      height: 38px;
      padding: 0 12px;
      border: 1.5px solid var(--border);
      border-radius: 8px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.85rem;
      outline: none;
      width: 220px;
      transition: border-color 0.2s;
    }
    .inline-search input:focus { border-color: var(--red); }

    /* ── FILTERS ── */
    .filters { display: flex; gap: 8px; margin-bottom: 1rem; flex-wrap: wrap; }
    .filters a {
      display: inline-flex; align-items: center;
      height: 34px; padding: 0 14px;
      border-radius: 8px;
      background: var(--surface);
      border: 1.5px solid var(--border);
      color: var(--text-muted);
      font-size: 0.8rem;
      font-weight: 500;
      text-decoration: none;
      transition: all 0.2s;
    }
    .filters a:hover, .filters a.active {
      background: var(--red-dark);
      border-color: var(--red-dark);
      color: #fff;
    }

    /* ── ALERTS ── */
    .alert {
      padding: 0.65rem 1rem;
      border-radius: 8px;
      font-size: 0.83rem;
      margin-bottom: 1rem;
      border-left: 3px solid;
    }
    .alert-success { background: #f0fdf4; border-color: #10b981; color: #065f46; }
    .alert-error   { background: #fef2f2; border-color: var(--red-light); color: var(--red-deeper); }
    .alert-info    { background: #eff6ff; border-color: #3b82f6; color: #1e3a8a; }

    /* ── MODALS ── */
    .modal {
      display: none;
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.45);
      z-index: 500;
      align-items: center;
      justify-content: center;
    }
    .modal[style*="block"] { display: flex !important; }
    .modal-content {
      background: var(--surface);
      border-radius: 16px;
      padding: 1.6rem;
      width: 90%;
      max-width: 420px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.25);
      position: relative;
    }
    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1rem;
      padding-bottom: 0.75rem;
      border-bottom: 1px solid var(--border);
    }
    .modal-header h3 {
      font-family: 'EB Garamond', serif;
      font-size: 1.15rem;
      color: var(--red-deeper);
    }
    .modal-close {
      width: 28px; height: 28px;
      border-radius: 6px;
      background: #f3f4f6;
      border: none;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      font-size: 1rem; color: var(--text-muted);
      transition: background 0.2s;
    }
    .modal-close:hover { background: #e5e7eb; }
    .modal-footer {
      display: flex; gap: 8px; justify-content: flex-end;
      margin-top: 1.2rem; padding-top: 0.75rem;
      border-top: 1px solid var(--border);
    }
    .modal-content input, .modal-content select {
      width: 100%;
      height: 40px; padding: 0 10px;
      margin: 4px 0 10px;
      border: 1.5px solid var(--border);
      border-radius: 8px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.85rem;
      outline: none;
      transition: border-color 0.2s;
    }
    .modal-content input:focus, .modal-content select:focus {
      border-color: var(--red);
    }
    .modal-content label {
      font-size: 0.72rem; font-weight: 600;
      letter-spacing: 0.06em; text-transform: uppercase;
      color: var(--text-muted); display: block; margin-bottom: 2px;
    }
    .modal-content p { font-size: 0.88rem; color: var(--text); line-height: 1.6; }
  </style>
</head>
<body>

<!-- ══ MODALS ══ -->
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
    <p>Are you sure you want to delete "<strong><span id="deleteCategoryName"></span></strong>"? <br><span style="color:var(--red-light);">This cannot be undone.</span></p>
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
    <p>Are you sure you want to delete "<strong><span id="deleteScheduleName"></span></strong>"?<br><span style="color:var(--red-light);">This cannot be undone.</span></p>
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

<!-- ══ SIDEBAR ══ -->
<div class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <button id="hamburger" class="hamburger-btn" title="Toggle menu">
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
    <a class="nav-item <?= $page==='dashboard'     ? 'active':'' ?>" href="dashboard.php?page=dashboard"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
    <div class="nav-section-label">Inventory</div>
    <a class="nav-item <?= $page==='medicines'     ? 'active':'' ?>" href="dashboard.php?page=medicines"><i class="fas fa-pills"></i><span>Medicines</span></a>
    <a class="nav-item <?= $page==='categories'    ? 'active':'' ?>" href="dashboard.php?page=categories"><i class="fas fa-tags"></i><span>Categories</span></a>
    <a class="nav-item <?= $page==='donations'     ? 'active':'' ?>" href="dashboard.php?page=donations"><i class="fas fa-hand-holding-medical"></i><span>Donations</span></a>
    <div class="nav-section-label">Administration</div>
    <a class="nav-item <?= $page==='manage_users'  ? 'active':'' ?>" href="dashboard.php?page=manage_users"><i class="fas fa-users"></i><span>Manage Users</span></a>
    <a class="nav-item <?= $page==='schedules'     ? 'active':'' ?>" href="dashboard.php?page=schedules"><i class="fas fa-clock"></i><span>Schedules</span></a>
    <div class="nav-section-label">Activity</div>
    <a class="nav-item <?= $page==='logs'          ? 'active':'' ?>" href="dashboard.php?page=logs"><i class="fas fa-history"></i><span>Logs</span></a>
    <a class="nav-item <?= $page==='notifications' ? 'active':'' ?>" href="dashboard.php?page=notifications"><i class="fas fa-bell"></i><span>Notifications <?php if($unreadCount>0): ?><span style="background:var(--gold);color:var(--red-deeper);border-radius:10px;padding:1px 7px;font-size:0.7rem;margin-left:4px;"><?= $unreadCount ?></span><?php endif; ?></span></a>
  </nav>

  <div class="sidebar-footer">
    <button class="nav-item" onclick="openLogoutModal()" style="color:rgba(255,255,255,0.6);">
      <i class="fas fa-sign-out-alt"></i><span>Logout</span>
    </button>
  </div>
</div>

<!-- ══ TOPBAR ══ -->
<div class="topbar" id="topbar">
  <span class="topbar-title">
    <?php
      $titles = [
        'dashboard'     => 'Dashboard',
        'manage_users'  => 'Manage Users',
        'medicines'     => 'Medicine Overview',
        'categories'    => 'Categories',
        'donations'     => 'Donation Requests',
        'logs'          => 'Activity Logs',
        'schedules'     => 'Check Schedules',
        'notifications' => 'Notifications',
      ];
      echo $titles[$page] ?? 'Admin Panel';
    ?>
  </span>

  <div class="topbar-right">
    <!-- notification bell -->
    <a href="dashboard.php?page=notifications" class="topbar-notif" title="Notifications">
      <i class="fas fa-bell"></i>
      <?php if ($unreadCount > 0): ?>
        <span class="badge"><?= $unreadCount ?></span>
      <?php endif; ?>
    </a>

    <div class="topbar-divider"></div>

    <!-- profile -->
    <div class="profile-menu">
      <button class="profile-btn" onclick="toggleProfileMenu()" type="button">
        <div class="profile-avatar">
          <?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?>
        </div>
        <div class="profile-info">
          <div class="p-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div>
          <div class="p-role"><?= ucfirst($_SESSION['role'] ?? 'Admin') ?></div>
        </div>
        <i class="fas fa-chevron-down profile-chevron"></i>
      </button>

      <div class="profile-dropdown" id="profileDropdown">
        <div class="dropdown-header">
          <div class="dh-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div>
          <div class="dh-role"><?= ucfirst($_SESSION['role'] ?? 'Admin') ?> &mdash; BENE MediCon</div>
        </div>
        <a href="dashboard.php?page=notifications" class="dropdown-item">
          <i class="fas fa-bell"></i> Notifications
          <?php if ($unreadCount > 0): ?><span style="margin-left:auto;background:var(--red-light);color:#fff;border-radius:10px;padding:1px 8px;font-size:0.68rem;"><?= $unreadCount ?></span><?php endif; ?>
        </a>
        <button onclick="openLogoutModal()" class="dropdown-item danger">
          <i class="fas fa-sign-out-alt"></i> Logout
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ MAIN CONTENT ══ -->
<div class="main-content" id="mainContent">

<?php if ($page === 'notifications'): ?>
  <h1 class="page-heading">Notifications</h1>
  <form method="POST" style="margin-bottom:1rem;">
    <button type="submit" name="mark_all_read" class="btn btn-add">
      <i class="fas fa-check-double"></i> Mark All as Read
    </button>
  </form>
  <div class="table-wrap">
    <table>
      <tr><th>Message</th><th>Status</th><th>Received</th><th>Action</th></tr>
      <?php if ($notifications && $notifications->num_rows > 0): ?>
        <?php while($n = $notifications->fetch_assoc()): ?>
        <tr>
          <td><?= htmlspecialchars($n['message']) ?></td>
          <td><?= $n['is_read'] ? '<span class="badge-read">Read</span>' : '<span class="badge-unread">Unread</span>' ?></td>
          <td><?= htmlspecialchars($n['created_at']) ?></td>
          <td><?php if (!$n['is_read']): ?><a class="btn" href="mark_read.php?id=<?= (int)$n['id'] ?>">Mark as Read</a><?php endif; ?></td>
        </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="4" style="text-align:center;color:var(--text-muted);">No notifications.</td></tr>
      <?php endif; ?>
    </table>
  </div>

<?php elseif ($page === 'dashboard'): ?>
  <h1 class="page-heading">Admin Dashboard</h1>
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
        <tr><td colspan="3" style="text-align:center;color:var(--text-muted);">No recent logs.</td></tr>
      <?php endif; ?>
    </table>
  </div>

<?php elseif ($page === 'donations'): ?>
  <h1 class="page-heading">Donation Requests</h1>
  <?php if (!empty($success_message)): ?><div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>
  <?php $donations = $conn->query("SELECT dr.id, dr.status, dr.requested_at, dr.approved_at, m.name AS med_name, m.type AS med_type, u.username AS staff_name FROM donation_requests dr JOIN medicines m ON dr.medicine_id = m.id JOIN users u ON dr.staff_id = u.id ORDER BY dr.requested_at DESC"); ?>
  <div class="table-wrap">
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
            <?php if ($d['status']==='approved'): ?><span class="badge-approved">&#10003; Approved</span>
            <?php elseif ($d['status']==='pending'): ?><span class="badge-pending">&#8987; Pending</span>
            <?php else: ?><span class="badge-rejected">&#10007; Rejected</span><?php endif; ?>
          </td>
          <td>
            <?php if ($d['status']==='pending'): ?>
              <a class="btn btn-add" href="dashboard.php?page=donations&approve=<?= (int)$d['id'] ?>" onclick="return confirm('Approve this request?')"><i class="fas fa-check"></i> Approve</a>
              <a class="btn btn-del" href="dashboard.php?page=donations&reject=<?= (int)$d['id'] ?>"  onclick="return confirm('Reject this request?')"><i class="fas fa-times"></i> Reject</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text-muted);">No donation requests.</td></tr>
      <?php endif; ?>
    </table>
  </div>

<?php elseif ($page === 'manage_users'): ?>
  <h1 class="page-heading">Manage Users</h1>
  <?php if(isset($_GET['msg'])): ?><div class="alert alert-info"><?= htmlspecialchars($_GET['msg']) ?></div><?php endif; ?>
  <?php if(!empty($error_message)): ?><div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>
  <?php if(!empty($success_message)): ?><div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>

  <form method="GET" class="inline-search">
    <input type="hidden" name="page" value="manage_users">
    <input type="text" name="search" placeholder="&#128269; Search users..." value="<?= htmlspecialchars($search ?? '') ?>">
    <button type="submit" class="btn"><i class="fas fa-search"></i> Search</button>
  </form>

  <div class="form-card">
    <h3><?= !empty($editUser) ? 'Edit User' : 'Add New User' ?></h3>
    <form method="POST">
      <?php if (!empty($editUser)): ?>
        <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">
        <label>Username</label>
        <input type="text" name="username" value="<?= htmlspecialchars($editUser['username']) ?>" required>
        <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:1rem;">Role: <strong><?= ucfirst($editUser['role']) ?></strong></p>
        <button type="submit" name="update_user" class="btn"><i class="fas fa-save"></i> Update User</button>
        <a class="btn btn-grey" href="dashboard.php?page=manage_users" style="margin-left:6px;">Cancel</a>
      <?php else: ?>
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
        <button type="submit" name="add_user" class="btn btn-add" style="margin-top:4px;"><i class="fas fa-plus"></i> Add User</button>
      <?php endif; ?>
    </form>
  </div>

  <div class="table-wrap">
    <table>
      <tr><th>ID</th><th>Username</th><th>Role</th><th>Created</th><th>Last Login</th><th>Status</th><th>Action</th></tr>
      <?php if (!empty($users) && $users->num_rows > 0): ?>
        <?php while($row = $users->fetch_assoc()): ?>
        <tr>
          <td><?= (int)$row['id'] ?></td>
          <td><?= htmlspecialchars($row['username']) ?></td>
          <td><?= ucfirst($row['role']) ?></td>
          <td><?= htmlspecialchars($row['created_at']) ?></td>
          <td><?= $row['last_login'] ? htmlspecialchars($row['last_login']) : '&mdash;' ?></td>
          <td><?= $row['force_password_change'] ? '<span class="badge-must-change">Must change password</span>' : '<span class="badge-active">Active</span>' ?></td>
          <td>
            <a class="btn" href="?page=manage_users&edit=<?= (int)$row['id'] ?>"><i class="fas fa-edit"></i> Edit</a>
            <?php if ($row['role'] === 'admin'): ?>
              <button class="btn btn-del" disabled style="opacity:0.4;cursor:not-allowed;">Protected</button>
            <?php else: ?>
              <a class="btn btn-del" href="?page=manage_users&delete=<?= (int)$row['id'] ?>" onclick="return confirm('Delete this user?')"><i class="fas fa-trash"></i></a>
              <a class="btn" href="?page=manage_users&reset=<?= (int)$row['id'] ?>" onclick="return confirm('Reset password for this user?')"><i class="fas fa-key"></i> Reset</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-muted);">No users found.</td></tr>
      <?php endif; ?>
    </table>
  </div>

<?php elseif ($page === 'categories'): ?>
  <h1 class="page-heading">Medicine Categories</h1>
  <?php if(!empty($error_message)): ?><div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>
  <?php if(!empty($success_message)): ?><div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>

  <div class="form-card">
    <?php if ($editCategory): ?>
      <h3>Edit: <?= htmlspecialchars($editCategory['name']) ?></h3>
      <form method="POST">
        <input type="hidden" name="id" value="<?= (int)$editCategory['id'] ?>">
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
      <tr><th>ID</th><th>Name</th><th>Action</th></tr>
      <?php if ($categories && $categories->num_rows > 0): ?>
        <?php while($cat = $categories->fetch_assoc()): ?>
        <tr>
          <td><?= (int)$cat['id'] ?></td>
          <td><?= htmlspecialchars($cat['name']) ?></td>
          <td>
            <a class="btn" href="dashboard.php?page=categories&edit_cat=<?= (int)$cat['id'] ?>"><i class="fas fa-edit"></i> Edit</a>
            <button class="btn btn-del" onclick="openDeleteCategoryModal(<?= (int)$cat['id'] ?>, '<?= addslashes(htmlspecialchars($cat['name'])) ?>')"><i class="fas fa-trash"></i> Delete</button>
          </td>
        </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="3" style="text-align:center;color:var(--text-muted);">No categories found.</td></tr>
      <?php endif; ?>
    </table>
  </div>

<?php elseif ($page === 'medicines'): ?>
  <h1 class="page-heading">Medicine Overview</h1>
  <div class="filters">
    <a href="?page=medicines" <?= !isset($_GET['filter']) ? 'class="active"':'' ?>>All</a>
    <a href="?page=medicines&filter=low_stock" <?= (isset($_GET['filter'])&&$_GET['filter']==='low_stock') ? 'class="active"':'' ?>>Low Stock</a>
    <a href="?page=medicines&filter=expiring" <?= (isset($_GET['filter'])&&$_GET['filter']==='expiring') ? 'class="active"':'' ?>>Expiring Soon</a>
  </div>
  <form method="GET" class="inline-search">
    <input type="hidden" name="page" value="medicines">
    <input type="text" name="search" placeholder="&#128269; Search medicine..." value="<?= htmlspecialchars($search ?? '') ?>">
    <button type="submit" class="btn"><i class="fas fa-search"></i> Search</button>
  </form>
  <div class="table-wrap">
    <table id="medicines-table">
      <tr><th>ID</th><th>Medicine</th><th>Category</th><th>Qty</th><th>Expiry</th><th>Status</th></tr>
      <?php if (!empty($meds) && $meds->num_rows > 0): while($row = $meds->fetch_assoc()):
        $class = "badge-good"; $status = "Good";
        if ($row['quantity'] <= 20 && $row['expired_date'] > date("Y-m-d")) { $status="Low Stock"; $class="badge-low"; }
        if ($row['expired_date'] <= date("Y-m-d")) { $status="Expired"; $class="badge-expired"; }
        elseif ($row['expired_date'] <= date("Y-m-d", strtotime("+7 days"))) { $status="Expiring Soon"; $class="badge-low"; }
      ?>
        <tr>
          <td><?= (int)$row['id'] ?></td>
          <td><?= htmlspecialchars($row['name']) ?></td>
          <td><?= htmlspecialchars($row['type']) ?></td>
          <td><?= (int)$row['quantity'] ?></td>
          <td><?= htmlspecialchars($row['expired_date']) ?></td>
          <td><span class="<?= $class ?>"><?= $status ?></span></td>
        </tr>
      <?php endwhile; else: ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text-muted);">No results.</td></tr>
      <?php endif; ?>
    </table>
  </div>

<?php elseif ($page === 'logs'): ?>
  <h1 class="page-heading">Activity Logs</h1>
  <div class="table-wrap">
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
  </div>

<?php elseif ($page === 'schedules'): ?>
  <h1 class="page-heading">Check Schedules</h1>
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
      <tr><th>Name</th><th>Type</th><th>Time</th><th>Next Check</th><th>Status</th><th>Action</th></tr>
      <?php if ($schedules && $schedules->num_rows > 0): ?>
        <?php while($sched = $schedules->fetch_assoc()): ?>
        <tr>
          <td><?= htmlspecialchars($sched['schedule_name']) ?></td>
          <td><?= ucfirst($sched['schedule_type']) ?></td>
          <td><?= $sched['schedule_time'] ?></td>
          <td><?= $sched['next_check'] ?></td>
          <td><?= $sched['is_active'] ? '<span class="badge-active">Active</span>' : '<span class="badge-inactive">Inactive</span>' ?></td>
          <td>
            <button class="btn" onclick="toggleSchedule(<?= $sched['id'] ?>)"><?= $sched['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            <button class="btn" onclick="openEditScheduleModal(<?= $sched['id'] ?>, '<?= addslashes(htmlspecialchars($sched['schedule_name'])) ?>', '<?= $sched['schedule_type'] ?>', '<?= $sched['schedule_time'] ?>', <?= $sched['schedule_day'] ?>)"><i class="fas fa-edit"></i> Edit</button>
            <button class="btn btn-del" onclick="openDeleteScheduleModal(<?= $sched['id'] ?>, '<?= addslashes(htmlspecialchars($sched['schedule_name'])) ?>')"><i class="fas fa-trash"></i></button>
          </td>
        </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text-muted);">No schedules found.</td></tr>
      <?php endif; ?>
    </table>
  </div>

<?php else: ?>
  <h1 class="page-heading">Page Not Found</h1>
  <p style="color:var(--text-muted);">Unknown page requested.</p>
<?php endif; ?>

</div><!-- /main-content -->

<script>
  // ── Sidebar expand/collapse ──
  document.addEventListener('DOMContentLoaded', function () {
    const sidebar   = document.getElementById('sidebar');
    const topbar    = document.getElementById('topbar');
    const main      = document.getElementById('mainContent');
    const hamburger = document.getElementById('hamburger');

    function applyExpanded(expanded) {
      if (expanded) {
        sidebar.classList.add('expanded');
        topbar.style.left  = 'var(--sidebar-expanded)';
        main.style.marginLeft = 'var(--sidebar-expanded)';
      } else {
        sidebar.classList.remove('expanded');
        topbar.style.left  = 'var(--sidebar-w)';
        main.style.marginLeft = 'var(--sidebar-w)';
      }
    }

    if (localStorage.getItem('sidebarExpanded') === 'true') applyExpanded(true);

    hamburger.addEventListener('click', () => {
      const isExpanded = !sidebar.classList.contains('expanded');
      applyExpanded(isExpanded);
      localStorage.setItem('sidebarExpanded', isExpanded);
    });
  });

  // ── Profile dropdown ──
  function toggleProfileMenu() {
    const dropdown = document.getElementById('profileDropdown');
    dropdown.classList.toggle('show');
    document.addEventListener('click', function closeDD(e) {
      if (!document.querySelector('.profile-menu').contains(e.target)) {
        dropdown.classList.remove('show');
        document.removeEventListener('click', closeDD);
      }
    });
  }

  // ── Modals ──
  function openLogoutModal()  { document.getElementById('logoutModal').style.display = 'block'; }
  function closeLogoutModal() { document.getElementById('logoutModal').style.display = 'none';  }

  function openDeleteCategoryModal(id, name) {
    document.getElementById('deleteCategoryName').textContent = name;
    document.getElementById('confirmDeleteCategory').href = 'dashboard.php?page=categories&delete_cat=' + id;
    document.getElementById('deleteCategoryModal').style.display = 'block';
  }
  function closeDeleteCategoryModal() { document.getElementById('deleteCategoryModal').style.display = 'none'; }

  function openDeleteScheduleModal(id, name) {
    document.getElementById('deleteScheduleName').textContent = name;
    document.getElementById('confirmDeleteSchedule').href = 'dashboard.php?page=schedules&delete_schedule=' + id;
    document.getElementById('deleteScheduleModal').style.display = 'block';
  }
  function closeDeleteScheduleModal() { document.getElementById('deleteScheduleModal').style.display = 'none'; }

  function openEditScheduleModal(id, name, type, time, day) {
    document.getElementById('editScheduleId').value   = id;
    document.getElementById('editScheduleName').value = name;
    document.getElementById('editScheduleType').value = type;
    document.getElementById('editScheduleTime').value = time;
    document.getElementById('editScheduleDay').value  = day || '';
    document.getElementById('editScheduleModal').style.display = 'block';
    updateDayInput(document.getElementById('editScheduleType'), document.getElementById('editScheduleDay'), document.getElementById('editDayField'), document.getElementById('editDayLabel'));
  }
  function closeEditScheduleModal() { document.getElementById('editScheduleModal').style.display = 'none'; }

  window.onclick = function (e) {
    ['logoutModal','deleteCategoryModal','deleteScheduleModal','editScheduleModal'].forEach(id => {
      const m = document.getElementById(id);
      if (m && e.target === m) m.style.display = 'none';
    });
  };

  // ── Schedule helpers ──
  function toggleSchedule(id) { window.location.href = `dashboard.php?page=schedules&toggle_schedule=${id}`; }

  function updateDayInput(sel, inp, container, lbl) {
    const t = sel.value;
    inp.max = t === 'weekly' ? 7 : 31;
    lbl.textContent = t === 'weekly' ? 'Day of week (1–7)' : 'Day of month (1–31)';
    container.style.display = t === 'daily' ? 'none' : 'block';
  }

  document.addEventListener('DOMContentLoaded', function () {
    const ct = document.getElementById('schedule_type');
    const ci = document.getElementById('schedule_day');
    const cc = document.getElementById('dayField');
    const cl = document.getElementById('dayLabel');
    if (ct && ci && cc && cl) {
      ct.addEventListener('change', () => updateDayInput(ct, ci, cc, cl));
      updateDayInput(ct, ci, cc, cl);
    }
    const et = document.getElementById('editScheduleType');
    const ei = document.getElementById('editScheduleDay');
    const ec = document.getElementById('editDayField');
    const el = document.getElementById('editDayLabel');
    if (et && ei && ec && el) et.addEventListener('change', () => updateDayInput(et, ei, ec, el));
  });

  // ── Medicine API (auto-refresh every 5 min) ──
  function fetchMedicinesAPI() {
    fetch('api/medicines.php').then(r=>r.json()).then(data=>updateMedicineTable(data)).catch(()=>{});
  }
  function updateMedicineTable(data) {
    const tb = document.querySelector('#medicines-table tbody');
    if (!tb) return;
    tb.innerHTML = '';
    data.forEach(med => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${med.id}</td><td>${med.name}</td><td>${med.type}</td><td>${med.quantity}</td><td>${med.expired_date}</td><td class="${getStatusClass(med)}">${getStatusText(med)}</td>`;
      tb.appendChild(tr);
    });
  }
  function getStatusClass(med) {
    if (new Date(med.expired_date) <= new Date()) return 'badge-expired';
    if (med.quantity <= 20) return 'badge-low';
    if (new Date(med.expired_date) <= new Date(Date.now()+7*86400000)) return 'badge-low';
    return 'badge-good';
  }
  function getStatusText(med) {
    if (new Date(med.expired_date) <= new Date()) return 'Expired';
    if (med.quantity <= 20) return 'Low Stock';
    if (new Date(med.expired_date) <= new Date(Date.now()+7*86400000)) return 'Expiring Soon';
    return 'Good';
  }
  setInterval(fetchMedicinesAPI, 300000);
</script>
</body>
</html>
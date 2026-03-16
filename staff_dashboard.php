<?php
session_start();
date_default_timezone_set('Asia/Manila');
// Database connection
include "db.php";

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Staff Dashboard | BENE MediCon</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
    background: #BC2605;
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
    background: #BC2605;
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
.profile-thumb {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    object-fit: cover;
}
.profile-thumb-large {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    margin-bottom: 10px;
    object-fit: cover;
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
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', 'Roboto', sans-serif;
}
body {
    min-height: 100vh;
    background-color: #f5f7fa;
}
/* Sidebar */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 80px;
    height: 100%;
    background-color: #BC2605;
    color: white;
    padding-top: 20px;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: 1000;
    overflow: hidden;
    box-shadow: 3px 0 15px rgba(0,0,0,0.1);
}
.sidebar.expanded {
    width: 250px;
}
/* Header: Hamburger + Title */
.sidebar-header {
    display: flex;
    align-items: center;
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
.sidebar.expanded .line-1 {
    transform: rotate(45deg) translate(3px, 3px);
}
.sidebar.expanded .line-2 {
    opacity: 0;
}
.sidebar.expanded .line-3 {
    transform: rotate(-45deg) translate(4px, -4px);
}
.sidebar h2 {
    font-size: 1.3rem;
    margin-left: 15px;
    white-space: nowrap;
    opacity: 0;
    transition: opacity 0.3s 0.1s ease;
}
.sidebar.expanded h2 {
    opacity: 1;
}
/* Menu Buttons */
.sidebar button {
    display: flex;
    align-items: center;
    padding: 15px 20px;
    color: white;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 1rem;
    width: 100%;
    text-align: left;
    border-radius: 0 20px 20px 0;
}
.sidebar button i {
    font-size: 1.3rem;
    min-width: 30px;
    text-align: center;
}
.sidebar button span {
    opacity: 0;
    transition: opacity 0.3s 0.1s ease;
    margin-left: 15px;
}
.sidebar.expanded button span {
    opacity: 1;
}
.sidebar button:hover,
.sidebar button.active {
    background-color: #BC2605;
}
/* Main Content */
.main-content {
    margin-left: 80px;
    padding: 30px;
    transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    min-height: 100vh;
}
.sidebar.expanded ~ .main-content {
    margin-left: 250px;
}
/* Search Bar */
#search-container {
    display: none;
    margin-bottom: 30px;
    padding: 15px;
    background-color: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
#search-container input {
    width: 100%;
    padding: 12px;
    font-size: 1rem;
    border: 1px solid #ccc;
    border-radius: 6px;
    outline: none;
}
/* Category Cards */
.category-card {
    background: #1a73e8;
    color: white;
    padding: 20px;
    border-radius: 10px;
    text-align: center;
    cursor: pointer;
    flex: 0 0 calc(33.333% - 20px); /* ← Fixed width */
    min-width: 150px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    transition: transform 0.2s ease, background 0.3s ease;
    position: relative;
}
.category-card:hover {
    transform: scale(1.05);
    background: #0a4c9e;
}
.category-card p {
    font-size: 0.9em;
    margin: 5px 0;
    font-weight: bold;
}
.category-badge {
    position: absolute;
    top: 6px;
    right: 6px;
    background-color: #f44336;
    color: white;
    font-size: 13px;
    font-weight: bold;
    width: 24px;
    height: 24px;
    min-width: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 6px rgba(0,0,0,0.3);
    z-index: 2;
    border: 2px solid white;
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0% { transform: scale(1); box-shadow: 0 0 6px rgba(244, 67, 54, 0.5); }
    50% { transform: scale(1.15); box-shadow: 0 0 12px rgba(244, 67, 54, 0.8); }
    100% { transform: scale(1); box-shadow: 0 0 6px rgba(244, 67, 54, 0.5); }
}
/* Content Sections */
.content {
    display: none;
    opacity: 0;
    animation: fadeIn 0.5s forwards;
    padding: 20px;
    border-radius: 10px;
    background-color: #ffffff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.content.active {
    display: block;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
h1 {
    color: #1a73e8;
    margin-bottom: 20px;
    font-weight: 600;
}
p {
    color: #555;
    line-height: 1.7;
}
/* Dashboard Cards */
.dashboard-cards {
    display: flex;
    gap: 20px;
    margin-bottom: 30px;
    flex-wrap: wrap;
}
.card {
    flex: 1;
    min-width: 200px;
    padding: 20px;
    border-radius: 12px;
    color: white;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    text-align: center;
    transition: all 0.3s ease;
}
.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.2);
}
.card h3 {
    font-size: 1.1rem;
    margin-bottom: 10px;
}
.card p {
    font-size: 1.8rem;
    font-weight: bold;
    margin: 0;
}
.card-1 { background: linear-gradient(135deg, #6a11cb, #2575fc); }
.card-2 { background: linear-gradient(135deg, #11998e, #38ef7d); }
.card-3 { background: linear-gradient(135deg, #ff416c, #ff4b2b); }
.card-4 { background: linear-gradient(135deg, #f7971e, #ffd200); }
.card-5 { background: linear-gradient(135deg, #00c6ff, #0072ff); }

/* Table */
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}
table th, table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}
table th {
    background-color: #f0f4f8;
    color: #333;
}
table tr:hover {
    background-color: #f9fafa;
}
.expiring-soon td,
.warning {
    color: #c62828 !important;
    font-weight: bold;
}
.expiring-soon td {
    background-color: #ffebee !important;
}
.warning {
    background-color: #fff3e0 !important;
}
.warning td {
    color: #ef6c00 !important;
}
/* Stock Buttons */
.stock-btn {
    padding: 6px 10px;
    margin: 2px;
    border: none;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
    color: white;
}
.add-btn {
    background: #4caf50;
}
.use-btn {
    background: #f44336;
}
/* Notification Bell */
.notification-bell {
    position: fixed;
    top: 20px;
    right: 20px;
    background-color: #BC2605;
    color: white;
    padding: 12px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 20px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    z-index: 1100;
}
.notification-count {
    position: absolute;
    top: -5px;
    right: -5px;
    background-color: #d32f2f;
    color: white;
    font-size: 12px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
/* Modal */
/* Ensure modals are full-screen and ignore sidebar */
.modal {
    display: none;
    position: fixed; /* ← Key change: fixed, not absolute */
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    z-index: 9999; /* Higher than sidebar (z-index: 1000) */
    overflow-y: auto; /* Allow scrolling if content overflows */
}

.modal-content {
    background-color: #fff;
    margin: 5% auto; /* Center vertically & horizontally */
    padding: 20px;
    border-radius: 10px;
    width: 80%;
    max-width: 800px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.2);
    position: relative;
    z-index: 10000; /* Even higher inside the modal */
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}
.modal-header h3 {
    color: #d32f2f;
    margin: 0;
}
.modal-close {
    font-size: 24px;
    cursor: pointer;
    color: #aaa;
}
.modal-close:hover {
    color: #000;
}
.password-note {
    background: #fff3cd;
    color: #856404;
    padding: 15px;
    border: 1px solid #ffeeba;
    border-radius: 8px;
    margin: 20px 0;
    margin-left: 80px;
    transition: margin-left 0.4s;
}
.sidebar.expanded ~ .main-content .password-note {
    margin-left: 250px;
}

/* =============== 🎁 DONATION STYLES =============== */
.donate-btn {
    background: #9c27b0;
    color: white;
    padding: 6px 12px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 14px;
    display: inline-block;
    margin-left: 5px;
}
.donate-btn:hover {
    background: #7b1fa2;
}
.status-pending { color: #f57c00; font-weight: bold; }
.status-approved { color: #388e3c; font-weight: bold; }
.status-rejected { color: #d32f2f; font-weight: bold; }
</style>
<!-- ==================== MEDICAL CHATBOT (Floating Widget) ==================== -->
<style>
.chat-head {
    position: fixed;
    bottom: 20px;
    right: 20px;
    width: 60px;
    height: 60px;
    background: #007BFF;
    color: white;
    border-radius: 50%;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 24px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    cursor: pointer;
    z-index: 1000;
}
.chat-container {
    display: none;
    position: fixed;
    bottom: 90px;
    right: 20px;
    width: 380px;
    height: 500px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    z-index: 999;
    flex-direction: column;
    overflow: hidden;
}
.chat-header {
    background: #007BFF;
    color: white;
    padding: 14px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
}
.disclaimer {
    background: #e3f2fd;
    color: #0d47a1;
    font-size: 0.8em;
    padding: 8px;
    text-align: center;
    border-bottom: 1px solid #bbdefb;
}
#chatbox {
    flex: 1;
    padding: 16px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: #fcfdff;
}
.message {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    max-width: 80%;
}
.message.user {
    align-self: flex-end;
    flex-direction: row-reverse;
}
.message-text {
    padding: 10px 14px;
    border-radius: 16px;
    line-height: 1.5;
}
.message.user .message-text {
    background: #007BFF;
    color: white;
    border-bottom-left-radius: 5px;
}
.message.bot .message-text {
    background: #e9ecef;
    color: #212529;
    border-bottom-right-radius: 5px;
}
.avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
}
#user-input {
    display: flex;
    gap: 8px;
    padding: 12px 16px;
    background: white;
    border-top: 1px solid #eee;
}
#micBtn {
    width: 40px;
    height: 40px;
    background: #28a745;
    color: white;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    justify-content: center;
    align-items: center;
}
#sendBtn {
    width: 40px;
    height: 40px;
    background: #007BFF;
    color: white;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    justify-content: center;
    align-items: center;
}
/* Creative Password Alert */
.password-alert {
    display: flex;
    align-items: center;
    background: #fff9c4;
    border: 1px dashed #ffc107;
    border-radius: 12px;
    padding: 14px 18px;
    margin-bottom: 20px;
    font-size: 0.95rem;
    color: #e65100;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    animation: slideInUp 0.5s ease-out;
    max-width: 650px;
    gap: 12px;
}
.password-alert i {
    font-size: 1.4rem;
    color: #ff8f00;
}
.password-alert span {
    flex: 1;
}
.password-alert a {
    color: #0a4c9e;
    font-weight: bold;
    text-decoration: underline;
    margin-left: 4px;
}
.password-alert a:hover {
    color: #d32f2f;
}
.password-alert .close-btn {
    background: none;
    border: none;
    font-size: 1.3rem;
    color: #999;
    cursor: pointer;
    padding: 0;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: background 0.2s, color 0.2s;
}
.password-alert .close-btn:hover {
    background: #ffe0b2;
    color: #c62828;
}
@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(-15px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Manage Category Modal */
#manageCategoryModal {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 1200;
    width: 90%;
    max-width: 1000px;
    background-color: #fff;
    border-radius: 10px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.2);
    padding: 20px;
    max-height: 80vh;
    overflow-y: auto;
}

/* Search Category Modal */
#searchCategoryModal {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 1200;
    width: 90%;
    max-width: 1000px;
    background-color: #fff;
    border-radius: 10px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.2);
    padding: 20px;
    max-height: 80vh;
    overflow-y: auto;
}
</style>
</head>
<body>
<div id="logoutModal" class="modal">
<div class="modal-content" style="max-width: 400px;">
<div class="modal-header">
<h3><i class="fas fa-sign-out-alt"></i> Logout Confirmation</h3>
<span class="modal-close" onclick="closeLogoutModal()">&times;</span>
</div>
<div class="modal-body" style="text-align: center; padding: 20px;">
<p style="font-size: 1.1em; margin-bottom: 20px;">Are you sure you want to logout?</p>
<button onclick="window.location.href='logout.php'"
style="background: #d32f2f; color: white; padding: 10px 20px; border: none; border-radius: 5px; margin-right: 10px; cursor: pointer;">
Yes, Logout
</button>
<button onclick="closeLogoutModal()"
style="background: #757575; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
Cancel
</button>
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
<h2>BENE MediCon</h2>
</div>
<button id="btn-dashboard" class="active">
<i class="fas fa-tachometer-alt"></i>
<span>Dashboard</span>
</button>



<button id="btn-inventory">
<i class="fas fa-boxes"></i>
<span>Inventory</span>
</button>

<?php if (!$isGuest): ?>
<button id="btn-expiration">
<i class="fas fa-calendar-times"></i>
<span>Expiration</span>
</button>

<!-- =============== 🎁 DONATION & DISPOSAL BUTTONS =============== -->
<button id="btn-donate">
  <i class="fas fa-hand-holding-medical"></i>
  <span>Donate or Dispose</span>
</button>
<button id="btn-donation-history">
  <i class="fas fa-clipboard-list"></i>
  <span>Donation Requests</span>
</button>
<button id="btn-disposal-history">
  <i class="fas fa-trash-alt"></i>
  <span>Disposal Requests</span>
</button>
<?php else: ?>
<!-- For guests, show only Expiration (read-only) -->
<button id="btn-expiration">
  <i class="fas fa-calendar-times"></i>
  <span>Expiration</span>
</button>
<?php endif; ?>
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
<a href="change_password.php">Click here to continue</a>.
</span>
<button class="close-btn" onclick="this.closest('.password-alert').remove();">&times;</button>
</div>
<?php endif; ?>

<div id="content-dashboard" class="content active">
<h1>Staff Dashboard</h1>
<div class="dashboard-cards">
<div class="card card-1">
<h3>Total Medicines</h3>
<p><?php echo $result->num_rows; ?></p>
</div>
<div class="card card-2">
<h3>Expiring Soon</h3>
<p><?php echo $expired_count; ?></p>
</div>
<div class="card card-3">
<h3>Categories</h3>
<p><?php echo count($categories); ?></p>
</div>
<div class="card card-4">
<h3>Last Updated</h3>
<p><?php echo $formatted_date; ?></p>
</div>
<div class="card card-5">
<h3>Low Stock Alerts</h3>
<p><?php echo $low_stock_count; ?></p>
</div>
</div>
<p>Welcome to the <strong>BENE MediCon Inventory System</strong>. Use the sidebar to manage medicines, check expirations, and more.</p>
<!-- Charts Grid -->
<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin: 20px 0;">
<div style="background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); height: 280px;">
<canvas id="categoryChart"></canvas>
</div>
<div style="background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); height: 280px;">
<canvas id="expiryChart"></canvas>
</div>
<div style="background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); height: 280px;">
<canvas id="stockLevelsChart"></canvas>
</div>
<div style="background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); height: 280px;">
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
                boxWidth: 12,
                padding: 8,
                font: {
                    size: 11
                }
            }
        },
        title: {
            display: true,
            font: {
                size: 13,
                weight: 'bold'
            },
            padding: {
                top: 5,
                bottom: 5
            }
        }
    }
};

new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($categories); ?>,
        datasets: [{
            label: 'Medicines by Category',
            data: <?php echo json_encode($categoryData); ?>,
            backgroundColor: ['#4285f4', '#34a853', '#fbbc05', '#ea4335', '#46bdc6', '#7baaf7']
        }]
    },
    options: {
        ...commonOptions,
        plugins: {
            ...commonOptions.plugins,
            title: {
                ...commonOptions.plugins.title,
                text: 'Medicine Distribution by Category'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    font: { size: 11 }
                }
            },
            x: {
                ticks: {
                    font: { size: 11 }
                }
            }
        }
    }
});

new Chart(document.getElementById('expiryChart'), {
    type: 'pie',
    data: {
        labels: ['Valid', 'Expired'],
        datasets: [{
            data: [<?php echo $valid; ?>, <?php echo $expired; ?>],
            backgroundColor: ['#34a853', '#ea4335']
        }]
    },
    options: {
        ...commonOptions,
        plugins: {
            ...commonOptions.plugins,
            title: {
                ...commonOptions.plugins.title,
                text: 'Medicine Expiry Status'
            }
        }
    }
});

new Chart(document.getElementById('stockLevelsChart'), {
    type: 'doughnut',
    data: {
        labels: ['Low Stock (≤20)', 'Normal (21-50)', 'High (>50)'],
        datasets: [{
            data: [
                <?php echo $lowStock; ?>,
                <?php echo $normalStock; ?>,
                <?php echo $highStock; ?>
            ],
            backgroundColor: ['#ea4335', '#fbbc05', '#34a853']
        }]
    },
    options: {
        ...commonOptions,
        plugins: {
            ...commonOptions.plugins,
            title: {
                ...commonOptions.plugins.title,
                text: 'Stock Level Distribution'
            }
        }
    }
});

new Chart(document.getElementById('expiryTrendChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($months); ?>,
        datasets: [{
            label: 'Expiring Medicines',
            data: <?php echo json_encode($counts); ?>,
            borderColor: '#4285f4',
            tension: 0.1
        }]
    },
    options: {
        ...commonOptions,
        plugins: {
            ...commonOptions.plugins,
            title: {
                ...commonOptions.plugins.title,
                text: 'Monthly Expiry Trend'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    font: { size: 11 }
                }
            },
            x: {
                ticks: {
                    font: { size: 11 },
                    maxRotation: 45,
                    minRotation: 45
                }
            }
        }
    }
});
</script>
</div>



<div id="content-inventory" class="content">
  <h1>Inventory</h1>
  <p>Manage your medicine stock and track expiration dates.</p>

  <!-- Toggle Buttons -->
  <div style="display: flex; gap: 10px; margin: 15px 0;">
    <button id="btn-search-view" class="stock-btn" style="background: #1a73e8;" onclick="switchInventoryView('search')">🔍 Search</button>
    <button id="btn-manage-view" class="stock-btn" style="background: #4CAF50;" onclick="switchInventoryView('manage')" <?php echo $isGuest ? 'disabled' : ''; ?>>🛠️ Manage Stock</button>
    <?php if (!$isGuest): ?>
    <button onclick="openAddMedicineModal()" class="stock-btn" style="background: #25d5a3ff;">
    ➕ Add Medicine
    </button>
    <?php endif; ?>
  </div>

  <!-- Unified Search Bar -->
  <div style="margin-bottom: 20px;">
    <input type="text" id="inventory-search" placeholder="🔍 Search medicine by name..." 
           style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 1rem;">
  </div>


    <!-- SEARCH VIEW (Category Buttons Only) -->
    <div id="inventory-search-view">

        <!-- Toggle Button -->
        <button id="toggleSearchTableBtn" class="stock-btn" style="background: #6c757d; margin-bottom: 15px;"
                onclick="toggleSearchTable()">
            📊 Toggle Medicine Table
        </button>

        <!-- Table Container -->
        <div id="searchTableContainer" style="display: none;">
            <div style="display: flex; flex-wrap: wrap; gap: 20px; justify-content: space-between;">
                <?php
                $medsByCategory = [];
                $result = $conn->query("
                    SELECT *,
                    CASE
                        WHEN expired_date < CURDATE() THEN 3
                        WHEN quantity <= 20 THEN 1
                        ELSE 2
                    END AS sort_order
                    FROM medicines
                    ORDER BY sort_order ASC, expired_date ASC
                ");
                while ($row = $result->fetch_assoc()) {
                    $medsByCategory[$row['type']][] = $row;
                }

                foreach ($categories as $cat):
                    if (!isset($medsByCategory[$cat]) || empty($medsByCategory[$cat])) continue;
                    $lowCount = 0;
                    foreach ($medsByCategory[$cat] as $m) {
                        $expDate = new DateTime($m['expired_date']);
                        $today = new DateTime();
                        $isExpired = $expDate < $today;
                        if (!$isExpired && $m['quantity'] <= 20) $lowCount++;
                    }
                ?>
                <div class="category-card" style="flex: 1 1 calc(33.333% - 20px); min-width: 300px; max-width: 400px; display: flex; flex-direction: column;">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px; background: #f0f4f8; border-bottom: 1px solid #ddd;">
                    <h3 style="color: #1a73e8; margin: 0;"><?php echo htmlspecialchars($cat); ?></h3>
                    <?php if ($lowCount > 0): ?>
                        <span class="category-badge" title="<?php echo $lowCount; ?> low stock item(s)"><?php echo $lowCount; ?></span>
                    <?php endif; ?>
                    </div>
                    <div style="flex: 1; overflow-y: auto; padding: 15px; background: white; border: 1px solid #eee; border-top: none;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem; text-align: left;">
                        <thead>
                        <tr style="background: #f0f4f8; border-bottom: 2px solid #ddd;">
                            <th style="padding: 8px; width: 50px;">Image</th>
                            <th style="padding: 8px; min-width: 120px;">Name</th>
                            <th style="padding: 8px; min-width: 80px;">Batch Date</th>
                            <th style="padding: 8px; min-width: 80px;">Expiry Date</th>
                            <th style="padding: 8px; width: 60px; text-align: center;">Qty</th>
                            <th style="padding: 8px; width: 80px;">Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($medsByCategory[$cat] as $row):
                            $expiryDate = new DateTime($row['expired_date']);
                            $today = new DateTime();
                            $isExpired = $expiryDate < $today;
                            $status = $isExpired ? '🔴 Expired' : (($row['quantity'] <= 20 && !$isExpired) ? '⚠️ Low Stock' : '✅ In Stock');
                            $rowClass = $isExpired ? 'expiring-soon' : (($row['quantity'] <= 20 && !$isExpired) ? 'warning' : '');
                        ?>
                        <tr class="<?= $rowClass ?>" style="border-bottom: 1px solid #eee;">
                            <td style="padding: 8px;"><img src="uploads/medicines/<?php echo htmlspecialchars($row['image']); ?>" width="40" alt="Medicine"></td>
                            <td style="padding: 8px; word-break: break-word;"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td style="padding: 8px;"><?php echo htmlspecialchars($row['batch_date']); ?></td>
                            <td style="padding: 8px;"><?php echo htmlspecialchars($row['expired_date']); ?></td>
                            <td style="padding: 8px; text-align: center;"><?php echo (int)$row['quantity']; ?></td>
                            <td style="padding: 8px; font-weight: bold;"><?php echo $status; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
        <?php foreach ($categories as $cat): ?>
            <button class="stock-btn" style="background:#1a73e8; padding:10px 16px;"
                    onclick="openSearchCategoryModal('<?php echo addslashes($cat); ?>')">
            <?php echo htmlspecialchars($cat); ?>
            </button>
        <?php endforeach; ?>
        </div>
    <p style="color: #666;">Click a category above to view its medicines.</p>
    </div>

  <!-- 🛠️ MANAGE VIEW (Category Buttons Only) -->
  <div id="inventory-manage-view" style="display: none;">

    <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
      <?php foreach ($categories as $cat): ?>
        <button class="stock-btn" style="background:#0288d1; padding:10px 16px;"
                <?php echo $isGuest ? 'disabled' : 'onclick="openManageCategoryModal(\'' . addslashes($cat) . '\')"' ?>>
          <?php echo htmlspecialchars($cat); ?>
        </button>
      <?php endforeach; ?>
    </div>

    <p style="color: #666;">Click a category above to manage its medicines.</p>
  </div>

    <!-- ✅ CATEGORY MANAGE MODAL -->
    <div id="manageCategoryModal" class="modal">
    <div class="modal-content" style="max-width: 95%; width: 95%;">
        <div class="modal-header">
        <h3 id="manageCategoryTitle">Manage Medicines</h3>
        <span class="modal-close" onclick="closeManageCategoryModal()">&times;</span>
        </div>
        <div class="modal-body">
        <div id="manageCategoryTableContainer">
            <!-- Table loaded via JS -->
        </div>
        </div>
    </div>
    </div>

    <!-- ✅ SEARCH CATEGORY MODAL -->
    <div id="searchCategoryModal" class="modal">
    <div class="modal-content" style="max-width: 95%; width: 95%;">
    <div class="modal-header">
        <h3 id="searchCategoryTitle">Medicines in Category</h3>
        <span class="modal-close" onclick="closeSearchCategoryModal()">&times;</span>
    </div>
    <div class="modal-body">
        <div id="searchCategoryTableContainer"></div>
    </div>
    </div>
    </div>

  <!-- Add Medicine Modal (unchanged) -->
  <div id="addMedicineModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>➕ Add New Medicine</h3>
        <span class="modal-close" onclick="closeAddMedicineModal()">&times;</span>
      </div>
      <div class="modal-body">
        <form method="POST" action="staff_dashboard.php" enctype="multipart/form-data">
          <div style="max-width: 100%;">
            <label style="display:block;margin:10px 0 5px;font-weight:600;">Medicine Name</label>
            <input type="text" name="name" required
              style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-bottom:15px;">
            <label style="display:block;margin:10px 0 5px;font-weight:600;">Category</label>
            <select name="type" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-bottom:15px;">
              <option value="" disabled selected>Select Category</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
              <?php endforeach; ?>
            </select>
            <label style="display:block;margin:10px 0 5px;font-weight:600;">Batch Date</label>
            <input type="date" name="batch_date" required
              style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-bottom:15px;">
            <label style="display:block;margin:10px 0 5px;font-weight:600;">Expiration Date</label>
            <input type="date" name="expired_date" required
              style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-bottom:15px;">
            <label style="display:block;margin:10px 0 5px;font-weight:600;">Quantity</label>
            <input type="number" name="quantity" required min="1" value="100"
              style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-bottom:15px;">
            <label style="display:block;margin:10px 0 5px;font-weight:600;">Upload Image:</label>
            <input type="file" name="image" required style="margin-bottom:15px;">
            <button type="submit" name="add_medicine"
              style="background:#1a73e8;color:white;padding:12px;border:none;border-radius:6px;width:100%;
              font-size:16px;cursor:pointer;">
              💊 Add Medicine
            </button>
          </div>
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
<div class="profile-menu">
<div class="profile-icon" onclick="toggleProfileMenu()">
<img src="uploads/avatars/<?php echo htmlspecialchars($_SESSION['profile_pic'] ?? 'default.jpg'); ?>"
alt="Profile" class="profile-thumb">
</div>
<div class="profile-dropdown" id="profileDropdown">
<div class="profile-header">
<img src="uploads/avatars/<?php echo htmlspecialchars($_SESSION['profile_pic'] ?? 'default.jpg'); ?>"
alt="Profile" class="profile-thumb-large">
<p class="profile-name"><?php echo htmlspecialchars($_SESSION['username']); ?></p>
<p class="profile-role">Staff Member</p>
</div>
<?php if (!$isGuest): ?>
<a href="edit_profile.php">
<i class="fas fa-user-edit"></i> Edit Profile
</a>
<a href="change_password.php">
<i class="fas fa-key"></i> Change Password
</a>
<?php endif; ?>
<a href="#" onclick="openLogoutModal()">
<i class="fas fa-sign-out-alt"></i> Logout
</a>
</div>
</div>

<!-- Notification Bell -->
<div class="notification-bell" id="bell" onclick="openModal()" title="Click to view expiring medicines">
<i class="fa fa-bell"></i>
<?php if ($expired_count > 0): ?>
<div class="notification-count"><?php echo $expired_count; ?></div>
<?php endif; ?>
</div>

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
<i class="fas fa-clinic-medical"></i> MedBot
<span class="chat-close" id="chatClose">&times;</span>
</div>
<div class="disclaimer">Powered by BENE MediCon</div>
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
const sidebar = document.getElementById("sidebar");
const hamburger = document.getElementById("hamburger");
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

hamburger.addEventListener("click", () => {
    sidebar.classList.toggle("expanded");
});

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
}

let currentInventoryView = 'search';

function toggleSearchTable() {
    const container = document.getElementById('searchTableContainer');
    const btn = document.getElementById('toggleSearchTableBtn');
    if (container.style.display === 'none') {
        container.style.display = 'block';
        btn.innerHTML = '📊 Hide Medicine Table';
    } else {
        container.style.display = 'none';
        btn.innerHTML = '📊 Show Medicine Table';
    }
}

function switchInventoryView(view) {
    if (isGuest && view === 'manage') {
        showToast("Guests cannot manage stock.", "error");
        return;
    }
    currentInventoryView = view;
    document.getElementById('inventory-search-view').style.display = view === 'search' ? 'block' : 'none';
    document.getElementById('inventory-manage-view').style.display = view === 'manage' ? 'block' : 'none';
    
    // Update button styles
    document.getElementById('btn-search-view').style.background = view === 'search' ? '#1a73e8' : '#90a4ae';
    document.getElementById('btn-manage-view').style.background = view === 'manage' ? '#4CAF50' : '#90a4ae';
}

// Unified search that filters both views
document.getElementById("inventory-search").addEventListener("keyup", function () {
    const filter = this.value.toLowerCase();

    // Search View: Hide entire category cards if no match
    const categoryCards = document.querySelectorAll("#inventory-search-view .category-card");
    categoryCards.forEach(card => {
        const txt = card.textContent.toLowerCase();
        card.style.display = txt.includes(filter) ? "flex" : "none";
    });

    // Manage View: Filter table rows
    const manageRows = document.querySelectorAll("#inventory-table tbody tr");
    manageRows.forEach(row => {
        const txt = row.textContent.toLowerCase();
        row.style.display = txt.includes(filter) ? "" : "none";
    });
});

Object.keys(buttons).forEach(key => {
    if (buttons[key]) buttons[key].addEventListener("click", () => showSection(key));
});

// Live search inside Inventory tab
document.getElementById("inventory-search").addEventListener("keyup", function () {
    const filter = this.value.toLowerCase();
    const rows = document.querySelectorAll("#inventory-table tbody tr, #inventory-table tr:not(:first-child)");
    rows.forEach(row => {
        const txt = row.textContent.toLowerCase();
        row.style.display = txt.includes(filter) ? "" : "none";
    });
});

// Open Manage Category Modal
function openManageCategoryModal(category) {
    // Fetch medicines for this category via AJAX or build inline
    const container = document.getElementById('manageCategoryTableContainer');
    const title = document.getElementById('manageCategoryTitle');
    title.textContent = `Manage: ${category}`;

    // Build table rows
    let rows = '';
    <?php
    // Reuse medsByCategory from earlier (or requery)
    $manageMeds = [];
    $res = $conn->query("SELECT * FROM medicines ORDER BY 
        CASE WHEN expired_date < CURDATE() THEN 3
             WHEN quantity <= 20 THEN 1
             ELSE 2 END,
        expired_date ASC");
    while ($r = $res->fetch_assoc()) {
        $manageMeds[] = $r;
    }
    $jsonMeds = json_encode($manageMeds);
    ?>
    const allMeds = <?php echo $jsonMeds; ?>;
    const filtered = allMeds.filter(med => med.type === category);

    if (filtered.length === 0) {
        rows = `<tr><td colspan="8" style="text-align:center;padding:20px;color:#666;">No medicines in this category.</td></tr>`;
    } else {
        filtered.forEach(row => {
            const expiryDate = new Date(row.expired_date);
            const today = new Date();
            const isExpired = expiryDate < today;
            const isLowStock = !isExpired && row.quantity <= 20;
            const status = isExpired ? '🔴 Expired' : (isLowStock ? '⚠️ Low Stock' : '✅ In Stock');
            const rowClass = isExpired ? 'expiring-soon' : (isLowStock ? 'warning' : '');

            let actions = '';
            if (isExpired) {
                actions = '<span style="color: #999; font-style: italic;">— Expired —</span>';
            } else {
                actions = `
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="id" value="${row.id}">
                    <input type="hidden" name="action" value="add">
                    <input type="number" name="change" placeholder="Qty" min="1" required style="width:60px;padding:5px;font-size:0.9rem;">
                    <button type="submit" name="adjust_stock" class="stock-btn add-btn" style="font-size:0.9rem;padding:5px 8px;">➕ Add</button>
                  </form>
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="id" value="${row.id}">
                    <input type="hidden" name="action" value="use">
                    <input type="number" name="change" placeholder="Qty" min="1" required style="width:60px;padding:5px;font-size:0.9rem;">
                    <button type="submit" name="adjust_stock" class="stock-btn use-btn" onclick="return confirm('Use this stock?')" style="font-size:0.9rem;padding:5px 8px;">➖ Use</button>
                  </form>
                  <a href="#" onclick="openEditModal(${row.id})" class="edit-btn" style="color:#fff;background:#0288d1;padding:5px 8px;border-radius:4px;text-decoration:none;font-size:0.9rem;">Edit</a>
                  <a href="#" onclick="openDeleteModal(${row.id}, '${row.name.replace(/'/g, "\\'")}')"
                     class="delete-btn" style="color:#fff;background:#e53935;padding:5px 8px;border-radius:4px;text-decoration:none;font-size:0.9rem;">Delete</a>
                `;
            }

            rows += `
              <tr class="${rowClass}">
                <td style="padding:12px;"><img src="uploads/medicines/${row.image}" width="40" alt="Medicine"></td>
                <td style="padding:12px; word-break: break-word;">${row.name}</td>
                <td style="padding:12px;">${row.type}</td>
                <td style="padding:12px;">${row.batch_date}</td>
                <td style="padding:12px;">${row.expired_date}</td>
                <td style="padding:12px; text-align: center;">${row.quantity}</td>
                <td style="padding:12px; font-weight: bold;">${status}</td>
                <td style="padding:12px; display: flex; gap: 5px; flex-wrap: wrap;">${actions}</td>
              </tr>
            `;
        });
    }

    container.innerHTML = `
      <table style="width:100%; border-collapse: collapse; margin-top: 10px;">
        <thead>
          <tr style="background:#f0f4f8; border-bottom:2px solid #ddd;">
            <th style="padding:12px;">Image</th>
            <th style="padding:12px;">Name</th>
            <th style="padding:12px;">Type</th>
            <th style="padding:12px;">Batch Date</th>
            <th style="padding:12px;">Expiry Date</th>
            <th style="padding:12px; text-align:center;">Qty</th>
            <th style="padding:12px;">Status</th>
            <th style="padding:12px;">Actions</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;

    document.getElementById('manageCategoryModal').style.display = 'block';
}

function closeManageCategoryModal() {
    document.getElementById('manageCategoryModal').style.display = 'none';
}

function openEditModal(id) {
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

// Open Search Category Modal (read-only table)
function openSearchCategoryModal(category) {
    const container = document.getElementById('searchCategoryTableContainer');
    const title = document.getElementById('searchCategoryTitle');
    title.textContent = `Medicines: ${category}`;

    let rows = '';
    <?php
    // Reuse sorted medicine data
    $searchMeds = [];
    $res = $conn->query("SELECT * FROM medicines ORDER BY 
        CASE WHEN expired_date < CURDATE() THEN 3
             WHEN quantity <= 20 THEN 1
             ELSE 2 END,
        expired_date ASC");
    while ($r = $res->fetch_assoc()) {
        $searchMeds[] = $r;
    }
    $jsonSearchMeds = json_encode($searchMeds);
    ?>
    const allMeds = <?php echo $jsonSearchMeds; ?>;
    const filtered = allMeds.filter(med => med.type === category);

    if (filtered.length === 0) {
        rows = `<tr><td colspan="7" style="text-align:center;padding:20px;color:#666;">No medicines in this category.</td></tr>`;
    } else {
        filtered.forEach(row => {
            const expiryDate = new Date(row.expired_date);
            const today = new Date();
            const isExpired = expiryDate < today;
            const isLowStock = !isExpired && row.quantity <= 20;
            const status = isExpired ? '🔴 Expired' : (isLowStock ? '⚠️ Low Stock' : '✅ In Stock');
            const rowClass = isExpired ? 'expiring-soon' : (isLowStock ? 'warning' : '');

            rows += `
              <tr class="${rowClass}">
                <td style="padding:12px;"><img src="uploads/medicines/${row.image}" width="40" alt="Medicine"></td>
                <td style="padding:12px; word-break: break-word;">${row.name}</td>
                <td style="padding:12px;">${row.batch_date}</td>
                <td style="padding:12px;">${row.expired_date}</td>
                <td style="padding:12px; text-align: center;">${row.quantity}</td>
                <td style="padding:12px; font-weight: bold;">${status}</td>
              </tr>
            `;
        });
    }

    container.innerHTML = `
      <table style="width:100%; border-collapse: collapse; margin-top: 10px;">
        <thead>
          <tr style="background:#f0f4f8; border-bottom:2px solid #ddd;">
            <th style="padding:12px;">Image</th>
            <th style="padding:12px;">Name</th>
            <th style="padding:12px;">Batch Date</th>
            <th style="padding:12px;">Expiry Date</th>
            <th style="padding:12px; text-align:center;">Qty</th>
            <th style="padding:12px;">Status</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;

    document.getElementById('searchCategoryModal').style.display = 'block';
}

function closeSearchCategoryModal() {
    document.getElementById('searchCategoryModal').style.display = 'none';
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
        if (!profile.contains(e.target)) {
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
<?php
session_start();
include('../db.php');

$message = "A user attempted password reset but needs admin assistance.";

$result = $conn->query("SELECT id FROM users WHERE role = 'admin'");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $admin_id = $row['id'];
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param("is", $admin_id, $message);
            $stmt->execute();
        }
    }
    echo "ok"; // success response
} else {
    echo "error"; // failure response
}
?>
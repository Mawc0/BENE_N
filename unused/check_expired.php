<?php
// check_expired.php - Logs ALL expired medicines (past & today) not already logged

// Database connection
include "db.php";

$today = date('Y-m-d');

// Insert ALL medicines that have expired on or before today AND are not in expired_logs
$stmt = $conn->prepare("
    INSERT INTO expired_logs (medicine_id, name, type, batch_date, expired_date, quantity_at_expiry, image)
    SELECT id, name, type, batch_date, expired_date, quantity, image 
    FROM medicines 
    WHERE expired_date <= ? 
    AND id NOT IN (SELECT medicine_id FROM expired_logs)
");

if ($stmt === false) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("s", $today);

if ($stmt->execute()) {
    $affected = $stmt->affected_rows;
    echo "<p>✅ $affected expired medicine(s) moved to expired_logs.</p>";
    if ($affected > 0) {
        error_log("$affected expired medicines logged at " . date('Y-m-d H:i:s'));
    }
} else {
    echo "❌ Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>
<?php
// Database connection
include "db.php";

$id = intval($_GET['id']);
$result = $conn->query("SELECT * FROM medicines WHERE id = $id");
if ($row = $result->fetch_assoc()) {
    echo json_encode($row);
} else {
    echo json_encode([]);
}
$conn->close();
?>
<?php
// check_username.php

// Database connection
include "db.php";

// Get the username from the request
$username = mysqli_real_escape_string($conn, $_GET['username']);

// Query to check if the username already exists
$query = "SELECT * FROM users WHERE username = '$username'";
$result = $conn->query($query);

// Return the result
if ($result->num_rows > 0) {
    echo json_encode(['available' => false, 'message' => 'Username already taken']);
} else {
    echo json_encode(['available' => true, 'message' => 'Username is available']);
}

$conn->close();
?>

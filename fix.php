<?php
echo "<h2>🔧 Fixing All Admin Passwords...</h2>";

// Database connection
include "db.php";

// List of admin usernames and passwords
$admins = [
    ['medicon25', 'password123'],
    ['frenz', 'frenz123'],
    ['Marc', 'marc123'],
    ['Nicole', 'nicole123']
];

$fixed = 0;

foreach ($admins as [$username, $password]) {
    // Generate secure bcrypt hash
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // Try exact match first
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
    $stmt->bind_param("ss", $hash, $username);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo "<p style='color:green'>✅ Fixed: <strong>$username</strong> → Now use password: <strong>$password</strong></p>";
        $fixed++;
    } else {
        // Try case-insensitive + trim
        $stmt2 = $conn->prepare("UPDATE users SET password = ? WHERE TRIM(LOWER(username)) = ?");
        $lower_username = strtolower($username);
        $stmt2->bind_param("ss", $hash, $lower_username);
        $stmt2->execute();

        if ($stmt2->affected_rows > 0) {
            echo "<p style='color:green'>✅ Fixed (case-insensitive): <strong>$username</strong> → Now use password: <strong>$password</strong></p>";
            $fixed++;
        } else {
            echo "<p style='color:red'>❌ Not found: <strong>$username</strong> (check spelling)</p>";
        }
        $stmt2->close();
    }
    $stmt->close();
}

$conn->close();

// 🔁 REMOVED: unlink(__FILE__); → File will NOT delete itself

echo "<br><h3>🎉 All done! $fixed admin(s) fixed.</h3>";
echo "<p>You can run this script again if needed.</p>";
echo "<a href='login.php'>👉 Go to Login Page</a> | <a href='fix.php'>🔄 Run Again</a>";
?>
<?php
// Start the session and check if the user is logged in and is a staff member
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'staff') {
    // If session variables are not set correctly, redirect to login
    var_dump($_SESSION); // This will print session variables for debugging purposes
    header("Location: login.php"); // Redirect to login page if not logged in
    exit();
}

// Database connection
include "db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the form data for security questions
    $security_question_1 = $_POST['security_question_1'];
    $security_answer_1 = $_POST['security_answer_1'];
    $security_question_2 = $_POST['security_question_2'];
    $security_answer_2 = $_POST['security_answer_2'];

    // Save security questions to the database
    $user_id = $_SESSION['user_id']; // Use the logged-in user's ID from the session
    $stmt = $db->prepare("UPDATE users SET security_question_1 = ?, security_answer_1 = ?, security_question_2 = ?, security_answer_2 = ? WHERE id = ?");

    // Check if the statement is prepared successfully
    if (!$stmt) {
        error_log("Error preparing statement: " . $db->error);
        echo "Error preparing statement: " . $db->error;
        exit();
    }

    $stmt->bind_param("ssssi", $security_question_1, $security_answer_1, $security_question_2, $security_answer_2, $user_id);

    // Execute the query and check for errors
    if ($stmt->execute()) {
        // Redirect to the dashboard with a success message after setting up security questions
        header("Location: staff_dashboard.php?success=Security questions set up successfully.");
        exit();
    } else {
        // Log error for debugging
        error_log("Error executing query: " . $stmt->error);
        echo "Error: " . $stmt->error;
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="profile-style.css">
    <title>Set Security Questions - BENE MediCon</title>
</head>
<body>
    <div class="container">
        <h2>Set Up Security Questions</h2>
        <form action="profile.php" method="POST">
            <label for="security_question_1">Security Question 1</label>
            <select name="security_question_1" required>
                <option value="mother_maiden_name">What is your mother's maiden name?</option>
                <option value="first_pet">What was the name of your first pet?</option>
                <option value="favorite_teacher">Who was your favorite celebrity/artist?</option>
            </select>
            <input type="text" name="security_answer_1" placeholder="Answer" required>

            <label for="security_question_2">Security Question 2</label>
            <select name="security_question_2" required>
                <option value="birth_city">In which city were you born?</option>
                <option value="favorite_color">What is your favorite color?</option>
                <option value="best_friend">Who was your childhood best friend?</option>
            </select>
            <input type="text" name="security_answer_2" placeholder="Answer" required>

            <button type="submit" class="btn">Save Security Questions</button>
        </form>
    </div>
</body>
</html>

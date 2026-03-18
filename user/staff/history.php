<?php
// history.php - Shows expired medicines from expired_logs (true history)

// Database connection
include "../../db.php";

// Fetch dynamic categories from DB
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

$action = $_GET['action'] ?? '';

// === ACTION: Get count per category from expired_logs ===
if ($action === 'get_counts') {
    $counts = [];
    foreach ($categories as $cat) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM expired_logs WHERE type = ?");
        $stmt->bind_param("s", $cat);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $counts[$cat] = (int)$result['count'];
        $stmt->close();
    }
    header('Content-Type: application/json');
    echo json_encode($counts);
    exit;
}

// === ACTION: Get expired medicines from a category in expired_logs ===
if ($action === 'get_category') {
    $category = $_GET['category'] ?? '';

    // Allow any category that exists in the system (not just hardcoded list)
    if (!in_array($category, $categories)) {
        echo "<p style='color: red;'>Category not found.</p>";
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM expired_logs WHERE type = ? ORDER BY expired_date DESC");
    $stmt->bind_param("s", $category);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        echo "<table style='width:100%; border-collapse:collapse;'>";
        echo "<tr>
                <th style='text-align:left; padding:8px; border-bottom:1px solid #ddd;'>Image</th>
                <th style='text-align:left; padding:8px; border-bottom:1px solid #ddd;'>Name</th>
                <th style='text-align:left; padding:8px; border-bottom:1px solid #ddd;'>Batch Date</th>
                <th style='text-align:left; padding:8px; border-bottom:1px solid #ddd;'>Expired On</th>
                <th style='text-align:left; padding:8px; border-bottom:1px solid #ddd;'>Quantity</th>
                <th style='text-align:left; padding:8px; border-bottom:1px solid #ddd;'>Status</th>
              </tr>";

        $today = new DateTime();
        while ($row = $result->fetch_assoc()) {
            $expiredDate = new DateTime($row['expired_date']);
            $daysAgo = $today->diff($expiredDate)->days;
            $label = $daysAgo == 0 ? 'Today' : ($daysAgo == 1 ? 'Yesterday' : "$daysAgo days ago");

            echo "<tr style='border-bottom:1px solid #eee;'>
                    <td><img src='uploads/medicines/" . htmlspecialchars($row['image']) . "' width='40' style='border-radius:4px;'></td>
                    <td><strong>" . htmlspecialchars($row['name']) . "</strong></td>
                    <td>" . htmlspecialchars($row['batch_date']) . "</td>
                    <td style='color:#c62828; font-weight:bold;'>" . htmlspecialchars($row['expired_date']) . "</td>
                    <td><strong>" . (int)$row['quantity_at_expiry'] . " units</strong></td>
                    <td style='color:#c62828; font-weight:bold;'>" . $label . "</td>
                  </tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: #666; font-style: italic;'>No expired medicines in this category.</p>";
    }
    $stmt->close();
    exit;
}

// Invalid action
http_response_code(400);
echo "Invalid action.";
$conn->close();
?>
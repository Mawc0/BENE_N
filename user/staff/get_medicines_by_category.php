<?php
// Database connection
include "db.php";

$category = $_GET['category'] ?? '';
if (!$category) {
    echo "<p>No category selected.</p>";
    exit();
}

$sql = "SELECT * FROM medicines WHERE type = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $category);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "<table border='1' cellpadding='10' width='100%'>
            <tr>
                <th>Image</th>
                <th>Name</th>
                <th>Batch Date</th>
                <th>Expiry Date</th>
                <th>Quantity</th>
                <th>Status</th>
            </tr>";
    while ($row = $result->fetch_assoc()) {
        $expiryDate = new DateTime($row['expired_date']);
        $today = new DateTime();
        $isExpired = $expiryDate < $today;
        $balance = $isExpired ? 0 : $row['quantity'];
        $isLowStock = !$isExpired && $balance <= 20;
        $status = $isExpired ? '🔴 Expired' : ($isLowStock ? '⚠️ Low Stock' : '✅ In Stock');
        $rowClass = $isExpired ? 'style="background-color:#ffebee;color:#c62828;"' : ($isLowStock ? 'style="background-color:#fff3e0;color:#ef6c00;"' : '');

        echo "<tr $rowClass>
                <td><img src='uploads/medicines/{$row['image']}' width='50' alt='Medicine'></td>
                <td>{$row['name']}</td>
                <td>{$row['batch_date']}</td>
                <td>{$row['expired_date']}</td>
                <td><strong>" . (int) $row['quantity'] . "</strong></td>
                <td>$status</td>
            </tr>";

    }
    echo "</table>";
} else {
    echo "<p>No medicines in this category.</p>";
}
$stmt->close();
$conn->close();
?>
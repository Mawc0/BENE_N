<?php
// Database connection
include "db.php";

// Get search query from URL
$query = isset($_GET['query']) ? $_GET['query'] : '';  // This captures the search term

// Search in the database using the captured query
$sql = "SELECT * FROM medicines WHERE name LIKE '%$query%'";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Results</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #e3f2fd;
            text-align: center;
        }
        .container {
            margin-top: 50px;
        }
        table {
            width: 80%;
            margin: auto;
            border-collapse: collapse;
            background: #fff;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        th, td {
            padding: 10px;
            border: 1px solid #ccc;
        }
        th {
            background: #0d6efd;
            color: white;
        }
        img {
            width: 50px;
            height: 50px;
        }
        .home-button {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 15px;
            background: #0d6efd;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        .home-button:hover {
            background: #084298;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Search Results </h1>
        <?php if ($result->num_rows > 0): ?>
            <table>
                <tr>
                    <th>Image</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Batch Date</th>
                    <th>Expired Date</th>
                </tr>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><img src="uploads/<?php echo $row['image']; ?>" alt="Medicine"></td>
                        <td><?php echo $row['name']; ?></td>
                        <td><?php echo $row['type']; ?></td>
                        <td><?php echo $row['batch_date']; ?></td>
                        <td><?php echo $row['expired_date']; ?></td>
                    </tr>
                <?php endwhile; ?>
            </table>
        <?php else: ?>
            <p>No medicines found.</p>
        <?php endif; ?>
        <a href="staff_dashboard.php" class="home-button">HOME</a>
    </div>
</body>
</html>

<?php
$conn->close();
?>

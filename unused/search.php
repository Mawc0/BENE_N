<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEARCH MEDICINE</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #e3f2fd;
        }
        .container {
            background: #ffffff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-left: 5px solid #0d6efd;
        }
        h1 {
            color: #0d6efd;
            margin-bottom: 20px;
        }
        .search-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: center;
        }
        .search-container input {
            width: 300px;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            outline: none;
            font-size: 16px;
        }
        .button-container {
            margin-top: 15px;
            display: flex;
            gap: 10px;
        }
        .button {
            background: #0d6efd;
            color: white;
            border: none;
            padding: 12px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s;
            font-size: 16px;
            text-decoration: none;
            display: inline-block;
        }
        .button:hover {
            background: #084298;
        }
        .home-button {
            background: #6c757d;
        }
        .home-button:hover {
            background: #495057;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>SEARCH MEDICINES</h1>
        <form action="search_result.php" method="GET" class="search-container">
    <input type="text" name="query" placeholder="Search Medicine Name." required>
    <div class="button-container">
        <button type="submit" class="button search-button">SEARCH🔍</button>
        <a href="staff_dashboard.php" class="button home-button">HOME</a>
    </div>
</form>

    </div>
</body>
</html>
  
<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Manual | BENE MediCon</title>
  <link rel="stylesheet" href="login-about-style.css?v=1.0">
  <style>
    .manual-container {
      width: 90%;
      max-width: 900px;
      margin: 40px auto;
      background: #fff;
      padding: 20px;
      border-radius: 10px;
      box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    .manual-container h1 {
      text-align: center;
      margin-bottom: 20px;
    }
    iframe {
      width: 100%;
      height: 600px;
      border: none;
      border-radius: 8px;
    }
    /* Website Name */
    .site-name {
        font-size: 32px;            /* Larger, bold website name */
        color: #555;
        font-weight: bold;
        letter-spacing: 1px;
        margin-bottom: 15px;        /* Add spacing between site name and title */
    }

    /* Highlight Style for "MediCon" */
    .highlight {
        color: #03d32c;             /* Green color for contrast */
    }
    .btn-back {
      display: inline-block;
      margin-top: 15px;
      padding: 10px 18px;
      background: #f4e35f; /* Yellow button */;
      color:  #333;
      border-radius: 6px;
      text-decoration: none;
      font-weight: bold;
    }
    .btn-back:hover {
      background: #f0d400; /* Darker yellow on hover */
    }
  </style>
</head>
<body>

  <div class="manual-container">
  <h1 class="site-name">BENE MediCon<span class="highlight"> User Manual</span></h1>
    <!-- Embed PDF inside page -->
    <iframe src="../docs/Users-Manual-Bene_MediCon.pdf"></iframe>

    <!-- Action buttons -->
    <div style="text-align:center; margin-top:15px;">
      <a href="../docs/Users-Manual-Bene_MediCon.pdf" target="_blank" class="btn-back">📖 Open in New Tab</a>
      <a href="../docs/Users-Manual-Bene_MediCon.pdf" download class="btn-back">⬇️ Download PDF</a>
      <a href="about.php" class="btn-back">⬅ Back to About</a>
    </div>
  </div>

</body>
</html>

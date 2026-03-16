<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Manual | BENE MediCon</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../styles/home.css">
</head>
<body>

<!-- BACKGROUND GOLD ACCENTS -->
<div class="bg">
  <div class="bg-gold-bar"></div>
  <div class="bg-gold-bar-bottom"></div>
  <div class="bg-blob bg-blob-tl"></div>
  <div class="bg-blob bg-blob-br"></div>
  <div class="bg-gold-glow-tr"></div>
  <div class="bg-gold-glow-bl"></div>
  <div class="bg-ring bg-ring-1"></div>
  <div class="bg-ring bg-ring-2"></div>
  <div class="bg-ring bg-ring-3"></div>
  <div class="bg-ring bg-ring-4"></div>
  <div class="bg-ring bg-ring-5"></div>
  <div class="bg-ring bg-ring-6"></div>
</div>

<div class="manual-card">
  <div class="manual-body">

    <!-- header: logo + title -->
    <div class="manual-header">
      <div class="manual-logo">
        <img src="../images/bene_medicon_logo.png" alt="BENE MediCon">
        <span class="manual-logo-name">BENE <span>MediCon</span></span>
      </div>
    </div>

    <h1 class="manual-title">User <span>Manual</span></h1>
    <p class="manual-tagline">Browse the guide below or download a copy for offline use.</p>

    <!-- embedded PDF -->
    <iframe
      class="manual-frame"
      src="../docs/Users-Manual-Bene_MediCon.pdf"
      title="BENE MediCon User Manual">
    </iframe>

    <!-- action buttons -->
    <div class="manual-actions">
      <a href="../docs/Users-Manual-Bene_MediCon.pdf" target="_blank" class="btn-manual-action primary">
        &#128214; Open in New Tab
      </a>
      <a href="../docs/Users-Manual-Bene_MediCon.pdf" download class="btn-manual-action secondary">
        &#8595; Download PDF
      </a>
      <a href="about.php" class="btn-manual-action ghost">
        &#8592; Back to About
      </a>
    </div>

  </div>
</div>

</body>
</html>
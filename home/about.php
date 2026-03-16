<?php
session_start();

$funFacts = [
    "The first disposable syringe was invented in 1956 by New Zealand pharmacist Colin Murdoch.",
    "Band-Aids were invented in 1920 by Earle Dickson to help his wife cover kitchen cuts.",
    "The first latex gloves for surgery were made in 1890 to protect a nurse's skin from harsh disinfectants.",
    "An average hospital can use over 100,000 gloves per day, making them one of the top consumed items.",
    "The modern stethoscope was invented in 1816 by René Laennec, who used a rolled-up paper tube.",
    "Disposable medical masks were first used in surgeries in the late 1800s to prevent infection.",
    "IV drips were first widely used during World War II to deliver fluids quickly to soldiers.",
    "The thermometer was invented in the 1600s, but the medical mercury thermometer became common in the 1860s.",
    "X-ray machines were invented in 1895 by Wilhelm Roentgen, revolutionizing diagnostics.",
    "Disposable scalpels and surgical tools are now standard to prevent infection.",
    "Syringe needles today are laser-sharpened to minimize pain during injection.",
    "The pulse oximeter was invented in the 1970s and became common in the 1980s.",
    "Adhesive medical tape was first developed in 1845 by Dr. Horace Day.",
    "Antiseptic wipes became common only after the 1950s.",
    "The first disposable contact lenses appeared in 1987, revolutionizing eye care.",
    "Crutches have been used since ancient Egypt — over 4,000 years ago.",
    "Wheelchairs were first depicted in Chinese art in the 6th century.",
    "Surgical masks were initially resisted by surgeons in the early 1900s.",
    "The first defibrillator was invented in the 1930s; portable versions came in the 1970s.",
    "Digital thermometers replaced mercury ones in most hospitals by the 1990s.",
    "Cotton swabs (Q-tips) were invented in 1923 by Leo Gerstenzang.",
    "The tongue depressor has been in use since the 1800s.",
    "Sharps disposal containers were introduced in the 1970s to safely handle used needles.",
    "Blood pressure cuffs were first created in 1881 and refined in 1896.",
    "The first hospital gowns were introduced in the late 19th century.",
    "Oxygen masks became standard in World War I aviation medicine.",
    "Face shields became widely used in healthcare during the 1980s AIDS crisis.",
    "Hand sanitizer gel was invented in 1966 by nursing student Lupe Hernandez.",
    "Modern adjustable hospital beds were invented in the early 20th century."
];

do {
    $randomFact = $funFacts[array_rand($funFacts)];
} while (isset($_SESSION['last_fact']) && $_SESSION['last_fact'] === $randomFact);
$_SESSION['last_fact'] = $randomFact;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About | BENE MediCon</title>
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

<div class="card">

    <!-- LEFT -->
    <div class="left">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
        <div class="blob blob-4"></div>

        <div class="top-bar">
            <a href="user-manual.php" class="btn-manual btn-manual-view">&#128214; View Manual</a>
            <a href="../docs/Users-Manual-Bene_MediCon.pdf" download class="btn-manual btn-manual-dl">&#8595; Download</a>
        </div>

        <div class="left-content">
            <img src="../images/bene_medicon_logo.png" alt="BENE MediCon" class="medicine-img">
            <h1 class="left-title">BENE <span>MediCon</span></h1>
            <p class="left-subtitle">San Beda College Alabang</p>
            <!-- <p class="motto">Fides · Scientia · Virtus</p> -->
        </div>
    </div>

    <!-- RIGHT -->
    <div class="right">
        <h2>About Us</h2>
        <p class="tagline">Healthcare Inventory Management System</p>

        <div class="trivia-card">
            <div class="trivia-header">
                <span class="trivia-label">Medical Trivia</span>
                <button class="trivia-refresh" id="refreshBtn" onclick="refreshTrivia()">&#8635; New Fact</button>
            </div>
            <p id="trivia-text"><?= htmlspecialchars($randomFact) ?></p>
        </div>

        <div class="about-section">
            <h3>What We Do</h3>
            <p>BENE MediCon simplifies how healthcare staff and administrators at San Beda College Alabang manage and track medical supplies. Our platform provides secure login access for staff and administrators, ensuring support of handling medical inventory records. </p>
        </div>

        <div class="about-section">
            <h3>Key Features</h3>
            <ul class="feature-list">
                <li>Secure role-based access for staff &amp; admins</li>
                <li>Real-time medical inventory records</li>
                <li>User-friendly interface for daily operations</li>
                <li>Streamlined healthcare processes</li>
            </ul>
        </div>

        <a href="login.php" class="btn-back">&#8592; Back to Login</a>
    </div>

</div>

<script>
function refreshTrivia() {
    const btn = document.getElementById('refreshBtn');
    btn.classList.add('spinning');
    btn.disabled = true;
    fetch('get_trivia.php')
        .then(r => r.text())
        .then(data => { document.getElementById('trivia-text').textContent = data; })
        .catch(console.error)
        .finally(() => setTimeout(() => { btn.classList.remove('spinning'); btn.disabled = false; }, 450));
}
</script>
</body>
</html>
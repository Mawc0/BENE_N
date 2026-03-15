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
    "The first blood transfusion equipment was developed in the 1800s but became safer with proper tubing in the 20th century.",
    "Disposable scalpels and surgical tools are now standard to prevent infection — older tools had to be endlessly sterilized.",
    "Syringe needles today are laser-sharpened to minimize pain during injection.",
    "The pulse oximeter, which clips to a finger, was invented in the 1970s and became common in the 1980s.",
    "Adhesive medical tape was first developed in 1845 by Dr. Horace Day.",
    "Antiseptic wipes became common only after the 1950s, before which alcohol bottles were used with cotton.",
    "The first disposable contact lenses appeared in 1987, revolutionizing eye care convenience.",
    "Crutches have been used since ancient Egypt — drawings show their use over 4,000 years ago.",
    "Wheelchairs were first depicted in Chinese art in the 6th century.",
    "Surgical masks were initially resisted by surgeons in the early 1900s until infection reduction was proven.",
    "The first defibrillator was invented in 1930s, but portable versions became common in the 1970s."
];

do {
    $randomFact = $funFacts[array_rand($funFacts)];
} while (isset($_SESSION['last_fact']) && $_SESSION['last_fact'] === $randomFact);

$_SESSION['last_fact'] = $randomFact;
echo $randomFact;
?>
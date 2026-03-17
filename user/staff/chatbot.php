<?php
// DEBUG: Show errors only in log
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/chatbot_debug.log');

session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
header('Content-Type: application/json');

// 🔑 Groq API Key
require_once __DIR__ . '/config.php';
$GROQ_API_KEY = GROQ_API_KEY;

// 🗄️ Database
include "../../db.php";
$userMessage = trim($_POST['message'] ?? '');
if (empty($userMessage)) {
    echo json_encode(['reply' => "Please ask about medicines."]);
    exit;
}

$today = date('Y-m-d');
$tenMonths = date('Y-m-d', strtotime('+10 months'));
$twelveMonths = date('Y-m-d', strtotime('+12 months'));

// 🔍 Helper: Find medicine by name
function findMedicine($input, $conn) {
    $stmt = $conn->query("SELECT name FROM medicines");
    while ($row = $stmt->fetch_assoc()) {
        if (stripos($input, strtolower($row['name'])) !== false) {
            return $row['name'];
        }
    }
    return null;
}

$medicine = findMedicine(strtolower($userMessage), $conn);

// 🧠 Intent Detection
$intent = null;
$lowerMsg = strtolower($userMessage);

// --- Donation / Disposal ---
if (str_contains($lowerMsg, 'donate') && (str_contains($lowerMsg, 'can') || str_contains($lowerMsg, 'eligible'))) {
    $intent = 'donation_eligible';
} elseif (str_contains($lowerMsg, 'dispose') || str_contains($lowerMsg, 'dispose') || str_contains($lowerMsg, 'trash') || str_contains($lowerMsg, 'discard')) {
    $intent = 'disposal_eligible';
} elseif (str_contains($lowerMsg, 'latest') || str_contains($lowerMsg, 'newest') || str_contains($lowerMsg, 'recently added')) {
    $intent = 'latest_medicines';
}
// --- Existing Intents ---
elseif (str_contains($lowerMsg, 'how many') && (str_contains($lowerMsg, 'expired') || str_contains($lowerMsg, 'inactive'))) {
    $intent = 'expired_medicines_all';
} elseif (str_contains($lowerMsg, 'how many') && str_contains($lowerMsg, 'medicine')) {
    $intent = 'total_medicines';
} elseif (str_contains($lowerMsg, 'list') && str_contains($lowerMsg, 'category')) {
    $intent = 'list_categories';
}elseif (str_contains($lowerMsg, 'how many') && str_contains($lowerMsg, 'categor')) {
    $intent = 'total_categories';
} elseif ((str_contains($lowerMsg, 'stock') || str_contains($lowerMsg, 'how many')) && $medicine) {
    $intent = 'stock';
} elseif ((str_contains($lowerMsg, 'expire') || str_contains($lowerMsg, 'expiry')) && $medicine) {
    $intent = 'expiry';
} elseif ((str_contains($lowerMsg, 'info') || str_contains($lowerMsg, 'about')) && $medicine) {
    $intent = 'info';
}

// 🧾 Handle Intent
$reply = null;
switch ($intent) {
    // ✅ DONATION ELIGIBLE (10–12 months)
    case 'donation_eligible':
        $stmt = $conn->prepare("
            SELECT name, type, expired_date, quantity
            FROM medicines
            WHERE expired_date > ? AND expired_date <= ?
            ORDER BY expired_date ASC
        ");
        $stmt->bind_param("ss", $tenMonths, $twelveMonths);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if ($results) {
            $reply = "🎁 **Donation-Eligible Medicines (10–12 months left):**\n";
            foreach ($results as $r) {
                $days = (strtotime($r['expired_date']) - strtotime($today)) / 86400;
                $months = floor($days / 30);
                $reply .= "• **{$r['name']}** ({$r['type']}) – Expires in ~$months months ({$r['quantity']} units)\n";
            }
        } else {
            $reply = "No medicines are currently eligible for donation (must expire in 10–12 months).";
        }
        break;

    // ✅ DISPOSAL ELIGIBLE (≤10 months, not expired)
    case 'disposal_eligible':
        $stmt = $conn->prepare("
            SELECT name, type, expired_date, quantity
            FROM medicines
            WHERE expired_date > ? AND expired_date <= ?
            ORDER BY expired_date ASC
        ");
        $stmt->bind_param("ss", $today, $tenMonths);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if ($results) {
            $reply = "🗑️ **Disposal-Eligible Medicines (≤10 months left):**\n";
            foreach ($results as $r) {
                $days = (strtotime($r['expired_date']) - strtotime($today)) / 86400;
                $reply .= "• **{$r['name']}** ({$r['type']}) – Expires in " . round($days) . " days ({$r['quantity']} units)\n";
            }
        } else {
            $reply = "No medicines are currently eligible for disposal (must expire within 10 months and not be expired).";
        }
        break;

    // ✅ LATEST ADDED
    case 'latest_medicines':
        $stmt = $conn->query("SELECT batch_date FROM medicines ORDER BY batch_date DESC, id DESC LIMIT 1");
        $latestBatch = $stmt->fetch_row()[0] ?? null;
        if ($latestBatch) {
            $stmt = $conn->prepare("SELECT name, type, batch_date, expired_date, quantity FROM medicines WHERE batch_date = ? ORDER BY id DESC");
            $stmt->bind_param("s", $latestBatch);
            $stmt->execute();
            $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            if ($results) {
                $count = count($results);
                $interval = (new DateTime($latestBatch))->diff(new DateTime())->days;
                $label = $interval == 0 ? 'today' : ($interval == 1 ? 'yesterday' : "$interval days ago");
                $reply = "🆕 **Latest Medicine" . ($count > 1 ? "s" : "") . " Added ($count, $label):**\n";
                foreach ($results as $r) {
                    $status = $r['expired_date'] < $today ? "🔴 Expired" : "🟢 Active";
                    $reply .= "• **{$r['name']}** ({$r['type']}) – Stock: {$r['quantity']} | Expiry: {$r['expired_date']} $status\n";
                }
            } else {
                $reply = "No medicines found for the latest batch.";
            }
        } else {
            $reply = "No medicines in inventory yet.";
        }
        break;

    // ✅ EXPIRED MEDICINES
    case 'expired_medicines_all':
        $stmt = $conn->prepare("SELECT name, type, expired_date, quantity FROM medicines WHERE expired_date < ? ORDER BY expired_date ASC");
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if ($results) {
            $reply = "❌ **Expired Medicines (" . count($results) . "):**\n";
            foreach ($results as $r) {
                $daysOverdue = (strtotime($today) - strtotime($r['expired_date'])) / 86400;
                $reply .= "• **{$r['name']}** ({$r['type']}) – Expired {$r['expired_date']} ({$daysOverdue} days ago, {$r['quantity']} units)\n";
            }
        } else {
            $reply = "🎉 No expired medicines found!";
        }
        break;

    // ✅ TOTAL MEDICINES
    case 'total_medicines':
        $stmt = $conn->query("SELECT COUNT(*) FROM medicines");
        $count = $stmt->fetch_row()[0];
        $reply = "📦 **Total Medicines**: $count";
        break;

    // ✅ LIST CATEGORIES
    case 'list_categories':
        $stmt = $conn->query("SELECT name FROM categories ORDER BY name");
        $cats = [];
        while ($row = $stmt->fetch_assoc()) $cats[] = $row['name'];
        $reply = "📋 **Categories**: " . implode(", ", $cats);
        break;

    // ✅ TOTAL CATEGORIES
    case 'total_categories':
        $stmt = $conn->query("SELECT COUNT(*) FROM categories");
        $count = $stmt->fetch_row()[0];
        $reply = "📂 **Total Categories**: $count";
        break;

    // ✅ STOCK
    case 'stock':
        if ($medicine) {
            $stmt = $conn->prepare("SELECT name, quantity, expired_date FROM medicines WHERE name = ?");
            $stmt->bind_param("s", $medicine);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $status = $row['expired_date'] < $today ? "🔴 Expired" : "🟢 Active";
                $reply = "**{$row['name']}**\n• Stock: **{$row['quantity']} units**\n• Status: $status";
            } else {
                $reply = "Medicine not found.";
            }
        } else {
            $reply = "Which medicine are you checking?";
        }
        break;

    // ✅ EXPIRY
    case 'expiry':
        if ($medicine) {
            $stmt = $conn->prepare("SELECT expired_date FROM medicines WHERE name = ?");
            $stmt->bind_param("s", $medicine);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $days = (strtotime($row['expired_date']) - strtotime($today)) / 86400;
                if ($days < 0) {
                    $reply = "$medicine expired on {$row['expired_date']}.";
                } else {
                    $reply = "$medicine expires in " . round($days) . " days (on {$row['expired_date']}).";
                }
            } else {
                $reply = "Medicine not found.";
            }
        } else {
            $reply = "Tell me the medicine name to check expiry.";
        }
        break;

    // ✅ INFO
    case 'info':
        if ($medicine) {
            $stmt = $conn->prepare("SELECT name, type, batch_date, expired_date, quantity FROM medicines WHERE name = ?");
            $stmt->bind_param("s", $medicine);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $status = $row['expired_date'] < $today ? "🔴 Expired" : "🟢 Active";
                $reply = "**{$row['name']}** ({$row['type']})\n• Batch: {$row['batch_date']}\n• Expiry: {$row['expired_date']} $status\n• Stock: **{$row['quantity']} units**";
            } else {
                $reply = "Medicine not found.";
            }
        } else {
            $reply = "Please specify a medicine name.";
        }
        break;
}

// 🤖 Fallback to Groq if no rule matched
if ($reply === null) {
    // Fetch full medicine list for AI context
    $medList = [];
    $stmt = $conn->query("SELECT name, type, expired_date, quantity FROM medicines ORDER BY name");
    while ($r = $stmt->fetch_assoc()) {
        $medList[] = "{$r['name']} ({$r['type']}) - expires {$r['expired_date']}, stock: {$r['quantity']}";
    }
    $medicineListStr = !empty($medList) ? implode("\n", $medList) : "None";

    $systemPrompt = "You are BENE Asssist, AI assistant for BENE MediCon.
CURRENT INVENTORY:
- Medicines:\n$medicineListStr

RULES:
1. ONLY use medicine names from the list above.
2. If a medicine is not listed, say: 'I don't have that medicine in inventory.'
3. NEVER guess expiry dates, stock, or disposal/donation rules.
4. For donation: only medicines expiring in 10–12 months are eligible.
5. For disposal: only medicines expiring within 10 months (and not expired) are eligible.
6. Keep answers short, medical, and factual.
7. You CANNOT perform actions (add/edit/delete).

Answer the user:";

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        "model" => "llama-3.1-8b-instant",
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => $userMessage]
        ],
        "temperature" => 0.3,
        "max_tokens" => 300
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $GROQ_API_KEY",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Required on Hostinger

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $reply = $data['choices'][0]['message']['content'] ?? "I couldn't process that.";
        $reply = trim(preg_replace('/\s+/', ' ', $reply));
    } else {
        $reply = "⚠️ BENE Assist is offline. Ask about stock, expiry, donation, or disposal.";
    }
}

echo json_encode(['reply' => $reply]);
exit;
?>
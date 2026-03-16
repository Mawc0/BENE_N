<?php
// Start session for conversation history
session_start();

// Handle only POST requests (AJAX from frontend)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $host = 'localhost';
    $dbname = 'bene_medicon';
    $username = 'root';
    $password = '';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        error_log("DB Connection Failed: " . $e->getMessage());
        echo json_encode(['reply' => "Database connection failed."]);
        exit;
    }

    $user_input = strtolower(trim($_POST['message'] ?? ''));
    if (empty($user_input)) {
        echo json_encode(['reply' => "Please ask about medicines."]);
        exit;
    }

    // Initialize chat history
    if (!isset($_SESSION['chat_history'])) {
        $_SESSION['chat_history'] = [];
    }
    $_SESSION['chat_history'][] = ['sender' => 'user', 'message' => $user_input];

    // Common phrases
    $greetings = ['hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening'];
    $goodbyes = ['bye', 'goodbye', 'quit', 'exit', 'see you'];
    $thanks = ['thank you', 'thanks', 'thank', 'thank you so much', 'thanks a lot'];

    // --- Thank you response ---
    if (in_array($user_input, $thanks)) {
        $reply = "You're very welcome! Feel free to ask more questions.";
        $_SESSION['chat_history'][] = ['sender' => 'bot', 'message' => $reply];
        echo json_encode(['reply' => $reply]);
        exit;
    }

    // --- Debug command ---
    if ($user_input === 'debug types') {
        $stmt = $pdo->query("SELECT type, COUNT(*) as count FROM medicines GROUP BY TRIM(type) ORDER BY type");
        $rows = $stmt->fetchAll();
        $msg = "🔍 Raw 'type' values in DB:\n\n";
        foreach ($rows as $r) {
            $t = trim($r['type']) === '' ? '[EMPTY]' : "'{$r['type']}'";
            $msg .= "• {$t} → {$r['count']} medicine(s)\n";
        }
        echo json_encode(['reply' => $msg]);
        exit;
    }

    // --- Greetings ---
    if (in_array($user_input, $greetings)) {
        $_SESSION['chat_history'] = []; // Reset on new conversation
        $reply = "Hello! I'm MedBot. Ask me about medicines.";
        $_SESSION['chat_history'][] = ['sender' => 'bot', 'message' => $reply];
        echo json_encode(['reply' => $reply]);
        exit;
    }

    // --- Goodbye ---
    if (in_array($user_input, $goodbyes)) {
        $reply = "Stay safe and healthy! 👋";
        $_SESSION['chat_history'][] = ['sender' => 'bot', 'message' => $reply];
        echo json_encode(['reply' => $reply]);
        exit;
    }

    $today = date('Y-m-d');

    // --- Find medicine by name ---
    function findMedicine($input, $pdo) {
        $stmt = $pdo->query("SELECT name FROM medicines");
        $meds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($meds as $med) {
            if (stripos($input, strtolower($med)) !== false) {
                return $med;
            }
        }
        return null;
    }

    $medicine = findMedicine($user_input, $pdo);

    // --- Get last mentioned medicine from history ---
    function getLastMedicine($history) {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['sender'] === 'bot') {
                if (preg_match('/\*\*([^*]+)\*\*/', $history[$i]['message'], $m)) {
                    return $m[1];
                }
            }
        }
        return null;
    }

    // Resolve "it", "they", "latest", etc.
    $vague_terms = ['it', 'they', 'this', 'that', 'the medicine', 'latest', 'recent'];
    if (count(array_intersect(explode(' ', $user_input), $vague_terms)) > 0 && !$medicine) {
        $lastMed = getLastMedicine($_SESSION['chat_history']);
        if ($lastMed) {
            $medicine = $lastMed;
        }
    }

    // --- Intent Detection Order ---
    $intent = null;

    // 🔹 Detect: "expired recently"
    if (
        (stripos($user_input, 'recent') !== false || stripos($user_input, 'recently') !== false)
        && (str_contains($user_input, 'expire') || str_contains($user_input, 'expired'))
    ) {
        $intent = 'expired_medicines_recent';
    }

    // 🔹 General expired / inactive
    elseif (
        str_contains($user_input, 'not active') ||
        str_contains($user_input, 'inactive') ||
        str_contains($user_input, 'expired')
    ) {
        $intent = 'expired_medicines_all';
    }

    // 🔹 Active medicines
    elseif (
        str_contains($user_input, 'not expires') ||
        str_contains($user_input, 'active medicine') ||
        str_contains($user_input, 'still good') ||
        (str_contains($user_input, 'not') && str_contains($user_input, 'expire')) ||
        str_contains($user_input, 'active')
    ) {
        $intent = 'active_medicines';
    }

    // 🔹 Latest medicines added
    elseif (
        stripos($user_input, 'latest') !== false ||
        stripos($user_input, 'recent') !== false ||
        stripos($user_input, 'new') !== false
    ) {
        if (
            stripos($user_input, 'medicine') !== false ||
            stripos($user_input, 'medicines') !== false ||
            stripos($user_input, 'stock') !== false ||
            stripos($user_input, 'added') !== false ||
            stripos($user_input, 'received') !== false
        ) {
            $intent = 'latest_medicines';
        }
    }

    // 🔹 Stock check
    elseif ((str_contains($user_input, 'how many') || str_contains($user_input, 'stock')) &&
              (str_contains($user_input, 'have') || str_contains($user_input, 'left') || str_contains($user_input, 'available'))) {
        $intent = 'stock';
    } elseif (str_contains($user_input, 'stock') || str_contains($user_input, 'available')) {
        $intent = 'stock';
    }

    // 🔹 List all medicines
    elseif (str_contains($user_input, 'list') || str_contains($user_input, 'show') || str_contains($user_input, 'all')) {
        $intent = 'list';
    }

    // 🔹 Type/category
    elseif (str_contains($user_input, 'type') || str_contains($user_input, 'category')) {
        $intent = 'type';
    }

    // 🔹 Expiry date
    elseif (str_contains($user_input, 'expire') || str_contains($user_input, 'expiry')) {
        $intent = 'expiry';
    }

    // 🔹 Info about medicine
    elseif (str_contains($user_input, 'info') || str_contains($user_input, 'about') || str_contains($user_input, 'what is')) {
        $intent = 'info';
    }

    // 🔹 Totals
    elseif (str_contains($user_input, 'how many medicines') || str_contains($user_input, 'total of medicines')) {
        $intent = 'total_medicines';
    } elseif (str_contains($user_input, 'how many categories') || str_contains($user_input, 'total of categories')) {
        $intent = 'total_categories';
    } elseif (str_contains($user_input, 'list categories') || str_contains($user_input, 'what are the categories')) {
        $intent = 'list_categories';
    }

    // 🔹 List by category
    elseif (preg_match('/^(show|list|all)\s+(.+?)(\s+medicines?)?$/', $user_input, $m)) {
        $intent = 'category';
        $category_query = trim($m[2]);
    }

    // --- Handle Intents ---
    switch ($intent) {

        case 'expired_medicines_recent':
            // Extract number of days if specified
            if (preg_match('/last (\d+) days?/', $user_input, $matches)) {
                $days = (int)$matches[1];
            } else {
                $days = 7; // default
            }
            $cutoff_date = date('Y-m-d', strtotime("-$days days"));

            $stmt = $pdo->prepare("
                SELECT name, type, expired_date, quantity 
                FROM medicines 
                WHERE expired_date < ? 
                  AND expired_date >= ?
                ORDER BY expired_date DESC
            ");
            $stmt->execute([$today, $cutoff_date]);
            $results = $stmt->fetchAll();

            if ($results) {
                $reply = "⚠️ **Recently Expired Medicines (last $days days): " . count($results) . "**\n\n";
                foreach ($results as $r) {
                    $daysAgo = (strtotime($today) - strtotime($r['expired_date'])) / 86400;
                    $reply .= "• **{$r['name']}** ({$r['type']})\n";
                    $reply .= "  – Expired: {$r['expired_date']} ({$r['quantity']} units)\n";
                    $reply .= "  – _{$daysAgo} day(s) ago_\n\n";
                }
            } else {
                $reply = "🎉 No medicines have expired in the past $days days.";
            }
            break;

        case 'expired_medicines_all':
            $stmt = $pdo->prepare("SELECT name, type, expired_date, quantity FROM medicines WHERE expired_date < ? ORDER BY expired_date ASC");
            $stmt->execute([$today]);
            $results = $stmt->fetchAll();

            if ($results) {
                $reply = "❌ **Expired Medicines (" . count($results) . ")**:\n\n";
                foreach ($results as $r) {
                    $daysOverdue = (strtotime($today) - strtotime($r['expired_date'])) / 86400;
                    $reply .= "• **{$r['name']}** ({$r['type']})\n";
                    $reply .= "  – Expired: {$r['expired_date']} ({$r['quantity']} units)\n";
                    $reply .= "  – _{$daysOverdue} day(s) overdue_\n\n";
                }
            } else {
                $reply = "🎉 No expired medicines found!";
            }
            break;

        case 'active_medicines':
            $stmt = $pdo->prepare("SELECT name, type, expired_date, quantity FROM medicines WHERE expired_date >= ? ORDER BY expired_date ASC");
            $stmt->execute([$today]);
            $results = $stmt->fetchAll();

            if ($results) {
                $reply = "✅ **Active Medicines (" . count($results) . ")**:\n\n";
                foreach ($results as $r) {
                    $daysLeft = (strtotime($r['expired_date']) - strtotime($today)) / 86400;
                    $reply .= "• **{$r['name']}** ({$r['type']})\n";
                    $reply .= "  – Expires: {$r['expired_date']} ({$r['quantity']} units)\n";
                    $reply .= "  – _{$daysLeft} day(s) left_\n\n";
                }
            } else {
                $reply = "🚫 No active medicines found.";
            }
            break;

        case 'latest_medicines':
            $stmt = $pdo->query("SELECT batch_date FROM medicines ORDER BY batch_date DESC, id DESC LIMIT 1");
            $latestBatch = $stmt->fetchColumn();

            if (!$latestBatch) {
                $reply = "No medicines in inventory.";
                break;
            }

            $stmt = $pdo->prepare("SELECT name, type, batch_date, expired_date, quantity FROM medicines WHERE batch_date = ? ORDER BY id DESC");
            $stmt->execute([$latestBatch]);
            $results = $stmt->fetchAll();

            if (!$results) {
                $reply = "No medicines found for the latest batch.";
                break;
            }

            $count = count($results);
            $interval = (new DateTime($latestBatch))->diff(new DateTime())->days;
            $label = $interval == 0 ? 'today' : ($interval == 1 ? 'yesterday' : "$interval days ago");

            $reply = "🆕 **Latest Medicine" . ($count > 1 ? "s" : "") . " Added ($count)**:\n\n";
            foreach ($results as $r) {
                $status = $r['expired_date'] < $today ? "🔴 Expired" : "🟢 Active";
                $reply .= "• **{$r['name']}** ({$r['type']})\n";
                $reply .= "  – Received: {$r['batch_date']} ($label)\n";
                $reply .= "  – Stock: **{$r['quantity']} units**\n";
                $reply .= "  – Expiry: {$r['expired_date']} $status\n\n";
            }
            $reply .= "_These were added $label._";
            break;

        case 'stock':
            if ($medicine) {
                $stmt = $pdo->prepare("SELECT name, quantity, expired_date FROM medicines WHERE name = ?");
                $stmt->execute([$medicine]);
                $row = $stmt->fetch();
                if ($row) {
                    $status = $row['expired_date'] < $today ? "🔴 Expired" : "🟢 Active";
                    $qty = max(0, $row['quantity']);
                    $reply = "**{$row['name']}**\n";
                    $reply .= "• Available: **$qty units**\n";
                    $reply .= "• Expiry: {$row['expired_date']} $status";
                } else {
                    $reply = "Medicine not found.";
                }
            } else {
                $reply = "Which medicine are you checking?";
            }
            break;

        case 'list':
            $stmt = $pdo->query("SELECT name, type, expired_date, quantity FROM medicines ORDER BY name");
            $results = $stmt->fetchAll();
            if (!$results) {
                $reply = "No medicines in the system.";
            } else {
                $reply = "📋 **All Medicines (Total: " . count($results) . ")**:\n\n";
                foreach ($results as $r) {
                    $status = $r['expired_date'] < $today ? "🔴 Expired" : "🟢 Active";
                    $qty = $r['quantity'] > 0 ? $r['quantity'] : 0;
                    $reply .= "• **{$r['name']}** ({$r['type']}) – Expires: {$r['expired_date']} | Stock: $qty | $status\n";
                }
            }
            break;

        case 'type':
            if ($medicine) {
                $stmt = $pdo->prepare("SELECT type FROM medicines WHERE name = ?");
                $stmt->execute([$medicine]);
                $row = $stmt->fetch();
                if ($row) {
                    $reply = "$medicine is a **{$row['type']}**.";
                } else {
                    $reply = "Medicine not found.";
                }
            } else {
                $reply = "Which medicine are you asking about?";
            }
            break;

        case 'expiry':
            if ($medicine) {
                $stmt = $pdo->prepare("SELECT expired_date FROM medicines WHERE name = ?");
                $stmt->execute([$medicine]);
                $row = $stmt->fetch();
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
                $reply = "Tell me the name of the medicine to check expiry.";
            }
            break;

        case 'info':
            if ($medicine) {
                $stmt = $pdo->prepare("SELECT name, type, batch_date, expired_date, quantity FROM medicines WHERE name = ?");
                $stmt->execute([$medicine]);
                $row = $stmt->fetch();
                if ($row) {
                    $status = $row['expired_date'] < $today ? "🔴 Expired" : "🟢 Active";
                    $reply = "**{$row['name']}** ({$row['type']})\n";
                    $reply .= "• Batch: {$row['batch_date']}\n";
                    $reply .= "• Expiry: {$row['expired_date']} $status\n";
                    $reply .= "• Stock: **{$row['quantity']} units**";
                } else {
                    $reply = "Medicine not found.";
                }
            } else {
                $reply = "Please specify a medicine name for details.";
            }
            break;

        case 'total_medicines':
            $stmt = $pdo->query("SELECT COUNT(*) FROM medicines");
            $count = $stmt->fetchColumn();
            $reply = "📦 **Total Medicines**: $count";
            break;

                case 'total_categories':
            $stmt = $pdo->query("SELECT COUNT(*) FROM categories");
            $count = $stmt->fetchColumn();
            $reply = "🏷️ **Total Categories**: $count";
            break;

        case 'list_categories':
            $stmt = $pdo->query("SELECT name FROM categories ORDER BY name");
            $categories = array_column($stmt->fetchAll(), 'name');
            if (empty($categories)) {
                $reply = "No categories found.";
            } else {
                $reply = "📋 **Available Medicine Categories**:\n\n• " . implode("\n• ", $categories);
            }
            break;

        case 'category':
            // Get user's category query (e.g., "pain reliever")
            $category_query = trim($m[2] ?? '');

            // Fetch all valid category names
            $stmt = $pdo->query("SELECT name FROM categories");
            $valid_categories = array_column($stmt->fetchAll(), 'name');
            $found = false;

            // Try to match user input to a real category (case-insensitive)
            foreach ($valid_categories as $cat) {
                if (stripos($category_query, strtolower($cat)) !== false || 
                    stripos(strtolower($cat), $category_query) !== false) {
                    
                    // Fetch medicines in this category
                    $stmt = $pdo->prepare("
                        SELECT m.name, m.expired_date, m.quantity 
                        FROM medicines m
                        JOIN categories c ON m.category_id = c.id
                        WHERE c.name = ?
                        ORDER BY m.name
                    ");
                    $stmt->execute([$cat]);
                    $results = $stmt->fetchAll();
                    $count = count($results);

                    if ($count > 0) {
                        $reply = "💊 **$cat Medicines (Total: $count)**:\n\n";
                        foreach ($results as $r) {
                            $status = $r['expired_date'] < $today ? "🔴" : "🟢";
                            $qty = max(0, (int)$r['quantity']);
                            $reply .= "• {$r['name']} – Expires: {$r['expired_date']} | Stock: $qty $status\n";
                        }
                    } else {
                        $reply = "📦 No medicines found in the **$cat** category.";
                    }
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $reply = "❓ Category not recognized. Available categories:\n• " . implode("\n• ", $valid_categories);
            }
            break;

        default:
            if ($medicine) {
                $stmt = $pdo->prepare("SELECT name, type, batch_date, expired_date, quantity FROM medicines WHERE name = ?");
                $stmt->execute([$medicine]);
                $row = $stmt->fetch();
                if ($row) {
                    $status = $row['expired_date'] < $today ? "🔴 Expired" : "🟢 Active";
                    $reply = "**{$row['name']}** ({$row['type']})\n";
                    $reply .= "• Batch: {$row['batch_date']}\n";
                    $reply .= "• Expiry: {$row['expired_date']} $status\n";
                    $reply .= "• Stock: **{$row['quantity']} units**";
                } else {
                    $reply = "Sorry, I couldn't find that medicine.";
                }
            } else {
                $reply = "I'm here to help. Just let me know what you need.";
            }
            break;
    }

    // Save bot reply to history
    $_SESSION['chat_history'][] = ['sender' => 'bot', 'message' => $reply];

    echo json_encode(['reply' => $reply]);
    exit;
}

// Serve HTML for GET request
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>💬 BENE MediCon Chatbot</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', sans-serif; background: #f0f4f8; }
    .chat-head {
      position: fixed; bottom: 20px; right: 20px;
      width: 60px; height: 60px;
      background: #d9534f; color: white;
      border-radius: 50%; display: flex;
      justify-content: center; align-items: center;
      font-size: 24px; cursor: pointer;
      box-shadow: 0 4px 12px rgba(217,83,79,0.4);
      z-index: 1000;
    }
    .chat-container {
      display: none; position: fixed; bottom: 90px; right: 20px;
      width: 380px; height: 500px;
      background: white; border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.15);
      flex-direction: column; z-index: 999;
    }
    .chat-header {
      background: #d9534f; color: white;
      padding: 14px 16px;
      display: flex; justify-content: space-between; align-items: center;
    }
    .disclaimer {
      background: #f8d7da; color: #721c24;
      font-size: 0.8em; padding: 8px;
      text-align: center;
    }
    #chatbox {
      flex: 1; padding: 16px; overflow-y: auto;
      display: flex; flex-direction: column; gap: 12px;
      background: #fcfdff;
    }
    .message {
      display: flex; align-items: flex-start; gap: 10px;
      max-width: 80%;
    }
    .message.user { align-self: flex-end; flex-direction: row-reverse; }
    .message-text {
      padding: 10px 14px; border-radius: 16px;
      line-height: 1.5; max-width: 100%;
      word-wrap: break-word;
    }
    .message.user .message-text {
      background: #d9534f; color: white;
      border-bottom-left-radius: 5px;
    }
    .message.bot .message-text {
      background: #e9ecef; color: #212529;
      border-bottom-right-radius: 5px;
    }
    .avatar {
      width: 32px; height: 32px;
      border-radius: 50%; object-fit: cover;
    }
    #user-input {
      display: flex; gap: 8px;
      padding: 12px 16px;
      background: white;
      border-top: 1px solid #eee;
    }
    #user-input input {
      flex: 1; padding: 10px 14px;
      border: 1px solid #ced4da;
      border-radius: 12px; outline: none;
    }
    #user-input button {
      width: 40px; height: 40px;
      background: #d9534f; color: white;
      border: none; border-radius: 50%;
      cursor: pointer; display: flex;
      justify-content: center; align-items: center;
    }
    #micBtn { background: #28a745; }
    #micBtn:hover, #sendBtn:hover { opacity: 0.9; }
  </style>
</head>
<body>

  <!-- Chat Icon -->
  <div class="chat-head" id="chatHead">
    <i class="fas fa-comments"></i>
  </div>

  <!-- Chat Window -->
  <div class="chat-container" id="chatContainer">
    <div class="chat-header">
      <i class="fas fa-clinic-medical"></i> MedBot
      <span class="chat-close" id="chatClose">&times;</span>
    </div>
    <div class="disclaimer">Powered by BENE MediCon</div>
    <div id="chatbox">
      <div class="message bot">
        <img src="https://ui-avatars.com/api/?name=MedBot&background=d9534f&color=fff" class="avatar" alt="Bot">
        <div class="message-text">
          Hi! I'm MedBot. Ask me about medicines.<br>
          Try:<br>
          • <strong>What medicines expired recently?</strong><br>
          • <strong>Show inactive medicines</strong><br>
          • <strong>How many Paracetamol do we have?</strong>
        </div>
      </div>
    </div>
    <div id="user-input">
      <button id="micBtn" title="Click to speak">
        <i class="fas fa-microphone"></i>
      </button>
      <input type="text" id="message" placeholder="Ask or speak...">
      <button id="sendBtn" onclick="sendMessage()">
        <i class="fas fa-paper-plane"></i>
      </button>
    </div>
  </div>

  <script>
    const chatHead = document.getElementById("chatHead");
    const chatContainer = document.getElementById("chatContainer");
    const chatClose = document.getElementById("chatClose");
    const chatbox = document.getElementById("chatbox");
    const input = document.getElementById("message");
    const micBtn = document.getElementById("micBtn");

    chatHead.addEventListener("click", () => {
      chatContainer.style.display = "flex";
      chatHead.style.display = "none";
    });

    chatClose.addEventListener("click", () => {
      chatContainer.style.display = "none";
      chatHead.style.display = "flex";
    });

    function appendMessage(sender, text) {
      const messageDiv = document.createElement("div");
      messageDiv.classList.add("message", sender);

      const avatar = document.createElement("img");
      avatar.className = "avatar";
      avatar.alt = sender === "user" ? "You" : "MedBot";

      // ✅ FIXED: Removed extra spaces in URLs
      avatar.src = sender === "user"
        ? "https://ui-avatars.com/api/?name=You&background=6c757d&color=fff"
        : "https://ui-avatars.com/api/?name=MedBot&background=d9534f&color=fff";

      const textDiv = document.createElement("div");
      textDiv.className = "message-text";
      textDiv.innerHTML = text.replace(/\n/g, '<br>');

      messageDiv.appendChild(avatar);
      messageDiv.appendChild(textDiv);
      chatbox.appendChild(messageDiv);
      chatbox.scrollTop = chatbox.scrollHeight;
    }

    function sendMessage() {
      const message = input.value.trim();
      if (!message) return;

      appendMessage("user", message);
      input.value = "";

      fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ 'message': message })
      })
      .then(r => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
      })
      .then(data => appendMessage("bot", data.reply || "No reply."))
      .catch(err => {
        console.error("Fetch Error:", err);
        appendMessage("bot", "❌ Failed to connect. Is the server running?");
      });
    }

    input.addEventListener("keypress", e => {
      if (e.key === "Enter") sendMessage();
    });

    // Voice Input
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (SpeechRecognition) {
      const recognition = new SpeechRecognition();
      recognition.lang = 'en-US';
      recognition.interimResults = false;

      micBtn.addEventListener("click", () => {
        micBtn.disabled = true;
        micBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        micBtn.style.background = '#dc3545';
        recognition.start();
      });

      recognition.addEventListener("result", e => {
        input.value = e.results[0][0].transcript;
      });

      recognition.addEventListener("end", () => {
        micBtn.disabled = false;
        micBtn.innerHTML = '<i class="fas fa-microphone"></i>';
        micBtn.style.background = '#28a745';
        sendMessage();
      });

      recognition.onerror = (e) => {
        micBtn.disabled = false;
        micBtn.innerHTML = '<i class="fas fa-microphone"></i>';
        micBtn.style.background = '#28a745';
        appendMessage("bot", `🎙️ Error: ${e.error}`);
      };
    } else {
      micBtn.title = "Voice not supported";
      micBtn.style.opacity = "0.6";
      micBtn.style.cursor = "not-allowed";
    }
  </script>
</body>
</html>
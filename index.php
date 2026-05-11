<?php
// =====================================================================
// Madhiyar AI Pro - Super Chatbot with Database, Multiple Conversations,
// Markdown, Syntax Highlighting, Voice, Export, and more.
// =====================================================================
session_start();
date_default_timezone_set('Asia/Kolkata');

// ---------- Database Setup (SQLite) ----------
$dbFile = __DIR__ . '/madhiyar_pro.sqlite';
$pdo = new PDO("sqlite:$dbFile");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Create tables
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT UNIQUE,
    name TEXT DEFAULT 'Guest',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS conversations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    title TEXT DEFAULT 'New Chat',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER,
    role TEXT CHECK(role IN ('user','assistant','system')),
    content TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS api_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER,
    request_json TEXT,
    response_json TEXT,
    http_code INTEGER,
    error TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Current user (session-based)
$sessionId = session_id();
$stmt = $pdo->prepare("SELECT id FROM users WHERE session_id = ?");
$stmt->execute([$sessionId]);
$user = $stmt->fetch();
if (!$user) {
    $pdo->prepare("INSERT INTO users (session_id) VALUES (?)")->execute([$sessionId]);
    $userId = $pdo->lastInsertId();
} else {
    $userId = $user['id'];
}

// Active conversation
if (isset($_GET['conv'])) {
    $convId = (int)$_GET['conv'];
    // Verify ownership
    $chk = $pdo->prepare("SELECT id FROM conversations WHERE id = ? AND user_id = ?");
    $chk->execute([$convId, $userId]);
    if (!$chk->fetch()) {
        $convId = null; // invalid, will create new
    }
} else {
    $convId = null;
}
if (!$convId) {
    $stmt = $pdo->prepare("SELECT id FROM conversations WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $last = $stmt->fetch();
    if ($last) {
        $convId = $last['id'];
    } else {
        $pdo->prepare("INSERT INTO conversations (user_id, title) VALUES (?, 'New Chat')")->execute([$userId]);
        $convId = $pdo->lastInsertId();
    }
}

// ---------- Handle AJAX POST (send full conversation) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!empty($input['messages']) && is_array($input['messages'])) {
        // Save last user message (assumed the last element is user)
        $lastMsg = end($input['messages']);
        if ($lastMsg['role'] === 'user') {
            $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?, 'user', ?)");
            $stmt->execute([$convId, $lastMsg['content']]);
            // Update title if first message
            $pdo->prepare("UPDATE conversations SET title = ? WHERE id = ? AND title = 'New Chat'")
                ->execute([mb_substr($lastMsg['content'], 0, 40), $convId]);
        }

        $api_key = "sk_liyugn5i_2wzZJk1SOdeFvj4WRDpkfegt"; // <-- Replace with real key
        $api_url = "https://api.sarvam.ai/v1/chat/completions";

        $request_data = [
            'model' => 'sarvam-105b',
            'messages' => $input['messages'],
            'temperature' => 0.7,
            'max_tokens' => 1500
        ];

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'api-subscription-key: ' . $api_key,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // Log API call
        $logStmt = $pdo->prepare("INSERT INTO api_logs (conversation_id, request_json, response_json, http_code, error) VALUES (?, ?, ?, ?, ?)");
        $logStmt->execute([$convId, json_encode($request_data), $response, $http_code, $curl_error]);

        if ($curl_error) {
            $reply = "cURL Error: " . $curl_error;
        } elseif ($http_code !== 200) {
            $reply = "API பிழை (HTTP $http_code). தயவுசெய்து மீண்டும் முயற்சிக்கவும்.";
        } else {
            $respData = json_decode($response, true);
            $reply = $respData['choices'][0]['message']['content'] ?? "மன்னிக்கவும், பதில் கிடைக்கவில்லை.";
        }

        // Save assistant answer
        $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?, 'assistant', ?)");
        $stmt->execute([$convId, $reply]);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['reply' => $reply]);
        exit;
    }
}

// ---------- Other GET actions ----------
// New conversation
if (isset($_GET['new'])) {
    $pdo->prepare("INSERT INTO conversations (user_id) VALUES (?)")->execute([$userId]);
    header('Location: ?conv=' . $pdo->lastInsertId());
    exit;
}
// Delete conversation
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM messages WHERE conversation_id = ?")->execute([$delId]);
    $pdo->prepare("DELETE FROM conversations WHERE id = ? AND user_id = ?")->execute([$delId, $userId]);
    header('Location: ?');
    exit;
}

// Fetch user's conversations
$convStmt = $pdo->prepare("SELECT id, title, created_at FROM conversations WHERE user_id = ? ORDER BY id DESC");
$convStmt->execute([$userId]);
$conversations = $convStmt->fetchAll();

// Fetch messages of current conversation
$msgStmt = $pdo->prepare("SELECT role, content FROM messages WHERE conversation_id = ? ORDER BY id ASC");
$msgStmt->execute([$convId]);
$dbMessages = $msgStmt->fetchAll();

// Build conversation title
$currentTitle = 'New Chat';
foreach ($conversations as $c) {
    if ($c['id'] == $convId) {
        $currentTitle = $c['title'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="ta" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Madhiyar AI Pro</title>
    <!-- Icons, Markdown, Syntax Highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap');

        :root[data-theme="light"] {
            --primary: #0066ff; --primary-hover: #0052cc;
            --bg: #f0f2f8; --surface: #ffffff; --msg-bg: #f8f9fc;
            --text: #1d1d1f; --border: #e0e0e0; --bot-bg: #f1f1f3;
        }
        :root[data-theme="dark"] {
            --primary: #3385ff; --primary-hover: #66a3ff;
            --bg: #121212; --surface: #1e1e1e; --msg-bg: #2d2d2d;
            --text: #e0e0e0; --border: #333; --bot-bg: #2d2d2d;
        }

        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg); color: var(--text); height:100vh; display:flex; align-items:center; justify-content:center; }
        .app-container { display:flex; width:100%; max-width:1300px; height:95vh; background: var(--surface); border-radius:24px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,0.15); border:1px solid var(--border); }

        /* Sidebar */
        .sidebar { width:280px; background: var(--surface); border-right:1px solid var(--border); display:flex; flex-direction:column; padding:20px; gap:12px; }
        .logo { font-size:1.4rem; font-weight:600; } .logo span { color:var(--primary); }
        .new-btn { background:var(--primary); color:#fff; border:none; padding:10px; border-radius:8px; cursor:pointer; }
        .conv-list { flex:1; overflow-y:auto; display:flex; flex-direction:column; gap:8px; }
        .conv-item { padding:10px; border-radius:8px; background:var(--bot-bg); cursor:pointer; display:flex; justify-content:space-between; align-items:center; font-size:0.9rem; }
        .conv-item.active { background:var(--primary); color:#fff; }
        .conv-item .delete-btn { background:none; border:none; cursor:pointer; color:inherit; }
        .theme-btn { margin-top:auto; background:var(--bot-bg); border:1px solid var(--border); padding:8px; border-radius:8px; cursor:pointer; color:var(--text); }

        /* Main */
        .main { flex:1; display:flex; flex-direction:column; }
        .chat-header { padding:16px 24px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; }
        .chat-messages { flex:1; padding:24px; overflow-y:auto; display:flex; flex-direction:column; gap:18px; background:var(--bg); }
        .message-wrapper { max-width:85%; }
        .wrapper-user { align-self:flex-end; }
        .wrapper-bot { align-self:flex-start; }
        .message { padding:14px 20px; border-radius:18px; line-height:1.6; font-size:0.98rem; }
        .user-message { background:var(--primary); color:#fff; border-bottom-right-radius:6px; }
        .bot-message { background:var(--bot-bg); border:1px solid var(--border); color:var(--text); border-bottom-left-radius:6px; }
        .copy-btn { background:none; border:none; color:#888; cursor:pointer; font-size:0.8rem; margin-top:5px; align-self:flex-start; }
        .copy-btn:hover { color:var(--primary); }
        .typing .dot { width:7px; height:7px; background:var(--text); border-radius:50%; display:inline-block; margin:0 2px; animation: bounce 1.4s infinite ease-in-out; opacity:0.6; }
        .typing .dot:nth-child(1){animation-delay:-0.32s} .typing .dot:nth-child(2){animation-delay:-0.16s}
        @keyframes bounce { 0%,80%,100%{transform:scale(0)} 40%{transform:scale(1)} }

        .input-area { padding:18px 24px; border-top:1px solid var(--border); display:flex; gap:12px; align-items:flex-end; }
        textarea { flex:1; padding:12px 18px; border:1px solid var(--border); border-radius:12px; background:var(--msg-bg); color:var(--text); resize:none; min-height:50px; max-height:150px; outline:none; font-size:1rem; }
        textarea:focus { border-color:var(--primary); }
        .send-btn, .voice-btn { background:var(--primary); color:#fff; border:none; width:44px; height:44px; border-radius:10px; cursor:pointer; display:flex; align-items:center; justify-content:center; }
        .send-btn:hover, .voice-btn:hover { background:var(--primary-hover); }

        @media(max-width:768px){ .app-container{flex-direction:column; height:100vh; border-radius:0; } .sidebar{width:100%; flex-direction:row; overflow-x:auto; padding:10px; } }
    </style>
</head>
<body>
<div class="app-container">
    <aside class="sidebar">
        <div class="logo">Madhiyar <span>AI</span></div>
        <button class="new-btn" onclick="location='?new=1'">+ புதிய உரையாடல்</button>
        <div class="conv-list">
            <?php foreach($conversations as $conv): $active = $conv['id']==$convId ? 'active' : ''; ?>
            <div class="conv-item <?=$active?>" onclick="location='?conv=<?=$conv['id']?>'">
                <span style="flex:1"><?=htmlspecialchars(mb_substr($conv['title'],0,25))?></span>
                <button class="delete-btn" onclick="event.stopPropagation(); if(confirm('இந்த உரையாடலை நீக்கவா?')) location='?delete=<?=$conv['id']?>'"><i class="fas fa-trash-alt"></i></button>
            </div>
            <?php endforeach; ?>
        </div>
        <button class="theme-btn" onclick="toggleTheme()"><i class="fas fa-moon"></i> Dark Mode</button>
    </aside>

    <main class="main">
        <div class="chat-header">
            <strong><?=htmlspecialchars($currentTitle)?></strong>
            <div style="display:flex; gap:12px;">
                <button onclick="exportChat()" title="Chat Export"><i class="fas fa-download"></i></button>
                <button onclick="clearChat()" title="இந்த Chat-ஐ அழி" style="color:#ff4d4f;"><i class="fas fa-trash"></i></button>
            </div>
        </div>

        <div class="chat-messages" id="chatMessages">
            <?php if(empty($dbMessages)): ?>
                <div class="message-wrapper wrapper-bot"><div class="message bot-message">👋 வணக்கம்! நான் Madhiyar AI Pro. உங்களுக்கு எப்படி உதவ முடியும்?</div></div>
            <?php else: foreach($dbMessages as $msg): ?>
                <div class="message-wrapper wrapper-<?=$msg['role']=='user'?'user':'bot'?>">
                    <div class="message <?=$msg['role']=='user'?'user-message':'bot-message'?>">
                        <?php if($msg['role']=='user'): echo nl2br(htmlspecialchars($msg['content'])); else: echo '<span class="markdown-body"></span>'; endif; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="input-area">
            <button class="voice-btn" id="voiceBtn" title="குரல் உள்ளீடு"><i class="fas fa-microphone"></i></button>
            <textarea id="userInput" placeholder="உங்கள் செய்தியை உள்ளிடவும்... (Shift+Enter = புதிய வரி)" oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px';"></textarea>
            <button class="send-btn" onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
        </div>
    </main>
</div>

<script>
    // ---------- Configuration ----------
    const convId = <?=json_encode($convId)?>;
    const chatBox = document.getElementById('chatMessages');
    const input = document.getElementById('userInput');
    let history = []; // Will be loaded from DB messages

    // Load initial messages from PHP rendered content
    window.addEventListener('DOMContentLoaded', () => {
        const allMessages = document.querySelectorAll('.message-wrapper');
        allMessages.forEach(wrapper => {
            const msgDiv = wrapper.querySelector('.message');
            if (msgDiv.classList.contains('user-message')) {
                history.push({ role: 'user', content: msgDiv.innerText });
            } else if(msgDiv.classList.contains('bot-message')) {
                const span = msgDiv.querySelector('.markdown-body');
                if(span) {
                    // Already rendered by PHP? Actually we inserted empty span, need to fetch content from DB.
                    // Better to re-render markdown from data-attribute or hidden content.
                } else {
                    history.push({ role: 'assistant', content: msgDiv.innerText });
                }
            }
        });
        // Re-render bot messages with markdown from DB content
        <?php
        // Pass DB messages to JS for proper markdown rendering
        echo "const dbMessages = " . json_encode($dbMessages) . ";";
        ?>
        dbMessages.forEach((msg, index) => {
            if (msg.role === 'assistant') {
                const botWrappers = document.querySelectorAll('.wrapper-bot .bot-message');
                if (botWrappers[index]) {
                    botWrappers[index].innerHTML = marked.parse(msg.content);
                    Prism.highlightAllUnder(botWrappers[index]);
                }
            }
        });
        // Ensure history is aligned
        history = dbMessages;
    });

    // Send message
    async function sendMessage() {
        const text = input.value.trim();
        if(!text) return;
        input.value = '';
        input.style.height = 'auto';

        addMessageUI('user', text);
        history.push({role:'user', content:text});

        const typingId = showTyping();

        try {
            const res = await fetch('', {
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({ messages: history })
            });
            const data = await res.json();
            document.getElementById(typingId).remove();
            addMessageUI('bot', data.reply);
            history.push({role:'assistant', content:data.reply});
        } catch(e) {
            document.getElementById(typingId).remove();
            addMessageUI('bot', '❌ பிழை ஏற்பட்டது. மீண்டும் முயற்சிக்கவும்.');
        }
    }

    function addMessageUI(role, content) {
        const wrapper = document.createElement('div');
        wrapper.className = `message-wrapper wrapper-${role}`;
        const msgDiv = document.createElement('div');
        msgDiv.className = `message ${role}-message`;

        if(role === 'user') {
            msgDiv.innerText = content;
        } else {
            msgDiv.innerHTML = marked.parse(content);
            Prism.highlightAllUnder(msgDiv);
            // Copy button
            const copyBtn = document.createElement('button');
            copyBtn.className = 'copy-btn';
            copyBtn.innerHTML = '<i class="fas fa-copy"></i> நகலெடு';
            copyBtn.onclick = () => {
                navigator.clipboard.writeText(content);
                copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => copyBtn.innerHTML = '<i class="fas fa-copy"></i> நகலெடு', 2000);
            };
            wrapper.appendChild(msgDiv);
            wrapper.appendChild(copyBtn);
        }
        if(role==='user') wrapper.appendChild(msgDiv);
        chatBox.appendChild(wrapper);
        chatBox.scrollTop = chatBox.scrollHeight;
    }

    function showTyping() {
        const id = 'typing-' + Date.now();
        const div = document.createElement('div');
        div.id = id;
        div.className = 'message-wrapper wrapper-bot';
        div.innerHTML = `<div class="message bot-message"><div class="typing"><span class="dot"></span><span class="dot"></span><span class="dot"></span></div></div>`;
        chatBox.appendChild(div);
        chatBox.scrollTop = chatBox.scrollHeight;
        return id;
    }

    input.addEventListener('keydown', e => {
        if(e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Voice Input
    document.getElementById('voiceBtn').onclick = () => {
        if(!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
            alert('உங்கள் உலாவியில் Voice Input ஆதரிக்கப்படவில்லை.');
            return;
        }
        const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        const recog = new SR();
        recog.lang = 'ta-IN';
        recog.interimResults = false;
        recog.onresult = e => {
            input.value = e.results[0][0].transcript;
            sendMessage();
        };
        recog.onerror = e => alert('Voice error: '+e.error);
        recog.start();
    };

    // Export Chat
    function exportChat() {
        let text = "Madhiyar AI Pro - Chat Export\n\n";
        history.forEach(m => {
            text += (m.role==='user'?'👤 நீங்கள்':'🤖 Madhiyar AI') + ': ' + m.content + '\n\n';
        });
        const blob = new Blob([text], {type:'text/plain'});
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'chat_export.txt';
        a.click();
    }

    // Clear current chat
    function clearChat() {
        if(confirm('இந்த உரையாடலை அழிக்க விரும்புகிறீர்களா?')) {
            location.href = '?delete=' + convId;
        }
    }

    // Theme Toggle
    function toggleTheme() {
        const html = document.documentElement;
        const cur = html.getAttribute('data-theme');
        html.setAttribute('data-theme', cur==='dark'?'light':'dark');
        localStorage.setItem('theme', cur==='dark'?'light':'dark');
    }
    (() => { const t = localStorage.getItem('theme'); if(t) document.documentElement.setAttribute('data-theme',t); })();

    // Re-run Prism after dynamic content
    document.addEventListener('DOMContentLoaded', () => Prism.highlightAll());
</script>
</body>
</html>
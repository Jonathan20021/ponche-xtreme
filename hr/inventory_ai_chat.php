<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$initialPrompt = $_GET['prompt'] ?? '';

// Load prior conversation
$userId = (int) ($_SESSION['user_id'] ?? 0);
$history = [];
if ($userId > 0) {
    $stmt = $pdo->prepare("SELECT role, message, created_at FROM inventory_ai_chats
        WHERE user_id = ? AND role IN ('user','assistant')
        ORDER BY id DESC LIMIT 40");
    $stmt->execute([$userId]);
    $history = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asistente IA - Inventario</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        .chat-wrap { height: calc(100vh - 200px); min-height: 500px; display:flex; flex-direction:column; }
        .chat-messages { flex:1; overflow-y:auto; padding: 16px; }
        .msg-row { display:flex; gap:.75rem; margin-bottom: 1rem; }
        .msg-user { justify-content: flex-end; }
        .msg-bubble { padding: .75rem 1rem; border-radius: .75rem; max-width: min(80%, 720px); }
        .msg-user .msg-bubble  { background: linear-gradient(135deg,#1f3f76,#152849); color:white; }
        .msg-ai   .msg-bubble  { background: var(--surface); color:var(--text); border: 1px solid var(--border); }
        .msg-ai .msg-bubble table { border-collapse: collapse; margin: .5em 0; width:100%; font-size:.85em; }
        .msg-ai .msg-bubble table th, .msg-ai .msg-bubble table td { border: 1px solid var(--border); padding: 4px 8px; text-align:left; }
        .msg-ai .msg-bubble table th { background: rgba(124,58,237,.2); color:#e9d5ff; }
        .msg-ai .msg-bubble code { background: var(--surface-2); padding: 2px 5px; border-radius: 4px; font-size:.85em; }
        .msg-ai .msg-bubble pre { background: var(--surface-2); padding: .75rem; border-radius: 8px; overflow-x:auto; }
        .msg-ai .msg-bubble pre code { background: transparent; padding: 0; }
        .msg-ai .msg-bubble h1, .msg-ai .msg-bubble h2, .msg-ai .msg-bubble h3 { font-weight: 600; margin: .6em 0 .3em; }
        .msg-ai .msg-bubble ul, .msg-ai .msg-bubble ol { margin: .3em 0 .3em 1.5em; }
        .avatar { width: 36px; height: 36px; border-radius: 50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .avatar-user { background: rgba(8,145,178,.25); color:#67e8f9; }
        .avatar-ai   { background: linear-gradient(135deg,#5e7cba,#1f3f76); color:white; }
        .typing-dot { width:8px; height:8px; background:#94a3b8; border-radius:50%; display:inline-block; margin: 0 2px; animation: bounce 1.4s infinite ease-in-out both; }
        .typing-dot:nth-child(1) { animation-delay: -.32s; } .typing-dot:nth-child(2) { animation-delay: -.16s; }
        @keyframes bounce { 0%,80%,100%{transform:scale(0)} 40%{transform:scale(1)} }
        .chip { font-size:.75rem; padding: 4px 10px; border-radius:999px; background: rgba(148,163,184,.15); color:var(--text); cursor:pointer; border: 1px solid var(--border); transition: all .15s; }
        .chip:hover { background: rgba(124,58,237,.2); color:#e9d5ff; border-color: rgba(124,58,237,.4); }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-6">
        <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
            <div class="flex items-center gap-4">
                <a href="inventory.php" class="text-slate-400 hover:text-white"><i class="fas fa-arrow-left text-xl"></i></a>
                <div>
                    <h1 class="text-2xl font-bold text-white">
                        <span style="background: linear-gradient(135deg,#5e7cba,#1f3f76); -webkit-background-clip:text; background-clip:text; color:transparent;">
                            <i class="fas fa-robot"></i> Asistente IA
                        </span>
                        de Inventario
                    </h1>
                    <p class="text-slate-400 text-sm">Pregunta en lenguaje natural - Claude conoce el estado del inventario en tiempo real</p>
                </div>
            </div>
            <button onclick="clearHistory()" class="btn-secondary text-sm" style="background:rgba(239,68,68,.1);color:#fca5a5;">
                <i class="fas fa-trash mr-1"></i>Limpiar historial
            </button>
        </div>

        <div class="glass-card chat-wrap p-0">
            <div id="chatMessages" class="chat-messages">
                <?php if (empty($history)): ?>
                    <div id="welcome" class="text-center py-12">
                        <div class="inline-block p-4 rounded-full mb-4" style="background: linear-gradient(135deg,#5e7cba,#1f3f76);">
                            <i class="fas fa-robot text-3xl text-white"></i>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">Hola! Soy tu asistente de inventario</h3>
                        <p class="text-slate-400 mb-6">Puedo ayudarte a consultar stock, sugerir reordenes, detectar anomalias y mas.</p>
                        <div class="flex flex-wrap gap-2 justify-center max-w-2xl mx-auto">
                            <button class="chip" onclick="sendQuick('Que items tengo en stock bajo y cuanto deberia reordenar')">Stock bajo + reorden</button>
                            <button class="chip" onclick="sendQuick('Cuanto valor total tengo en inventario y como esta distribuido por categoria')">Valor total por categoria</button>
                            <button class="chip" onclick="sendQuick('Hay lotes proximos a vencer? Que debo hacer con ellos')">Lotes por vencer</button>
                            <button class="chip" onclick="sendQuick('Cuales son los 5 items mas consumidos este mes')">Top 5 consumidos</button>
                            <button class="chip" onclick="sendQuick('Resume el estado del botiquin y proyecta cuanto durara el stock')">Estado del botiquin</button>
                            <button class="chip" onclick="sendQuick('Que items estan agotados ahora mismo')">Items agotados</button>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($history as $h): ?>
                        <div class="msg-row msg-<?= $h['role'] === 'user' ? 'user' : 'ai' ?>">
                            <?php if ($h['role'] !== 'user'): ?>
                                <div class="avatar avatar-ai"><i class="fas fa-robot"></i></div>
                            <?php endif; ?>
                            <div class="msg-bubble" data-md="<?= $h['role'] === 'assistant' ? '1' : '0' ?>">
                                <?php if ($h['role'] === 'assistant'): ?>
                                    <span class="md-content"><?= htmlspecialchars($h['message']) ?></span>
                                <?php else: ?>
                                    <?= nl2br(htmlspecialchars($h['message'])) ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($h['role'] === 'user'): ?>
                                <div class="avatar avatar-user"><i class="fas fa-user"></i></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="border-t border-slate-700 p-3 bg-slate-900/40">
                <form id="chatForm" class="flex gap-2">
                    <textarea id="chatInput" rows="2"
                        placeholder="Escribe tu pregunta sobre el inventario..."
                        class="flex-1 bg-slate-800 border border-slate-700 rounded-lg text-white p-3 resize-none focus:outline-none focus:ring-2 focus:ring-cyan-500"></textarea>
                    <button type="submit" id="sendBtn"
                        class="btn-primary px-5"
                        style="background: linear-gradient(135deg,#5e7cba,#1f3f76);">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
                <p class="text-xs text-slate-500 mt-1.5">Enter para enviar · Shift+Enter para nueva linea</p>
            </div>
        </div>
    </div>

    <script>
    const chat = document.getElementById('chatMessages');
    const input = document.getElementById('chatInput');
    const btn = document.getElementById('sendBtn');

    // Render existing markdown messages
    document.querySelectorAll('.md-content').forEach(el => {
        const raw = el.textContent;
        el.innerHTML = marked.parse(raw, { breaks: true });
    });
    scrollToBottom();

    function scrollToBottom() {
        chat.scrollTop = chat.scrollHeight;
    }
    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function appendMessage(role, content, isMarkdown) {
        const welcome = document.getElementById('welcome');
        if (welcome) welcome.remove();
        const row = document.createElement('div');
        row.className = 'msg-row msg-' + (role === 'user' ? 'user' : 'ai');
        const avatar = `<div class="avatar avatar-${role === 'user' ? 'user' : 'ai'}"><i class="fas fa-${role === 'user' ? 'user' : 'robot'}"></i></div>`;
        const bubble = document.createElement('div');
        bubble.className = 'msg-bubble';
        if (isMarkdown) {
            bubble.innerHTML = marked.parse(content, { breaks: true });
        } else {
            bubble.innerHTML = escapeHtml(content).replace(/\n/g, '<br>');
        }
        if (role === 'user') {
            row.innerHTML = '';
            row.appendChild(bubble);
            const av = document.createElement('div'); av.className = 'avatar avatar-user'; av.innerHTML = '<i class="fas fa-user"></i>';
            row.appendChild(av);
        } else {
            const av = document.createElement('div'); av.className = 'avatar avatar-ai'; av.innerHTML = '<i class="fas fa-robot"></i>';
            row.appendChild(av);
            row.appendChild(bubble);
        }
        chat.appendChild(row);
        scrollToBottom();
        return bubble;
    }
    function appendTyping() {
        const row = document.createElement('div');
        row.className = 'msg-row msg-ai';
        row.id = 'typingRow';
        row.innerHTML = `<div class="avatar avatar-ai"><i class="fas fa-robot"></i></div>
                         <div class="msg-bubble"><span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span></div>`;
        chat.appendChild(row);
        scrollToBottom();
    }
    function removeTyping() {
        const t = document.getElementById('typingRow');
        if (t) t.remove();
    }

    async function send(message) {
        if (!message.trim()) return;
        appendMessage('user', message, false);
        input.value = '';
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';
        appendTyping();
        try {
            const fd = new FormData();
            fd.append('action', 'chat');
            fd.append('message', message);
            const r = await fetch('../api/inventory_ai.php', { method: 'POST', body: fd });
            const j = await r.json();
            removeTyping();
            if (!j.success) {
                appendMessage('assistant', '**Error:** ' + (j.error || 'No se pudo conectar a Claude'), true);
                return;
            }
            appendMessage('assistant', j.reply, true);
        } catch (e) {
            removeTyping();
            appendMessage('assistant', '**Error de red:** ' + e.message, true);
        } finally {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i>';
            input.focus();
        }
    }

    function sendQuick(text) {
        input.value = text;
        send(text);
    }

    document.getElementById('chatForm').addEventListener('submit', e => {
        e.preventDefault();
        send(input.value);
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send(input.value);
        }
    });

    async function clearHistory() {
        if (!confirm('Borrar todo el historial de chat?')) return;
        const fd = new FormData();
        fd.append('action', 'clear_history');
        try {
            await fetch('../api/inventory_ai.php', { method: 'POST', body: fd });
            window.location.reload();
        } catch (e) { alert('Error: ' + e.message); }
    }

    // Auto-send if a prompt was passed in the URL
    <?php if ($initialPrompt !== ''): ?>
    setTimeout(() => sendQuick(<?= json_encode($initialPrompt) ?>), 200);
    <?php endif; ?>
    input.focus();
    </script>
</body>
</html>

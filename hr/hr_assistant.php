<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/hr_assistant_functions.php';

// Check authentication and permissions
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

ensurePermission('hr_dashboard');

$userId = $_SESSION['user_id'];
$userName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Usuario';

include __DIR__ . '/../header.php';
?>

<style>
:root {
    --ai-primary: #2563eb;
    --ai-primary-hover: #1d4ed8;
    --ai-gradient: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    --ai-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    --ai-shadow-lg: 0 10px 25px rgba(37, 99, 235, 0.15);
}

.chat-container {
    max-width: 1000px;
    margin: 0 auto;
    height: calc(100vh - 200px);
    display: flex;
    flex-direction: column;
    background: var(--bg-primary);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--ai-shadow-lg);
}

.chat-header {
    background: var(--bg-primary);
    padding: 1.5rem 2rem;
    border-bottom: 1px solid var(--border-color);
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 2rem;
    background: var(--bg-secondary);
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.chat-input-container {
    padding: 1.5rem 2rem;
    background: var(--bg-primary);
    border-top: 1px solid var(--border-color);
}

.message {
    display: flex;
    gap: 1rem;
    max-width: 80%;
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.message.user {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.message.assistant {
    align-self: flex-start;
}

.message-avatar {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.message.user .message-avatar {
    background: var(--ai-primary);
    color: white;
}

.message.assistant .message-avatar {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    color: var(--ai-primary);
}

.message-content {
    padding: 0.875rem 1.25rem;
    border-radius: 12px;
    line-height: 1.6;
    font-size: 0.9375rem;
}

.message.user .message-content {
    background: var(--ai-primary);
    color: white;
}

.message.assistant .message-content {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    color: var(--text-primary);
}

.typing-indicator {
    display: none;
    align-items: center;
    gap: 0.4rem;
    padding: 0.875rem 1.25rem;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    max-width: 70px;
}

.typing-indicator.active {
    display: flex;
}

.typing-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--ai-primary);
    animation: typing 1.4s infinite;
}

.typing-dot:nth-child(2) {
    animation-delay: 0.2s;
}

.typing-dot:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes typing {
    0%, 60%, 100% {
        transform: translateY(0);
        opacity: 0.4;
    }
    30% {
        transform: translateY(-8px);
        opacity: 1;
    }
}

.chat-input {
    display: flex;
    gap: 0.75rem;
    align-items: flex-end;
}

.chat-input textarea {
    flex: 1;
    padding: 0.875rem 1rem;
    border-radius: 10px;
    border: 1px solid var(--border-color);
    background: var(--bg-secondary);
    color: var(--text-primary);
    resize: none;
    font-family: inherit;
    font-size: 0.9375rem;
    transition: all 0.2s;
    max-height: 120px;
}

.chat-input textarea:focus {
    outline: none;
    border-color: var(--ai-primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.send-button {
    padding: 0.875rem 1.5rem;
    background: var(--ai-primary);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 500;
    font-size: 0.9375rem;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.send-button:hover:not(:disabled) {
    background: var(--ai-primary-hover);
    box-shadow: var(--ai-shadow);
}

.send-button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.quick-questions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.quick-question-btn {
    padding: 0.5rem 0.875rem;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.8125rem;
    font-weight: 500;
}

.quick-question-btn:hover {
    background: var(--ai-primary);
    color: white;
    border-color: var(--ai-primary);
    box-shadow: var(--ai-shadow);
}

.welcome-message {
    text-align: center;
    padding: 2rem 1rem;
    color: var(--text-secondary);
}

.welcome-message h2 {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--text-primary);
}

.welcome-message p {
    font-size: 0.9375rem;
    margin-bottom: 2rem;
    color: var(--text-secondary);
}

.feature-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
}

.feature-card {
    padding: 1.25rem;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    text-align: left;
    transition: all 0.2s;
}

.feature-card:hover {
    border-color: var(--ai-primary);
    box-shadow: var(--ai-shadow);
}

.feature-card i {
    font-size: 1.5rem;
    margin-bottom: 0.75rem;
    color: var(--ai-primary);
}

.feature-card h3 {
    font-size: 0.9375rem;
    font-weight: 600;
    margin-bottom: 0.375rem;
    color: var(--text-primary);
}

.feature-card p {
    font-size: 0.8125rem;
    color: var(--text-secondary);
    line-height: 1.5;
}

/* Scrollbar styling */
.chat-messages::-webkit-scrollbar {
    width: 6px;
}

.chat-messages::-webkit-scrollbar-track {
    background: transparent;
}

.chat-messages::-webkit-scrollbar-thumb {
    background: var(--border-color);
    border-radius: 3px;
}

.chat-messages::-webkit-scrollbar-thumb:hover {
    background: var(--ai-primary);
}
</style>

<div class="container mx-auto px-4 py-6">
    <div class="chat-container">
        <div class="chat-header">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div style="width: 40px; height: 40px; background: var(--ai-primary); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem;">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-semibold" style="color: var(--text-primary); margin: 0;">
                            Asistente Virtual RH
                        </h1>
                        <p style="font-size: 0.8125rem; color: var(--text-secondary); margin: 0;">Conectado a la base de datos</p>
                    </div>
                </div>
                <button onclick="clearChat()" style="padding: 0.5rem 1rem; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-secondary); cursor: pointer; transition: all 0.2s; font-size: 0.875rem; font-weight: 500;" onmouseover="this.style.borderColor='var(--ai-primary)'; this.style.color='var(--ai-primary)';" onmouseout="this.style.borderColor='var(--border-color)'; this.style.color='var(--text-secondary)';">
                    <i class="fas fa-redo mr-2"></i>
                    Nueva conversación
                </button>
            </div>
        </div>

        <div id="chatMessages" class="chat-messages">
            <div class="welcome-message">
                <h2>¡Hola, <?= htmlspecialchars($userName) ?>! 👋</h2>
                <p>Soy tu asistente virtual de RH. Puedo ayudarte con información sobre vacaciones, permisos, horarios, evaluaciones y mucho más.</p>
                
                <div class="feature-grid">
                    <div class="feature-card">
                        <i class="fas fa-umbrella-beach"></i>
                        <h3>Vacaciones</h3>
                        <p>Consulta tu balance de días disponibles y solicita vacaciones</p>
                    </div>
                    <div class="feature-card">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>Permisos</h3>
                        <p>Información sobre cómo solicitar permisos y ver tus solicitudes</p>
                    </div>
                    <div class="feature-card">
                        <i class="fas fa-clock"></i>
                        <h3>Horarios</h3>
                        <p>Consulta tu horario de trabajo y políticas de asistencia</p>
                    </div>
                    <div class="feature-card">
                        <i class="fas fa-star"></i>
                        <h3>Evaluaciones</h3>
                        <p>Información sobre tu próxima evaluación de desempeño</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="chat-input-container">
            <div class="quick-questions">
                <button class="quick-question-btn" onclick="sendQuickQuestion('¿Cuántos días de vacaciones me quedan?')">
                    <i class="fas fa-umbrella-beach mr-1"></i> Días de vacaciones
                </button>
                <button class="quick-question-btn" onclick="sendQuickQuestion('¿Cómo solicito un permiso?')">
                    <i class="fas fa-file-alt mr-1"></i> Solicitar permiso
                </button>
                <button class="quick-question-btn" onclick="sendQuickQuestion('¿Cuál es mi horario de trabajo?')">
                    <i class="fas fa-clock mr-1"></i> Mi horario
                </button>
                <button class="quick-question-btn" onclick="sendQuickQuestion('¿Cuándo es mi próxima evaluación?')">
                    <i class="fas fa-star mr-1"></i> Próxima evaluación
                </button>
            </div>
            
            <div class="chat-input mt-4">
                <textarea 
                    id="messageInput" 
                    placeholder="Escribe tu pregunta aquí..." 
                    rows="1"
                    onkeydown="handleKeyPress(event)"
                ></textarea>
                <button id="sendButton" class="send-button" onclick="sendMessage()">
                    <i class="fas fa-paper-plane"></i>
                    Enviar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let conversationHistory = [];

// Load chat history on page load
document.addEventListener('DOMContentLoaded', function() {
    loadChatHistory();
});

async function loadChatHistory() {
    try {
        const response = await fetch('hr_assistant_history.php');
        const data = await response.json();
        
        if (data.success && data.history.length > 0) {
            const messagesContainer = document.getElementById('chatMessages');
            const welcomeMessage = messagesContainer.querySelector('.welcome-message');
            if (welcomeMessage) {
                welcomeMessage.remove();
            }
            
            // Display all previous messages
            data.history.forEach(item => {
                addMessage(item.message, 'user', false);
                addMessage(item.response, 'assistant', false);
                
                // Rebuild conversation history for context
                conversationHistory.push({
                    role: 'user',
                    content: item.message
                });
                conversationHistory.push({
                    role: 'model',
                    content: item.response
                });
            });
        }
    } catch (error) {
        console.error('Error loading chat history:', error);
    }
}

function handleKeyPress(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}

function sendQuickQuestion(question) {
    document.getElementById('messageInput').value = question;
    sendMessage();
}

async function sendMessage() {
    const input = document.getElementById('messageInput');
    const message = input.value.trim();
    
    if (!message) return;
    
    // Add user message to chat
    addMessage(message, 'user');
    input.value = '';
    
    // Disable send button
    const sendButton = document.getElementById('sendButton');
    sendButton.disabled = true;
    
    // Show typing indicator
    showTypingIndicator();
    
    try {
        const response = await fetch('hr_assistant_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                message: message,
                history: conversationHistory
            })
        });
        
        const data = await response.json();
        
        hideTypingIndicator();
        
        if (data.success) {
            addMessage(data.response, 'assistant');
            conversationHistory.push({
                role: 'user',
                content: message
            });
            conversationHistory.push({
                role: 'model',
                content: data.response
            });
        } else {
            addMessage('Lo siento, ocurrió un error: ' + (data.error || 'Error desconocido'), 'assistant');
        }
    } catch (error) {
        hideTypingIndicator();
        addMessage('Lo siento, no pude conectarme con el servidor. Por favor, intenta de nuevo.', 'assistant');
        console.error('Error:', error);
    }
    
    sendButton.disabled = false;
}

function addMessage(text, sender, scroll = true) {
    const messagesContainer = document.getElementById('chatMessages');
    
    // Remove welcome message if exists
    const welcomeMessage = messagesContainer.querySelector('.welcome-message');
    if (welcomeMessage) {
        welcomeMessage.remove();
    }
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${sender}`;
    
    const avatar = document.createElement('div');
    avatar.className = 'message-avatar';
    avatar.innerHTML = sender === 'user' ? '<i class="fas fa-user"></i>' : '<i class="fas fa-robot"></i>';
    
    const content = document.createElement('div');
    content.className = 'message-content';
    content.innerHTML = formatMessage(text);
    
    messageDiv.appendChild(avatar);
    messageDiv.appendChild(content);
    
    messagesContainer.appendChild(messageDiv);
    
    if (scroll) {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
}

function formatMessage(text) {
    // Convert markdown-style formatting to HTML
    text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
    text = text.replace(/\n/g, '<br>');
    
    // Convert lists
    text = text.replace(/^- (.*?)$/gm, '• $1');
    
    return text;
}

function showTypingIndicator() {
    const messagesContainer = document.getElementById('chatMessages');
    
    const typingDiv = document.createElement('div');
    typingDiv.className = 'message assistant';
    typingDiv.id = 'typingIndicator';
    
    const avatar = document.createElement('div');
    avatar.className = 'message-avatar';
    avatar.innerHTML = '<i class="fas fa-robot"></i>';
    
    const indicator = document.createElement('div');
    indicator.className = 'typing-indicator active';
    indicator.innerHTML = '<div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div>';
    
    typingDiv.appendChild(avatar);
    typingDiv.appendChild(indicator);
    
    messagesContainer.appendChild(typingDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function hideTypingIndicator() {
    const indicator = document.getElementById('typingIndicator');
    if (indicator) {
        indicator.remove();
    }
}

async function clearChat() {
    if (!confirm('¿Estás seguro de que deseas borrar todo el historial de conversación?')) {
        return;
    }
    
    try {
        const response = await fetch('hr_assistant_clear.php', {
            method: 'POST'
        });
        const data = await response.json();
        
        if (data.success) {
            conversationHistory = [];
            const messagesContainer = document.getElementById('chatMessages');
            messagesContainer.innerHTML = `
                <div class="welcome-message">
                    <h2>¡Hola, <?= htmlspecialchars($userName) ?>! 👋</h2>
                    <p>Soy tu asistente virtual de RH. Puedo ayudarte con información sobre vacaciones, permisos, horarios, evaluaciones y mucho más.</p>
                    
                    <div class="feature-grid">
                        <div class="feature-card">
                            <i class="fas fa-umbrella-beach"></i>
                            <h3>Vacaciones</h3>
                            <p>Consulta tu balance de días disponibles y solicita vacaciones</p>
                        </div>
                        <div class="feature-card">
                            <i class="fas fa-clipboard-list"></i>
                            <h3>Permisos</h3>
                            <p>Información sobre cómo solicitar permisos y ver tus solicitudes</p>
                        </div>
                        <div class="feature-card">
                            <i class="fas fa-clock"></i>
                            <h3>Horarios</h3>
                            <p>Consulta tu horario de trabajo y políticas de asistencia</p>
                        </div>
                        <div class="feature-card">
                            <i class="fas fa-star"></i>
                            <h3>Evaluaciones</h3>
                            <p>Información sobre tu próxima evaluación de desempeño</p>
                        </div>
                    </div>
                </div>
            `;
        } else {
            alert('Error al limpiar el historial: ' + data.error);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al limpiar el historial');
    }
}

// Auto-resize textarea
document.getElementById('messageInput').addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>

<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

// Verificar que el usuario tenga permiso para mensajes masivos
ensurePermission('chat_mass_message');

$pageTitle = 'Mensajes Masivos';
include '../header.php';
?>

<div class="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900">
    <div class="container mx-auto px-4 py-8">
        <!-- Header Section -->
        <div class="mb-8">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-gradient-to-r from-cyan-500 to-blue-600 rounded-xl shadow-lg">
                        <i class="fas fa-bullhorn text-white text-xl"></i>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold text-white mb-1">Mensajes Masivos</h1>
                        <p class="text-slate-400">Envía mensajes a todos los usuarios del sistema</p>
                    </div>
                </div>
                <nav class="flex items-center gap-2 text-sm">
                    <a href="../dashboard.php" class="text-slate-400 hover:text-white transition-colors">Dashboard</a>
                    <i class="fas fa-chevron-right text-slate-600 text-xs"></i>
                    <a href="index.php" class="text-slate-400 hover:text-white transition-colors">Chat</a>
                    <i class="fas fa-chevron-right text-slate-600 text-xs"></i>
                    <span class="text-cyan-400">Mensajes Masivos</span>
                </nav>
            </div>

            <!-- Information Banner -->
            <div class="bg-gradient-to-r from-blue-500/10 to-cyan-500/10 border border-blue-500/20 rounded-2xl p-6 mb-8">
                <div class="flex items-start gap-4">
                    <div class="p-2 bg-blue-500/20 rounded-lg">
                        <i class="fas fa-info-circle text-blue-400 text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-2">Información del Sistema</h3>
                        <p class="text-slate-300 leading-relaxed">
                            Los mensajes masivos se envían como conversaciones individuales a todos los usuarios activos del sistema.
                            Cada usuario recibirá el mensaje en su chat personal, manteniendo la privacidad y organización.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Main Form Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                <!-- Message Form -->
                <div class="lg:col-span-2">
                    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-2xl p-6 shadow-2xl">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="p-2 bg-gradient-to-r from-green-500 to-emerald-600 rounded-lg">
                                <i class="fas fa-paper-plane text-white"></i>
                            </div>
                            <h2 class="text-xl font-semibold text-white">Enviar Mensaje Masivo</h2>
                        </div>

                        <!-- Message Status Area -->
                        <div id="messageArea" class="mb-6"></div>

                        <form id="massMessageForm" class="space-y-6">
                            <div>
                                <label for="messageText" class="block text-sm font-medium text-slate-300 mb-2">
                                    <i class="fas fa-comment-alt mr-2 text-cyan-400"></i>
                                    Mensaje
                                </label>
                                <textarea 
                                    class="w-full px-4 py-3 bg-slate-700/50 border border-slate-600 rounded-xl text-white placeholder-slate-400 focus:ring-2 focus:ring-cyan-500 focus:border-transparent transition-all duration-200 resize-none" 
                                    id="messageText" 
                                    name="message_text" 
                                    rows="6" 
                                    placeholder="Escribe tu mensaje aquí..."
                                    required
                                    maxlength="2000"
                                ></textarea>
                                <div class="flex justify-between items-center mt-2">
                                    <div class="text-sm text-slate-400">
                                        <span id="charCount" class="font-medium">0</span><span class="text-slate-500">/2000 caracteres</span>
                                    </div>
                                    <div class="h-1 w-32 bg-slate-700 rounded-full overflow-hidden">
                                        <div id="charProgress" class="h-full bg-gradient-to-r from-cyan-500 to-blue-500 transition-all duration-300" style="width: 0%"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-4">
                                <div class="flex items-start gap-3">
                                    <input type="checkbox" id="confirmSend" required class="mt-1 w-4 h-4 text-cyan-600 bg-slate-700 border-slate-600 rounded focus:ring-cyan-500">
                                    <label for="confirmSend" class="text-sm text-slate-300 leading-relaxed">
                                        <strong class="text-yellow-400">Confirmo que deseo enviar este mensaje a todos los usuarios activos del sistema</strong>
                                        <br>
                                        <span class="text-slate-400 text-xs mt-1 block">Esta acción no se puede deshacer y todos los usuarios recibirán una notificación.</span>
                                    </label>
                                </div>
                            </div>

                            <div class="flex flex-col sm:flex-row gap-3 pt-4">
                                <button type="button" onclick="clearForm()" class="flex-1 sm:flex-none px-6 py-3 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-xl transition-all duration-200 font-medium">
                                    <i class="fas fa-times mr-2"></i>
                                    Limpiar
                                </button>
                                <button type="submit" id="sendButton" class="flex-1 px-8 py-3 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-600 hover:to-blue-700 text-white rounded-xl transition-all duration-200 font-medium shadow-lg hover:shadow-xl">
                                    <i class="fas fa-paper-plane mr-2"></i>
                                    Enviar Mensaje Masivo
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Statistics Sidebar -->
                <div class="space-y-6">
                    <!-- User Statistics -->
                    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-2xl p-6 shadow-2xl">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="p-2 bg-gradient-to-r from-purple-500 to-pink-600 rounded-lg">
                                <i class="fas fa-users text-white"></i>
                            </div>
                            <h2 class="text-xl font-semibold text-white">Estadísticas</h2>
                        </div>

                        <div class="space-y-4">
                            <!-- Active Users -->
                            <div class="bg-gradient-to-r from-green-500/10 to-emerald-500/10 border border-green-500/20 rounded-xl p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm text-slate-400 mb-1">Usuarios Activos</p>
                                        <p class="text-2xl font-bold text-green-400" id="activeUsersCount">
                                            <i class="fas fa-spinner fa-spin text-lg"></i>
                                        </p>
                                    </div>
                                    <div class="p-3 bg-green-500/20 rounded-lg">
                                        <i class="fas fa-user-check text-green-400 text-xl"></i>
                                    </div>
                                </div>
                            </div>

                            <!-- Total Users -->
                            <div class="bg-gradient-to-r from-blue-500/10 to-cyan-500/10 border border-blue-500/20 rounded-xl p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm text-slate-400 mb-1">Total Usuarios</p>
                                        <p class="text-2xl font-bold text-blue-400" id="totalUsersCount">
                                            <i class="fas fa-spinner fa-spin text-lg"></i>
                                        </p>
                                    </div>
                                    <div class="p-3 bg-blue-500/20 rounded-lg">
                                        <i class="fas fa-users text-blue-400 text-xl"></i>
                                    </div>
                                </div>
                            </div>

                            <!-- Recipients -->
                            <div class="bg-gradient-to-r from-orange-500/10 to-yellow-500/10 border border-orange-500/20 rounded-xl p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm text-slate-400 mb-1">Destinatarios</p>
                                        <p class="text-2xl font-bold text-orange-400" id="recipientsCount">
                                            <i class="fas fa-spinner fa-spin text-lg"></i>
                                        </p>
                                    </div>
                                    <div class="p-3 bg-orange-500/20 rounded-lg">
                                        <i class="fas fa-paper-plane text-orange-400 text-xl"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tips Section -->
                    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-2xl p-6 shadow-2xl">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="p-2 bg-gradient-to-r from-yellow-500 to-orange-600 rounded-lg">
                                <i class="fas fa-lightbulb text-white"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-white">Consejos</h3>
                        </div>
                        <div class="space-y-3 text-sm text-slate-300">
                            <div class="flex items-start gap-2">
                                <i class="fas fa-check text-green-400 mt-1 text-xs"></i>
                                <p>Los mensajes se envían como conversaciones individuales</p>
                            </div>
                            <div class="flex items-start gap-2">
                                <i class="fas fa-check text-green-400 mt-1 text-xs"></i>
                                <p>Solo usuarios activos recibirán el mensaje</p>
                            </div>
                            <div class="flex items-start gap-2">
                                <i class="fas fa-check text-green-400 mt-1 text-xs"></i>
                                <p>Todas las acciones quedan registradas en los logs</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Modern animations and effects */
.animate-fade-in {
    animation: fadeInUp 0.6s ease-out;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Hover effects for cards */
.bg-slate-800\/50:hover {
    background-color: rgba(30, 41, 59, 0.7);
    transition: all 0.3s ease;
}

/* Custom scrollbar for textarea */
textarea::-webkit-scrollbar {
    width: 8px;
}

textarea::-webkit-scrollbar-track {
    background: rgba(51, 65, 85, 0.3);
    border-radius: 4px;
}

textarea::-webkit-scrollbar-thumb {
    background: rgba(6, 182, 212, 0.5);
    border-radius: 4px;
}

textarea::-webkit-scrollbar-thumb:hover {
    background: rgba(6, 182, 212, 0.7);
}

/* Loading animation */
.loading-pulse {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: .5;
    }
}

/* Button hover effects */
button:hover {
    transform: translateY(-1px);
    transition: all 0.2s ease;
}

button:active {
    transform: translateY(0);
}

/* Gradient text effect */
.gradient-text {
    background: linear-gradient(135deg, #06b6d4, #3b82f6);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* Glass morphism effect */
.glass {
    backdrop-filter: blur(16px) saturate(180%);
    -webkit-backdrop-filter: blur(16px) saturate(180%);
    background-color: rgba(30, 41, 59, 0.75);
    border: 1px solid rgba(255, 255, 255, 0.125);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const messageTextarea = document.getElementById('messageText');
    const charCountSpan = document.getElementById('charCount');
    const massMessageForm = document.getElementById('massMessageForm');
    const sendButton = document.getElementById('sendButton');
    const messageArea = document.getElementById('messageArea');

    // Contador de caracteres y barra de progreso
    messageTextarea.addEventListener('input', function() {
        const count = this.value.length;
        const maxLength = 2000;
        const percentage = (count / maxLength) * 100;
        
        charCountSpan.textContent = count;
        
        // Actualizar barra de progreso
        const progressBar = document.getElementById('charProgress');
        if (progressBar) {
            progressBar.style.width = percentage + '%';
            
            // Cambiar color basado en el porcentaje
            if (percentage > 90) {
                progressBar.className = 'h-full bg-gradient-to-r from-red-500 to-red-600 transition-all duration-300';
                charCountSpan.className = 'font-medium text-red-400';
            } else if (percentage > 75) {
                progressBar.className = 'h-full bg-gradient-to-r from-yellow-500 to-orange-500 transition-all duration-300';
                charCountSpan.className = 'font-medium text-yellow-400';
            } else {
                progressBar.className = 'h-full bg-gradient-to-r from-cyan-500 to-blue-500 transition-all duration-300';
                charCountSpan.className = 'font-medium text-slate-300';
            }
        }
    });

    // Cargar estadísticas de usuarios
    loadUserStats();

    // Manejar envío del formulario
    massMessageForm.addEventListener('submit', function(e) {
        e.preventDefault();
        sendMassMessage();
    });
});

async function loadUserStats() {
    try {
        const response = await fetch('mass_message_stats.php');
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('activeUsersCount').textContent = data.active_users;
            document.getElementById('totalUsersCount').textContent = data.total_users;
            document.getElementById('recipientsCount').textContent = data.recipients;
        } else {
            document.getElementById('activeUsersCount').innerHTML = '<i class="fas fa-exclamation-triangle text-warning"></i>';
            document.getElementById('totalUsersCount').innerHTML = '<i class="fas fa-exclamation-triangle text-warning"></i>';
            document.getElementById('recipientsCount').innerHTML = '<i class="fas fa-exclamation-triangle text-warning"></i>';
        }
    } catch (error) {
        console.error('Error loading user stats:', error);
        document.getElementById('activeUsersCount').innerHTML = '<i class="fas fa-times text-danger"></i>';
        document.getElementById('totalUsersCount').innerHTML = '<i class="fas fa-times text-danger"></i>';
        document.getElementById('recipientsCount').innerHTML = '<i class="fas fa-times text-danger"></i>';
    }
}

async function sendMassMessage() {
    const messageText = document.getElementById('messageText').value.trim();
    const confirmSend = document.getElementById('confirmSend').checked;
    const sendButton = document.getElementById('sendButton');
    const messageArea = document.getElementById('messageArea');

    if (!messageText) {
        showMessage('error', 'Por favor, escribe un mensaje.');
        return;
    }

    if (!confirmSend) {
        showMessage('error', 'Debes confirmar que deseas enviar el mensaje.');
        return;
    }

    // Deshabilitar botón y mostrar loading
    sendButton.disabled = true;
    sendButton.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Enviando...';

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'send_mass_message',
                message_text: messageText
            })
        });

        const data = await response.json();

        if (data.success) {
            showMessage('success', `${data.message}. Enviado a ${data.sent_count} de ${data.total_users} usuarios.`);
            
            if (data.warnings && data.warnings.length > 0) {
                showMessage('warning', 'Algunas advertencias: ' + data.warnings.join(', '));
            }
            
            // Limpiar formulario
            clearForm();
            
            // Recargar estadísticas
            loadUserStats();
        } else {
            showMessage('error', data.error || 'Error desconocido al enviar el mensaje.');
        }
    } catch (error) {
        console.error('Error sending mass message:', error);
        showMessage('error', 'Error de conexión. Por favor, intenta nuevamente.');
    } finally {
        // Rehabilitar botón
        sendButton.disabled = false;
        sendButton.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Enviar Mensaje Masivo';
    }
}

function showMessage(type, message) {
    const messageArea = document.getElementById('messageArea');
    
    // Definir colores y estilos basados en el tipo
    const styles = {
        success: {
            bg: 'bg-gradient-to-r from-green-500/10 to-emerald-500/10',
            border: 'border-green-500/30',
            icon: 'fa-check-circle text-green-400',
            text: 'text-green-300'
        },
        warning: {
            bg: 'bg-gradient-to-r from-yellow-500/10 to-orange-500/10',
            border: 'border-yellow-500/30',
            icon: 'fa-exclamation-triangle text-yellow-400',
            text: 'text-yellow-300'
        },
        error: {
            bg: 'bg-gradient-to-r from-red-500/10 to-red-600/10',
            border: 'border-red-500/30',
            icon: 'fa-times-circle text-red-400',
            text: 'text-red-300'
        }
    };
    
    const style = styles[type] || styles.error;
    
    const alertDiv = document.createElement('div');
    alertDiv.className = `${style.bg} border ${style.border} rounded-xl p-4 mb-4 animate-fade-in`;
    alertDiv.innerHTML = `
        <div class="flex items-start gap-3">
            <div class="p-1">
                <i class="fas ${style.icon} text-lg"></i>
            </div>
            <div class="flex-1">
                <p class="${style.text} font-medium leading-relaxed">${message}</p>
            </div>
            <button type="button" onclick="this.parentElement.parentElement.remove()" class="text-slate-400 hover:text-white transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;

    messageArea.appendChild(alertDiv);

    // Auto-remove after 8 seconds for success messages
    if (type === 'success') {
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.style.opacity = '0';
                alertDiv.style.transform = 'translateY(-10px)';
                alertDiv.style.transition = 'all 0.3s ease';
                setTimeout(() => alertDiv.remove(), 300);
            }
        }, 8000);
    }
}

function clearForm() {
    document.getElementById('messageText').value = '';
    document.getElementById('confirmSend').checked = false;
    document.getElementById('charCount').textContent = '0';
    document.getElementById('charCount').style.color = '#6c757d';
    
    // Limpiar mensajes
    const messageArea = document.getElementById('messageArea');
    messageArea.innerHTML = '';
}
</script>

<?php include '../footer.php'; ?>

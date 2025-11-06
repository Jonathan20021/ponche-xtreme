<?php
/**
 * Panel de Administración de Permisos de Chat
 */

require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ensurePermission('chat_admin');

require_once __DIR__ . '/../header.php';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_permissions') {
        $targetUserId = (int)$_POST['user_id'];
        $canUseChat = isset($_POST['can_use_chat']) ? 1 : 0;
        $canCreateGroups = isset($_POST['can_create_groups']) ? 1 : 0;
        $canUploadFiles = isset($_POST['can_upload_files']) ? 1 : 0;
        $maxFileSizeMb = (int)$_POST['max_file_size_mb'];
        $canSendVideos = isset($_POST['can_send_videos']) ? 1 : 0;
        $canSendDocuments = isset($_POST['can_send_documents']) ? 1 : 0;
        
        $stmt = $pdo->prepare("
            INSERT INTO chat_permissions 
            (user_id, can_use_chat, can_create_groups, can_upload_files, max_file_size_mb, can_send_videos, can_send_documents)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            can_use_chat = VALUES(can_use_chat),
            can_create_groups = VALUES(can_create_groups),
            can_upload_files = VALUES(can_upload_files),
            max_file_size_mb = VALUES(max_file_size_mb),
            can_send_videos = VALUES(can_send_videos),
            can_send_documents = VALUES(can_send_documents)
        ");
        
        if ($stmt->execute([$targetUserId, $canUseChat, $canCreateGroups, $canUploadFiles, $maxFileSizeMb, $canSendVideos, $canSendDocuments])) {
            $successMessage = 'Permisos actualizados correctamente';
        } else {
            $errorMessage = 'Error al actualizar permisos';
        }
    }
    
    if ($action === 'restrict_user') {
        $targetUserId = (int)$_POST['user_id'];
        $reason = trim($_POST['restriction_reason']);
        $duration = (int)$_POST['restriction_duration'];
        
        $restrictedUntil = null;
        if ($duration > 0) {
            $restrictedUntil = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
        }
        
        $stmt = $pdo->prepare("
            UPDATE chat_permissions
            SET is_restricted = 1, restriction_reason = ?, restricted_until = ?
            WHERE user_id = ?
        ");
        
        if ($stmt->execute([$reason, $restrictedUntil, $targetUserId])) {
            $successMessage = 'Usuario restringido correctamente';
        } else {
            $errorMessage = 'Error al restringir usuario';
        }
    }
    
    if ($action === 'unrestrict_user') {
        $targetUserId = (int)$_POST['user_id'];
        
        $stmt = $pdo->prepare("
            UPDATE chat_permissions
            SET is_restricted = 0, restriction_reason = NULL, restricted_until = NULL
            WHERE user_id = ?
        ");
        
        if ($stmt->execute([$targetUserId])) {
            $successMessage = 'Restricción removida correctamente';
        } else {
            $errorMessage = 'Error al remover restricción';
        }
    }
}

// Obtener usuarios con sus permisos
$stmt = $pdo->query("
    SELECT 
        u.id,
        u.username,
        u.full_name,
        u.role,
        COALESCE(p.can_use_chat, 1) as can_use_chat,
        COALESCE(p.can_create_groups, 0) as can_create_groups,
        COALESCE(p.can_upload_files, 1) as can_upload_files,
        COALESCE(p.max_file_size_mb, 50) as max_file_size_mb,
        COALESCE(p.can_send_videos, 1) as can_send_videos,
        COALESCE(p.can_send_documents, 1) as can_send_documents,
        COALESCE(p.is_restricted, 0) as is_restricted,
        p.restriction_reason,
        p.restricted_until
    FROM users u
    LEFT JOIN chat_permissions p ON p.user_id = u.id
    WHERE u.is_active = 1
    ORDER BY u.full_name
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener estadísticas
$stats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM chat_messages WHERE DATE(created_at) = CURDATE()) as messages_today,
        (SELECT COUNT(*) FROM chat_conversations WHERE is_active = 1) as active_conversations,
        (SELECT COUNT(DISTINCT user_id) FROM chat_user_status WHERE status = 'online') as online_users,
        (SELECT COUNT(*) FROM chat_attachments WHERE DATE(created_at) = CURDATE()) as files_today
")->fetch(PDO::FETCH_ASSOC);
?>

<div class="container mx-auto px-4 py-8">
    <?php if (isset($successMessage)): ?>
        <div class="bg-green-500/20 border border-green-500/40 text-green-200 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($successMessage) ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($errorMessage)): ?>
        <div class="bg-red-500/20 border border-red-500/40 text-red-200 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($errorMessage) ?>
        </div>
    <?php endif; ?>
    
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold">Administración de Chat</h1>
            <p class="text-slate-400 mt-1">Gestiona permisos, restricciones y monitorea conversaciones</p>
        </div>
    </div>
    
    <!-- Pestañas -->
    <div class="flex gap-4 mb-6 border-b border-slate-700">
        <button onclick="switchTab('permissions')" id="tab-permissions" 
                class="px-4 py-2 font-semibold border-b-2 border-cyan-500 text-cyan-400 transition-colors">
            <i class="fas fa-shield-alt mr-2"></i>Permisos
        </button>
        <button onclick="switchTab('monitoring')" id="tab-monitoring"
                class="px-4 py-2 font-semibold border-b-2 border-transparent text-slate-400 hover:text-white transition-colors">
            <i class="fas fa-eye mr-2"></i>Monitoreo de Conversaciones
        </button>
    </div>
    
    <!-- Contenido de Permisos -->
    <div id="permissions-content">
    <!-- Estadísticas -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-6">
            <div class="flex items-center gap-3">
                <div class="h-12 w-12 rounded-lg bg-cyan-500/20 flex items-center justify-center text-cyan-400">
                    <i class="fas fa-comments text-xl"></i>
                </div>
                <div>
                    <p class="text-slate-400 text-sm">Mensajes Hoy</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['messages_today']) ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-6">
            <div class="flex items-center gap-3">
                <div class="h-12 w-12 rounded-lg bg-purple-500/20 flex items-center justify-center text-purple-400">
                    <i class="fas fa-inbox text-xl"></i>
                </div>
                <div>
                    <p class="text-slate-400 text-sm">Conversaciones</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['active_conversations']) ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-6">
            <div class="flex items-center gap-3">
                <div class="h-12 w-12 rounded-lg bg-green-500/20 flex items-center justify-center text-green-400">
                    <i class="fas fa-circle text-xl"></i>
                </div>
                <div>
                    <p class="text-slate-400 text-sm">En Línea</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['online_users']) ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-slate-800/50 border border-slate-700 rounded-lg p-6">
            <div class="flex items-center gap-3">
                <div class="h-12 w-12 rounded-lg bg-orange-500/20 flex items-center justify-center text-orange-400">
                    <i class="fas fa-file text-xl"></i>
                </div>
                <div>
                    <p class="text-slate-400 text-sm">Archivos Hoy</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['files_today']) ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Lista de usuarios -->
    <div class="bg-slate-800/50 border border-slate-700 rounded-lg overflow-hidden">
        <div class="p-6 border-b border-slate-700">
            <h2 class="text-xl font-semibold">Permisos de Usuarios</h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-900/50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-400 uppercase tracking-wider">Usuario</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-400 uppercase tracking-wider">Rol</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-400 uppercase tracking-wider">Chat</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-400 uppercase tracking-wider">Grupos</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-400 uppercase tracking-wider">Archivos</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-400 uppercase tracking-wider">Tamaño Máx.</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-400 uppercase tracking-wider">Estado</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-slate-400 uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700">
                    <?php foreach ($users as $user): ?>
                        <tr class="hover:bg-slate-700/30 transition-colors">
                            <td class="px-6 py-4">
                                <div>
                                    <p class="font-medium"><?= htmlspecialchars($user['full_name']) ?></p>
                                    <p class="text-sm text-slate-400">@<?= htmlspecialchars($user['username']) ?></p>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-slate-700 text-slate-200">
                                    <?= htmlspecialchars($user['role']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($user['can_use_chat']): ?>
                                    <i class="fas fa-check-circle text-green-400"></i>
                                <?php else: ?>
                                    <i class="fas fa-times-circle text-red-400"></i>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($user['can_create_groups']): ?>
                                    <i class="fas fa-check-circle text-green-400"></i>
                                <?php else: ?>
                                    <i class="fas fa-times-circle text-slate-600"></i>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($user['can_upload_files']): ?>
                                    <i class="fas fa-check-circle text-green-400"></i>
                                <?php else: ?>
                                    <i class="fas fa-times-circle text-red-400"></i>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center text-sm">
                                <?= $user['max_file_size_mb'] ?>MB
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if ($user['is_restricted']): ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-500/20 text-red-400">
                                        Restringido
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-500/20 text-green-400">
                                        Activo
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <button onclick="editPermissions(<?= $user['id'] ?>)" 
                                        class="text-cyan-400 hover:text-cyan-300 mr-3"
                                        title="Editar permisos">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($user['is_restricted']): ?>
                                    <button onclick="unrestrictUser(<?= $user['id'] ?>)" 
                                            class="text-green-400 hover:text-green-300"
                                            title="Remover restricción">
                                        <i class="fas fa-unlock"></i>
                                    </button>
                                <?php else: ?>
                                    <button onclick="restrictUser(<?= $user['id'] ?>)" 
                                            class="text-red-400 hover:text-red-300"
                                            title="Restringir usuario">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>
    <!-- Fin de Contenido de Permisos -->
    
    <!-- Contenido de Monitoreo -->
    <div id="monitoring-content" class="hidden">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Lista de Conversaciones -->
            <div class="lg:col-span-1 bg-slate-800/50 border border-slate-700 rounded-lg overflow-hidden">
                <div class="p-4 border-b border-slate-700">
                    <h3 class="font-semibold">Conversaciones</h3>
                    <div class="mt-3">
                        <input type="text" id="search-conversations" placeholder="Buscar conversaciones..." 
                               class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg text-sm">
                    </div>
                </div>
                <div id="conversations-list" class="overflow-y-auto" style="max-height: calc(100vh - 400px);">
                    <div class="p-4 text-center text-slate-400">
                        <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
                        <p class="text-sm">Cargando conversaciones...</p>
                    </div>
                </div>
            </div>
            
            <!-- Mensajes de la Conversación Seleccionada -->
            <div class="lg:col-span-2 bg-slate-800/50 border border-slate-700 rounded-lg overflow-hidden flex flex-col">
                <div class="p-4 border-b border-slate-700">
                    <div id="conversation-header" class="flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold" id="conversation-title">Selecciona una conversación</h3>
                            <p class="text-sm text-slate-400" id="conversation-subtitle"></p>
                        </div>
                        <button id="refresh-messages" onclick="refreshMessages()" class="text-slate-400 hover:text-white hidden">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                <div id="messages-container" class="flex-1 overflow-y-auto p-4" style="max-height: calc(100vh - 450px);">
                    <div class="flex items-center justify-center h-full text-slate-400">
                        <div class="text-center">
                            <i class="fas fa-comments text-4xl mb-3 opacity-50"></i>
                            <p>Selecciona una conversación para ver los mensajes</p>
                        </div>
                    </div>
                </div>
                <div class="p-4 border-t border-slate-700 bg-slate-900/50" id="message-stats" style="display: none;">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-400">Total mensajes: <span id="total-messages" class="font-semibold text-white">0</span></span>
                        <span class="text-slate-400">Con archivos: <span id="messages-with-files" class="font-semibold text-white">0</span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Fin de Contenido de Monitoreo -->
</div>

<!-- Modal de edición de permisos -->
<div id="editModal" class="hidden fixed inset-0 bg-black/70 flex items-center justify-center z-50" onclick="if(event.target === this) closeModal()">
    <div class="bg-slate-800 border border-slate-700 rounded-lg p-6 max-w-md w-full mx-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-bold">Editar Permisos</h3>
            <button type="button" onclick="closeModal()" class="text-slate-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="update_permissions">
            <input type="hidden" name="user_id" id="edit_user_id">
            
            <div class="space-y-4">
                <label class="flex items-center gap-3">
                    <input type="checkbox" name="can_use_chat" id="edit_can_use_chat" class="rounded">
                    <span>Puede usar el chat</span>
                </label>
                
                <label class="flex items-center gap-3">
                    <input type="checkbox" name="can_create_groups" id="edit_can_create_groups" class="rounded">
                    <span>Puede crear grupos</span>
                </label>
                
                <label class="flex items-center gap-3">
                    <input type="checkbox" name="can_upload_files" id="edit_can_upload_files" class="rounded">
                    <span>Puede subir archivos</span>
                </label>
                
                <div>
                    <label class="block text-sm font-medium mb-2">Tamaño máximo de archivo (MB)</label>
                    <input type="number" name="max_file_size_mb" id="edit_max_file_size_mb" 
                           class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg"
                           min="1" max="500">
                </div>
                
                <label class="flex items-center gap-3">
                    <input type="checkbox" name="can_send_videos" id="edit_can_send_videos" class="rounded">
                    <span>Puede enviar videos</span>
                </label>
                
                <label class="flex items-center gap-3">
                    <input type="checkbox" name="can_send_documents" id="edit_can_send_documents" class="rounded">
                    <span>Puede enviar documentos</span>
                </label>
            </div>
            
            <div class="flex gap-3 mt-6">
                <button type="submit" class="flex-1 bg-cyan-500 hover:bg-cyan-600 text-white px-4 py-2 rounded-lg transition-colors">
                    Guardar
                </button>
                <button type="button" onclick="closeModal()" class="flex-1 bg-slate-700 hover:bg-slate-600 text-white px-4 py-2 rounded-lg transition-colors">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de restricción -->
<div id="restrictModal" class="hidden fixed inset-0 bg-black/70 flex items-center justify-center z-50" onclick="if(event.target === this) closeRestrictModal()">
    <div class="bg-slate-800 border border-slate-700 rounded-lg p-6 max-w-md w-full mx-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-bold">Restringir Usuario</h3>
            <button type="button" onclick="closeRestrictModal()" class="text-slate-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="restrict_user">
            <input type="hidden" name="user_id" id="restrict_user_id">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Razón de restricción</label>
                    <textarea name="restriction_reason" required
                              class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg"
                              rows="3"></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">Duración (días, 0 = permanente)</label>
                    <input type="number" name="restriction_duration" value="0" min="0"
                           class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded-lg">
                </div>
            </div>
            
            <div class="flex gap-3 mt-6">
                <button type="submit" class="flex-1 bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition-colors">
                    Restringir
                </button>
                <button type="button" onclick="closeRestrictModal()" class="flex-1 bg-slate-700 hover:bg-slate-600 text-white px-4 py-2 rounded-lg transition-colors">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const usersData = <?= json_encode($users) ?>;
let currentConversationId = null;
let conversations = [];
let monitoringInterval = null;

function editPermissions(userId) {
    const user = usersData.find(u => u.id === userId);
    if (!user) return;
    
    document.getElementById('edit_user_id').value = userId;
    document.getElementById('edit_can_use_chat').checked = user.can_use_chat == 1;
    document.getElementById('edit_can_create_groups').checked = user.can_create_groups == 1;
    document.getElementById('edit_can_upload_files').checked = user.can_upload_files == 1;
    document.getElementById('edit_max_file_size_mb').value = user.max_file_size_mb;
    document.getElementById('edit_can_send_videos').checked = user.can_send_videos == 1;
    document.getElementById('edit_can_send_documents').checked = user.can_send_documents == 1;
    
    document.getElementById('editModal').classList.remove('hidden');
}

function switchTab(tab) {
    // Update tab styles
    document.querySelectorAll('[id^="tab-"]').forEach(btn => {
        btn.classList.remove('border-cyan-500', 'text-cyan-400');
        btn.classList.add('border-transparent', 'text-slate-400');
    });
    document.getElementById(`tab-${tab}`).classList.remove('border-transparent', 'text-slate-400');
    document.getElementById(`tab-${tab}`).classList.add('border-cyan-500', 'text-cyan-400');
    
    // Show/hide content
    document.getElementById('permissions-content').classList.toggle('hidden', tab !== 'permissions');
    document.getElementById('monitoring-content').classList.toggle('hidden', tab !== 'monitoring');
    
    // Load monitoring data if needed
    if (tab === 'monitoring' && conversations.length === 0) {
        loadConversations();
        startMonitoring();
    } else if (tab === 'permissions') {
        stopMonitoring();
    }
}

function startMonitoring() {
    if (monitoringInterval) return;
    monitoringInterval = setInterval(loadConversations, 10000); // Actualizar cada 10 segundos
}

function stopMonitoring() {
    if (monitoringInterval) {
        clearInterval(monitoringInterval);
        monitoringInterval = null;
    }
}

async function loadConversations() {
    try {
        console.log('Loading conversations...');
        const response = await fetch('monitoring_api.php?action=get_conversations');
        console.log('Response status:', response.status);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        console.log('Data received:', data);
        
        if (data.success) {
            conversations = data.conversations;
            renderConversations(conversations);
        } else {
            throw new Error(data.error || 'Unknown error');
        }
    } catch (error) {
        console.error('Error loading conversations:', error);
        const container = document.getElementById('conversations-list');
        container.innerHTML = `
            <div class="p-4 text-center text-red-400">
                <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                <p class="text-sm">Error al cargar conversaciones</p>
                <p class="text-xs mt-1">${error.message}</p>
            </div>
        `;
    }
}

function renderConversations(convs) {
    const container = document.getElementById('conversations-list');
    
    if (convs.length === 0) {
        container.innerHTML = `
            <div class="p-4 text-center text-slate-400">
                <i class="fas fa-inbox text-2xl mb-2"></i>
                <p class="text-sm">No hay conversaciones activas</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = convs.map(conv => {
        const isActive = currentConversationId === conv.id;
        return `
            <div class="conversation-item p-4 hover:bg-slate-700/30 cursor-pointer border-b border-slate-700 transition-colors ${isActive ? 'bg-slate-700/50' : ''}"
                 onclick="loadConversation(${conv.id})">
                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0">
                        ${conv.is_group ? 
                            '<div class="w-10 h-10 rounded-full bg-purple-500/20 flex items-center justify-center text-purple-400"><i class="fas fa-users"></i></div>' :
                            '<div class="w-10 h-10 rounded-full bg-cyan-500/20 flex items-center justify-center text-cyan-400"><i class="fas fa-user"></i></div>'
                        }
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between">
                            <p class="font-semibold truncate">${escapeHtml(conv.name)}</p>
                            <span class="text-xs text-slate-500">${conv.participant_count} <i class="fas fa-user text-xs"></i></span>
                        </div>
                        <p class="text-sm text-slate-400 truncate">${conv.last_message || 'Sin mensajes'}</p>
                        <p class="text-xs text-slate-500 mt-1">${conv.last_message_time}</p>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

async function loadConversation(conversationId) {
    currentConversationId = conversationId;
    
    try {
        const response = await fetch(`monitoring_api.php?action=get_messages&conversation_id=${conversationId}`);
        const data = await response.json();
        
        if (data.success) {
            renderMessages(data.messages, data.conversation);
            document.getElementById('refresh-messages').classList.remove('hidden');
            document.getElementById('message-stats').style.display = 'block';
            document.getElementById('total-messages').textContent = data.messages.length;
            document.getElementById('messages-with-files').textContent = data.messages.filter(m => m.has_attachment).length;
            
            // Si es un grupo, cargar participantes
            if (data.conversation.type === 'group' || data.conversation.type === 'channel') {
                await loadParticipants(conversationId);
            } else {
                // Ocultar panel de participantes si no es grupo
                const participantsPanel = document.getElementById('participants-panel');
                if (participantsPanel) participantsPanel.style.display = 'none';
            }
        }
    } catch (error) {
        console.error('Error loading conversation:', error);
    }
}

async function loadParticipants(conversationId) {
    try {
        const response = await fetch(`monitoring_api.php?action=get_participants&conversation_id=${conversationId}`);
        const data = await response.json();
        
        if (data.success) {
            renderParticipants(data.participants, conversationId);
        }
    } catch (error) {
        console.error('Error loading participants:', error);
    }
}

function renderParticipants(participants, conversationId) {
    let participantsPanel = document.getElementById('participants-panel');
    
    // Crear panel si no existe
    if (!participantsPanel) {
        participantsPanel = document.createElement('div');
        participantsPanel.id = 'participants-panel';
        participantsPanel.className = 'bg-slate-800 border border-slate-700 rounded-lg p-4 mt-4';
        document.querySelector('.col-span-2').appendChild(participantsPanel);
    }
    
    participantsPanel.style.display = 'block';
    participantsPanel.innerHTML = `
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-semibold text-cyan-400">
                <i class="fas fa-users mr-2"></i>Participantes del Grupo (${participants.length})
            </h3>
        </div>
        <div class="space-y-2">
            ${participants.map(p => `
                <div class="flex items-center justify-between p-3 bg-slate-700/50 rounded-lg hover:bg-slate-700 transition-colors">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-cyan-400 to-purple-500 flex items-center justify-center text-white font-semibold">
                            ${p.full_name.substring(0, 2).toUpperCase()}
                        </div>
                        <div>
                            <p class="font-medium text-white">${escapeHtml(p.full_name)}</p>
                            <p class="text-xs text-slate-400">@${escapeHtml(p.username)} • ${escapeHtml(p.user_role)}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        ${p.role === 'admin' ? 
                            '<span class="px-2 py-1 bg-purple-500/20 text-purple-400 text-xs rounded-full">Admin</span>' : 
                            '<span class="px-2 py-1 bg-slate-600 text-slate-300 text-xs rounded-full">Miembro</span>'
                        }
                        <button onclick="confirmRemoveParticipant(${conversationId}, ${p.user_id}, '${escapeHtml(p.full_name)}')" 
                                class="text-red-400 hover:text-red-300 transition-colors p-2"
                                title="Remover participante">
                            <i class="fas fa-user-minus"></i>
                        </button>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

async function confirmRemoveParticipant(conversationId, userId, userName) {
    if (!confirm(`¿Estás seguro de remover a ${userName} del grupo?`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('conversation_id', conversationId);
        formData.append('user_id', userId);
        
        const response = await fetch('monitoring_api.php?action=remove_participant', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Participante removido exitosamente');
            await loadParticipants(conversationId);
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        console.error('Error removing participant:', error);
        alert('Error al remover participante');
    }
}

function renderMessages(messages, conversation) {
    const container = document.getElementById('messages-container');
    const title = document.getElementById('conversation-title');
    const subtitle = document.getElementById('conversation-subtitle');
    
    title.textContent = conversation.name;
    subtitle.textContent = `${conversation.participant_count} participante(s) • ${messages.length} mensajes`;
    
    if (messages.length === 0) {
        container.innerHTML = `
            <div class="flex items-center justify-center h-full text-slate-400">
                <div class="text-center">
                    <i class="fas fa-comment-slash text-4xl mb-3 opacity-50"></i>
                    <p>No hay mensajes en esta conversación</p>
                </div>
            </div>
        `;
        return;
    }
    
    container.innerHTML = messages.map(msg => {
        const isDeleted = parseInt(msg.is_deleted) === 1;
        const isEdited = parseInt(msg.is_edited) === 1;
        const hasAttachment = msg.has_attachment && msg.attachment_name;
        
        // Determinar qué mostrar
        let messageContent = '';
        if (isDeleted) {
            messageContent = '(Mensaje eliminado)';
        } else if (!msg.content && hasAttachment) {
            messageContent = ''; // No mostrar nada si solo hay archivo
        } else if (msg.content) {
            messageContent = escapeHtml(msg.content);
        } else {
            messageContent = '(Mensaje sin contenido)';
        }
        
        const attachmentHtml = hasAttachment && !isDeleted ? `
            <div class="mt-2 p-2 bg-slate-700/50 rounded border border-slate-600 text-sm">
                <i class="fas fa-paperclip mr-2"></i>
                <span class="text-cyan-400">${escapeHtml(msg.attachment_name)}</span>
                <span class="text-slate-400 ml-2">(${formatFileSize(msg.attachment_size)})</span>
            </div>
        ` : '';
        
        return `
            <div class="mb-4">
                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-cyan-500 to-purple-500 flex items-center justify-center text-white text-sm font-semibold">
                            ${msg.sender_name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase()}
                        </div>
                    </div>
                    <div class="flex-1">
                        <div class="flex items-baseline gap-2">
                            <p class="font-semibold text-sm">${escapeHtml(msg.sender_name)}</p>
                            <span class="text-xs text-slate-500">${msg.sent_at}</span>
                            ${isDeleted ? '<span class="text-xs text-red-400"><i class="fas fa-trash"></i> Eliminado</span>' : ''}
                            ${isEdited && !isDeleted ? '<span class="text-xs text-slate-400"><i class="fas fa-edit"></i> Editado</span>' : ''}
                        </div>
                        ${messageContent ? `<div class="mt-1 text-sm ${isDeleted ? 'text-slate-500 italic' : ''}">${messageContent}</div>` : ''}
                        ${attachmentHtml}
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    container.scrollTop = container.scrollHeight;
}

function refreshMessages() {
    if (currentConversationId) {
        loadConversation(currentConversationId);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatFileSize(bytes) {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
}

// Search functionality
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('search-conversations')?.addEventListener('input', (e) => {
        const search = e.target.value.toLowerCase();
        const filtered = conversations.filter(conv => 
            conv.name.toLowerCase().includes(search) || 
            (conv.last_message && conv.last_message.toLowerCase().includes(search))
        );
        renderConversations(filtered);
    });
});

function restrictUser(userId) {
    document.getElementById('restrict_user_id').value = userId;
    document.getElementById('restrictModal').classList.remove('hidden');
}

function unrestrictUser(userId) {
    if (confirm('¿Estás seguro de que deseas remover la restricción?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="unrestrict_user">
            <input type="hidden" name="user_id" value="${userId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function closeModal() {
    document.getElementById('editModal').classList.add('hidden');
}

function closeRestrictModal() {
    document.getElementById('restrictModal').classList.add('hidden');
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>

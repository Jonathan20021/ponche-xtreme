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
            <p class="text-slate-400 mt-1">Gestiona permisos y restricciones del sistema de chat</p>
        </div>
    </div>
    
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

<!-- Modal de edición de permisos -->
<div id="editModal" class="hidden fixed inset-0 bg-black/70 flex items-center justify-center z-50">
    <div class="bg-slate-800 border border-slate-700 rounded-lg p-6 max-w-md w-full mx-4">
        <h3 class="text-xl font-bold mb-4">Editar Permisos</h3>
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
<div id="restrictModal" class="hidden fixed inset-0 bg-black/70 flex items-center justify-center z-50">
    <div class="bg-slate-800 border border-slate-700 rounded-lg p-6 max-w-md w-full mx-4">
        <h3 class="text-xl font-bold mb-4">Restringir Usuario</h3>
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

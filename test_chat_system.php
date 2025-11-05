<?php
/**
 * Script de Prueba del Sistema de Chat
 * Verifica que todas las tablas y configuraciones estén correctas
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/chat/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Solo admins pueden ejecutar este script
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('Solo administradores pueden ejecutar este script');
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prueba de Sistema de Chat</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #0f172a;
            color: #e2e8f0;
        }
        .header {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .test-section {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .test-item {
            padding: 10px;
            margin: 10px 0;
            border-left: 4px solid #64748b;
            background: #0f172a;
        }
        .test-item.success {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.1);
        }
        .test-item.error {
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.1);
        }
        .test-item.warning {
            border-color: #f59e0b;
            background: rgba(245, 158, 11, 0.1);
        }
        h2 {
            color: #06b6d4;
            margin-top: 0;
        }
        code {
            background: #334155;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
        .icon {
            margin-right: 8px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🧪 Prueba de Sistema de Chat</h1>
        <p>Verificación de instalación y configuración</p>
    </div>

    <?php
    $results = [];
    
    // Prueba 1: Verificar tablas
    echo '<div class="test-section">';
    echo '<h2>📊 Verificación de Tablas</h2>';
    
    $tables = [
        'chat_conversations',
        'chat_participants',
        'chat_messages',
        'chat_attachments',
        'chat_reactions',
        'chat_read_receipts',
        'chat_notifications',
        'chat_permissions',
        'chat_user_status',
        'chat_scheduled_messages'
    ];
    
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt->rowCount() > 0) {
                echo "<div class='test-item success'><span class='icon'>✅</span> Tabla <code>{$table}</code> existe</div>";
                $results['tables'][$table] = true;
            } else {
                echo "<div class='test-item error'><span class='icon'>❌</span> Tabla <code>{$table}</code> NO existe</div>";
                $results['tables'][$table] = false;
            }
        } catch (Exception $e) {
            echo "<div class='test-item error'><span class='icon'>❌</span> Error al verificar <code>{$table}</code>: {$e->getMessage()}</div>";
            $results['tables'][$table] = false;
        }
    }
    echo '</div>';
    
    // Prueba 2: Verificar directorios
    echo '<div class="test-section">';
    echo '<h2>📁 Verificación de Directorios</h2>';
    
    $dirs = [
        'chat/uploads',
        'chat/uploads/images',
        'chat/uploads/videos',
        'chat/uploads/documents',
        'chat/uploads/audio',
        'chat/uploads/thumbnails'
    ];
    
    foreach ($dirs as $dir) {
        $fullPath = __DIR__ . '/' . $dir;
        if (file_exists($fullPath) && is_dir($fullPath)) {
            $writable = is_writable($fullPath);
            if ($writable) {
                echo "<div class='test-item success'><span class='icon'>✅</span> Directorio <code>{$dir}</code> existe y es escribible</div>";
                $results['dirs'][$dir] = true;
            } else {
                echo "<div class='test-item warning'><span class='icon'>⚠️</span> Directorio <code>{$dir}</code> existe pero NO es escribible</div>";
                $results['dirs'][$dir] = false;
            }
        } else {
            echo "<div class='test-item error'><span class='icon'>❌</span> Directorio <code>{$dir}</code> NO existe</div>";
            $results['dirs'][$dir] = false;
        }
    }
    echo '</div>';
    
    // Prueba 3: Verificar permisos de sección
    echo '<div class="test-section">';
    echo '<h2>🔒 Verificación de Permisos de Sección</h2>';
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM section_permissions WHERE section_key IN ('chat', 'chat_admin')");
    $permCount = $stmt->fetch()['count'];
    
    if ($permCount > 0) {
        echo "<div class='test-item success'><span class='icon'>✅</span> Permisos de sección configurados ({$permCount} entradas)</div>";
        
        $stmt = $pdo->query("SELECT section_key, role FROM section_permissions WHERE section_key IN ('chat', 'chat_admin') ORDER BY section_key, role");
        $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<ul style='margin-left: 30px;'>";
        foreach ($perms as $perm) {
            echo "<li><code>{$perm['section_key']}</code> → <strong>{$perm['role']}</strong></li>";
        }
        echo "</ul>";
    } else {
        echo "<div class='test-item error'><span class='icon'>❌</span> No se encontraron permisos de sección configurados</div>";
    }
    echo '</div>';
    
    // Prueba 4: Verificar permisos de usuarios
    echo '<div class="test-section">';
    echo '<h2>👥 Verificación de Permisos de Usuarios</h2>';
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM chat_permissions");
    $userPermCount = $stmt->fetch()['count'];
    
    echo "<div class='test-item success'><span class='icon'>✅</span> {$userPermCount} usuarios tienen permisos configurados</div>";
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM users u 
        LEFT JOIN chat_permissions p ON p.user_id = u.id 
        WHERE u.is_active = 1 AND p.user_id IS NULL
    ");
    $missingPerms = $stmt->fetch()['count'];
    
    if ($missingPerms > 0) {
        echo "<div class='test-item warning'><span class='icon'>⚠️</span> {$missingPerms} usuarios activos sin permisos de chat configurados</div>";
        echo "<div style='margin-left: 30px; margin-top: 10px;'>";
        echo "<p>Ejecuta este SQL para agregar permisos por defecto:</p>";
        echo "<code style='display: block; padding: 10px; margin-top: 10px;'>";
        echo "INSERT INTO chat_permissions (user_id, can_use_chat, can_create_groups, can_upload_files, max_file_size_mb, can_send_videos, can_send_documents)";
        echo "<br>SELECT id, 1, 0, 1, 50, 1, 1 FROM users WHERE is_active = 1 AND id NOT IN (SELECT user_id FROM chat_permissions);";
        echo "</code>";
        echo "</div>";
    }
    echo '</div>';
    
    // Prueba 5: Verificar archivos del sistema
    echo '<div class="test-section">';
    echo '<h2>📄 Verificación de Archivos del Sistema</h2>';
    
    $files = [
        'chat/config.php' => 'Configuración',
        'chat/api.php' => 'API REST',
        'chat/upload.php' => 'Subida de archivos',
        'chat/admin.php' => 'Panel de administración',
        'chat/serve.php' => 'Servidor de archivos',
        'assets/css/chat.css' => 'Estilos CSS',
        'assets/js/chat.js' => 'JavaScript del cliente'
    ];
    
    foreach ($files as $file => $desc) {
        $fullPath = __DIR__ . '/' . $file;
        if (file_exists($fullPath)) {
            echo "<div class='test-item success'><span class='icon'>✅</span> {$desc} <code>{$file}</code></div>";
        } else {
            echo "<div class='test-item error'><span class='icon'>❌</span> {$desc} <code>{$file}</code> NO existe</div>";
        }
    }
    echo '</div>';
    
    // Prueba 6: Configuración PHP
    echo '<div class="test-section">';
    echo '<h2>⚙️ Configuración PHP</h2>';
    
    $uploadMaxSize = ini_get('upload_max_filesize');
    $postMaxSize = ini_get('post_max_size');
    $maxExecutionTime = ini_get('max_execution_time');
    
    echo "<div class='test-item success'><span class='icon'>ℹ️</span> <code>upload_max_filesize</code> = {$uploadMaxSize}</div>";
    echo "<div class='test-item success'><span class='icon'>ℹ️</span> <code>post_max_size</code> = {$postMaxSize}</div>";
    echo "<div class='test-item success'><span class='icon'>ℹ️</span> <code>max_execution_time</code> = {$maxExecutionTime}s</div>";
    
    $uploadBytes = parse_size($uploadMaxSize);
    $chatMaxBytes = CHAT_UPLOAD_MAX_SIZE;
    
    if ($uploadBytes < $chatMaxBytes) {
        echo "<div class='test-item warning'><span class='icon'>⚠️</span> El límite de PHP ({$uploadMaxSize}) es menor que el configurado en el chat (" . format_bytes($chatMaxBytes) . ")</div>";
    }
    echo '</div>';
    
    // Prueba 7: API Test
    echo '<div class="test-section">';
    echo '<h2>🔌 Prueba de API</h2>';
    
    try {
        $apiUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/chat/api.php?action=get_unread_count';
        $context = stream_context_create([
            'http' => [
                'header' => 'Cookie: ' . $_SERVER['HTTP_COOKIE']
            ]
        ]);
        $response = @file_get_contents($apiUrl, false, $context);
        
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['success']) && $data['success']) {
                echo "<div class='test-item success'><span class='icon'>✅</span> API responde correctamente (mensajes no leídos: {$data['unread_count']})</div>";
            } else {
                echo "<div class='test-item warning'><span class='icon'>⚠️</span> API responde pero con error: " . ($data['error'] ?? 'desconocido') . "</div>";
            }
        } else {
            echo "<div class='test-item error'><span class='icon'>❌</span> No se pudo conectar a la API</div>";
        }
    } catch (Exception $e) {
        echo "<div class='test-item error'><span class='icon'>❌</span> Error al probar API: {$e->getMessage()}</div>";
    }
    echo '</div>';
    
    // Resumen final
    echo '<div class="test-section" style="background: linear-gradient(135deg, rgba(6, 182, 212, 0.2) 0%, rgba(8, 145, 178, 0.2) 100%);">';
    echo '<h2>📋 Resumen</h2>';
    
    $allTablesOk = !in_array(false, $results['tables'] ?? [], true);
    $allDirsOk = !in_array(false, $results['dirs'] ?? [], true);
    
    if ($allTablesOk && $allDirsOk && $permCount > 0) {
        echo "<div class='test-item success' style='font-size: 18px; padding: 20px;'>";
        echo "<span class='icon' style='font-size: 24px;'>🎉</span> ";
        echo "<strong>¡Sistema de chat instalado correctamente!</strong>";
        echo "<p style='margin-top: 10px; margin-bottom: 0;'>Puedes empezar a usar el chat ahora.</p>";
        echo "</div>";
    } else {
        echo "<div class='test-item warning' style='font-size: 18px; padding: 20px;'>";
        echo "<span class='icon' style='font-size: 24px;'>⚠️</span> ";
        echo "<strong>Hay algunos problemas que debes resolver</strong>";
        echo "<p style='margin-top: 10px; margin-bottom: 0;'>Revisa los errores arriba y corrígelos antes de usar el sistema.</p>";
        echo "</div>";
    }
    
    echo '</div>';
    
    // Funciones auxiliares
    function parse_size($size) {
        $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
        $size = preg_replace('/[^0-9\.]/', '', $size);
        if ($unit) {
            return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
        }
        return round($size);
    }
    
    function format_bytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    ?>
    
    <div style="text-align: center; margin-top: 40px; padding: 20px; color: #64748b;">
        <p>Sistema de Chat v1.0 • Evallish BPO Control</p>
    </div>
</body>
</html>

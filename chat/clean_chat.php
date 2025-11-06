<?php
/**
 * Script para limpiar completamente el chat
 * Elimina todos los datos de la base de datos y archivos físicos
 * 
 * ADVERTENCIA: Esta acción no se puede deshacer
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar que el usuario esté autenticado y sea administrador
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    die('❌ Solo los administradores pueden ejecutar este script');
}

// Verificar confirmación
$confirm = $_GET['confirm'] ?? '';
if ($confirm !== 'YES_DELETE_ALL') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Limpiar Chat - Confirmación</title>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
            .warning { background: #fee; border: 2px solid #c00; padding: 20px; border-radius: 8px; }
            .warning h1 { color: #c00; margin-top: 0; }
            .btn { display: inline-block; padding: 12px 24px; margin: 10px 5px; text-decoration: none; 
                   border-radius: 5px; font-weight: bold; }
            .btn-danger { background: #c00; color: white; }
            .btn-danger:hover { background: #a00; }
            .btn-safe { background: #0a0; color: white; }
            .btn-safe:hover { background: #080; }
            ul { line-height: 1.8; }
        </style>
    </head>
    <body>
        <div class="warning">
            <h1>⚠️ ADVERTENCIA: Esta acción es irreversible</h1>
            
            <p><strong>Este script eliminará:</strong></p>
            <ul>
                <li>Todas las conversaciones</li>
                <li>Todos los mensajes</li>
                <li>Todos los archivos adjuntos (físicos y registros)</li>
                <li>Todas las reacciones y notificaciones</li>
                <li>Todo el historial del chat</li>
            </ul>
            
            <p><strong>NO se eliminarán:</strong></p>
            <ul>
                <li>Permisos de chat de usuarios</li>
                <li>Configuración de usuarios</li>
            </ul>
            
            <p style="color: #c00; font-weight: bold; font-size: 18px;">
                ¿Estás ABSOLUTAMENTE SEGURO de que quieres continuar?
            </p>
            
            <a href="?confirm=YES_DELETE_ALL" class="btn btn-danger" 
               onclick="return confirm('¿REALMENTE seguro? Esta acción NO se puede deshacer.')">
                SÍ, ELIMINAR TODO
            </a>
            <a href="../dashboard.php" class="btn btn-safe">
                NO, Cancelar y volver
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Limpiando Chat...</title></head><body>";
echo "<h1>🧹 Limpiando sistema de chat...</h1>";
echo "<pre>";

try {
    // 1. Contar registros antes de eliminar
    echo "\n📊 ESTADÍSTICAS ANTES DE LIMPIAR:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    $tables = [
        'chat_conversations' => 'Conversaciones',
        'chat_participants' => 'Participantes',
        'chat_messages' => 'Mensajes',
        'chat_attachments' => 'Archivos adjuntos',
        'chat_reactions' => 'Reacciones',
        'chat_read_receipts' => 'Lecturas',
        'chat_notifications' => 'Notificaciones',
        'chat_typing' => 'Estados de escritura',
        'chat_online_status' => 'Estados online',
        'chat_scheduled_messages' => 'Mensajes programados',
        'chat_user_status' => 'Estados de usuario'
    ];
    
    foreach ($tables as $table => $name) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo sprintf("%-30s: %d registros\n", $name, $count);
    }
    
    // 2. Eliminar archivos físicos
    echo "\n\n🗑️  ELIMINANDO ARCHIVOS FÍSICOS:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    $uploadDirs = [
        'documents',
        'images', 
        'videos',
        'audio',
        'thumbnails'
    ];
    
    $totalFiles = 0;
    $totalSize = 0;
    
    foreach ($uploadDirs as $dir) {
        $dirPath = CHAT_UPLOAD_DIR . $dir . '/';
        if (is_dir($dirPath)) {
            $files = glob($dirPath . '*');
            $dirFiles = 0;
            $dirSize = 0;
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $dirSize += filesize($file);
                    unlink($file);
                    $dirFiles++;
                    $totalFiles++;
                }
            }
            
            $totalSize += $dirSize;
            $sizeFormatted = $dirSize > 1024*1024 ? 
                round($dirSize / (1024*1024), 2) . ' MB' : 
                round($dirSize / 1024, 2) . ' KB';
            
            echo "✓ $dir/: $dirFiles archivos eliminados ($sizeFormatted)\n";
        } else {
            echo "⚠ $dir/: directorio no existe\n";
        }
    }
    
    $totalSizeFormatted = $totalSize > 1024*1024 ? 
        round($totalSize / (1024*1024), 2) . ' MB' : 
        round($totalSize / 1024, 2) . ' KB';
    
    echo "\n✓ Total: $totalFiles archivos eliminados ($totalSizeFormatted liberados)\n";
    
    // 3. Limpiar base de datos
    echo "\n\n💾 LIMPIANDO BASE DE DATOS:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    foreach (array_keys($tables) as $table) {
        $pdo->exec("TRUNCATE TABLE $table");
        echo "✓ Tabla $table limpiada\n";
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // 4. Verificar que todo esté limpio
    echo "\n\n📊 ESTADÍSTICAS DESPUÉS DE LIMPIAR:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    foreach ($tables as $table => $name) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo sprintf("%-30s: %d registros\n", $name, $count);
    }
    
    echo "\n\n✅ PROCESO COMPLETADO EXITOSAMENTE\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "El chat ha sido completamente limpiado y está listo para usar desde cero.\n";
    echo "Los permisos y configuraciones de usuarios se mantuvieron intactos.\n\n";
    
    echo "<a href='../dashboard.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; ";
    echo "background: #0a0; color: white; text-decoration: none; border-radius: 5px;'>";
    echo "← Volver al Dashboard</a>\n";
    
} catch (Exception $e) {
    echo "\n\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre></body></html>";
?>

<?php
/**
 * Archivo de diagnóstico para el chat en cPanel
 * Accede a: tu-dominio.com/chat/test_chat.php
 */

// Iniciar sesión si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico del Chat</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .section {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .success { color: #22c55e; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        h2 { margin-top: 0; border-bottom: 2px solid #3b82f6; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f3f4f6; }
        pre { background: #1f2937; color: #fff; padding: 15px; border-radius: 4px; overflow-x: auto; }
        .button { 
            display: inline-block;
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin: 5px;
        }
        .button:hover { background: #2563eb; }
    </style>
</head>
<body>
    <h1>🔍 Diagnóstico del Sistema de Chat</h1>

    <!-- 1. Información de PHP -->
    <div class="section">
        <h2>1. Información de PHP</h2>
        <table>
            <tr>
                <th>Parámetro</th>
                <th>Valor</th>
            </tr>
            <tr>
                <td>Versión de PHP</td>
                <td class="<?php echo version_compare(PHP_VERSION, '7.4.0', '>=') ? 'success' : 'error'; ?>">
                    <?php echo PHP_VERSION; ?>
                    <?php if (version_compare(PHP_VERSION, '7.4.0', '>=')): ?>
                        ✓ Compatible
                    <?php else: ?>
                        ✗ Requiere PHP 7.4 o superior
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td>Extensión PDO</td>
                <td class="<?php echo extension_loaded('pdo') ? 'success' : 'error'; ?>">
                    <?php echo extension_loaded('pdo') ? '✓ Instalada' : '✗ No instalada'; ?>
                </td>
            </tr>
            <tr>
                <td>Extensión PDO MySQL</td>
                <td class="<?php echo extension_loaded('pdo_mysql') ? 'success' : 'error'; ?>">
                    <?php echo extension_loaded('pdo_mysql') ? '✓ Instalada' : '✗ No instalada'; ?>
                </td>
            </tr>
            <tr>
                <td>JSON</td>
                <td class="<?php echo function_exists('json_decode') ? 'success' : 'error'; ?>">
                    <?php echo function_exists('json_decode') ? '✓ Habilitado' : '✗ No habilitado'; ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- 2. Permisos de Carpetas -->
    <div class="section">
        <h2>2. Permisos de Carpetas</h2>
        <?php
        $directories = [
            'uploads' => __DIR__ . '/uploads',
            'uploads/images' => __DIR__ . '/uploads/images',
            'uploads/videos' => __DIR__ . '/uploads/videos',
            'uploads/documents' => __DIR__ . '/uploads/documents',
            'uploads/audio' => __DIR__ . '/uploads/audio',
            'uploads/thumbnails' => __DIR__ . '/uploads/thumbnails',
        ];
        ?>
        <table>
            <tr>
                <th>Carpeta</th>
                <th>Existe</th>
                <th>Permisos</th>
                <th>Escribible</th>
            </tr>
            <?php foreach ($directories as $name => $path): ?>
            <tr>
                <td><?php echo $name; ?></td>
                <td class="<?php echo file_exists($path) ? 'success' : 'error'; ?>">
                    <?php echo file_exists($path) ? '✓ Sí' : '✗ No'; ?>
                </td>
                <td>
                    <?php 
                    if (file_exists($path)) {
                        echo substr(sprintf('%o', fileperms($path)), -4);
                    } else {
                        echo '-';
                    }
                    ?>
                </td>
                <td class="<?php echo (file_exists($path) && is_writable($path)) ? 'success' : 'error'; ?>">
                    <?php echo (file_exists($path) && is_writable($path)) ? '✓ Sí' : '✗ No'; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <?php
        // Intentar crear carpetas si no existen
        $errors = [];
        foreach ($directories as $name => $path) {
            if (!file_exists($path)) {
                if (@mkdir($path, 0755, true)) {
                    echo "<p class='success'>✓ Carpeta '$name' creada exitosamente</p>";
                } else {
                    $errors[] = $name;
                }
            }
        }
        if (!empty($errors)) {
            echo "<p class='error'>✗ No se pudieron crear las carpetas: " . implode(', ', $errors) . "</p>";
            echo "<p class='warning'>⚠ Crea manualmente estas carpetas con permisos 755</p>";
        }
        ?>
    </div>

    <!-- 3. Conexión a Base de Datos -->
    <div class="section">
        <h2>3. Conexión a Base de Datos</h2>
        <?php
        try {
            require_once __DIR__ . '/../db.php';
            echo "<p class='success'>✓ Conexión exitosa a la base de datos</p>";
            
            // Verificar tablas del chat
            $tables = [
                'chat_conversations',
                'chat_participants',
                'chat_messages',
                'chat_attachments',
                'chat_reactions',
                'chat_typing',
                'chat_read_receipts',
                'chat_online_status',
                'chat_notifications',
                'chat_settings'
            ];
            
            echo "<h3>Tablas del Chat:</h3>";
            echo "<table>";
            echo "<tr><th>Tabla</th><th>Estado</th><th>Registros</th></tr>";
            
            foreach ($tables as $table) {
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
                    $count = $stmt->fetchColumn();
                    echo "<tr><td>$table</td><td class='success'>✓ Existe</td><td>$count</td></tr>";
                } catch (Exception $e) {
                    echo "<tr><td>$table</td><td class='error'>✗ No existe</td><td>-</td></tr>";
                }
            }
            echo "</table>";
            
        } catch (Exception $e) {
            echo "<p class='error'>✗ Error de conexión: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        ?>
    </div>

    <!-- 4. Sesión de Usuario -->
    <div class="section">
        <h2>4. Sesión de Usuario</h2>
        <?php if (isset($_SESSION['user_id'])): ?>
            <p class="success">✓ Sesión activa</p>
            <table>
                <tr><th>Variable</th><th>Valor</th></tr>
                <tr><td>user_id</td><td><?php echo $_SESSION['user_id']; ?></td></tr>
                <?php if (isset($_SESSION['username'])): ?>
                <tr><td>username</td><td><?php echo htmlspecialchars($_SESSION['username']); ?></td></tr>
                <?php endif; ?>
                <?php if (isset($_SESSION['name'])): ?>
                <tr><td>name</td><td><?php echo htmlspecialchars($_SESSION['name']); ?></td></tr>
                <?php endif; ?>
            </table>
        <?php else: ?>
            <p class="error">✗ No hay sesión activa</p>
            <p class="warning">⚠ Debes iniciar sesión para usar el chat</p>
        <?php endif; ?>
    </div>

    <!-- 5. Prueba de API -->
    <div class="section">
        <h2>5. Prueba de API del Chat</h2>
        <p>Estos botones prueban diferentes endpoints de la API:</p>
        <a href="#" onclick="testAPI('get_conversations'); return false;" class="button">Test: Get Conversations</a>
        <a href="#" onclick="testAPI('get_online_users'); return false;" class="button">Test: Get Online Users</a>
        
        <h3>Resultado de la prueba:</h3>
        <pre id="apiResult">Haz clic en un botón para probar...</pre>
    </div>

    <!-- 6. Headers y Configuración del Servidor -->
    <div class="section">
        <h2>6. Headers y Configuración del Servidor</h2>
        <table>
            <tr>
                <th>Variable</th>
                <th>Valor</th>
            </tr>
            <tr>
                <td>SERVER_SOFTWARE</td>
                <td><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'No disponible'; ?></td>
            </tr>
            <tr>
                <td>DOCUMENT_ROOT</td>
                <td><?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'No disponible'; ?></td>
            </tr>
            <tr>
                <td>HTTP_HOST</td>
                <td><?php echo $_SERVER['HTTP_HOST'] ?? 'No disponible'; ?></td>
            </tr>
            <tr>
                <td>REQUEST_URI</td>
                <td><?php echo $_SERVER['REQUEST_URI'] ?? 'No disponible'; ?></td>
            </tr>
            <tr>
                <td>PHP_SELF</td>
                <td><?php echo $_SERVER['PHP_SELF'] ?? 'No disponible'; ?></td>
            </tr>
        </table>
    </div>

    <!-- 7. Configuración PHP -->
    <div class="section">
        <h2>7. Configuración PHP Relevante</h2>
        <table>
            <tr>
                <th>Directiva</th>
                <th>Valor</th>
            </tr>
            <tr>
                <td>display_errors</td>
                <td><?php echo ini_get('display_errors') ? 'On' : 'Off'; ?></td>
            </tr>
            <tr>
                <td>error_reporting</td>
                <td><?php echo ini_get('error_reporting'); ?></td>
            </tr>
            <tr>
                <td>upload_max_filesize</td>
                <td><?php echo ini_get('upload_max_filesize'); ?></td>
            </tr>
            <tr>
                <td>post_max_size</td>
                <td><?php echo ini_get('post_max_size'); ?></td>
            </tr>
            <tr>
                <td>max_execution_time</td>
                <td><?php echo ini_get('max_execution_time'); ?> segundos</td>
            </tr>
        </table>
    </div>

    <script>
        async function testAPI(action) {
            const resultEl = document.getElementById('apiResult');
            resultEl.textContent = 'Probando...';
            
            try {
                const response = await fetch('api.php?action=' + action);
                const text = await response.text();
                
                resultEl.textContent = 'Status: ' + response.status + ' ' + response.statusText + '\n\n';
                resultEl.textContent += 'Response:\n' + text;
                
                // Intentar parsear como JSON
                try {
                    const json = JSON.parse(text);
                    resultEl.textContent += '\n\nJSON válido ✓\n' + JSON.stringify(json, null, 2);
                } catch (e) {
                    resultEl.textContent += '\n\n⚠ No es JSON válido';
                }
            } catch (error) {
                resultEl.textContent = 'Error: ' + error.message;
            }
        }
    </script>

    <div style="margin-top: 40px; padding: 20px; background: #fef3c7; border-radius: 8px;">
        <h3>📋 Instrucciones para Resolver Problemas</h3>
        <ol>
            <li><strong>Si las carpetas no existen o no son escribibles:</strong>
                <ul>
                    <li>Conéctate por FTP o File Manager de cPanel</li>
                    <li>Navega a la carpeta <code>/chat/uploads/</code></li>
                    <li>Crea las subcarpetas: images, videos, documents, audio, thumbnails</li>
                    <li>Cambia los permisos a 755 (o 777 si 755 no funciona)</li>
                </ul>
            </li>
            <li><strong>Si no hay sesión activa:</strong>
                <ul>
                    <li>Inicia sesión en la aplicación</li>
                    <li>Regresa a esta página para verificar</li>
                </ul>
            </li>
            <li><strong>Si las tablas no existen:</strong>
                <ul>
                    <li>Ejecuta el archivo SQL de instalación en phpMyAdmin</li>
                    <li>Archivo: <code>INSTALL_CHAT.sql</code></li>
                </ul>
            </li>
            <li><strong>Si la API falla:</strong>
                <ul>
                    <li>Revisa el resultado de las pruebas arriba</li>
                    <li>Verifica los logs de error de PHP en cPanel</li>
                    <li>Asegúrate de que db.php tenga las credenciales correctas</li>
                </ul>
            </li>
        </ol>
    </div>
</body>
</html>

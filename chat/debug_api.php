<?php
/**
 * Archivo para depurar errores del chat en producción
 * Accede a: tu-dominio.com/chat/debug_api.php
 * 
 * Este archivo muestra los errores PHP y el estado de las peticiones
 */

// Habilitar todos los errores
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Iniciar sesión
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Información del request
$method = $_SERVER['REQUEST_METHOD'];
$headers = getallheaders();
$rawInput = file_get_contents('php://input');
$jsonData = $rawInput ? json_decode($rawInput, true) : null;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Debug API Chat</title>
    <style>
        body { 
            font-family: monospace; 
            background: #1a1a1a; 
            color: #0f0; 
            padding: 20px; 
        }
        .section { 
            background: #000; 
            border: 1px solid #0f0; 
            padding: 15px; 
            margin-bottom: 20px; 
        }
        h2 { color: #0ff; }
        pre { 
            background: #222; 
            padding: 10px; 
            overflow-x: auto; 
            border-left: 3px solid #0f0;
        }
        .error { color: #f00; }
        .success { color: #0f0; }
        .button {
            background: #0f0;
            color: #000;
            border: none;
            padding: 10px 20px;
            margin: 5px;
            cursor: pointer;
            font-family: monospace;
            font-weight: bold;
        }
        .button:hover { background: #0ff; }
    </style>
</head>
<body>
    <h1>🐛 DEBUG API CHAT</h1>

    <!-- Información de la petición actual -->
    <div class="section">
        <h2>📥 REQUEST INFO</h2>
        <pre>
METHOD: <?php echo $method; ?>

URI: <?php echo $_SERVER['REQUEST_URI'] ?? 'N/A'; ?>

QUERY STRING: <?php echo $_SERVER['QUERY_STRING'] ?? 'N/A'; ?>
        </pre>
    </div>

    <!-- Headers -->
    <div class="section">
        <h2>📋 HEADERS</h2>
        <pre><?php print_r($headers); ?></pre>
    </div>

    <!-- Session -->
    <div class="section">
        <h2>🔐 SESSION</h2>
        <?php if (isset($_SESSION['user_id'])): ?>
            <pre class="success">✓ Sesión activa
<?php print_r($_SESSION); ?></pre>
        <?php else: ?>
            <pre class="error">✗ No hay sesión activa</pre>
        <?php endif; ?>
    </div>

    <!-- Raw Input -->
    <div class="section">
        <h2>📄 RAW INPUT (php://input)</h2>
        <pre><?php echo $rawInput ?: '(vacío)'; ?></pre>
    </div>

    <!-- JSON Parsed -->
    <div class="section">
        <h2>🔄 JSON PARSED</h2>
        <pre><?php 
        if ($jsonData) {
            print_r($jsonData);
        } else {
            echo "(no es JSON válido o está vacío)";
        }
        ?></pre>
    </div>

    <!-- GET / POST -->
    <div class="section">
        <h2>📤 $_GET</h2>
        <pre><?php print_r($_GET); ?></pre>
    </div>

    <div class="section">
        <h2>📤 $_POST</h2>
        <pre><?php print_r($_POST); ?></pre>
    </div>

    <!-- Pruebas de API -->
    <div class="section">
        <h2>🧪 PRUEBAS DE API</h2>
        <p>Enviar petición a la API:</p>
        
        <button class="button" onclick="testSendMessage()">Test: Enviar Mensaje</button>
        <button class="button" onclick="testGetConversations()">Test: Get Conversations</button>
        <button class="button" onclick="testCreateConversation()">Test: Crear Conversación</button>
        
        <h3>Resultado:</h3>
        <pre id="testResult">Haz clic en un botón para probar...</pre>
    </div>

    <!-- Conexión DB -->
    <div class="section">
        <h2>🗄️ DATABASE TEST</h2>
        <?php
        try {
            require_once __DIR__ . '/../db.php';
            echo "<pre class='success'>✓ Conexión a DB exitosa</pre>";
            
            // Test query
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM chat_messages");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<pre>Total de mensajes en DB: " . $result['total'] . "</pre>";
            
        } catch (Exception $e) {
            echo "<pre class='error'>✗ Error de DB: " . htmlspecialchars($e->getMessage()) . "</pre>";
        }
        ?>
    </div>

    <!-- Logs de error PHP -->
    <div class="section">
        <h2>📝 PHP ERROR LOG (últimas 20 líneas)</h2>
        <pre><?php
        $errorLog = ini_get('error_log');
        if ($errorLog && file_exists($errorLog)) {
            $lines = file($errorLog);
            $lastLines = array_slice($lines, -20);
            echo htmlspecialchars(implode('', $lastLines));
        } else {
            echo "No se encontró el archivo de log o no está configurado.\n";
            echo "Ubicación configurada: " . ($errorLog ?: 'N/A');
        }
        ?></pre>
    </div>

    <script>
        async function testSendMessage() {
            const resultEl = document.getElementById('testResult');
            resultEl.textContent = '⏳ Enviando mensaje de prueba...';
            
            const testData = {
                action: 'send_message',
                conversation_id: 1, // Cambia esto según tu DB
                message_text: 'Mensaje de prueba desde debug: ' + new Date().toISOString()
            };
            
            resultEl.textContent += '\n\n📤 Datos enviados:\n' + JSON.stringify(testData, null, 2);
            
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(testData),
                    cache: 'no-cache'
                });
                
                const responseText = await response.text();
                
                resultEl.textContent += `\n\n📥 Respuesta:\nStatus: ${response.status} ${response.statusText}\n\n`;
                resultEl.textContent += `Headers:\n${JSON.stringify(Object.fromEntries(response.headers), null, 2)}\n\n`;
                resultEl.textContent += `Response Body:\n${responseText}\n\n`;
                
                try {
                    const json = JSON.parse(responseText);
                    resultEl.textContent += `Parsed JSON:\n${JSON.stringify(json, null, 2)}`;
                    
                    if (json.success) {
                        resultEl.textContent += '\n\n✅ ÉXITO: El mensaje se envió correctamente';
                    } else {
                        resultEl.textContent += '\n\n❌ ERROR: ' + (json.error || 'Error desconocido');
                        if (json.debug) {
                            resultEl.textContent += '\n\nDebug info:\n' + JSON.stringify(json.debug, null, 2);
                        }
                    }
                } catch (e) {
                    resultEl.textContent += `⚠️ No es JSON válido: ${e.message}`;
                }
            } catch (error) {
                resultEl.textContent = `❌ Error de red: ${error.message}`;
            }
        }
        
        async function testGetConversations() {
            const resultEl = document.getElementById('testResult');
            resultEl.textContent = '⏳ Obteniendo conversaciones...';
            
            try {
                const response = await fetch('api.php?action=get_conversations');
                const responseText = await response.text();
                
                resultEl.textContent = `Status: ${response.status} ${response.statusText}\n\n`;
                resultEl.textContent += `Response:\n${responseText}\n\n`;
                
                try {
                    const json = JSON.parse(responseText);
                    resultEl.textContent += `Parsed JSON:\n${JSON.stringify(json, null, 2)}`;
                } catch (e) {
                    resultEl.textContent += `⚠️ No es JSON válido`;
                }
            } catch (error) {
                resultEl.textContent = `❌ Error: ${error.message}`;
            }
        }
        
        async function testCreateConversation() {
            const resultEl = document.getElementById('testResult');
            resultEl.textContent = '⏳ Creando conversación de prueba...';
            
            const testData = {
                action: 'create_conversation',
                participants: [1, 2], // Cambia según tus user IDs
                name: 'Test Conversation ' + Date.now()
            };
            
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(testData)
                });
                
                const responseText = await response.text();
                
                resultEl.textContent = `Status: ${response.status} ${response.statusText}\n\n`;
                resultEl.textContent += `Response:\n${responseText}\n\n`;
                
                try {
                    const json = JSON.parse(responseText);
                    resultEl.textContent += `Parsed JSON:\n${JSON.stringify(json, null, 2)}`;
                } catch (e) {
                    resultEl.textContent += `⚠️ No es JSON válido`;
                }
            } catch (error) {
                resultEl.textContent = `❌ Error: ${error.message}`;
            }
        }
    </script>
</body>
</html>

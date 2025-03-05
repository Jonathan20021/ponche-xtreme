<?php
include 'db.php';
date_default_timezone_set('America/Santo_Domingo');

$last_type = null;
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $type = $_POST['type'];

    // Validar si el usuario existe
    $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        $user_id = $user['id'];
        $full_name = $user['full_name'];

        // Validar si Entry o Exit ya se registraron en el día
        if ($type === 'Entry' || $type === 'Exit') {
            $check_stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM attendance 
                WHERE user_id = ? AND type = ? AND DATE(timestamp) = CURDATE()
            ");
            $check_stmt->execute([$user_id, $type]);
            $exists = $check_stmt->fetchColumn();

            if ($exists > 0) {
                $error = "You can only register '$type' once per day.";
            }
        }

        if (!$error) {
            // Registrar el punch
            $ip_address = $_SERVER['REMOTE_ADDR'] === '::1' ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'];
            $insert_stmt = $pdo->prepare("
                INSERT INTO attendance (user_id, type, ip_address, timestamp) 
                VALUES (?, ?, ?, NOW())
            ");
            $insert_stmt->execute([$user_id, $type, $ip_address]);
            $success = "Attendance recorded successfully.";

            // Obtener el último registro
            $last_stmt = $pdo->prepare("
                SELECT type, timestamp 
                FROM attendance 
                WHERE user_id = ? 
                ORDER BY timestamp DESC LIMIT 1
            ");
            $last_stmt->execute([$user_id]);
            $last_type = $last_stmt->fetch(PDO::FETCH_ASSOC);

            // Enviar datos a Slack
            $slack_webhook_url = 'https://hooks.slack.com/services/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';
            $current_timestamp = date('Y-m-d H:i:s');

            $message = [
                "text" => "New Punch Recorded",
                "attachments" => [
                    [
                        "fields" => [
                            ["title" => "Full Name", "value" => $full_name, "short" => true],
                            ["title" => "Username", "value" => $username, "short" => true],
                            ["title" => "Type", "value" => $type, "short" => true],
                            ["title" => "IP Address", "value" => $ip_address, "short" => true],
                            ["title" => "Timestamp", "value" => $current_timestamp, "short" => true],
                        ]
                    ]
                ]
            ];

            $ch = curl_init($slack_webhook_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_exec($ch);
            curl_close($ch);
        }
    } else {
        $error = "User not found.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <title>Register Attendance</title>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto py-10">
        <h1 class="text-4xl font-bold text-center mb-6">Register Attendance</h1>
        <div class="max-w-2xl mx-auto bg-white p-6 rounded-lg shadow-lg">
            <?php if (isset($success)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            <?php if ($last_type): ?>
                <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4">
                    <strong>Last Type:</strong> <?= htmlspecialchars($last_type['type']) ?> 
                    <br> 
                    Registered at: <?= date('m/d/Y h:i A', strtotime($last_type['timestamp'])) ?>
                </div>
            <?php endif; ?>

            <!-- Mensaje de estado del almacenamiento -->
            <div id="storageStatus" class="hidden mb-4"></div>

            <form method="POST" class="space-y-4" id="punchForm">
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700">Username:</label>
                    <div class="flex space-x-2">
                        <input type="text" id="username" name="username" class="w-full mt-1 p-3 border rounded" placeholder="Enter username" required>
                        <button type="button" id="clearUsername" class="mt-1 px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">
                            Clear
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Select Attendance Type:</label>
                    <div class="grid grid-cols-3 gap-4 mt-4">
                        <button type="submit" name="type" value="Entry" class="bg-green-500 text-white py-3 rounded hover:bg-green-600">Entry</button>
                        <button type="submit" name="type" value="Break" class="bg-blue-500 text-white py-3 rounded hover:bg-blue-600">Break</button>
                        <button type="submit" name="type" value="Lunch" class="bg-yellow-500 text-white py-3 rounded hover:bg-yellow-600">Lunch</button>
                        <button type="submit" name="type" value="Meeting" class="bg-purple-500 text-white py-3 rounded hover:bg-purple-600">Meeting</button>
                        <button type="submit" name="type" value="Follow Up" class="bg-indigo-500 text-white py-3 rounded hover:bg-indigo-600">Follow Up</button>
                        <button type="submit" name="type" value="Ready" class="bg-purple-500 text-white py-3 rounded hover:bg-purple-600">Ready</button>
                        <button type="submit" name="type" value="Exit" class="bg-red-500 text-white py-3 rounded hover:bg-red-600">Exit</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Función para mostrar mensajes de estado
        function showStatus(message, isError = false) {
            const statusDiv = document.getElementById('storageStatus');
            statusDiv.className = isError 
                ? 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4'
                : 'bg-green-100 border-l-4 border-green-500 text-green-700 p-4';
            statusDiv.textContent = message;
            statusDiv.classList.remove('hidden');
        }

        // Función para verificar si localStorage está disponible
        function isLocalStorageAvailable() {
            try {
                const test = 'test';
                localStorage.setItem(test, test);
                localStorage.removeItem(test);
                return true;
            } catch(e) {
                return false;
            }
        }

        // Función para guardar el username usando diferentes métodos
        function saveUsername(username) {
            try {
                if (isLocalStorageAvailable()) {
                    localStorage.setItem('savedUsername', username);
                    showStatus('Username saved successfully!');
                } else {
                    // Alternativa usando cookies si localStorage no está disponible
                    document.cookie = `savedUsername=${username};path=/;max-age=31536000`; // 1 año
                    showStatus('Username saved using cookies');
                }
            } catch (error) {
                console.error('Error saving username:', error);
                showStatus('Error saving username. Please check browser settings.', true);
            }
        }

        // Función para obtener el username guardado
        function getSavedUsername() {
            try {
                if (isLocalStorageAvailable()) {
                    return localStorage.getItem('savedUsername');
                } else {
                    // Intentar obtener de cookies
                    const match = document.cookie.match(new RegExp('(^| )savedUsername=([^;]+)'));
                    return match ? match[2] : null;
                }
            } catch (error) {
                console.error('Error getting username:', error);
                showStatus('Error retrieving saved username', true);
                return null;
            }
        }

        // Función para limpiar el username guardado
        function clearSavedUsername() {
            try {
                if (isLocalStorageAvailable()) {
                    localStorage.removeItem('savedUsername');
                } else {
                    document.cookie = 'savedUsername=;path=/;expires=Thu, 01 Jan 1970 00:00:01 GMT;';
                }
                document.getElementById('username').value = '';
                showStatus('Username cleared successfully!');
            } catch (error) {
                console.error('Error clearing username:', error);
                showStatus('Error clearing username', true);
            }
        }

        // Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            try {
                // Cargar username guardado
                const savedUsername = getSavedUsername();
                if (savedUsername) {
                    document.getElementById('username').value = savedUsername;
                    showStatus('Saved username loaded');
                }

                // Configurar el formulario
                document.getElementById('punchForm').addEventListener('submit', function(e) {
                    const username = document.getElementById('username').value;
                    if (username) {
                        saveUsername(username);
                    }
                });

                // Configurar el botón de limpiar
                document.getElementById('clearUsername').addEventListener('click', clearSavedUsername);

            } catch (error) {
                console.error('Error in initialization:', error);
                showStatus('Error initializing username storage', true);
            }
        });
    </script>
</body>
</html>


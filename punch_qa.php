<?php
include 'db.php';
date_default_timezone_set('America/Santo_Domingo');

$last_type = null;
$error = null;
$success = null;
$punch_history = [];
$show_last_punch = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required POST data
    if (!isset($_POST['username']) || !isset($_POST['type'])) {
        $error = "Missing required fields.";
    } else {
        $username = trim($_POST['username']);
        $type = trim($_POST['type']);

        // Validate username format
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $error = "Invalid username format. Only letters, numbers, and underscores are allowed.";
        } else {
            // Validate if user exists
            $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user) {
                $user_id = $user['id'];
                $full_name = $user['full_name'];

                // Validate if Entry or Exit already registered for the day
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

                // Validar secuencia ENTRY/EXIT
                if (!$error) {
                    require_once 'lib/authorization_functions.php';
                    $sequenceValidation = validateEntryExitSequence($pdo, $user_id, $type);
                    if (!$sequenceValidation['valid']) {
                        $error = $sequenceValidation['message'];
                    }
                }

                if (!$error) {
                    // Register the punch
                    $ip_address = $_SERVER['REMOTE_ADDR'] === '::1' ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'];
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO attendance (user_id, type, ip_address, timestamp) 
                        VALUES (?, ?, ?, NOW())
                    ");
                    $insert_stmt->execute([$user_id, $type, $ip_address]);
                    
                    // Log attendance registration
                    require_once 'lib/logging_functions.php';
                    $recordId = $pdo->lastInsertId();
                    log_custom_action(
                        $pdo,
                        $user_id,
                        $_SESSION['full_name'],
                        $_SESSION['role'],
                        'attendance',
                        'create',
                        "Registro de asistencia QA: {$type}",
                        'attendance_record',
                        $recordId,
                        ['type' => $type, 'ip_address' => $ip_address]
                    );
                    $success = "Attendance recorded successfully.";
                    $show_last_punch = true;

                    // Get last 5 records for history
                    $history_stmt = $pdo->prepare("
                        SELECT type, timestamp 
                        FROM attendance 
                        WHERE user_id = ? 
                        ORDER BY timestamp DESC LIMIT 5
                    ");
                    $history_stmt->execute([$user_id]);
                    $punch_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Get last record
                    $last_type = $punch_history[0];

                    // Send data to Slack
                    sendSlackNotification($full_name, $username, $type, $ip_address);
                }
            } else {
                $error = "User not found.";
            }
        }
    }
}

function sendSlackNotification($full_name, $username, $type, $ip_address) {
    $slack_webhook_url = 'https://hooks.slack.com/services/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';
    $current_timestamp = date('Y-m-d H:i:s');

    $message = [
        "text" => "New Punch Recorded",
        "attachments" => [
            [
                "color" => getPunchColor($type),
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Añadido para evitar que muestre el "ok"
    curl_exec($ch);
    curl_close($ch);
}

function getPunchColor($type) {
    $colors = [
        'Entry' => '#22c55e',
        'Exit' => '#ef4444',
        'Break' => '#3b82f6',
        'Lunch' => '#eab308',
        'Meeting' => '#a855f7',
        'Follow Up' => '#6366f1',
        'Ready' => '#a855f7'
    ];
    return $colors[$type] ?? '#6b7280';
}

// Obtener el historial inicial si hay un usuario en la sesión
if (isset($_COOKIE['savedUsername'])) {
    $username = $_COOKIE['savedUsername'];
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user) {
        $history_stmt = $pdo->prepare("
            SELECT type, timestamp 
            FROM attendance 
            WHERE user_id = ? 
            ORDER BY timestamp DESC LIMIT 5
        ");
        $history_stmt->execute([$user['id']]);
        $punch_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <title>Register Attendance</title>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            --success-gradient: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            --danger-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        body {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            min-height: 100vh;
        }

        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .punch-button {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border: none;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .punch-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transform: translateX(-100%);
            transition: 0.5s;
        }

        .punch-button:hover::before {
            transform: translateX(100%);
        }

        .punch-button:active {
            transform: scale(0.95);
        }

        .punch-button.entry { background: var(--success-gradient); }
        .punch-button.exit { background: var(--danger-gradient); }
        .punch-button.break { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .punch-button.lunch { background: linear-gradient(135deg, #eab308 0%, #ca8a04 100%); }
        .punch-button.meeting { background: linear-gradient(135deg, #a855f7 0%, #9333ea 100%); }
        .punch-button.follow-up { background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); }
        .punch-button.ready { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }

        .loading {
            position: relative;
            pointer-events: none;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .status-message {
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .input-focus {
            transition: all 0.3s ease;
        }

        .input-focus:focus {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .history-item {
            transition: all 0.3s ease;
        }

        .history-item:hover {
            transform: translateX(5px);
            background: rgba(255, 255, 255, 0.8);
        }

        .keyboard-shortcut {
            position: absolute;
            top: 5px;
            right: 5px;
            font-size: 0.75rem;
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <div class="container mx-auto py-8 px-4">
        <div class="max-w-4xl mx-auto">
            <div class="text-center mb-10">
                <h1 class="text-5xl font-bold text-gray-800 mb-3 bg-clip-text text-transparent bg-gradient-to-r from-indigo-600 to-purple-600">
                    Register Attendance
                </h1>
                <p class="text-gray-600 text-lg">Quick and easy attendance tracking</p>
            </div>

            <div class="glass-effect rounded-2xl shadow-xl p-8 mb-8">
                <?php if (isset($success)): ?>
                    <div class="status-message bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-3 text-xl"></i>
                            <?= htmlspecialchars($success) ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (isset($error)): ?>
                    <div class="status-message bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-3 text-xl"></i>
                            <?= htmlspecialchars($error) ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($show_last_punch && $last_type): ?>
                    <div class="glass-effect bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 mb-6 rounded-r">
                        <div class="flex items-center justify-between">
                            <div>
                                <i class="fas fa-clock mr-3 text-xl"></i>
                                <strong>Last Punch:</strong> <?= htmlspecialchars($last_type['type']) ?>
                                <br> 
                                <span class="text-sm opacity-75"><?= date('m/d/Y h:i A', strtotime($last_type['timestamp'])) ?></span>
                            </div>
                            <div class="text-sm text-blue-600 bg-blue-100 px-3 py-1 rounded-full">
                                <i class="fas fa-keyboard mr-1"></i>
                                Keyboard shortcuts available
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div id="storageStatus" class="hidden mb-6"></div>

                <form method="POST" class="space-y-8" id="punchForm">
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-3">
                            <i class="fas fa-user mr-2"></i> Username
                        </label>
                        <div class="flex space-x-3">
                            <input type="text" 
                                   id="username" 
                                   name="username" 
                                   class="input-focus flex-1 p-4 border-2 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all" 
                                   placeholder="Enter username" 
                                   required
                                   autocomplete="off"
                                   pattern="[a-zA-Z0-9_]+"
                                   title="Only letters, numbers, and underscores allowed">
                            <button type="button" 
                                    id="clearUsername" 
                                    class="px-6 py-4 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-colors">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">
                            <i class="fas fa-clock mr-2"></i> Select Attendance Type
                        </label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <button type="submit" 
                                    name="type" 
                                    value="Entry" 
                                    class="punch-button entry text-white py-5 rounded-xl flex items-center justify-center space-x-3 relative">
                                <i class="fas fa-sign-in-alt text-xl"></i>
                                <span>Entry</span>
                                <span class="keyboard-shortcut">E</span>
                            </button>
                            <button type="submit" 
                                    name="type" 
                                    value="Break" 
                                    class="punch-button break text-white py-5 rounded-xl flex items-center justify-center space-x-3 relative">
                                <i class="fas fa-coffee text-xl"></i>
                                <span>Break</span>
                                <span class="keyboard-shortcut">B</span>
                            </button>
                            <button type="submit" 
                                    name="type" 
                                    value="Lunch" 
                                    class="punch-button lunch text-white py-5 rounded-xl flex items-center justify-center space-x-3 relative">
                                <i class="fas fa-utensils text-xl"></i>
                                <span>Lunch</span>
                                <span class="keyboard-shortcut">L</span>
                            </button>
                            <button type="submit" 
                                    name="type" 
                                    value="Meeting" 
                                    class="punch-button meeting text-white py-5 rounded-xl flex items-center justify-center space-x-3 relative">
                                <i class="fas fa-users text-xl"></i>
                                <span>Meeting</span>
                                <span class="keyboard-shortcut">M</span>
                            </button>
                            <button type="submit" 
                                    name="type" 
                                    value="Follow Up" 
                                    class="punch-button follow-up text-white py-5 rounded-xl flex items-center justify-center space-x-3 relative">
                                <i class="fas fa-tasks text-xl"></i>
                                <span>Follow Up</span>
                                <span class="keyboard-shortcut">F</span>
                            </button>
                            <button type="submit" 
                                    name="type" 
                                    value="Ready" 
                                    class="punch-button ready text-white py-5 rounded-xl flex items-center justify-center space-x-3 relative">
                                <i class="fas fa-check text-xl"></i>
                                <span>Ready</span>
                                <span class="keyboard-shortcut">R</span>
                            </button>
                            <button type="submit" 
                                    name="type" 
                                    value="Exit" 
                                    class="punch-button exit text-white py-5 rounded-xl flex items-center justify-center space-x-3 relative">
                                <i class="fas fa-sign-out-alt text-xl"></i>
                                <span>Exit</span>
                                <span class="keyboard-shortcut">X</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if (!empty($punch_history)): ?>
            <div class="glass-effect rounded-2xl shadow-xl p-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-history mr-3 text-indigo-600"></i> Recent Activity
                </h2>
                <div class="space-y-3">
                    <?php foreach ($punch_history as $punch): ?>
                        <div class="history-item flex items-center justify-between p-4 bg-gray-50 rounded-xl">
                            <div class="flex items-center space-x-4">
                                <div class="w-3 h-3 rounded-full" style="background-color: <?= getPunchColor($punch['type']) ?>"></div>
                                <span class="font-medium text-gray-800"><?= htmlspecialchars($punch['type']) ?></span>
                            </div>
                            <span class="text-sm text-gray-600">
                                <?= date('m/d/Y h:i A', strtotime($punch['timestamp'])) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
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
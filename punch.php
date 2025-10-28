<?php
include 'db.php';
date_default_timezone_set('America/Santo_Domingo');

function normalizeColorValue(?string $color, string $default): string
{
    $color = trim((string) $color);
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        return strtoupper($color);
    }
    return strtoupper($default);
}

$attendanceTypesAll = getAttendanceTypes($pdo, false);

if (empty($attendanceTypesAll)) {
    $attendanceTypesAll = [
        ['id' => null, 'slug' => 'ENTRY', 'label' => 'Entry', 'icon_class' => 'fas fa-sign-in-alt', 'shortcut_key' => 'E', 'color_start' => '#22C55E', 'color_end' => '#16A34A', 'sort_order' => 10, 'is_unique_daily' => 1, 'is_active' => 1],
        ['id' => null, 'slug' => 'BREAK', 'label' => 'Break', 'icon_class' => 'fas fa-coffee', 'shortcut_key' => 'B', 'color_start' => '#3B82F6', 'color_end' => '#2563EB', 'sort_order' => 20, 'is_unique_daily' => 0, 'is_active' => 1],
        ['id' => null, 'slug' => 'LUNCH', 'label' => 'Lunch', 'icon_class' => 'fas fa-utensils', 'shortcut_key' => 'L', 'color_start' => '#EAB308', 'color_end' => '#CA8A04', 'sort_order' => 30, 'is_unique_daily' => 0, 'is_active' => 1],
        ['id' => null, 'slug' => 'MEETING', 'label' => 'Meeting', 'icon_class' => 'fas fa-users', 'shortcut_key' => 'M', 'color_start' => '#A855F7', 'color_end' => '#7C3AED', 'sort_order' => 40, 'is_unique_daily' => 0, 'is_active' => 1],
        ['id' => null, 'slug' => 'FOLLOW_UP', 'label' => 'Follow Up', 'icon_class' => 'fas fa-tasks', 'shortcut_key' => 'F', 'color_start' => '#6366F1', 'color_end' => '#4338CA', 'sort_order' => 50, 'is_unique_daily' => 0, 'is_active' => 1],
        ['id' => null, 'slug' => 'READY', 'label' => 'Ready', 'icon_class' => 'fas fa-check', 'shortcut_key' => 'R', 'color_start' => '#8B5CF6', 'color_end' => '#6D28D9', 'sort_order' => 60, 'is_unique_daily' => 0, 'is_active' => 1],
        ['id' => null, 'slug' => 'EXIT', 'label' => 'Exit', 'icon_class' => 'fas fa-sign-out-alt', 'shortcut_key' => 'X', 'color_start' => '#EF4444', 'color_end' => '#DC2626', 'sort_order' => 70, 'is_unique_daily' => 1, 'is_active' => 1],
    ];
}

$attendanceTypesAll = array_map(function (array $row) {
    $slugCandidate = $row['slug'] ?? ($row['label'] ?? '');
    $row['slug'] = sanitizeAttendanceTypeSlug($slugCandidate);
    if ($row['slug'] === '') {
        $row['slug'] = sanitizeAttendanceTypeSlug($row['label'] ?? 'TIPO');
    }
    $row['label'] = $row['label'] ?? $row['slug'];
    $row['icon_class'] = trim($row['icon_class'] ?? '') !== '' ? trim($row['icon_class']) : 'fas fa-circle';
    $row['color_start'] = normalizeColorValue($row['color_start'] ?? null, '#6366F1');
    $row['color_end'] = normalizeColorValue($row['color_end'] ?? null, $row['color_start']);
    $row['shortcut_key'] = strtoupper(trim($row['shortcut_key'] ?? ''));
    $row['sort_order'] = (int) ($row['sort_order'] ?? 0);
    $row['is_unique_daily'] = (int) ($row['is_unique_daily'] ?? 0);
    $row['is_active'] = (int) ($row['is_active'] ?? 1);
    return $row;
}, $attendanceTypesAll);

usort($attendanceTypesAll, function (array $a, array $b) {
    $order = ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
    if ($order !== 0) {
        return $order;
    }
    return strcasecmp($a['label'], $b['label']);
});

$attendanceTypeMap = [];
$activeAttendanceTypes = [];
foreach ($attendanceTypesAll as $row) {
    $attendanceTypeMap[$row['slug']] = $row;
    if ((int) ($row['is_active'] ?? 0) === 1) {
        $activeAttendanceTypes[] = $row;
    }
}

if (empty($activeAttendanceTypes)) {
    $activeAttendanceTypes = $attendanceTypesAll;
}

$hasAttendanceShortcuts = false;
foreach ($activeAttendanceTypes as $row) {
    if ($row['shortcut_key'] !== '') {
        $hasAttendanceShortcuts = true;
        break;
    }
}

$last_type = null;
$error = null;
$success = null;
$punch_history = [];
$show_last_punch = false;
$selectedTypeMeta = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required POST data
    if (!isset($_POST['username']) || !isset($_POST['type'])) {
        $error = "Missing required fields.";
    } else {
        $username = trim($_POST['username']);
        $typeSlug = sanitizeAttendanceTypeSlug($_POST['type'] ?? '');

        if ($typeSlug === '' || !isset($attendanceTypeMap[$typeSlug])) {
            $error = "Tipo de asistencia no valido.";
        } else {
            $selectedTypeMeta = $attendanceTypeMap[$typeSlug];
            $typeLabel = $selectedTypeMeta['label'] ?? $selectedTypeMeta['slug'];

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

                    // Validate unique per day constraint when applicable
                    if ((int) ($selectedTypeMeta['is_unique_daily'] ?? 0) === 1) {
                        $check_stmt = $pdo->prepare("
                            SELECT COUNT(*) 
                            FROM attendance 
                            WHERE user_id = ? AND type = ? AND DATE(timestamp) = CURDATE()
                        ");
                        $check_stmt->execute([$user_id, $typeSlug]);
                        $exists = (int) $check_stmt->fetchColumn();

                        if ($exists > 0) {
                            $error = "You can only register '{$typeLabel}' once per day.";
                        }
                    }

                    if (!$error) {
                        // Register the punch
                        $ip_address = $_SERVER['REMOTE_ADDR'] === '::1' ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'];
                        $insert_stmt = $pdo->prepare("
                            INSERT INTO attendance (user_id, type, ip_address, timestamp) 
                            VALUES (?, ?, ?, NOW())
                        ");
                        $insert_stmt->execute([$user_id, $typeSlug, $ip_address]);
                        $success = "Attendance recorded successfully as {$typeLabel}.";
                        $show_last_punch = true;

                        // Get last 5 records for history
                        $history_stmt = $pdo->prepare("
                            SELECT type, timestamp 
                            FROM attendance 
                            WHERE user_id = ? 
                            ORDER BY timestamp DESC LIMIT 5
                        ");
                        $history_stmt->execute([$user_id]);
                        $punch_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        $punch_history = array_map(function (array $row): array {
                            $row['type_label'] = resolveAttendanceLabel($row['type']);
                            return $row;
                        }, $punch_history);

                        // Get last record
                        $last_type = $punch_history[0] ?? null;

                        // Send data to Slack
                        sendSlackNotification($full_name, $username, $selectedTypeMeta, $ip_address);
                    }
                } else {
                    $error = "User not found.";
                }
            }
        }
    }
}

function sendSlackNotification($full_name, $username, array $type, $ip_address) {
    $slack_webhook_url = 'https://hooks.slack.com/services/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';
    $current_timestamp = date('Y-m-d H:i:s');
    $typeLabel = $type['label'] ?? ($type['slug'] ?? 'Tipo');
    $typeSlug = sanitizeAttendanceTypeSlug($type['slug'] ?? $typeLabel);
    $color = normalizeColorValue($type['color_start'] ?? null, '#6366F1');

    $message = [
        "text" => "New Punch Recorded",
        "attachments" => [
            [
                "color" => $color,
                "fields" => [
                    ["title" => "Full Name", "value" => $full_name, "short" => true],
                    ["title" => "Username", "value" => $username, "short" => true],
                    ["title" => "Type", "value" => "{$typeLabel} ({$typeSlug})", "short" => true],
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
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Evita mostrar "ok" en pantalla
    curl_exec($ch);
    curl_close($ch);
}

function getPunchColor($type) {
    global $attendanceTypeMap;

    $slug = sanitizeAttendanceTypeSlug((string) $type);
    if ($slug !== '' && isset($attendanceTypeMap[$slug])) {
        return normalizeColorValue($attendanceTypeMap[$slug]['color_start'] ?? null, '#6B7280');
    }

    return '#6B7280';
}

function resolveAttendanceLabel(string $value): string
{
    global $attendanceTypeMap;

    $slug = sanitizeAttendanceTypeSlug($value);
    if ($slug !== '' && isset($attendanceTypeMap[$slug])) {
        return $attendanceTypeMap[$slug]['label'] ?? $slug;
    }

    if ($slug !== '') {
        return ucwords(strtolower(str_replace('_', ' ', $slug)));
    }

    return $value !== '' ? $value : 'Tipo';
}

// Obtener el historial inicial si hay un usuario en la sesiÃ³n
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
        $historyData = $history_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $historyData = array_map(function (array $row): array {
            $row['type_label'] = resolveAttendanceLabel($row['type']);
            return $row;
        }, $historyData);

        if (empty($punch_history)) {
            $punch_history = $historyData;
        }

        if ($last_type === null && !empty($historyData)) {
            $last_type = $historyData[0];
        }
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
            --color-start: #6366F1;
            --color-end: #4338CA;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            border: none;
            background: linear-gradient(135deg, var(--color-start) 0%, var(--color-end) 100%);
            color: #ffffff;
            box-shadow: 0 12px 25px rgba(15, 23, 42, 0.25);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            min-height: 64px;
            font-weight: 600;
            border-radius: 0.875rem;
        }

        .punch-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, rgba(255, 255, 255, 0.24), rgba(255, 255, 255, 0));
            transform: translateX(-100%);
            transition: 0.5s;
        }

        .punch-button:hover::before {
            transform: translateX(100%);
        }

        .punch-button:active {
            transform: scale(0.95);
        }

        .punch-button:focus-visible {
            outline: 3px solid rgba(59, 130, 246, 0.35);
            outline-offset: 3px;
        }

        .punch-button .keyboard-shortcut {
            position: absolute;
            top: 8px;
            right: 10px;
            font-size: 0.75rem;
            background: rgba(15, 23, 42, 0.35);
            padding: 0.1rem 0.45rem;
            border-radius: 9999px;
            border: 1px solid rgba(255, 255, 255, 0.35);
            letter-spacing: 0.05em;
        }

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
                                <strong>Last Punch:</strong> <?= htmlspecialchars($last_type['type_label'] ?? resolveAttendanceLabel($last_type['type'] ?? '')) ?>
                                <br> 
                                <span class="text-sm opacity-75"><?= date('m/d/Y h:i A', strtotime($last_type['timestamp'])) ?></span>
                            </div>
                            <?php if ($hasAttendanceShortcuts): ?>
                                <div class="text-sm text-blue-600 bg-blue-100 px-3 py-1 rounded-full">
                                    <i class="fas fa-keyboard mr-1"></i>
                                    Keyboard shortcuts available
                                </div>
                            <?php endif; ?>
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
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                            <?php if (empty($activeAttendanceTypes)): ?>
                                <div class="col-span-full text-center text-gray-500 py-12 border-2 border-dashed border-gray-200 rounded-2xl">
                                    <i class="fas fa-exclamation-circle text-3xl mb-3 block"></i>
                                    <p class="font-semibold">No hay tipos de asistencia activos.</p>
                                    <p class="text-sm text-gray-400">Configura nuevos botones desde Configuración → Tipos de asistencia.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($activeAttendanceTypes as $type): ?>
                                    <?php
                                        $buttonSlug = htmlspecialchars($type['slug'], ENT_QUOTES, 'UTF-8');
                                        $buttonLabel = htmlspecialchars($type['label'], ENT_QUOTES, 'UTF-8');
                                        $iconClass = htmlspecialchars($type['icon_class'] ?? 'fas fa-circle', ENT_QUOTES, 'UTF-8');
                                        $shortcutRaw = strtoupper(trim($type['shortcut_key'] ?? ''));
                                        $shortcutSafe = htmlspecialchars($shortcutRaw, ENT_QUOTES, 'UTF-8');
                                        $colorStart = htmlspecialchars($type['color_start'] ?? '#6366F1', ENT_QUOTES, 'UTF-8');
                                        $colorEnd = htmlspecialchars($type['color_end'] ?? $colorStart, ENT_QUOTES, 'UTF-8');
                                        $style = sprintf('--color-start: %s; --color-end: %s;', $colorStart, $colorEnd);
                                    ?>
                                    <button type="submit"
                                            name="type"
                                            value="<?= $buttonSlug ?>"
                                            class="punch-button text-white py-5 px-4 w-full relative"
                                            style="<?= $style ?>"
                                            title="<?= $buttonLabel ?>"
                                            <?= $shortcutRaw !== '' ? 'data-shortcut="' . $shortcutSafe . '"' : '' ?>>
                                        <i class="<?= $iconClass ?> text-xl"></i>
                                        <span><?= $buttonLabel ?></span>
                                        <?php if ($shortcutRaw !== ''): ?>
                                            <span class="keyboard-shortcut"><?= $shortcutSafe ?></span>
                                        <?php endif; ?>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
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
                                <span class="font-medium text-gray-800"><?= htmlspecialchars($punch['type_label'] ?? resolveAttendanceLabel($punch['type'])) ?></span>
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
        // FunciÃ³n para mostrar mensajes de estado
        function showStatus(message, isError = false) {
            const statusDiv = document.getElementById('storageStatus');
            statusDiv.className = isError 
                ? 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4'
                : 'bg-green-100 border-l-4 border-green-500 text-green-700 p-4';
            statusDiv.textContent = message;
            statusDiv.classList.remove('hidden');
        }

        // FunciÃ³n para verificar si localStorage estÃ¡ disponible
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

        // FunciÃ³n para guardar el username usando diferentes mÃ©todos
        function saveUsername(username) {
            try {
                if (isLocalStorageAvailable()) {
                    localStorage.setItem('savedUsername', username);
                    showStatus('Username saved successfully!');
                } else {
                    // Alternativa usando cookies si localStorage no estÃ¡ disponible
                    document.cookie = `savedUsername=${username};path=/;max-age=31536000`; // 1 aÃ±o
                    showStatus('Username saved using cookies');
                }
            } catch (error) {
                console.error('Error saving username:', error);
                showStatus('Error saving username. Please check browser settings.', true);
            }
        }

        // FunciÃ³n para obtener el username guardado
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

        // FunciÃ³n para limpiar el username guardado
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

                // Configurar el botÃ³n de limpiar
                document.getElementById('clearUsername').addEventListener('click', clearSavedUsername);

                const shortcutButtons = Array.from(document.querySelectorAll('.punch-button[data-shortcut]'));
                if (shortcutButtons.length > 0) {
                    const shortcutMap = new Map();
                    shortcutButtons.forEach(function (button) {
                        const key = (button.dataset.shortcut || '').toUpperCase();
                        if (key.length === 1 && !shortcutMap.has(key)) {
                            shortcutMap.set(key, button);
                        }
                    });

                    if (shortcutMap.size > 0) {
                        document.addEventListener('keydown', function (event) {
                            if (!event.key) {
                                return;
                            }

                            const activeElement = document.activeElement;
                            if (activeElement && ['INPUT', 'TEXTAREA', 'SELECT'].includes(activeElement.tagName)) {
                                return;
                            }

                            const key = event.key.toUpperCase();
                            if (shortcutMap.has(key)) {
                                event.preventDefault();
                                shortcutMap.get(key).click();
                            }
                        });
                    }
                }

            } catch (error) {
                console.error('Error in initialization:', error);
                showStatus('Error initializing username storage', true);
            }
        });
    </script>
</body>
</html>




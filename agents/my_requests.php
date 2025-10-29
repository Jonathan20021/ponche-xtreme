<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login_agent.php');
    exit;
}

require_once '../db.php';

$user_id = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$full_name = $_SESSION['full_name'] ?? '';

// Get employee record
$empStmt = $pdo->prepare("SELECT * FROM employees WHERE user_id = ?");
$empStmt->execute([$user_id]);
$employee = $empStmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    // Create employee record if it doesn't exist
    $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$user_id]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        $nameParts = explode(' ', $user['full_name'], 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';
        
        $insertEmp = $pdo->prepare("
            INSERT INTO employees (user_id, employee_code, first_name, last_name, hire_date, employment_status, department_id)
            VALUES (?, ?, ?, ?, COALESCE((SELECT created_at FROM users WHERE id = ?), NOW()), 'ACTIVE', ?)
        ");
        $insertEmp->execute([$user_id, $user['employee_code'], $firstName, $lastName, $user_id, $user['department_id']]);
        $employee = $pdo->query("SELECT * FROM employees WHERE user_id = $user_id")->fetch(PDO::FETCH_ASSOC);
    }
}

$employeeId = $employee['id'] ?? null;

// Handle permission request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_permission']) && $employeeId) {
    $requestType = $_POST['request_type'];
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $startTime = $_POST['start_time'] ?: null;
    $endTime = $_POST['end_time'] ?: null;
    $reason = trim($_POST['reason']);
    
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $totalDays = $start->diff($end)->days + 1;
    
    $totalHours = null;
    if ($startTime && $endTime) {
        $timeStart = new DateTime($startTime);
        $timeEnd = new DateTime($endTime);
        $totalHours = ($timeEnd->getTimestamp() - $timeStart->getTimestamp()) / 3600;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO permission_requests (employee_id, user_id, request_type, start_date, end_date, start_time, end_time, total_days, total_hours, reason)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$employeeId, $user_id, $requestType, $startDate, $endDate, $startTime, $endTime, $totalDays, $totalHours, $reason]);
    $successMsg = "Solicitud de permiso enviada correctamente.";
}

// Handle vacation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vacation']) && $employeeId) {
    $startDate = $_POST['vacation_start_date'];
    $endDate = $_POST['vacation_end_date'];
    $vacationType = $_POST['vacation_type'];
    $reason = trim($_POST['vacation_reason'] ?? '');
    
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $totalDays = $start->diff($end)->days + 1;
    
    $stmt = $pdo->prepare("
        INSERT INTO vacation_requests (employee_id, user_id, start_date, end_date, total_days, vacation_type, reason)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$employeeId, $user_id, $startDate, $endDate, $totalDays, $vacationType, $reason]);
    $successMsg = "Solicitud de vacaciones enviada correctamente.";
}

// Get my permission requests
$permissions = [];
if ($employeeId) {
    $permStmt = $pdo->prepare("
        SELECT pr.*, reviewer.username as reviewer_username
        FROM permission_requests pr
        LEFT JOIN users reviewer ON reviewer.id = pr.reviewed_by
        WHERE pr.employee_id = ?
        ORDER BY pr.created_at DESC
    ");
    $permStmt->execute([$employeeId]);
    $permissions = $permStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get my vacation requests
$vacations = [];
if ($employeeId) {
    $vacStmt = $pdo->prepare("
        SELECT vr.*, reviewer.username as reviewer_username
        FROM vacation_requests vr
        LEFT JOIN users reviewer ON reviewer.id = vr.reviewed_by
        WHERE vr.employee_id = ?
        ORDER BY vr.created_at DESC
    ");
    $vacStmt->execute([$employeeId]);
    $vacations = $vacStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get vacation balance
$vacationBalance = null;
if ($employeeId) {
    $balanceStmt = $pdo->prepare("
        SELECT * FROM vacation_balances 
        WHERE employee_id = ? AND year = YEAR(CURDATE())
    ");
    $balanceStmt->execute([$employeeId]);
    $vacationBalance = $balanceStmt->fetch(PDO::FETCH_ASSOC);
}

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Solicitudes</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-file-alt text-indigo-400 mr-3"></i>
                    Mis Solicitudes
                </h1>
                <p class="text-slate-400">Solicita permisos y vacaciones</p>
            </div>
            <a href="../agent_dashboard.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Volver al Dashboard
            </a>
        </div>

        <?php if (isset($successMsg)): ?>
            <div class="status-banner success mb-6"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>

        <!-- Employee Info -->
        <?php if ($employee): ?>
            <div class="glass-card mb-8">
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 rounded-full flex items-center justify-center text-2xl font-bold text-white" 
                         style="background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);">
                        <?= strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)) ?>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-xl font-semibold text-white"><?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></h2>
                        <p class="text-slate-400"><?= htmlspecialchars($employee['employee_code']) ?> • <?= htmlspecialchars($employee['position'] ?: 'Empleado') ?></p>
                    </div>
                    <?php if ($vacationBalance): ?>
                        <div class="text-right">
                            <p class="text-slate-400 text-sm">Balance de Vacaciones</p>
                            <p class="text-2xl font-bold text-green-400"><?= number_format($vacationBalance['remaining_days'], 1) ?> días</p>
                            <p class="text-xs text-slate-500">de <?= number_format($vacationBalance['total_days'], 1) ?> disponibles</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Request Forms -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Permission Request Form -->
            <div class="glass-card">
                <h2 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-clipboard-list text-purple-400 mr-2"></i>
                    Solicitar Permiso
                </h2>
                <form method="POST">
                    <input type="hidden" name="submit_permission" value="1">
                    
                    <div class="form-group mb-4">
                        <label for="request_type">Tipo de permiso *</label>
                        <select id="request_type" name="request_type" required>
                            <option value="PERMISSION">Permiso</option>
                            <option value="SICK_LEAVE">Licencia Médica</option>
                            <option value="PERSONAL">Personal</option>
                            <option value="MEDICAL">Médico</option>
                            <option value="OTHER">Otro</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div class="form-group">
                            <label for="start_date">Fecha inicio *</label>
                            <input type="date" id="start_date" name="start_date" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label for="end_date">Fecha fin *</label>
                            <input type="date" id="end_date" name="end_date" required value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div class="form-group">
                            <label for="start_time">Hora inicio (opcional)</label>
                            <input type="time" id="start_time" name="start_time">
                        </div>
                        <div class="form-group">
                            <label for="end_time">Hora fin (opcional)</label>
                            <input type="time" id="end_time" name="end_time">
                        </div>
                    </div>

                    <div class="form-group mb-6">
                        <label for="reason">Motivo *</label>
                        <textarea id="reason" name="reason" rows="3" required placeholder="Describe el motivo..."></textarea>
                    </div>

                    <button type="submit" class="btn-primary w-full justify-center">
                        <i class="fas fa-paper-plane"></i>
                        Enviar Solicitud
                    </button>
                </form>
            </div>

            <!-- Vacation Request Form -->
            <div class="glass-card">
                <h2 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-umbrella-beach text-cyan-400 mr-2"></i>
                    Solicitar Vacaciones
                </h2>
                <form method="POST">
                    <input type="hidden" name="submit_vacation" value="1">
                    
                    <div class="form-group mb-4">
                        <label for="vacation_type">Tipo de vacaciones *</label>
                        <select id="vacation_type" name="vacation_type" required>
                            <option value="ANNUAL">Vacaciones Anuales</option>
                            <option value="UNPAID">No Remuneradas</option>
                            <option value="COMPENSATORY">Compensatorias</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div class="form-group">
                            <label for="vacation_start_date">Fecha inicio *</label>
                            <input type="date" id="vacation_start_date" name="vacation_start_date" required>
                        </div>
                        <div class="form-group">
                            <label for="vacation_end_date">Fecha fin *</label>
                            <input type="date" id="vacation_end_date" name="vacation_end_date" required>
                        </div>
                    </div>

                    <div class="form-group mb-6">
                        <label for="vacation_reason">Motivo (opcional)</label>
                        <textarea id="vacation_reason" name="vacation_reason" rows="3" placeholder="Describe el motivo..."></textarea>
                    </div>

                    <?php if ($vacationBalance): ?>
                        <div class="bg-blue-500/10 border border-blue-500/30 rounded-lg p-3 mb-4">
                            <p class="text-sm text-blue-300">
                                <i class="fas fa-info-circle mr-2"></i>
                                Tienes <strong><?= number_format($vacationBalance['remaining_days'], 1) ?> días</strong> disponibles
                            </p>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn-primary w-full justify-center">
                        <i class="fas fa-paper-plane"></i>
                        Enviar Solicitud
                    </button>
                </form>
            </div>
        </div>

        <!-- My Requests Tabs -->
        <div class="glass-card">
            <div class="flex border-b border-slate-700 mb-6">
                <button onclick="showTab('permissions')" id="tab-permissions" class="tab-button active px-6 py-3 font-semibold text-white border-b-2 border-purple-500">
                    <i class="fas fa-clipboard-list mr-2"></i>
                    Mis Permisos (<?= count($permissions) ?>)
                </button>
                <button onclick="showTab('vacations')" id="tab-vacations" class="tab-button px-6 py-3 font-semibold text-slate-400 border-b-2 border-transparent hover:text-white">
                    <i class="fas fa-umbrella-beach mr-2"></i>
                    Mis Vacaciones (<?= count($vacations) ?>)
                </button>
            </div>

            <!-- Permissions Tab -->
            <div id="content-permissions" class="tab-content">
                <?php if (empty($permissions)): ?>
                    <p class="text-slate-400 text-center py-8">No tienes solicitudes de permisos.</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($permissions as $perm): ?>
                            <?php
                            $statusColors = [
                                'PENDING' => 'bg-yellow-500',
                                'APPROVED' => 'bg-green-500',
                                'REJECTED' => 'bg-red-500',
                                'CANCELLED' => 'bg-gray-500'
                            ];
                            $statusColor = $statusColors[$perm['status']] ?? 'bg-gray-500';
                            ?>
                            <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700">
                                <div class="flex justify-between items-start mb-3">
                                    <div>
                                        <h3 class="text-lg font-semibold text-white">
                                            <?= str_replace('_', ' ', ucwords(strtolower($perm['request_type']))) ?>
                                        </h3>
                                        <p class="text-sm text-slate-400">
                                            Solicitado: <?= date('d/m/Y H:i', strtotime($perm['created_at'])) ?>
                                        </p>
                                    </div>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold text-white <?= $statusColor ?>">
                                        <?= htmlspecialchars($perm['status']) ?>
                                    </span>
                                </div>
                                <div class="grid grid-cols-2 gap-4 text-sm mb-3">
                                    <div>
                                        <p class="text-slate-400">Desde:</p>
                                        <p class="text-white"><?= date('d/m/Y', strtotime($perm['start_date'])) ?>
                                        <?= $perm['start_time'] ? date('H:i', strtotime($perm['start_time'])) : '' ?></p>
                                    </div>
                                    <div>
                                        <p class="text-slate-400">Hasta:</p>
                                        <p class="text-white"><?= date('d/m/Y', strtotime($perm['end_date'])) ?>
                                        <?= $perm['end_time'] ? date('H:i', strtotime($perm['end_time'])) : '' ?></p>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <p class="text-slate-400 text-sm">Motivo:</p>
                                    <p class="text-white text-sm"><?= htmlspecialchars($perm['reason']) ?></p>
                                </div>
                                <?php if ($perm['review_notes']): ?>
                                    <div class="bg-slate-900/50 rounded p-2 mt-2">
                                        <p class="text-slate-400 text-xs">Notas de revisión:</p>
                                        <p class="text-white text-sm"><?= htmlspecialchars($perm['review_notes']) ?></p>
                                        <?php if ($perm['reviewer_username']): ?>
                                            <p class="text-slate-500 text-xs mt-1">Por: <?= htmlspecialchars($perm['reviewer_username']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Vacations Tab -->
            <div id="content-vacations" class="tab-content hidden">
                <?php if (empty($vacations)): ?>
                    <p class="text-slate-400 text-center py-8">No tienes solicitudes de vacaciones.</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($vacations as $vac): ?>
                            <?php
                            $statusColors = [
                                'PENDING' => 'bg-yellow-500',
                                'APPROVED' => 'bg-green-500',
                                'REJECTED' => 'bg-red-500',
                                'CANCELLED' => 'bg-gray-500'
                            ];
                            $statusColor = $statusColors[$vac['status']] ?? 'bg-gray-500';
                            ?>
                            <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700">
                                <div class="flex justify-between items-start mb-3">
                                    <div>
                                        <h3 class="text-lg font-semibold text-white">
                                            <?= str_replace('_', ' ', ucwords(strtolower($vac['vacation_type']))) ?>
                                        </h3>
                                        <p class="text-sm text-slate-400">
                                            Solicitado: <?= date('d/m/Y H:i', strtotime($vac['created_at'])) ?>
                                        </p>
                                    </div>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold text-white <?= $statusColor ?>">
                                        <?= htmlspecialchars($vac['status']) ?>
                                    </span>
                                </div>
                                <div class="grid grid-cols-3 gap-4 text-sm mb-3">
                                    <div>
                                        <p class="text-slate-400">Desde:</p>
                                        <p class="text-white"><?= date('d/m/Y', strtotime($vac['start_date'])) ?></p>
                                    </div>
                                    <div>
                                        <p class="text-slate-400">Hasta:</p>
                                        <p class="text-white"><?= date('d/m/Y', strtotime($vac['end_date'])) ?></p>
                                    </div>
                                    <div>
                                        <p class="text-slate-400">Días:</p>
                                        <p class="text-white font-semibold"><?= number_format($vac['total_days'], 1) ?></p>
                                    </div>
                                </div>
                                <?php if ($vac['reason']): ?>
                                    <div class="mb-2">
                                        <p class="text-slate-400 text-sm">Motivo:</p>
                                        <p class="text-white text-sm"><?= htmlspecialchars($vac['reason']) ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php if ($vac['review_notes']): ?>
                                    <div class="bg-slate-900/50 rounded p-2 mt-2">
                                        <p class="text-slate-400 text-xs">Notas de revisión:</p>
                                        <p class="text-white text-sm"><?= htmlspecialchars($vac['review_notes']) ?></p>
                                        <?php if ($vac['reviewer_username']): ?>
                                            <p class="text-slate-500 text-xs mt-1">Por: <?= htmlspecialchars($vac['reviewer_username']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.add('hidden');
            });
            
            // Remove active from all buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active', 'text-white', 'border-purple-500', 'border-cyan-500');
                btn.classList.add('text-slate-400', 'border-transparent');
            });
            
            // Show selected tab
            document.getElementById('content-' + tabName).classList.remove('hidden');
            
            // Activate button
            const button = document.getElementById('tab-' + tabName);
            button.classList.add('active', 'text-white');
            button.classList.remove('text-slate-400', 'border-transparent');
            
            if (tabName === 'permissions') {
                button.classList.add('border-purple-500');
            } else {
                button.classList.add('border-cyan-500');
            }
        }
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>

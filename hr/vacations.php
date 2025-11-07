<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_vacations', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle vacation request creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_request'])) {
    $employeeId = (int)$_POST['employee_id'];
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $vacationType = $_POST['vacation_type'];
    $reason = trim($_POST['reason'] ?? '');
    
    // Calculate total days
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $totalDays = $start->diff($end)->days + 1;
    
    // Get user_id from employee_id
    $empStmt = $pdo->prepare("SELECT user_id FROM employees WHERE id = ?");
    $empStmt->execute([$employeeId]);
    $userId = $empStmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        INSERT INTO vacation_requests (employee_id, user_id, start_date, end_date, total_days, vacation_type, reason)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$employeeId, $userId, $startDate, $endDate, $totalDays, $vacationType, $reason]);
    $successMsg = "Solicitud de vacaciones creada correctamente.";
}

// Handle vacation request review
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_request'])) {
    $requestId = (int)$_POST['request_id'];
    $newStatus = $_POST['new_status'];
    $reviewNotes = trim($_POST['review_notes'] ?? '');
    
    $pdo->beginTransaction();
    
    try {
        // Get request details
        $reqStmt = $pdo->prepare("SELECT * FROM vacation_requests WHERE id = ?");
        $reqStmt->execute([$requestId]);
        $request = $reqStmt->fetch(PDO::FETCH_ASSOC);
        
        // Update request status
        $stmt = $pdo->prepare("
            UPDATE vacation_requests 
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $_SESSION['user_id'], $reviewNotes, $requestId]);
        
        // If approved, update vacation balance
        if ($newStatus === 'APPROVED' && $request) {
            $year = date('Y', strtotime($request['start_date']));
            
            // Check if balance exists
            $balanceStmt = $pdo->prepare("SELECT * FROM vacation_balances WHERE employee_id = ? AND year = ?");
            $balanceStmt->execute([$request['employee_id'], $year]);
            $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($balance) {
                // Update existing balance
                $newUsed = $balance['used_days'] + $request['total_days'];
                $newRemaining = $balance['total_days'] - $newUsed;
                
                $updateBalanceStmt = $pdo->prepare("
                    UPDATE vacation_balances 
                    SET used_days = ?, remaining_days = ?
                    WHERE id = ?
                ");
                $updateBalanceStmt->execute([$newUsed, $newRemaining, $balance['id']]);
            } else {
                // Create new balance (14 days default)
                $totalDays = 14.00;
                $usedDays = $request['total_days'];
                $remainingDays = $totalDays - $usedDays;
                
                $insertBalanceStmt = $pdo->prepare("
                    INSERT INTO vacation_balances (employee_id, year, total_days, used_days, remaining_days)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insertBalanceStmt->execute([$request['employee_id'], $year, $totalDays, $usedDays, $remainingDays]);
            }
        }
        
        $pdo->commit();
        $successMsg = "Solicitud revisada correctamente.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $errorMsg = "Error al revisar la solicitud: " . $e->getMessage();
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';

// Build query
$query = "
    SELECT vr.*, 
           e.first_name, e.last_name, e.employee_code, e.position,
           u.username,
           d.name as department_name,
           reviewer.username as reviewer_username
    FROM vacation_requests vr
    JOIN employees e ON e.id = vr.employee_id
    JOIN users u ON u.id = vr.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN users reviewer ON reviewer.id = vr.reviewed_by
    WHERE 1=1
";

$params = [];
if ($statusFilter !== 'all') {
    $query .= " AND vr.status = ?";
    $params[] = strtoupper($statusFilter);
}

$query .= " ORDER BY vr.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [
    'pending' => $pdo->query("SELECT COUNT(*) FROM vacation_requests WHERE status = 'PENDING'")->fetchColumn(),
    'approved' => $pdo->query("SELECT COUNT(*) FROM vacation_requests WHERE status = 'APPROVED'")->fetchColumn(),
    'rejected' => $pdo->query("SELECT COUNT(*) FROM vacation_requests WHERE status = 'REJECTED'")->fetchColumn(),
    'total' => $pdo->query("SELECT COUNT(*) FROM vacation_requests")->fetchColumn(),
];

// Get all employees for the form
$employees = $pdo->query("
    SELECT e.id, e.first_name, e.last_name, e.employee_code, u.username
    FROM employees e
    JOIN users u ON u.id = e.user_id
    WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
    ORDER BY e.last_name, e.first_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get pending requests count for notification
$pendingVacCount = $pdo->query("SELECT COUNT(*) FROM vacation_requests WHERE status = 'PENDING'")->fetchColumn();

// Get vacation balances
$balances = $pdo->query("
    SELECT vb.*, e.first_name, e.last_name, e.employee_code
    FROM vacation_balances vb
    JOIN employees e ON e.id = vb.employee_id
    WHERE vb.year = YEAR(CURDATE())
    ORDER BY e.last_name, e.first_name
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vacaciones - HR</title>
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
                    <i class="fas fa-plane-departure text-cyan-400 mr-3"></i>
                    Solicitudes de Vacaciones
                </h1>
                <p class="text-slate-400">Gestión de vacaciones y balance de días</p>
            </div>
            <div class="flex gap-3">
                <button onclick="document.getElementById('createRequestModal').classList.remove('hidden')" class="btn-primary">
                    <i class="fas fa-plus"></i>
                    Nueva Solicitud
                </button>
                <a href="index.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver a HR
                </a>
            </div>
        </div>

        <?php if (isset($successMsg)): ?>
            <div class="status-banner success mb-6"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        <?php if (isset($errorMsg)): ?>
            <div class="status-banner error mb-6"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <?php if ($pendingVacCount > 0): ?>
            <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-4 mb-6">
                <div class="flex items-center gap-3">
                    <i class="fas fa-exclamation-triangle text-yellow-400 text-2xl"></i>
                    <div>
                        <p class="text-yellow-300 font-semibold">¡Atención!</p>
                        <p class="text-yellow-200 text-sm">Tienes <strong><?= $pendingVacCount ?></strong> solicitud(es) de vacaciones pendiente(s) de revisión</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Pendientes</p>
                        <h3 class="text-3xl font-bold text-white"><?= $stats['pending'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                        <i class="fas fa-clock text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Aprobadas</p>
                        <h3 class="text-3xl font-bold text-white"><?= $stats['approved'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                        <i class="fas fa-check text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Rechazadas</p>
                        <h3 class="text-3xl font-bold text-white"><?= $stats['rejected'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                        <i class="fas fa-times text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Total</p>
                        <h3 class="text-3xl font-bold text-white"><?= $stats['total'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);">
                        <i class="fas fa-umbrella-beach text-white text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vacation Balances -->
        <?php if (!empty($balances)): ?>
            <div class="glass-card mb-8">
                <h2 class="text-xl font-semibold text-white mb-4">
                    <i class="fas fa-chart-pie text-cyan-400 mr-2"></i>
                    Balance de Vacaciones <?= date('Y') ?>
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Empleado</th>
                                <th class="text-left py-3 px-4 text-slate-400 font-medium">Código</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Total Días</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Días Usados</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Días Disponibles</th>
                                <th class="text-center py-3 px-4 text-slate-400 font-medium">Progreso</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($balances as $balance): ?>
                                <?php
                                $percentage = ($balance['used_days'] / $balance['total_days']) * 100;
                                $progressColor = $percentage >= 80 ? '#ef4444' : ($percentage >= 50 ? '#f59e0b' : '#10b981');
                                ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 text-white">
                                        <?= htmlspecialchars($balance['first_name'] . ' ' . $balance['last_name']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-slate-300">
                                        <?= htmlspecialchars($balance['employee_code']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-center text-white font-medium">
                                        <?= number_format($balance['total_days'], 1) ?>
                                    </td>
                                    <td class="py-3 px-4 text-center text-orange-400 font-medium">
                                        <?= number_format($balance['used_days'], 1) ?>
                                    </td>
                                    <td class="py-3 px-4 text-center text-green-400 font-semibold">
                                        <?= number_format($balance['remaining_days'], 1) ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 h-2 bg-slate-700 rounded-full overflow-hidden">
                                                <div class="h-full transition-all" style="width: <?= min($percentage, 100) ?>%; background: <?= $progressColor ?>;"></div>
                                            </div>
                                            <span class="text-xs text-slate-400 w-12 text-right"><?= number_format($percentage, 0) ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="glass-card mb-6">
            <form method="GET" class="flex flex-wrap gap-4 items-end">
                <div class="form-group flex-1 min-w-[200px]">
                    <label for="status">Estado</label>
                    <select id="status" name="status" onchange="this.form.submit()">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todos</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pendientes</option>
                        <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Aprobadas</option>
                        <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rechazadas</option>
                        <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Canceladas</option>
                    </select>
                </div>
                <button type="button" onclick="window.location.href='vacations.php'" class="btn-secondary">
                    <i class="fas fa-redo"></i>
                    Limpiar
                </button>
            </form>
        </div>

        <!-- Requests List -->
        <div class="glass-card">
            <h2 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-list text-cyan-400 mr-2"></i>
                Solicitudes (<?= count($requests) ?>)
            </h2>

            <?php if (empty($requests)): ?>
                <p class="text-slate-400 text-center py-8">No hay solicitudes de vacaciones.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($requests as $request): ?>
                        <?php
                        $statusColors = [
                            'PENDING' => 'bg-yellow-500',
                            'APPROVED' => 'bg-green-500',
                            'REJECTED' => 'bg-red-500',
                            'CANCELLED' => 'bg-gray-500'
                        ];
                        $statusColor = $statusColors[$request['status']] ?? 'bg-gray-500';
                        ?>
                        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700 hover:border-cyan-500 transition-all">
                            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white" 
                                             style="background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);">
                                            <?= strtoupper(substr($request['first_name'], 0, 1) . substr($request['last_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <h3 class="text-lg font-semibold text-white">
                                                <?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?>
                                            </h3>
                                            <p class="text-slate-400 text-sm">
                                                <?= htmlspecialchars($request['employee_code']) ?> • 
                                                <?= htmlspecialchars($request['position'] ?: 'Sin posición') ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                        <div>
                                            <p class="text-slate-400 mb-1">
                                                <i class="fas fa-tag text-cyan-400 mr-2"></i>
                                                Tipo: <span class="text-white"><?= str_replace('_', ' ', ucwords(strtolower($request['vacation_type']))) ?></span>
                                            </p>
                                            <p class="text-slate-400 mb-1">
                                                <i class="fas fa-calendar text-blue-400 mr-2"></i>
                                                Desde: <span class="text-white"><?= date('d/m/Y', strtotime($request['start_date'])) ?></span>
                                            </p>
                                            <p class="text-slate-400">
                                                <i class="fas fa-calendar-check text-green-400 mr-2"></i>
                                                Hasta: <span class="text-white"><?= date('d/m/Y', strtotime($request['end_date'])) ?></span>
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-slate-400 mb-1">
                                                <i class="fas fa-clock text-orange-400 mr-2"></i>
                                                Duración: <span class="text-white font-semibold"><?= number_format($request['total_days'], 1) ?> día(s)</span>
                                            </p>
                                            <p class="text-slate-400 mb-1">
                                                <i class="fas fa-user-clock text-indigo-400 mr-2"></i>
                                                Solicitado: <span class="text-white"><?= date('d/m/Y H:i', strtotime($request['created_at'])) ?></span>
                                            </p>
                                            <?php if ($request['reviewed_at']): ?>
                                                <p class="text-slate-400">
                                                    <i class="fas fa-user-check text-green-400 mr-2"></i>
                                                    Revisado por: <span class="text-white"><?= htmlspecialchars($request['reviewer_username']) ?></span>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($request['reason']): ?>
                                        <div class="mt-3">
                                            <p class="text-slate-400 text-sm mb-1">Motivo:</p>
                                            <p class="text-white text-sm bg-slate-900/50 p-2 rounded"><?= htmlspecialchars($request['reason']) ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($request['review_notes']): ?>
                                        <div class="mt-2">
                                            <p class="text-slate-400 text-sm mb-1">Notas de revisión:</p>
                                            <p class="text-white text-sm bg-slate-900/50 p-2 rounded"><?= htmlspecialchars($request['review_notes']) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="flex flex-col items-end gap-3">
                                    <span class="px-4 py-2 rounded-full text-sm font-semibold text-white <?= $statusColor ?>">
                                        <?= htmlspecialchars($request['status']) ?>
                                    </span>

                                    <?php if ($request['status'] === 'PENDING'): ?>
                                        <div class="flex gap-2">
                                            <button onclick="reviewRequest(<?= $request['id'] ?>, 'APPROVED')" class="btn-primary text-sm">
                                                <i class="fas fa-check"></i>
                                                Aprobar
                                            </button>
                                            <button onclick="reviewRequest(<?= $request['id'] ?>, 'REJECTED')" class="btn-secondary text-sm">
                                                <i class="fas fa-times"></i>
                                                Rechazar
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Create Request Modal -->
    <div id="createRequestModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto">
        <div class="glass-card m-4" style="width: min(600px, 95%);">
            <h3 class="text-xl font-semibold text-white mb-4">Nueva Solicitud de Vacaciones</h3>
            <form method="POST">
                <input type="hidden" name="create_request" value="1">
                
                <div class="form-group mb-4">
                    <label for="employee_id">Empleado *</label>
                    <select id="employee_id" name="employee_id" required>
                        <option value="">Seleccionar empleado</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>">
                                <?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_code'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

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
                        <label for="start_date">Fecha inicio *</label>
                        <input type="date" id="start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label for="end_date">Fecha fin *</label>
                        <input type="date" id="end_date" name="end_date" required>
                    </div>
                </div>

                <div class="form-group mb-6">
                    <label for="reason">Motivo (opcional)</label>
                    <textarea id="reason" name="reason" rows="3" placeholder="Describe el motivo de las vacaciones..."></textarea>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-check"></i>
                        Crear Solicitud
                    </button>
                    <button type="button" onclick="document.getElementById('createRequestModal').classList.add('hidden')" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Review Modal -->
    <div id="reviewModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="glass-card" style="width: min(500px, 90%);">
            <h3 class="text-xl font-semibold text-white mb-4">Revisar Solicitud</h3>
            <form method="POST" id="reviewForm">
                <input type="hidden" name="review_request" value="1">
                <input type="hidden" name="request_id" id="review_request_id">
                <input type="hidden" name="new_status" id="review_new_status">
                
                <div class="form-group mb-6">
                    <label for="review_notes">Notas (opcional)</label>
                    <textarea id="review_notes" name="review_notes" rows="4" placeholder="Agrega comentarios sobre esta decisión..."></textarea>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-check"></i>
                        Confirmar
                    </button>
                    <button type="button" onclick="document.getElementById('reviewModal').classList.add('hidden')" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function reviewRequest(requestId, newStatus) {
            document.getElementById('review_request_id').value = requestId;
            document.getElementById('review_new_status').value = newStatus;
            document.getElementById('reviewModal').classList.remove('hidden');
        }
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>

<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_trial_period');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $employeeId = (int)$_POST['employee_id'];
    $newStatus = $_POST['new_status'];
    
    if (in_array($newStatus, ['ACTIVE', 'TERMINATED'])) {
        $stmt = $pdo->prepare("UPDATE employees SET employment_status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $employeeId]);
        $successMsg = "Estado del empleado actualizado correctamente.";
    }
}

// Get all employees within their first 90 days (trial period)
try {
    $trialEmployees = $pdo->query("
        SELECT 
            e.*,
            u.username,
            u.hourly_rate,
            d.name as department_name,
            DATEDIFF(CURDATE(), e.hire_date) as days_elapsed,
            DATEDIFF(DATE_ADD(e.hire_date, INTERVAL 90 DAY), CURDATE()) as days_remaining,
            DATE_ADD(e.hire_date, INTERVAL 90 DAY) as trial_end_date,
            ROUND((DATEDIFF(CURDATE(), e.hire_date) / 90) * 100, 1) as completion_percentage
        FROM employees e
        JOIN users u ON u.id = e.user_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE DATEDIFF(CURDATE(), e.hire_date) <= 90
        AND e.employment_status != 'TERMINATED'
        ORDER BY days_remaining ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorMsg = "Error al cargar empleados: " . $e->getMessage();
    $trialEmployees = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Período de Prueba - HR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .progress-bar {
            height: 8px;
            background: rgba(148, 163, 184, 0.2);
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            transition: width 0.3s ease;
            border-radius: 4px;
        }
        .employee-card {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.1);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }
        .employee-card:hover {
            background: rgba(30, 41, 59, 0.8);
            border-color: rgba(99, 102, 241, 0.3);
            transform: translateY(-2px);
        }
        .theme-light .employee-card {
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(148, 163, 184, 0.2);
        }
        .theme-light .employee-card:hover {
            background: rgba(255, 255, 255, 1);
        }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-hourglass-half text-orange-400 mr-3"></i>
                    Empleados en Período de Prueba
                </h1>
                <p class="text-slate-400">Seguimiento de empleados en sus primeros 90 días</p>
            </div>
            <a href="index.php" class="btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Volver a HR
            </a>
        </div>

        <?php if (isset($successMsg)): ?>
            <div class="status-banner success mb-6"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        
        <?php if (isset($errorMsg)): ?>
            <div class="status-banner error mb-6"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <!-- Summary Stats -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Total en Prueba</p>
                        <h3 class="text-3xl font-bold text-white"><?= count($trialEmployees) ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Finalizan en 15 días</p>
                        <h3 class="text-3xl font-bold text-white">
                            <?= count(array_filter($trialEmployees, fn($e) => $e['days_remaining'] <= 15 && $e['days_remaining'] >= 0)) ?>
                        </h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                        <i class="fas fa-exclamation-triangle text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Período Vencido</p>
                        <h3 class="text-3xl font-bold text-white">
                            <?= count(array_filter($trialEmployees, fn($e) => $e['days_remaining'] < 0)) ?>
                        </h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                        <i class="fas fa-clock text-white text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Employee Cards -->
        <?php if (empty($trialEmployees)): ?>
            <div class="glass-card text-center py-12">
                <i class="fas fa-user-check text-6xl text-slate-600 mb-4"></i>
                <h3 class="text-xl font-semibold text-white mb-2">No hay empleados en período de prueba</h3>
                <p class="text-slate-400">Todos los empleados han completado su período de prueba.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-6">
                <?php foreach ($trialEmployees as $employee): ?>
                    <?php
                    $daysRemaining = $employee['days_remaining'];
                    $statusColor = $daysRemaining < 0 ? 'red' : ($daysRemaining <= 15 ? 'orange' : 'green');
                    $statusText = $daysRemaining < 0 ? 'Vencido' : ($daysRemaining == 0 ? 'Finaliza hoy' : $daysRemaining . ' días restantes');
                    $progressColor = $daysRemaining < 0 ? '#ef4444' : ($daysRemaining <= 15 ? '#f59e0b' : '#10b981');
                    ?>
                    <div class="employee-card">
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                            <!-- Employee Info -->
                            <div class="flex-1">
                                <div class="flex items-center gap-4 mb-3">
                                    <div class="w-16 h-16 rounded-full flex items-center justify-center text-2xl font-bold text-white" 
                                         style="background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);">
                                        <?= strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-semibold text-white">
                                            <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>
                                        </h3>
                                        <p class="text-slate-400 text-sm">
                                            <?= htmlspecialchars($employee['employee_code']) ?> • 
                                            <?= htmlspecialchars($employee['position'] ?: 'Sin posición') ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Progress Bar -->
                                <div class="mb-3">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-sm text-slate-400">Progreso del período de prueba</span>
                                        <span class="text-sm font-semibold text-white"><?= $employee['completion_percentage'] ?>%</span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?= min($employee['completion_percentage'], 100) ?>%; background: <?= $progressColor ?>;"></div>
                                    </div>
                                </div>

                                <!-- Details Grid -->
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                    <div>
                                        <p class="text-slate-400 mb-1">Fecha de ingreso</p>
                                        <p class="text-white font-medium"><?= date('d/m/Y', strtotime($employee['hire_date'])) ?></p>
                                    </div>
                                    <div>
                                        <p class="text-slate-400 mb-1">Finaliza</p>
                                        <p class="text-white font-medium"><?= date('d/m/Y', strtotime($employee['trial_end_date'])) ?></p>
                                    </div>
                                    <div>
                                        <p class="text-slate-400 mb-1">Días transcurridos</p>
                                        <p class="text-white font-medium"><?= $employee['days_elapsed'] ?> días</p>
                                    </div>
                                    <div>
                                        <p class="text-slate-400 mb-1">Departamento</p>
                                        <p class="text-white font-medium"><?= htmlspecialchars($employee['department_name'] ?: 'N/A') ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Status and Actions -->
                            <div class="flex flex-col items-end gap-3">
                                <span class="tag-pill px-4 py-2 text-sm font-semibold" style="background: linear-gradient(135deg, <?= $progressColor ?> 0%, <?= $progressColor ?>dd 100%);">
                                    <i class="fas fa-clock mr-2"></i>
                                    <?= $statusText ?>
                                </span>

                                <!-- Action Buttons -->
                                <div class="flex gap-2">
                                    <button onclick="confirmStatusChange(<?= $employee['id'] ?>, 'ACTIVE', '<?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>')" 
                                            class="btn-primary text-sm">
                                        <i class="fas fa-check"></i>
                                        Aprobar
                                    </button>
                                    <button onclick="confirmStatusChange(<?= $employee['id'] ?>, 'TERMINATED', '<?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>')" 
                                            class="btn-secondary text-sm">
                                        <i class="fas fa-times"></i>
                                        Terminar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Hidden form for status updates -->
    <form id="statusUpdateForm" method="POST" style="display: none;">
        <input type="hidden" name="update_status" value="1">
        <input type="hidden" name="employee_id" id="statusEmployeeId">
        <input type="hidden" name="new_status" id="statusNewStatus">
    </form>

    <script>
        function confirmStatusChange(employeeId, newStatus, employeeName) {
            const action = newStatus === 'ACTIVE' ? 'aprobar' : 'terminar';
            const message = `¿Está seguro de que desea ${action} a ${employeeName}?`;
            
            if (confirm(message)) {
                document.getElementById('statusEmployeeId').value = employeeId;
                document.getElementById('statusNewStatus').value = newStatus;
                document.getElementById('statusUpdateForm').submit();
            }
        }
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>

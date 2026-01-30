<?php
session_start();
require_once '../db.php';
ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$employeeId) {
    header('Location: employees.php');
    exit;
}

// Get employee details
$stmt = $pdo->prepare("
    SELECT e.*, u.username, u.hourly_rate, u.role, u.overtime_multiplier,
           d.name as department_name,
           b.name as bank_name,
           YEAR(CURDATE()) - YEAR(e.birth_date) as age,
           DATEDIFF(CURDATE(), e.hire_date) as days_employed
    FROM employees e
    JOIN users u ON u.id = e.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN banks b ON b.id = e.bank_id
    WHERE e.id = ?
");
$stmt->execute([$employeeId]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    header('Location: employees.php');
    exit;
}

$prevStmt = $pdo->prepare("SELECT id FROM employees WHERE id < ? ORDER BY id DESC LIMIT 1");
$prevStmt->execute([$employeeId]);
$prevId = $prevStmt->fetchColumn();

$nextStmt = $pdo->prepare("SELECT id FROM employees WHERE id > ? ORDER BY id ASC LIMIT 1");
$nextStmt->execute([$employeeId]);
$nextId = $nextStmt->fetchColumn();

// Get vacation balance
$vacBalance = $pdo->prepare("SELECT * FROM vacation_balances WHERE employee_id = ? AND year = YEAR(CURDATE())");
$vacBalance->execute([$employeeId]);
$vacationBalance = $vacBalance->fetch(PDO::FETCH_ASSOC);

// Get recent requests
$permissions = $pdo->prepare("SELECT * FROM permission_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 10");
$permissions->execute([$employeeId]);
$permissionsList = $permissions->fetchAll(PDO::FETCH_ASSOC);

$vacations = $pdo->prepare("SELECT * FROM vacation_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 10");
$vacations->execute([$employeeId]);
$vacationsList = $vacations->fetchAll(PDO::FETCH_ASSOC);

// Get document count
$docCount = $pdo->prepare("SELECT COUNT(*) FROM employee_documents WHERE employee_id = ?");
$docCount->execute([$employeeId]);
$documentCount = $docCount->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil - <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <div class="flex items-center gap-4">
                <a href="employees.php" class="btn-secondary"><i class="fas fa-arrow-left"></i></a>
                <div>
                    <h1 class="text-3xl font-bold text-white">Perfil de Empleado</h1>
                    <p class="text-slate-400"><?= htmlspecialchars($employee['employee_code']) ?></p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="inventory.php?employee_id=<?= (int) $employeeId ?>" class="btn-secondary">
                    <i class="fas fa-boxes"></i>
                    Inventario
                </a>
                <a href="inventory_assign.php?employee_id=<?= (int) $employeeId ?>" class="btn-secondary">
                    <i class="fas fa-plus-circle"></i>
                    Asignar Artículo
                </a>
                <?php if (!empty($prevId)): ?>
                    <a href="employee_profile.php?id=<?= (int)$prevId ?>" 
                       class="h-10 w-10 rounded-md bg-slate-700/40 border border-white/20 text-white flex items-center justify-center hover:bg-slate-600/50 hover:border-white/30 hover:shadow-md transition transform hover:scale-105" 
                       title="Anterior">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php else: ?>
                    <span class="h-10 w-10 rounded-md bg-slate-700/20 border border-white/10 text-slate-300 flex items-center justify-center opacity-50 cursor-not-allowed" aria-disabled="true" title="Anterior">
                        <i class="fas fa-chevron-left"></i>
                    </span>
                <?php endif; ?>
                <?php if (!empty($nextId)): ?>
                    <a href="employee_profile.php?id=<?= (int)$nextId ?>" 
                       class="h-10 w-10 rounded-md bg-slate-700/40 border border-white/20 text-white flex items-center justify-center hover:bg-slate-600/50 hover:border-white/30 hover:shadow-md transition transform hover:scale-105" 
                       title="Siguiente">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="h-10 w-10 rounded-md bg-slate-700/20 border border-white/10 text-slate-300 flex items-center justify-center opacity-50 cursor-not-allowed" aria-disabled="true" title="Siguiente">
                        <i class="fas fa-chevron-right"></i>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Employee Card -->
        <div class="glass-card mb-8">
            <div class="flex flex-col md:flex-row gap-6">
                <?php if (!empty($employee['photo_path']) && file_exists('../' . $employee['photo_path'])): ?>
                    <img src="../<?= htmlspecialchars($employee['photo_path']) ?>" 
                         alt="<?= htmlspecialchars($employee['first_name']) ?>" 
                         class="w-32 h-32 rounded-full object-cover border-4 border-blue-500 shadow-lg">
                <?php else: ?>
                    <div class="w-32 h-32 rounded-full flex items-center justify-center text-4xl font-bold text-white" 
                         style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                        <?= strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="flex-1">
                    <h2 class="text-3xl font-bold text-white mb-2">
                        <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>
                    </h2>
                    <p class="text-xl text-slate-300 mb-2"><?= htmlspecialchars($employee['position'] ?: 'Sin posición') ?></p>
                    <p class="text-slate-400"><i class="fas fa-building mr-2"></i><?= htmlspecialchars($employee['department_name'] ?: 'Sin departamento') ?></p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <div class="bg-slate-800/50 rounded-lg p-4">
                            <p class="text-slate-400 text-sm">Usuario</p>
                            <p class="text-white font-semibold"><?= htmlspecialchars($employee['username']) ?></p>
                        </div>
                        <div class="bg-slate-800/50 rounded-lg p-4">
                            <p class="text-slate-400 text-sm">Estado</p>
                            <p class="text-white font-semibold"><?= htmlspecialchars($employee['employment_status']) ?></p>
                        </div>
                        <div class="bg-slate-800/50 rounded-lg p-4">
                            <p class="text-slate-400 text-sm">Tarifa/Hora</p>
                            <p class="text-white font-semibold">$<?= number_format($employee['hourly_rate'], 2) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Info Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">Información Personal</h3>
                <div class="space-y-3">
                    <?php if ($employee['email']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-envelope text-blue-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Email</p><p class="text-white"><?= htmlspecialchars($employee['email']) ?></p></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($employee['phone']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-phone text-green-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Teléfono</p><p class="text-white"><?= htmlspecialchars($employee['phone']) ?></p></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($employee['birth_date']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-birthday-cake text-pink-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Nacimiento</p><p class="text-white"><?= date('d/m/Y', strtotime($employee['birth_date'])) ?> (<?= $employee['age'] ?> años)</p></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($employee['id_card_number']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-id-card text-yellow-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Cédula</p><p class="text-white"><?= htmlspecialchars($employee['id_card_number']) ?></p></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($employee['gender']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-user text-purple-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Género</p><p class="text-white"><?= htmlspecialchars($employee['gender']) ?></p></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">Información Laboral</h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-calendar-check text-green-400 w-5"></i>
                        <div><p class="text-slate-400 text-sm">Ingreso</p><p class="text-white"><?= date('d/m/Y', strtotime($employee['hire_date'])) ?></p></div>
                    </div>
                    <div class="flex items-center gap-3">
                        <i class="fas fa-clock text-blue-400 w-5"></i>
                        <div><p class="text-slate-400 text-sm">Días Empleado</p><p class="text-white"><?= $employee['days_employed'] ?> días</p></div>
                    </div>
                    <?php if ($vacationBalance): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-umbrella-beach text-cyan-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Vacaciones Disponibles</p><p class="text-white"><?= number_format($vacationBalance['remaining_days'], 1) ?> días</p></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4">
                    <i class="fas fa-university text-blue-400 mr-2"></i>
                    Información Bancaria
                </h3>
                <div class="space-y-3">
                    <?php if ($employee['bank_name']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-building text-blue-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Banco</p><p class="text-white"><?= htmlspecialchars($employee['bank_name']) ?></p></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($employee['bank_account_number']): ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-credit-card text-green-400 w-5"></i>
                            <div><p class="text-slate-400 text-sm">Número de Cuenta</p><p class="text-white"><?= htmlspecialchars($employee['bank_account_number']) ?></p></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!$employee['bank_name'] && !$employee['bank_account_number']): ?>
                        <p class="text-slate-400 text-center py-4">Sin información bancaria</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Documents Section -->
        <div class="glass-card mb-8">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-white">
                    <i class="fas fa-folder-open text-blue-400 mr-2"></i>
                    Record Digital de HR
                </h3>
                <a href="employee_documents.php?id=<?= $employeeId ?>" class="btn-primary">
                    <i class="fas fa-folder"></i>
                    Ver Todos los Documentos
                </a>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="bg-slate-800/50 rounded-lg p-4 text-center">
                    <i class="fas fa-file-alt text-4xl text-blue-400 mb-2"></i>
                    <p class="text-2xl font-bold text-white"><?= $documentCount ?></p>
                    <p class="text-slate-400 text-sm">Documentos</p>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-4 flex items-center justify-center">
                    <a href="employee_documents.php?id=<?= $employeeId ?>" class="text-center">
                        <i class="fas fa-id-card text-3xl text-green-400 mb-2"></i>
                        <p class="text-white text-sm">Identificación</p>
                    </a>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-4 flex items-center justify-center">
                    <a href="employee_documents.php?id=<?= $employeeId ?>" class="text-center">
                        <i class="fas fa-graduation-cap text-3xl text-purple-400 mb-2"></i>
                        <p class="text-white text-sm">Educación</p>
                    </a>
                </div>
                <div class="bg-slate-800/50 rounded-lg p-4 flex items-center justify-center">
                    <a href="employee_documents.php?id=<?= $employeeId ?>" class="text-center">
                        <i class="fas fa-briefcase text-3xl text-yellow-400 mb-2"></i>
                        <p class="text-white text-sm">Laboral</p>
                    </a>
                </div>
            </div>
            <div class="mt-4 p-4 bg-blue-500/10 border border-blue-500/30 rounded-lg">
                <p class="text-blue-300 text-sm">
                    <i class="fas fa-info-circle mr-2"></i>
                    Sistema completo de gestión documental para mantener el record de HR digitalizado. 
                    Sube cédulas, títulos, certificados, contratos y cualquier documento del empleado.
                </p>
            </div>
        </div>

        <!-- Requests -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4"><i class="fas fa-clipboard-list text-purple-400 mr-2"></i>Permisos Recientes</h3>
                <?php if (empty($permissionsList)): ?>
                    <p class="text-slate-400 text-center py-4">Sin solicitudes</p>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach (array_slice($permissionsList, 0, 5) as $perm): ?>
                            <div class="bg-slate-800/50 rounded p-3">
                                <div class="flex justify-between items-start">
                                    <p class="text-white text-sm font-semibold"><?= str_replace('_', ' ', ucwords(strtolower($perm['request_type']))) ?></p>
                                    <span class="px-2 py-1 rounded text-xs text-white <?= $perm['status'] === 'APPROVED' ? 'bg-green-500' : ($perm['status'] === 'PENDING' ? 'bg-yellow-500' : 'bg-red-500') ?>">
                                        <?= $perm['status'] ?>
                                    </span>
                                </div>
                                <p class="text-slate-400 text-xs mt-1"><?= date('d/m/Y', strtotime($perm['start_date'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="glass-card">
                <h3 class="text-lg font-semibold text-white mb-4"><i class="fas fa-umbrella-beach text-cyan-400 mr-2"></i>Vacaciones Recientes</h3>
                <?php if (empty($vacationsList)): ?>
                    <p class="text-slate-400 text-center py-4">Sin solicitudes</p>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach (array_slice($vacationsList, 0, 5) as $vac): ?>
                            <div class="bg-slate-800/50 rounded p-3">
                                <div class="flex justify-between items-start">
                                    <p class="text-white text-sm font-semibold"><?= str_replace('_', ' ', ucwords(strtolower($vac['vacation_type']))) ?></p>
                                    <span class="px-2 py-1 rounded text-xs text-white <?= $vac['status'] === 'APPROVED' ? 'bg-green-500' : ($vac['status'] === 'PENDING' ? 'bg-yellow-500' : 'bg-red-500') ?>">
                                        <?= $vac['status'] ?>
                                    </span>
                                </div>
                                <p class="text-slate-400 text-xs mt-1"><?= date('d/m/Y', strtotime($vac['start_date'])) ?> - <?= date('d/m/Y', strtotime($vac['end_date'])) ?> (<?= $vac['total_days'] ?> días)</p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>
</body>
</html>

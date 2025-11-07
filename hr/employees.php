<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

// Check permissions
ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle employee schedule update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule'])) {
    $employeeId = (int)$_POST['employee_id'];
    $scheduleTemplateId = !empty($_POST['schedule_template_id']) ? (int)$_POST['schedule_template_id'] : null;
    
    try {
        // Get user_id from employee
        $stmt = $pdo->prepare("SELECT user_id FROM employees WHERE id = ?");
        $stmt->execute([$employeeId]);
        $userId = $stmt->fetchColumn();
        
        if ($userId) {
            // Deactivate existing schedules
            deactivateEmployeeSchedules($pdo, $employeeId);
            
            // Get employee name for logging
            $empStmt = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE id = ?");
            $empStmt->execute([$employeeId]);
            $empData = $empStmt->fetch();
            $employeeName = $empData['first_name'] . ' ' . $empData['last_name'];
            
            // Get old schedule for logging
            $oldScheduleStmt = $pdo->prepare("SELECT schedule_template_id FROM employee_schedules WHERE employee_id = ? AND is_active = 1 LIMIT 1");
            $oldScheduleStmt->execute([$employeeId]);
            $oldSchedule = $oldScheduleStmt->fetchColumn();
            
            // Create new schedule from template if selected
            if ($scheduleTemplateId) {
                createEmployeeScheduleFromTemplate($pdo, $employeeId, $userId, $scheduleTemplateId);
                $successMsg = "Horario actualizado correctamente.";
            } else {
                $successMsg = "Horario eliminado. El empleado usará el horario global del sistema.";
            }
            
            // Log schedule change
            log_schedule_changed($pdo, $_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], $employeeId, $employeeName, ['schedule_template_id' => $oldSchedule], ['schedule_template_id' => $scheduleTemplateId]);
        } else {
            $errorMsg = "No se pudo encontrar el usuario asociado al empleado.";
        }
    } catch (Exception $e) {
        $errorMsg = "Error al actualizar horario: " . $e->getMessage();
    }
}

// Handle employee update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_employee'])) {
    $employeeId = (int)$_POST['employee_id'];
    
    $pdo->beginTransaction();
    
    try {
        // Get old employee data for logging
        $oldDataStmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $oldDataStmt->execute([$employeeId]);
        $oldData = $oldDataStmt->fetch(PDO::FETCH_ASSOC);
        
        // Prepare all employee data
        $data = [
            'first_name' => trim($_POST['first_name']),
            'last_name' => trim($_POST['last_name']),
            'email' => trim($_POST['email']) ?: null,
            'phone' => trim($_POST['phone']) ?: null,
            'mobile' => trim($_POST['mobile']) ?: null,
            'birth_date' => $_POST['birth_date'] ?: null,
            'position' => trim($_POST['position']) ?: null,
            'department_id' => !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null,
            'hire_date' => $_POST['hire_date'] ?: null,
            'termination_date' => $_POST['termination_date'] ?: null,
            'employment_status' => $_POST['employment_status'],
            'employment_type' => $_POST['employment_type'],
            'address' => trim($_POST['address']) ?: null,
            'city' => trim($_POST['city']) ?: null,
            'state' => trim($_POST['state']) ?: null,
            'postal_code' => trim($_POST['postal_code']) ?: null,
            'identification_type' => trim($_POST['identification_type']) ?: null,
            'identification_number' => trim($_POST['identification_number']) ?: null,
            'blood_type' => trim($_POST['blood_type']) ?: null,
            'marital_status' => trim($_POST['marital_status']) ?: null,
            'gender' => trim($_POST['gender']) ?: null,
            'emergency_contact_name' => trim($_POST['emergency_contact_name']) ?: null,
            'emergency_contact_phone' => trim($_POST['emergency_contact_phone']) ?: null,
            'emergency_contact_relationship' => trim($_POST['emergency_contact_relationship']) ?: null,
            'notes' => trim($_POST['notes']) ?: null,
            'id_card_number' => trim($_POST['id_card_number']) ?: null,
            'bank_id' => !empty($_POST['bank_id']) ? (int)$_POST['bank_id'] : null,
            'bank_account_number' => trim($_POST['bank_account_number']) ?: null,
        ];
        
        // Handle photo upload
        $photoPath = null;
        if (isset($_FILES['employee_photo']) && $_FILES['employee_photo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/employee_photos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Get current employee code
            $empStmt = $pdo->prepare("SELECT employee_code FROM employees WHERE id = ?");
            $empStmt->execute([$employeeId]);
            $empCode = $empStmt->fetchColumn();
            
            $fileExtension = strtolower(pathinfo($_FILES['employee_photo']['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($fileExtension, $allowedExtensions)) {
                $fileName = $empCode . '_' . time() . '.' . $fileExtension;
                $targetPath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['employee_photo']['tmp_name'], $targetPath)) {
                    $photoPath = 'uploads/employee_photos/' . $fileName;
                    $data['photo_path'] = $photoPath;
                }
            }
        }
        
        // Update employees table with ALL fields
        $updateSql = "
            UPDATE employees SET
                first_name = ?, last_name = ?, email = ?, phone = ?, mobile = ?,
                birth_date = ?, position = ?, department_id = ?, hire_date = ?, termination_date = ?,
                employment_status = ?, employment_type = ?,
                address = ?, city = ?, state = ?, postal_code = ?,
                identification_type = ?, identification_number = ?,
                blood_type = ?, marital_status = ?, gender = ?,
                emergency_contact_name = ?, emergency_contact_phone = ?, emergency_contact_relationship = ?,
                notes = ?, id_card_number = ?, bank_id = ?, bank_account_number = ?";
        
        $updateParams = [
            $data['first_name'], $data['last_name'], $data['email'], $data['phone'], $data['mobile'],
            $data['birth_date'], $data['position'], $data['department_id'], $data['hire_date'], $data['termination_date'],
            $data['employment_status'], $data['employment_type'],
            $data['address'], $data['city'], $data['state'], $data['postal_code'],
            $data['identification_type'], $data['identification_number'],
            $data['blood_type'], $data['marital_status'], $data['gender'],
            $data['emergency_contact_name'], $data['emergency_contact_phone'], $data['emergency_contact_relationship'],
            $data['notes'], $data['id_card_number'], $data['bank_id'], $data['bank_account_number']
        ];
        
        if (isset($data['photo_path'])) {
            $updateSql .= ", photo_path = ?";
            $updateParams[] = $data['photo_path'];
        }
        
        $updateSql .= ", updated_at = NOW() WHERE id = ?";
        $updateParams[] = $employeeId;
        
        $stmt = $pdo->prepare($updateSql);
        $stmt->execute($updateParams);
        
        // Sync to users table with all compensation fields
        $fullName = $data['first_name'] . ' ' . $data['last_name'];
        $compensationType = !empty($_POST['compensation_type']) ? trim($_POST['compensation_type']) : 'hourly';
        $hourlyRate = !empty($_POST['hourly_rate']) ? (float)$_POST['hourly_rate'] : 0.00;
        $hourlyRateDop = !empty($_POST['hourly_rate_dop']) ? (float)$_POST['hourly_rate_dop'] : 0.00;
        $monthlySalaryUsd = !empty($_POST['monthly_salary_usd']) ? (float)$_POST['monthly_salary_usd'] : 0.00;
        $monthlySalaryDop = !empty($_POST['monthly_salary_dop']) ? (float)$_POST['monthly_salary_dop'] : 0.00;
        $dailySalaryUsd = !empty($_POST['daily_salary_usd']) ? (float)$_POST['daily_salary_usd'] : 0.00;
        $dailySalaryDop = !empty($_POST['daily_salary_dop']) ? (float)$_POST['daily_salary_dop'] : 0.00;
        $preferredCurrency = !empty($_POST['preferred_currency']) ? strtoupper(trim($_POST['preferred_currency'])) : 'USD';
        
        $userStmt = $pdo->prepare("
            UPDATE users SET 
                full_name = ?, 
                compensation_type = ?,
                hourly_rate = ?, 
                hourly_rate_dop = ?,
                monthly_salary = ?,
                monthly_salary_dop = ?,
                daily_salary_usd = ?,
                daily_salary_dop = ?,
                preferred_currency = ?,
                department_id = ?
            WHERE id = (SELECT user_id FROM employees WHERE id = ?)
        ");
        $userStmt->execute([$fullName, $compensationType, $hourlyRate, $hourlyRateDop, $monthlySalaryUsd, $monthlySalaryDop, $dailySalaryUsd, $dailySalaryDop, $preferredCurrency, $data['department_id'], $employeeId]);
        
        // Log employee update
        log_employee_updated($pdo, $_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], $employeeId, $oldData, $data);
        
        $pdo->commit();
        $successMsg = "Empleado actualizado correctamente. Los cambios se sincronizaron con el usuario.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $errorMsg = "Error al actualizar empleado: " . $e->getMessage();
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$departmentFilter = $_GET['department'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Build query
$query = "
    SELECT e.*, u.username, u.compensation_type, u.hourly_rate, u.hourly_rate_dop, 
           u.monthly_salary, u.monthly_salary_dop, u.daily_salary_usd, u.daily_salary_dop,
           u.preferred_currency, u.role, d.name as department_name,
           b.name as bank_name,
           DATEDIFF(CURDATE(), e.hire_date) as days_employed,
           YEAR(CURDATE()) - YEAR(e.birth_date) as age
    FROM employees e
    JOIN users u ON u.id = e.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN banks b ON b.id = e.bank_id
    WHERE 1=1
";

$params = [];

if ($statusFilter !== 'all') {
    $query .= " AND e.employment_status = ?";
    $params[] = strtoupper($statusFilter);
}

if ($departmentFilter !== 'all') {
    $query .= " AND e.department_id = ?";
    $params[] = (int)$departmentFilter;
}

if ($searchQuery !== '') {
    $query .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ? OR u.username LIKE ?)";
    $searchParam = "%$searchQuery%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$query .= " ORDER BY e.last_name, e.first_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments for filters
$departments = getAllDepartments($pdo);

// Get banks for form
$banks = getAllBanks($pdo);

// Get schedule templates for form
$scheduleTemplates = getAllScheduleTemplates($pdo);

// Get statistics
$stats = [
    'total' => $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn(),
    'active' => $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status = 'ACTIVE'")->fetchColumn(),
    'trial' => $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status = 'TRIAL'")->fetchColumn(),
    'terminated' => $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status = 'TERMINATED'")->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Empleados - HR</title>
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
                    <i class="fas fa-id-card text-blue-400 mr-3"></i>
                    Gestión de Empleados
                </h1>
                <p class="text-slate-400">Directorio completo de empleados</p>
            </div>
            <div class="flex gap-3">
                <a href="new_employee.php" class="btn-primary">
                    <i class="fas fa-user-plus"></i>
                    Nuevo Empleado
                </a>
                <a href="index.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver a HR
                </a>
            </div>
        </div>

        <?php if (isset($successMsg)): ?>
            <div class="status-banner success mb-6"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Total</p>
                        <h3 class="text-3xl font-bold text-white"><?= $stats['total'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Activos</p>
                        <h3 class="text-3xl font-bold text-white"><?= $stats['active'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                        <i class="fas fa-user-check text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">En Prueba</p>
                        <h3 class="text-3xl font-bold text-white"><?= $stats['trial'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                        <i class="fas fa-user-clock text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Terminados</p>
                        <h3 class="text-3xl font-bold text-white"><?= $stats['terminated'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                        <i class="fas fa-user-times text-white text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="glass-card mb-6">
            <form method="GET" class="flex flex-wrap gap-4 items-end">
                <div class="form-group flex-1 min-w-[200px]">
                    <label for="search">Buscar</label>
                    <input type="text" id="search" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Nombre, código o usuario...">
                </div>
                <div class="form-group flex-1 min-w-[150px]">
                    <label for="status">Estado</label>
                    <select id="status" name="status">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todos</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Activos</option>
                        <option value="trial" <?= $statusFilter === 'trial' ? 'selected' : '' ?>>En Prueba</option>
                        <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspendidos</option>
                        <option value="terminated" <?= $statusFilter === 'terminated' ? 'selected' : '' ?>>Terminados</option>
                    </select>
                </div>
                <div class="form-group flex-1 min-w-[150px]">
                    <label for="department">Departamento</label>
                    <select id="department" name="department">
                        <option value="all" <?= $departmentFilter === 'all' ? 'selected' : '' ?>>Todos</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" <?= $departmentFilter == $dept['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-search"></i>
                    Buscar
                </button>
                <button type="button" onclick="window.location.href='employees.php'" class="btn-secondary">
                    <i class="fas fa-redo"></i>
                    Limpiar
                </button>
            </form>
        </div>

        <!-- Employees Grid -->
        <div class="glass-card">
            <h2 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-list text-blue-400 mr-2"></i>
                Empleados (<?= count($employees) ?>)
            </h2>

            <?php if (empty($employees)): ?>
                <p class="text-slate-400 text-center py-8">No se encontraron empleados.</p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($employees as $employee): ?>
                        <?php
                        $statusColors = [
                            'ACTIVE' => 'bg-green-500',
                            'TRIAL' => 'bg-yellow-500',
                            'SUSPENDED' => 'bg-orange-500',
                            'TERMINATED' => 'bg-red-500'
                        ];
                        $statusColor = $statusColors[$employee['employment_status']] ?? 'bg-gray-500';
                        ?>
                        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700 hover:border-blue-500 transition-all">
                            <div class="flex items-start gap-3 mb-3">
                                <?php if (!empty($employee['photo_path']) && file_exists('../' . $employee['photo_path'])): ?>
                                    <img src="../<?= htmlspecialchars($employee['photo_path']) ?>" 
                                         alt="<?= htmlspecialchars($employee['first_name']) ?>" 
                                         class="w-14 h-14 rounded-full object-cover flex-shrink-0 border-2 border-blue-500">
                                <?php else: ?>
                                    <div class="w-14 h-14 rounded-full flex items-center justify-center text-xl font-bold text-white flex-shrink-0" 
                                         style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                                        <?= strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-lg font-semibold text-white truncate">
                                        <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>
                                    </h3>
                                    <p class="text-slate-400 text-sm"><?= htmlspecialchars($employee['employee_code']) ?></p>
                                    <span class="inline-block px-2 py-1 rounded text-xs font-semibold text-white mt-1 <?= $statusColor ?>">
                                        <?= htmlspecialchars($employee['employment_status']) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="space-y-2 text-sm mb-3">
                                <?php if ($employee['position']): ?>
                                    <p class="text-slate-300">
                                        <i class="fas fa-briefcase text-indigo-400 mr-2 w-4"></i>
                                        <?= htmlspecialchars($employee['position']) ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ($employee['department_name']): ?>
                                    <p class="text-slate-300">
                                        <i class="fas fa-building text-blue-400 mr-2 w-4"></i>
                                        <?= htmlspecialchars($employee['department_name']) ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ($employee['email']): ?>
                                    <p class="text-slate-300 truncate">
                                        <i class="fas fa-envelope text-green-400 mr-2 w-4"></i>
                                        <?= htmlspecialchars($employee['email']) ?>
                                    </p>
                                <?php endif; ?>
                                <?php if ($employee['phone']): ?>
                                    <p class="text-slate-300">
                                        <i class="fas fa-phone text-purple-400 mr-2 w-4"></i>
                                        <?= htmlspecialchars($employee['phone']) ?>
                                    </p>
                                <?php endif; ?>
                                <p class="text-slate-300">
                                    <i class="fas fa-calendar text-orange-400 mr-2 w-4"></i>
                                    Ingreso: <?= date('d/m/Y', strtotime($employee['hire_date'])) ?>
                                </p>
                                <p class="text-slate-300">
                                    <i class="fas fa-dollar-sign text-green-400 mr-2 w-4"></i>
                                    $<?= number_format($employee['hourly_rate'], 2) ?>/hr
                                </p>
                            </div>

                            <div class="flex gap-2">
                                <button onclick="editEmployee(<?= htmlspecialchars(json_encode($employee)) ?>)" class="btn-primary text-sm flex-1">
                                    <i class="fas fa-edit"></i>
                                    Editar
                                </button>
                                <a href="employee_profile.php?id=<?= $employee['id'] ?>" class="btn-secondary text-sm flex-1 text-center">
                                    <i class="fas fa-eye"></i>
                                    Ver
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Employee Modal -->
    <div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto">
        <div class="glass-card m-4" style="width: min(800px, 95%); max-height: 90vh; overflow-y: auto;">
            <h3 class="text-xl font-semibold text-white mb-4">Editar Empleado</h3>
            <form method="POST" enctype="multipart/form-data" id="editForm">
                <input type="hidden" name="update_employee" value="1">
                <input type="hidden" name="employee_id" id="edit_employee_id">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="edit_first_name">Nombre *</label>
                        <input type="text" id="edit_first_name" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_last_name">Apellido *</label>
                        <input type="text" id="edit_last_name" name="last_name" required>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" id="edit_email" name="email">
                    </div>
                    <div class="form-group">
                        <label for="edit_phone">Teléfono</label>
                        <input type="tel" id="edit_phone" name="phone">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="edit_mobile">Móvil</label>
                        <input type="tel" id="edit_mobile" name="mobile">
                    </div>
                    <div class="form-group">
                        <label for="edit_birth_date">Fecha de nacimiento</label>
                        <input type="date" id="edit_birth_date" name="birth_date">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="edit_position">Posición</label>
                        <input type="text" id="edit_position" name="position">
                    </div>
                    <div class="form-group">
                        <label for="edit_department_id">Departamento</label>
                        <select id="edit_department_id" name="department_id">
                            <option value="">Sin departamento</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="edit_hire_date">Fecha de ingreso *</label>
                        <input type="date" id="edit_hire_date" name="hire_date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_termination_date">Fecha de terminación</label>
                        <input type="date" id="edit_termination_date" name="termination_date">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="edit_employment_status">Estado *</label>
                        <select id="edit_employment_status" name="employment_status" required>
                            <option value="ACTIVE">Activo</option>
                            <option value="TRIAL">En Prueba</option>
                            <option value="SUSPENDED">Suspendido</option>
                            <option value="TERMINATED">Terminado</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_employment_type">Tipo *</label>
                        <select id="edit_employment_type" name="employment_type" required>
                            <option value="FULL_TIME">Tiempo Completo</option>
                            <option value="PART_TIME">Medio Tiempo</option>
                            <option value="CONTRACT">Contrato</option>
                            <option value="INTERN">Pasante</option>
                        </select>
                    </div>
                </div>

                <h4 class="text-md font-semibold text-white mt-4 mb-3 border-b border-slate-700 pb-2">
                    <i class="fas fa-dollar-sign text-blue-400 mr-2"></i>
                    Compensación y Salario
                </h4>
                
                <div class="form-group mb-4">
                    <label for="edit_compensation_type">Tipo de Compensación *</label>
                    <select id="edit_compensation_type" name="compensation_type" onchange="toggleEditCompensationFields()" required>
                        <option value="hourly">Salario por Hora</option>
                        <option value="fixed">Salario Fijo (Mensual)</option>
                        <option value="daily">Salario Diario</option>
                    </select>
                </div>
                
                <!-- Campos para Salario por Hora -->
                <div id="edit_hourly_fields" class="edit-compensation-fields mb-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label for="edit_hourly_rate">Tarifa por hora (USD)</label>
                            <input type="number" id="edit_hourly_rate" name="hourly_rate" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label for="edit_hourly_rate_dop">Tarifa por hora (DOP)</label>
                            <input type="number" id="edit_hourly_rate_dop" name="hourly_rate_dop" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                </div>
                
                <!-- Campos para Salario Fijo -->
                <div id="edit_fixed_fields" class="edit-compensation-fields hidden mb-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label for="edit_monthly_salary_usd">Salario mensual (USD)</label>
                            <input type="number" id="edit_monthly_salary_usd" name="monthly_salary_usd" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label for="edit_monthly_salary_dop">Salario mensual (DOP)</label>
                            <input type="number" id="edit_monthly_salary_dop" name="monthly_salary_dop" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                </div>
                
                <!-- Campos para Salario Diario -->
                <div id="edit_daily_fields" class="edit-compensation-fields hidden mb-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label for="edit_daily_salary_usd">Salario diario (USD)</label>
                            <input type="number" id="edit_daily_salary_usd" name="daily_salary_usd" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label for="edit_daily_salary_dop">Salario diario (DOP)</label>
                            <input type="number" id="edit_daily_salary_dop" name="daily_salary_dop" step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                </div>
                
                <div class="form-group mb-4">
                    <label for="edit_preferred_currency">Moneda Preferida</label>
                    <select id="edit_preferred_currency" name="preferred_currency">
                        <option value="USD">USD (Dólares)</option>
                        <option value="DOP">DOP (Pesos Dominicanos)</option>
                    </select>
                </div>

                <div class="form-group mb-4">
                    <label for="edit_address">Dirección</label>
                    <textarea id="edit_address" name="address" rows="2"></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="form-group">
                        <label for="edit_city">Ciudad</label>
                        <input type="text" id="edit_city" name="city">
                    </div>
                    <div class="form-group">
                        <label for="edit_state">Estado/Provincia</label>
                        <input type="text" id="edit_state" name="state">
                    </div>
                    <div class="form-group">
                        <label for="edit_postal_code">Código Postal</label>
                        <input type="text" id="edit_postal_code" name="postal_code">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="edit_identification_type">Tipo de Identificación</label>
                        <select id="edit_identification_type" name="identification_type">
                            <option value="">Seleccionar...</option>
                            <option value="Cédula">Cédula</option>
                            <option value="Pasaporte">Pasaporte</option>
                            <option value="Licencia">Licencia de Conducir</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_identification_number">Número de Identificación</label>
                        <input type="text" id="edit_identification_number" name="identification_number">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="form-group">
                        <label for="edit_blood_type">Tipo de Sangre</label>
                        <select id="edit_blood_type" name="blood_type">
                            <option value="">Seleccionar...</option>
                            <option value="A+">A+</option>
                            <option value="A-">A-</option>
                            <option value="B+">B+</option>
                            <option value="B-">B-</option>
                            <option value="AB+">AB+</option>
                            <option value="AB-">AB-</option>
                            <option value="O+">O+</option>
                            <option value="O-">O-</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_marital_status">Estado Civil</label>
                        <select id="edit_marital_status" name="marital_status">
                            <option value="">Seleccionar...</option>
                            <option value="Soltero/a">Soltero/a</option>
                            <option value="Casado/a">Casado/a</option>
                            <option value="Divorciado/a">Divorciado/a</option>
                            <option value="Viudo/a">Viudo/a</option>
                            <option value="Unión Libre">Unión Libre</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_gender">Género</label>
                        <select id="edit_gender" name="gender">
                            <option value="">Seleccionar...</option>
                            <option value="Masculino">Masculino</option>
                            <option value="Femenino">Femenino</option>
                            <option value="Otro">Otro</option>
                        </select>
                    </div>
                </div>

                <h4 class="text-lg font-semibold text-white mb-3 mt-6">Contacto de Emergencia</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="form-group">
                        <label for="edit_emergency_contact_name">Nombre</label>
                        <input type="text" id="edit_emergency_contact_name" name="emergency_contact_name">
                    </div>
                    <div class="form-group">
                        <label for="edit_emergency_contact_phone">Teléfono</label>
                        <input type="tel" id="edit_emergency_contact_phone" name="emergency_contact_phone">
                    </div>
                    <div class="form-group">
                        <label for="edit_emergency_contact_relationship">Relación</label>
                        <input type="text" id="edit_emergency_contact_relationship" name="emergency_contact_relationship" placeholder="Ej: Madre, Esposo/a">
                    </div>
                </div>

                <div class="form-group mb-6">
                    <label for="edit_notes">Notas</label>
                    <textarea id="edit_notes" name="notes" rows="3" placeholder="Notas adicionales sobre el empleado..."></textarea>
                </div>

                <h4 class="text-lg font-semibold text-white mb-3 mt-6">
                    <i class="fas fa-university text-blue-400 mr-2"></i>
                    Información Bancaria
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="edit_id_card_number">Número de Cédula</label>
                        <input type="text" id="edit_id_card_number" name="id_card_number" placeholder="000-0000000-0">
                    </div>
                    <div class="form-group">
                        <label for="edit_bank_id">Banco</label>
                        <select id="edit_bank_id" name="bank_id">
                            <option value="">Sin banco</option>
                            <?php foreach ($banks as $bank): ?>
                                <option value="<?= $bank['id'] ?>"><?= htmlspecialchars($bank['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group mb-4">
                    <label for="edit_bank_account_number">Número de Cuenta Bancaria</label>
                    <input type="text" id="edit_bank_account_number" name="bank_account_number" placeholder="Número de cuenta">
                </div>

                <div class="form-group mb-6">
                    <label for="edit_employee_photo">Foto del Empleado</label>
                    <div id="current_photo_preview" class="mb-2"></div>
                    <input type="file" id="edit_employee_photo" name="employee_photo" accept="image/jpeg,image/png,image/gif,image/jpg" class="block w-full text-sm text-slate-400
                        file:mr-4 file:py-2 file:px-4
                        file:rounded-lg file:border-0
                        file:text-sm file:font-semibold
                        file:bg-blue-500 file:text-white
                        hover:file:bg-blue-600
                        file:cursor-pointer">
                    <p class="text-xs text-slate-400 mt-1">Formatos permitidos: JPG, PNG, GIF (Máx. 5MB)</p>
                </div>

                <h4 class="text-lg font-semibold text-white mb-3 mt-6">
                    <i class="fas fa-clock text-blue-400 mr-2"></i>
                    Horario de Trabajo
                </h4>
                <div class="form-group mb-4">
                    <label for="edit_schedule_template_id">Turno / Horario</label>
                    <div class="flex gap-2">
                        <select id="edit_schedule_template_id" name="schedule_template_id" class="flex-1" onchange="updateScheduleButtonsEdit()">
                            <option value="">Usar horario global del sistema</option>
                            <?php foreach ($scheduleTemplates as $template): ?>
                                <?php 
                                $timeInfo = date('g:i A', strtotime($template['entry_time'])) . ' - ' . date('g:i A', strtotime($template['exit_time']));
                                ?>
                                <option value="<?= $template['id'] ?>">
                                    <?= htmlspecialchars($template['name']) ?> (<?= $timeInfo ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="openNewScheduleModalEdit()" class="btn-secondary px-3 whitespace-nowrap" title="Crear nuevo turno">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button type="button" id="editScheduleBtnEdit" onclick="editSelectedScheduleEdit()" class="btn-secondary px-3 whitespace-nowrap hidden" title="Editar turno seleccionado">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" id="deleteScheduleBtnEdit" onclick="deleteSelectedScheduleEdit()" class="btn-secondary px-3 whitespace-nowrap hidden" title="Eliminar turno seleccionado" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">
                        <i class="fas fa-info-circle"></i>
                        El horario se aplicará automáticamente en el sistema de ponche.
                    </p>
                    <div id="current_schedule_info" class="mt-2 p-3 bg-slate-700/50 rounded-lg text-sm"></div>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-save"></i>
                        Guardar Cambios
                    </button>
                    <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para crear nuevo turno -->
    <div id="newScheduleModalEdit" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto">
        <div class="glass-card m-4" style="width: min(600px, 95%); max-height: 90vh; overflow-y: auto;">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-clock text-blue-400 mr-2"></i>
                Crear Nuevo Turno
            </h3>
            <form id="newScheduleFormEdit" onsubmit="saveNewScheduleEdit(event)">
                <div class="form-group mb-4">
                    <label for="new_schedule_name_edit">Nombre del Turno *</label>
                    <input type="text" id="new_schedule_name_edit" name="name" required placeholder="Ej: Turno Especial 8am-5pm">
                </div>

                <div class="form-group mb-4">
                    <label for="new_schedule_description_edit">Descripción</label>
                    <textarea id="new_schedule_description_edit" name="description" rows="2" placeholder="Descripción opcional del turno"></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="new_entry_time_edit">Hora de Entrada *</label>
                        <input type="time" id="new_entry_time_edit" name="entry_time" required value="10:00">
                    </div>
                    <div class="form-group">
                        <label for="new_exit_time_edit">Hora de Salida *</label>
                        <input type="time" id="new_exit_time_edit" name="exit_time" required value="19:00">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="new_lunch_time_edit">Hora de Almuerzo</label>
                        <input type="time" id="new_lunch_time_edit" name="lunch_time" value="14:00">
                    </div>
                    <div class="form-group">
                        <label for="new_break_time_edit">Hora de Descanso</label>
                        <input type="time" id="new_break_time_edit" name="break_time" value="17:00">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="form-group">
                        <label for="new_lunch_minutes_edit">Minutos Almuerzo</label>
                        <input type="number" id="new_lunch_minutes_edit" name="lunch_minutes" min="0" value="45">
                    </div>
                    <div class="form-group">
                        <label for="new_break_minutes_edit">Minutos Descanso</label>
                        <input type="number" id="new_break_minutes_edit" name="break_minutes" min="0" value="15">
                    </div>
                    <div class="form-group">
                        <label for="new_scheduled_hours_edit">Horas Programadas</label>
                        <input type="number" id="new_scheduled_hours_edit" name="scheduled_hours" step="0.25" min="0" value="8.00">
                    </div>
                </div>

                <div id="scheduleFormMessageEdit" class="mb-4 hidden"></div>

                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-save"></i>
                        Guardar Turno
                    </button>
                    <button type="button" onclick="closeNewScheduleModalEdit()" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function editEmployee(employee) {
            // Basic Info
            document.getElementById('edit_employee_id').value = employee.id;
            document.getElementById('edit_first_name').value = employee.first_name || '';
            document.getElementById('edit_last_name').value = employee.last_name || '';
            document.getElementById('edit_email').value = employee.email || '';
            document.getElementById('edit_phone').value = employee.phone || '';
            document.getElementById('edit_mobile').value = employee.mobile || '';
            document.getElementById('edit_birth_date').value = employee.birth_date || '';
            
            // Employment Info
            document.getElementById('edit_position').value = employee.position || '';
            document.getElementById('edit_department_id').value = employee.department_id || '';
            document.getElementById('edit_hire_date').value = employee.hire_date || '';
            document.getElementById('edit_termination_date').value = employee.termination_date || '';
            document.getElementById('edit_employment_status').value = employee.employment_status || 'ACTIVE';
            document.getElementById('edit_employment_type').value = employee.employment_type || 'FULL_TIME';
            
            // Compensation Info
            document.getElementById('edit_compensation_type').value = employee.compensation_type || 'hourly';
            document.getElementById('edit_hourly_rate').value = employee.hourly_rate || '';
            document.getElementById('edit_hourly_rate_dop').value = employee.hourly_rate_dop || '';
            document.getElementById('edit_monthly_salary_usd').value = employee.monthly_salary || '';
            document.getElementById('edit_monthly_salary_dop').value = employee.monthly_salary_dop || '';
            document.getElementById('edit_daily_salary_usd').value = employee.daily_salary_usd || '';
            document.getElementById('edit_daily_salary_dop').value = employee.daily_salary_dop || '';
            document.getElementById('edit_preferred_currency').value = employee.preferred_currency || 'USD';
            
            // Toggle compensation fields based on type
            toggleEditCompensationFields();
            
            // Address Info
            document.getElementById('edit_address').value = employee.address || '';
            document.getElementById('edit_city').value = employee.city || '';
            document.getElementById('edit_state').value = employee.state || '';
            document.getElementById('edit_postal_code').value = employee.postal_code || '';
            
            // Identification
            document.getElementById('edit_identification_type').value = employee.identification_type || '';
            document.getElementById('edit_identification_number').value = employee.identification_number || '';
            
            // Personal Details
            document.getElementById('edit_blood_type').value = employee.blood_type || '';
            document.getElementById('edit_marital_status').value = employee.marital_status || '';
            document.getElementById('edit_gender').value = employee.gender || '';
            
            // Emergency Contact
            document.getElementById('edit_emergency_contact_name').value = employee.emergency_contact_name || '';
            document.getElementById('edit_emergency_contact_phone').value = employee.emergency_contact_phone || '';
            document.getElementById('edit_emergency_contact_relationship').value = employee.emergency_contact_relationship || '';
            
            // Notes
            document.getElementById('edit_notes').value = employee.notes || '';
            
            // Banking Info
            document.getElementById('edit_id_card_number').value = employee.id_card_number || '';
            document.getElementById('edit_bank_id').value = employee.bank_id || '';
            document.getElementById('edit_bank_account_number').value = employee.bank_account_number || '';
            
            // Photo Preview
            const photoPreview = document.getElementById('current_photo_preview');
            if (employee.photo_path) {
                photoPreview.innerHTML = '<img src="../' + employee.photo_path + '" alt="Foto actual" class="w-24 h-24 rounded-lg object-cover border-2 border-blue-500">';
            } else {
                photoPreview.innerHTML = '<p class="text-slate-400 text-sm">Sin foto actual</p>';
            }
            
            // Load current schedule
            loadEmployeeSchedule(employee.id);
            
            document.getElementById('editModal').classList.remove('hidden');
        }
        
        function loadEmployeeSchedule(employeeId) {
            fetch(`get_employee_schedule.php?employee_id=${employeeId}`)
                .then(response => response.json())
                .then(data => {
                    const scheduleInfo = document.getElementById('current_schedule_info');
                    const scheduleSelect = document.getElementById('edit_schedule_template_id');
                    
                    if (data.schedule) {
                        const schedule = data.schedule;
                        scheduleInfo.innerHTML = `
                            <div class="text-green-400">
                                <i class="fas fa-check-circle mr-2"></i>
                                <strong>Horario Actual:</strong> ${schedule.schedule_name || 'Horario Personalizado'}
                            </div>
                            <div class="text-slate-300 mt-1">
                                <i class="fas fa-clock mr-2"></i>
                                ${schedule.entry_time} - ${schedule.exit_time} 
                                (${schedule.scheduled_hours} horas)
                            </div>
                        `;
                        // Try to select the matching template if it exists
                        scheduleSelect.value = '';
                    } else {
                        scheduleInfo.innerHTML = `
                            <div class="text-slate-400">
                                <i class="fas fa-info-circle mr-2"></i>
                                Usando horario global del sistema
                            </div>
                        `;
                        scheduleSelect.value = '';
                    }
                })
                .catch(error => {
                    console.error('Error loading schedule:', error);
                    document.getElementById('current_schedule_info').innerHTML = `
                        <div class="text-slate-400">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            No se pudo cargar el horario actual
                        </div>
                    `;
                });
        }
        
        let isEditModeEdit = false;
        let editingScheduleIdEdit = null;

        function updateScheduleButtonsEdit() {
            const select = document.getElementById('edit_schedule_template_id');
            const editBtn = document.getElementById('editScheduleBtnEdit');
            const deleteBtn = document.getElementById('deleteScheduleBtnEdit');
            
            if (select.value && select.value !== '') {
                editBtn.classList.remove('hidden');
                deleteBtn.classList.remove('hidden');
            } else {
                editBtn.classList.add('hidden');
                deleteBtn.classList.add('hidden');
            }
        }

        function openNewScheduleModalEdit() {
            isEditModeEdit = false;
            editingScheduleIdEdit = null;
            document.getElementById('newScheduleModalEdit').classList.remove('hidden');
            document.getElementById('newScheduleFormEdit').reset();
            document.getElementById('scheduleFormMessageEdit').classList.add('hidden');
            document.querySelector('#newScheduleModalEdit h3').innerHTML = '<i class="fas fa-clock text-blue-400 mr-2"></i>Crear Nuevo Turno';
        }

        function editSelectedScheduleEdit() {
            const select = document.getElementById('edit_schedule_template_id');
            const scheduleId = select.value;
            
            if (!scheduleId) return;
            
            fetch('../get_schedule_template.php?id=' + scheduleId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const template = data.template;
                        isEditModeEdit = true;
                        editingScheduleIdEdit = template.id;
                        
                        document.getElementById('new_schedule_name_edit').value = template.name;
                        document.getElementById('new_schedule_description_edit').value = template.description || '';
                        document.getElementById('new_entry_time_edit').value = template.entry_time.substring(0, 5);
                        document.getElementById('new_exit_time_edit').value = template.exit_time.substring(0, 5);
                        document.getElementById('new_lunch_time_edit').value = template.lunch_time ? template.lunch_time.substring(0, 5) : '14:00';
                        document.getElementById('new_break_time_edit').value = template.break_time ? template.break_time.substring(0, 5) : '17:00';
                        document.getElementById('new_lunch_minutes_edit').value = template.lunch_minutes;
                        document.getElementById('new_break_minutes_edit').value = template.break_minutes;
                        document.getElementById('new_scheduled_hours_edit').value = template.scheduled_hours;
                        
                        document.querySelector('#newScheduleModalEdit h3').innerHTML = '<i class="fas fa-edit text-blue-400 mr-2"></i>Editar Turno';
                        document.getElementById('newScheduleModalEdit').classList.remove('hidden');
                        document.getElementById('scheduleFormMessageEdit').classList.add('hidden');
                    } else {
                        alert('Error al cargar el turno: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error al cargar el turno: ' + error.message);
                });
        }

        function deleteSelectedScheduleEdit() {
            const select = document.getElementById('edit_schedule_template_id');
            const scheduleId = select.value;
            const scheduleName = select.options[select.selectedIndex].text;
            
            if (!scheduleId) return;
            
            if (!confirm('¿Estás seguro de que deseas eliminar el turno "' + scheduleName + '"?\n\nEsta acción no se puede deshacer.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('id', scheduleId);
            
            fetch('../delete_schedule_template.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    select.remove(select.selectedIndex);
                    updateScheduleButtonsEdit();
                    alert(data.message);
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error al eliminar el turno: ' + error.message);
            });
        }

        function closeNewScheduleModalEdit() {
            document.getElementById('newScheduleModalEdit').classList.add('hidden');
            isEditModeEdit = false;
            editingScheduleIdEdit = null;
        }

        function saveNewScheduleEdit(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            const messageDiv = document.getElementById('scheduleFormMessageEdit');
            
            if (isEditModeEdit && editingScheduleIdEdit) {
                formData.append('id', editingScheduleIdEdit);
            }
            
            messageDiv.className = 'status-banner mb-4';
            messageDiv.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Guardando turno...';
            messageDiv.classList.remove('hidden');
            
            const endpoint = isEditModeEdit ? '../update_schedule_template.php' : '../save_schedule_template.php';
            
            fetch(endpoint, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageDiv.className = 'status-banner success mb-4';
                    messageDiv.innerHTML = '<i class="fas fa-check-circle mr-2"></i>' + data.message;
                    
                    const select = document.getElementById('edit_schedule_template_id');
                    const template = data.template;
                    const entryTime = new Date('2000-01-01 ' + template.entry_time);
                    const exitTime = new Date('2000-01-01 ' + template.exit_time);
                    const timeInfo = entryTime.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'}) + 
                                   ' - ' + exitTime.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
                    
                    if (isEditModeEdit) {
                        const option = select.querySelector('option[value="' + template.id + '"]');
                        if (option) {
                            option.textContent = template.name + ' (' + timeInfo + ')';
                        }
                    } else {
                        const option = document.createElement('option');
                        option.value = template.id;
                        option.textContent = template.name + ' (' + timeInfo + ')';
                        option.selected = true;
                        select.appendChild(option);
                    }
                    
                    updateScheduleButtonsEdit();
                    
                    setTimeout(() => {
                        closeNewScheduleModalEdit();
                    }, 1000);
                } else {
                    messageDiv.className = 'status-banner error mb-4';
                    messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>' + data.error;
                }
            })
            .catch(error => {
                messageDiv.className = 'status-banner error mb-4';
                messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Error al guardar: ' + error.message;
            });
        }

        // Close modal when clicking outside
        document.getElementById('newScheduleModalEdit')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeNewScheduleModalEdit();
            }
        });
        
        // Función para mostrar/ocultar campos según el tipo de compensación en el formulario de edición
        function toggleEditCompensationFields() {
            const compensationType = document.getElementById('edit_compensation_type').value;
            const hourlyFields = document.getElementById('edit_hourly_fields');
            const fixedFields = document.getElementById('edit_fixed_fields');
            const dailyFields = document.getElementById('edit_daily_fields');
            
            // Ocultar todos los campos
            hourlyFields.classList.add('hidden');
            fixedFields.classList.add('hidden');
            dailyFields.classList.add('hidden');
            
            // Mostrar campos correspondientes
            if (compensationType === 'hourly') {
                hourlyFields.classList.remove('hidden');
            } else if (compensationType === 'fixed') {
                fixedFields.classList.remove('hidden');
            } else if (compensationType === 'daily') {
                dailyFields.classList.remove('hidden');
            }
        }
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>

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
            'supervisor_id' => !empty($_POST['supervisor_id']) ? (int)$_POST['supervisor_id'] : null,
            'campaign_id' => !empty($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : null,
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
                notes = ?, id_card_number = ?, bank_id = ?, bank_account_number = ?,
                supervisor_id = ?, campaign_id = ?";
        
        $updateParams = [
            $data['first_name'], $data['last_name'], $data['email'], $data['phone'], $data['mobile'],
            $data['birth_date'], $data['position'], $data['department_id'], $data['hire_date'], $data['termination_date'],
            $data['employment_status'], $data['employment_type'],
            $data['address'], $data['city'], $data['state'], $data['postal_code'],
            $data['identification_type'], $data['identification_number'],
            $data['blood_type'], $data['marital_status'], $data['gender'],
            $data['emergency_contact_name'], $data['emergency_contact_phone'], $data['emergency_contact_relationship'],
            $data['notes'], $data['id_card_number'], $data['bank_id'], $data['bank_account_number'],
            $data['supervisor_id'], $data['campaign_id']
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reinstate_employee'])) {
    $employeeId = (int)($_POST['employee_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("UPDATE employees SET employment_status = 'ACTIVE', termination_date = NULL, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$employeeId]);
        $successMsg = "Empleado reinstalado correctamente.";
    } catch (Exception $e) {
        $errorMsg = "Error al reinstalar empleado: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_employee'])) {
    $employeeId = (int)($_POST['employee_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->execute([$employeeId]);
        $successMsg = "Empleado eliminado correctamente.";
    } catch (Exception $e) {
        $errorMsg = "No se pudo eliminar el empleado.";
    }
}

// Get filter parameters
$statusFilter = $_GET['status'] ?? 'all';
$departmentFilter = $_GET['department'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Build query
$query = "
    SELECT e.*, 
           CONCAT(e.first_name, ' ', e.last_name) as full_name,
           u.username, u.compensation_type, u.hourly_rate, u.hourly_rate_dop, 
           u.monthly_salary, u.monthly_salary_dop, u.daily_salary_usd, u.daily_salary_dop,
           u.preferred_currency, u.role, d.name as department_name,
           b.name as bank_name,
           c.name as campaign_name, c.code as campaign_code, c.color as campaign_color,
           s.full_name as supervisor_name,
           DATEDIFF(CURDATE(), e.hire_date) as days_employed,
           YEAR(CURDATE()) - YEAR(e.birth_date) as age
    FROM employees e
    JOIN users u ON u.id = e.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN banks b ON b.id = e.bank_id
    LEFT JOIN campaigns c ON c.id = e.campaign_id
    LEFT JOIN users s ON s.id = e.supervisor_id
    WHERE 1=1
";

$params = [];

if ($statusFilter !== 'all') {
    $query .= " AND e.employment_status = ?";
    $params[] = strtoupper($statusFilter);
} else {
    $query .= " AND e.employment_status <> 'TERMINATED'";
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

// Quick assign data sources
$quickAssignCampaigns = $pdo->query("
    SELECT id, name, code, color
    FROM campaigns
    WHERE is_active = 1
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$quickAssignSupervisors = $pdo->query("
    SELECT id, full_name, role
    FROM users
    WHERE role IN ('Supervisor', 'Admin', 'HR', 'Manager') AND is_active = 1
    ORDER BY full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [
    'total' => $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn(),
    'active' => $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status = 'ACTIVE'")->fetchColumn(),
    'trial' => $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status = 'TRIAL'")->fetchColumn(),
    'terminated' => $pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status = 'TERMINATED'")->fetchColumn(),
];

$terminatedEmployees = $pdo->query("
    SELECT e.*, 
           CONCAT(e.first_name, ' ', e.last_name) as full_name,
           u.username, u.compensation_type, u.hourly_rate, u.hourly_rate_dop, 
           u.monthly_salary, u.monthly_salary_dop, u.daily_salary_usd, u.daily_salary_dop,
           u.preferred_currency, u.role, d.name as department_name,
           b.name as bank_name,
           c.name as campaign_name, c.code as campaign_code, c.color as campaign_color,
           s.full_name as supervisor_name,
           DATEDIFF(CURDATE(), e.hire_date) as days_employed,
           YEAR(CURDATE()) - YEAR(e.birth_date) as age
    FROM employees e
    JOIN users u ON u.id = e.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN banks b ON b.id = e.bank_id
    LEFT JOIN campaigns c ON c.id = e.campaign_id
    LEFT JOIN users s ON s.id = e.supervisor_id
    WHERE e.employment_status = 'TERMINATED'
    ORDER BY e.termination_date DESC, e.last_name, e.first_name
")->fetchAll(PDO::FETCH_ASSOC);
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
                <button type="button" class="btn-secondary" onclick="openTerminatedEmployees()">
                    <i class="fas fa-user-times"></i>
                    Terminados (<?= $stats['terminated'] ?>)
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

        <script>
        (function() {
            // Helper function para asignar valores de forma segura
            function setValue(id, value) {
                const element = document.getElementById(id);
                if (element) {
                    element.value = value || '';
                }
            }
            
            // Hacer funciones globales
            window.editEmployee = function(button) {
                const employee = JSON.parse(button.dataset.employee);
                
                // Basic Info
                setValue('edit_employee_id', employee.id);
                setValue('edit_first_name', employee.first_name);
                setValue('edit_last_name', employee.last_name);
                setValue('edit_email', employee.email);
                setValue('edit_phone', employee.phone);
                setValue('edit_mobile', employee.mobile);
                setValue('edit_birth_date', employee.birth_date);
                
                // Employment Info
                setValue('edit_position', employee.position);
                setValue('edit_department_id', employee.department_id);
                setValue('edit_hire_date', employee.hire_date);
                setValue('edit_termination_date', employee.termination_date);
                setValue('edit_employment_status', employee.employment_status || 'ACTIVE');
                setValue('edit_employment_type', employee.employment_type || 'FULL_TIME');
                
                // Compensation Info
                setValue('edit_compensation_type', employee.compensation_type || 'hourly');
                setValue('edit_hourly_rate', employee.hourly_rate);
                setValue('edit_hourly_rate_dop', employee.hourly_rate_dop);
                setValue('edit_monthly_salary_usd', employee.monthly_salary);
                setValue('edit_monthly_salary_dop', employee.monthly_salary_dop);
                setValue('edit_daily_salary_usd', employee.daily_salary_usd);
                setValue('edit_daily_salary_dop', employee.daily_salary_dop);
                setValue('edit_preferred_currency', employee.preferred_currency || 'USD');
                
                // Toggle compensation fields based on type
                if (typeof toggleEditCompensationFields === 'function') {
                    toggleEditCompensationFields();
                }
                
                // Address Info
                setValue('edit_address', employee.address);
                setValue('edit_city', employee.city);
                setValue('edit_state', employee.state);
                setValue('edit_postal_code', employee.postal_code);
                
                // Identification
                setValue('edit_identification_type', employee.identification_type);
                setValue('edit_identification_number', employee.identification_number);
                
                // Personal Details
                setValue('edit_blood_type', employee.blood_type);
                setValue('edit_marital_status', employee.marital_status);
                setValue('edit_gender', employee.gender);
                
                // Emergency Contact
                setValue('edit_emergency_contact_name', employee.emergency_contact_name);
                setValue('edit_emergency_contact_phone', employee.emergency_contact_phone);
                setValue('edit_emergency_contact_relationship', employee.emergency_contact_relationship);
                
                // Notes
                setValue('edit_notes', employee.notes);
                
                // Banking Info
                setValue('edit_id_card_number', employee.id_card_number);
                setValue('edit_bank_id', employee.bank_id);
                setValue('edit_bank_account_number', employee.bank_account_number);
                
                // Photo Preview
                const photoPreview = document.getElementById('current_photo_preview');
                if (photoPreview) {
                    if (employee.photo_path) {
                        photoPreview.innerHTML = '<img src="../' + employee.photo_path + '" alt="Foto actual" class="w-24 h-24 rounded-lg object-cover border-2 border-blue-500">';
                    } else {
                        photoPreview.innerHTML = '<p class="text-slate-400 text-sm">Sin foto actual</p>';
                    }
                }
                
                // Show modal first
                const editModal = document.getElementById('editModal');
                if (editModal) {
                    editModal.classList.remove('hidden');
                    // Prevent body scroll when modal is open
                    document.body.style.overflow = 'hidden';
                }
                
                // Load current schedule AFTER modal is visible with longer delay
                if (typeof loadEmployeeSchedule === 'function') {
                    setTimeout(() => {
                        loadEmployeeSchedule(employee.id);
                    }, 200);
                }
            };
            
            window.loadEmployeeSchedule = function(employeeId) {
                // Verify elements exist first
                const scheduleInfo = document.getElementById('current_schedule_info');
                const scheduleSelect = document.getElementById('edit_schedule_template_id');
                
                if (!scheduleInfo) {
                    console.warn('Schedule info element not found in modal');
                    return; // Exit if element doesn't exist
                }
                
                fetch(`get_employee_schedule.php?employee_id=${employeeId}`)
                    .then(response => response.json())
                    .then(data => {
                        // Double check element still exists
                        const info = document.getElementById('current_schedule_info');
                        const select = document.getElementById('edit_schedule_template_id');
                        
                        if (!info) return; // Exit if element disappeared
                        
                        if (data.schedule) {
                            const schedule = data.schedule;
                            info.innerHTML = `
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
                            if (select) select.value = '';
                        } else {
                            info.innerHTML = `
                                <div class="text-slate-400">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    Usando horario global del sistema
                                </div>
                            `;
                            if (select) select.value = '';
                        }
                    })
                    .catch(error => {
                        console.error('Error loading schedule:', error);
                        // Safely try to update error message
                        const info = document.getElementById('current_schedule_info');
                        if (info) {
                            info.innerHTML = `
                                <div class="text-slate-400">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    No se pudo cargar el horario actual
                                </div>
                            `;
                        }
                    });
            };
            
            var isEditModeEdit = false;
            var editingScheduleIdEdit = null;

            window.updateScheduleButtonsEdit = function() {
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
            };

            window.openNewScheduleModalEdit = function() {
                isEditModeEdit = false;
                editingScheduleIdEdit = null;
                document.getElementById('newScheduleModalEdit').classList.remove('hidden');
                document.getElementById('newScheduleFormEdit').reset();
                document.getElementById('scheduleFormMessageEdit').classList.add('hidden');
                document.querySelector('#newScheduleModalEdit h3').innerHTML = '<i class="fas fa-clock text-blue-400 mr-2"></i>Crear Nuevo Turno';
            };

            window.editSelectedScheduleEdit = function() {
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
                            
                            document.getElementById('newScheduleModalEdit').classList.remove('hidden');
                            document.getElementById('scheduleFormMessageEdit').classList.add('hidden');
                            document.querySelector('#newScheduleModalEdit h3').innerHTML = '<i class="fas fa-edit text-blue-400 mr-2"></i>Editar Turno: ' + template.name;
                        } else {
                            alert('Error: ' + data.error);
                        }
                    })
                    .catch(error => {
                        alert('Error al cargar el turno: ' + error.message);
                    });
            };

            window.deleteSelectedScheduleEdit = function() {
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
            };

            window.closeNewScheduleModalEdit = function() {
                document.getElementById('newScheduleModalEdit').classList.add('hidden');
                isEditModeEdit = false;
                editingScheduleIdEdit = null;
            };

            window.saveNewScheduleEdit = function(event) {
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
                    messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Error al guardar el turno';
                    console.error('Error:', error);
                });
            };
            
            document.getElementById('newScheduleModalEdit')?.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeNewScheduleModalEdit();
                }
            });
            
            window.toggleEditCompensationFields = function() {
                const compensationTypeEl = document.getElementById('edit_compensation_type');
                if (!compensationTypeEl) return;
                
                const compensationType = compensationTypeEl.value;
                const hourlyFields = document.getElementById('edit_hourly_fields');
                const fixedFields = document.getElementById('edit_fixed_fields');
                const dailyFields = document.getElementById('edit_daily_fields');
                
                if (!hourlyFields || !fixedFields || !dailyFields) return;
                
                hourlyFields.classList.add('hidden');
                fixedFields.classList.add('hidden');
                dailyFields.classList.add('hidden');
                
                if (compensationType === 'hourly') {
                    hourlyFields.classList.remove('hidden');
                } else if (compensationType === 'fixed') {
                    fixedFields.classList.remove('hidden');
                } else if (compensationType === 'daily') {
                    dailyFields.classList.remove('hidden');
                }
            };
            
            window.openEditCreateCampaignModal = function() {
                document.getElementById('editCampaignModal').classList.remove('hidden');
            };
            
            window.closeEditCampaignModal = function() {
                document.getElementById('editCampaignModal').classList.add('hidden');
                document.getElementById('editCampaignForm').reset();
            };
            
            window.saveEditCampaign = function() {
                const form = document.getElementById('editCampaignForm');
                const formData = new FormData(form);
                
                const messageDiv = document.getElementById('editCampaignMessage');
                messageDiv.className = 'status-banner mb-4';
                messageDiv.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Creando campaña...';
                messageDiv.classList.remove('hidden');
                
                fetch('../api/campaigns.php?action=create', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        messageDiv.className = 'status-banner success mb-4';
                        messageDiv.innerHTML = '<i class="fas fa-check-circle mr-2"></i>Campaña creada exitosamente';
                        
                        const select = document.getElementById('edit_campaign_id');
                        const option = document.createElement('option');
                        option.value = data.campaign.id;
                        option.textContent = data.campaign.name;
                        option.selected = true;
                        select.appendChild(option);
                        
                        setTimeout(() => {
                            closeEditCampaignModal();
                        }, 1000);
                    } else {
                        messageDiv.className = 'status-banner error mb-4';
                        messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>' + data.error;
                    }
                })
                .catch(error => {
                    messageDiv.className = 'status-banner error mb-4';
                    messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Error al crear la campaña';
                    console.error('Error:', error);
                });
            };

            function setQuickAssignMessage(type, text) {
                const messageContainer = document.getElementById('quickAssignMessage');
                if (!messageContainer) return;

                const classMap = {
                    loading: 'status-banner mb-4',
                    success: 'status-banner success mb-4',
                    error: 'status-banner error mb-4'
                };
                const iconMap = {
                    loading: 'fas fa-spinner fa-spin mr-2',
                    success: 'fas fa-check-circle mr-2',
                    error: 'fas fa-exclamation-circle mr-2'
                };

                messageContainer.className = classMap[type] || 'status-banner mb-4';
                messageContainer.innerHTML = '<i class="' + (iconMap[type] || 'fas fa-info-circle mr-2') + '"></i>' + text;
                messageContainer.classList.remove('hidden');
            }

            function resetQuickAssignMessage() {
                const messageContainer = document.getElementById('quickAssignMessage');
                if (!messageContainer) return;
                messageContainer.className = 'hidden';
                messageContainer.innerHTML = '';
            }

            window.quickAssign = function(button) {
                const modal = document.getElementById('quickAssignModal');
                const form = document.getElementById('quickAssignForm');
                const employeeIdInput = document.getElementById('quick_assign_employee_id');

                if (!button || !modal || !form || !employeeIdInput) {
                    console.warn('Quick assign modal elements are missing.');
                    return;
                }

                const employee = JSON.parse(button.dataset.employee);
                form.reset();
                resetQuickAssignMessage();

                const nameEl = document.getElementById('quick_assign_employee_name');
                const metaEl = document.getElementById('quick_assign_employee_meta');
                const campaignSelect = document.getElementById('quick_assign_campaign_id');
                const supervisorSelect = document.getElementById('quick_assign_supervisor_id');
                const submitBtn = document.getElementById('quickAssignSubmit');

                const fullName = ((employee.first_name || '') + ' ' + (employee.last_name || '')).trim() || 'Empleado';
                if (nameEl) {
                    nameEl.textContent = fullName;
                }

                if (metaEl) {
                    const bits = [];
                    if (employee.employee_code) {
                        bits.push('#' + employee.employee_code);
                    }
                    if (employee.department_name) {
                        bits.push(employee.department_name);
                    }
                    metaEl.textContent = bits.join(' - ');
                }

                employeeIdInput.value = employee.id || '';

                if (campaignSelect) {
                    campaignSelect.value = employee.campaign_id ? String(employee.campaign_id) : '';
                }

                if (supervisorSelect) {
                    supervisorSelect.value = employee.supervisor_id ? String(employee.supervisor_id) : '';
                }

                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i>Guardar';
                }

                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            };

            window.closeQuickAssignModal = function() {
                const modal = document.getElementById('quickAssignModal');
                if (!modal) return;
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            };

            function handleQuickAssignSubmit(event) {
                event.preventDefault();

                const form = event.target;
                const submitBtn = document.getElementById('quickAssignSubmit');

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Guardando...';
                }

                setQuickAssignMessage('loading', 'Guardando asignaci&oacute;n...');

                fetch('../api/employees.php?action=quick_assign', {
                    method: 'POST',
                    body: new FormData(form)
                })
                .then(async response => {
                    const data = await response.json().catch(() => ({ success: false, error: 'Respuesta no valida del servidor' }));
                    return { ok: response.ok, data };
                })
                .then(result => {
                    if (result.ok && result.data.success) {
                        setQuickAssignMessage('success', result.data.message || 'Asignaci&oacute;n actualizada correctamente');
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        setQuickAssignMessage('error', result.data.error || 'No se pudo actualizar la asignaci&oacute;n');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i>Guardar';
                        }
                    }
                })
                .catch(error => {
                    console.error('Quick assign error:', error);
                    setQuickAssignMessage('error', 'Error de comunicaci&oacute;n con el servidor');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i>Guardar';
                    }
                });
            }

            document.addEventListener('DOMContentLoaded', function() {
                const modal = document.getElementById('quickAssignModal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            window.closeQuickAssignModal();
                        }
                    });
                }

                const form = document.getElementById('quickAssignForm');
                if (form) {
                    form.addEventListener('submit', handleQuickAssignSubmit);
                }
            });
        })();
        </script>

        <script>
        function openTerminatedEmployees() {
            const modal = document.getElementById('terminatedEmployeesModal');
            if (modal) {
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        }
        function closeTerminatedEmployees() {
            const modal = document.getElementById('terminatedEmployeesModal');
            if (modal) {
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('terminatedEmployeesModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeTerminatedEmployees();
                    }
                });
            }
        });
        </script>

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
                                <?php if ($employee['campaign_name']): ?>
                                    <p class="text-slate-300">
                                        <i class="fas fa-bullhorn text-purple-400 mr-2 w-4"></i>
                                        <span class="px-2 py-0.5 rounded text-xs" style="background-color: <?= htmlspecialchars($employee['campaign_color']) ?>20; color: <?= htmlspecialchars($employee['campaign_color']) ?>;">
                                            <?= htmlspecialchars($employee['campaign_name']) ?>
                                        </span>
                                    </p>
                                <?php endif; ?>
                                <?php if ($employee['supervisor_name']): ?>
                                    <p class="text-slate-300">
                                        <i class="fas fa-user-tie text-yellow-400 mr-2 w-4"></i>
                                        <?= htmlspecialchars($employee['supervisor_name']) ?>
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
                                    <?php
                                    // Display salary based on preferred currency and compensation type
                                    $currency = $employee['preferred_currency'] ?? 'USD';
                                    $compensationType = $employee['compensation_type'] ?? 'hourly';
                                    $currencySymbol = $currency === 'DOP' ? 'RD$' : '$';
                                    
                                    if ($compensationType === 'hourly') {
                                        $rate = $currency === 'DOP' ? $employee['hourly_rate_dop'] : $employee['hourly_rate'];
                                        echo $currencySymbol . number_format($rate, 2) . '/hr';
                                    } elseif ($compensationType === 'fixed') {
                                        $salary = $currency === 'DOP' ? $employee['monthly_salary_dop'] : $employee['monthly_salary'];
                                        echo $currencySymbol . number_format($salary, 2) . '/mes';
                                    } elseif ($compensationType === 'daily') {
                                        $salary = $currency === 'DOP' ? $employee['daily_salary_dop'] : $employee['daily_salary_usd'];
                                        echo $currencySymbol . number_format($salary, 2) . '/día';
                                    }
                                    ?>
                                </p>
                            </div>

                            <div class="flex gap-2">
                                <button type="button" 
                                        onclick="quickAssign(this)" 
                                        data-employee='<?= json_encode($employee) ?>'
                                        class="btn-secondary text-xs px-2 py-1" 
                                        title="Asignar Campaña/Supervisor">
                                    <i class="fas fa-user-tag"></i>
                                </button>
                                <button type="button" onclick="editEmployee(this)" 
                                        data-employee='<?= json_encode($employee) ?>' 
                                        class="btn-primary text-sm flex-1">
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

    <div id="terminatedEmployeesModal" class="hidden fixed inset-0 bg-black bg-opacity-60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="glass-card w-full sm:max-w-xl md:max-w-2xl lg:max-w-4xl xl:max-w-6xl relative max-h-[85vh] flex flex-col rounded-2xl border border-white/10 shadow-2xl">
            <div class="flex items-center justify-between px-4 py-3 border-b border-white/10 sticky top-0 bg-slate-900/70 backdrop-blur z-10">
                <h3 class="text-xl font-semibold text-white">
                    <i class="fas fa-user-times text-red-400 mr-2"></i>
                    Empleados Terminados
                </h3>
                <button type="button" class="text-slate-400 hover:text-white" onclick="closeTerminatedEmployees()">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto">
            <?php if (empty($terminatedEmployees)): ?>
                <p class="text-slate-400 text-center py-8">No hay empleados terminados.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="table-auto text-sm w-full min-w-[750px]">
                        <thead class="sticky top-0 bg-slate-900/80 backdrop-blur border-b border-white/10">
                            <tr>
                                <th class="text-left p-3 text-slate-300 font-medium">Colaborador</th>
                                <th class="text-left p-3 text-slate-300 font-medium">Código</th>
                                <th class="text-left p-3 text-slate-300 font-medium">Departamento</th>
                                <th class="text-left p-3 text-slate-300 font-medium">Fecha Terminación</th>
                                <th class="text-left p-3 text-slate-300 font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($terminatedEmployees as $emp): ?>
                                <tr class="border-b border-slate-800/60 hover:bg-slate-800/40">
                                    <td class="p-3 text-white">
                                        <?= htmlspecialchars(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')) ?>
                                    </td>
                                    <td class="p-3 text-slate-300">
                                        <?= htmlspecialchars($emp['employee_code'] ?? '') ?>
                                    </td>
                                    <td class="p-3 text-slate-300">
                                        <?= htmlspecialchars($emp['department_name'] ?? '') ?>
                                    </td>
                                    <td class="p-3 text-slate-300">
                                        <?= !empty($emp['termination_date']) ? date('d/m/Y', strtotime($emp['termination_date'])) : '—' ?>
                                    </td>
                                    <td class="p-3">
                                        <div class="flex flex-wrap gap-2 items-center">
                                            <a href="employee_profile.php?id=<?= (int)$emp['id'] ?>" class="btn-secondary text-xs px-2 py-1">
                                                <i class="fas fa-eye"></i> Ver
                                            </a>
                                            <button type="button" onclick="editEmployee(this)" data-employee='<?= json_encode($emp) ?>' class="btn-primary text-xs px-2 py-1">
                                                <i class="fas fa-edit"></i> Editar
                                            </button>
                                            <form method="POST" onsubmit="return confirm('¿Reintegrar este empleado?')">
                                                <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
                                                <button type="submit" name="reinstate_employee" value="1" class="btn-secondary text-xs px-2 py-1">
                                                    <i class="fas fa-undo"></i> Reintegrar
                                                </button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('¿Eliminar permanentemente este empleado?')">
                                                <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
                                                <button type="submit" name="delete_employee" value="1" class="btn-secondary text-xs text-red-400 px-2 py-1">
                                                    <i class="fas fa-trash"></i> Eliminar
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Assign Modal -->
    <div id="quickAssignModal" class="hidden fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4">
        <div class="glass-card w-full max-w-xl relative">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-semibold text-white">
                    <i class="fas fa-user-tag text-indigo-400 mr-2"></i>
                    Asignaci&oacute;n R&aacute;pida
                </h3>
                <button type="button" class="text-slate-400 hover:text-white" onclick="closeQuickAssignModal()">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <div id="quickAssignMessage" class="hidden"></div>

            <form id="quickAssignForm" class="space-y-4">
                <input type="hidden" id="quick_assign_employee_id" name="employee_id">

                <div class="bg-slate-900/50 rounded-lg p-4">
                    <p class="text-sm text-slate-400 uppercase tracking-wide mb-1">Empleado</p>
                    <p id="quick_assign_employee_name" class="text-lg font-semibold text-white">Selecciona un empleado</p>
                    <p id="quick_assign_employee_meta" class="text-slate-400 text-sm"></p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label for="quick_assign_campaign_id">Campa&ntilde;a</label>
                        <select id="quick_assign_campaign_id" name="campaign_id">
                            <option value="">Sin campa&ntilde;a</option>
                            <?php foreach ($quickAssignCampaigns as $campaign): ?>
                                <option value="<?= htmlspecialchars($campaign['id']) ?>">
                                    <?= htmlspecialchars($campaign['name']) ?>
                                    <?php if (!empty($campaign['code'])): ?>
                                        (<?= htmlspecialchars($campaign['code']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-slate-400 mt-1">Selecciona la campa&ntilde;a que deseas asignar.</p>
                    </div>
                    <div class="form-group">
                        <label for="quick_assign_supervisor_id">Supervisor</label>
                        <select id="quick_assign_supervisor_id" name="supervisor_id">
                            <option value="">Sin supervisor</option>
                            <?php foreach ($quickAssignSupervisors as $supervisor): ?>
                                <option value="<?= htmlspecialchars($supervisor['id']) ?>">
                                    <?= htmlspecialchars($supervisor['full_name']) ?>
                                    <?php if (!empty($supervisor['role'])): ?>
                                        (<?= htmlspecialchars($supervisor['role']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-slate-400 mt-1">Supervisor responsable del empleado.</p>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button type="button" class="btn-secondary flex-1" onclick="closeQuickAssignModal()">
                        <i class="fas fa-times mr-2"></i>
                        Cancelar
                    </button>
                    <button type="submit" id="quickAssignSubmit" class="btn-primary flex-1">
                        <i class="fas fa-save mr-2"></i>
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Employee Modal -->
    <div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50" style="overflow-y: scroll; padding: 2rem;">
        <div class="glass-card max-w-5xl mx-auto">
            <h3 class="text-xl font-semibold text-white mb-6">
                <i class="fas fa-user-edit text-blue-400 mr-2"></i>
                Editar Empleado
            </h3>
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
                    <button type="button" onclick="document.getElementById('editModal').classList.add('hidden'); document.body.style.overflow = 'auto';" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Close modal when clicking outside
    document.getElementById('editModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
    });
    </script>

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

    <!-- Modal para crear campaña (Edit Employee) -->
    <div id="editCampaignModal" class="modal hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-bullhorn mr-2"></i>
                    Crear Nueva Campaña
                </h3>
                <button type="button" class="close-modal" onclick="closeEditCampaignModal()">&times;</button>
            </div>
            
            <div id="editCampaignMessage" class="hidden"></div>
            
            <form id="editCampaignForm" onsubmit="event.preventDefault(); saveEditCampaign();">
                <div class="form-group">
                    <label for="edit_campaign_name">Nombre *</label>
                    <input type="text" id="edit_campaign_name" name="name" required 
                           placeholder="Nombre de la campaña">
                </div>
                
                <div class="form-group">
                    <label for="edit_campaign_code">Código *</label>
                    <input type="text" id="edit_campaign_code" name="code" required 
                           placeholder="Código único (ej: SALES-2024)" maxlength="50">
                </div>
                
                <div class="form-group">
                    <label for="edit_campaign_description">Descripción</label>
                    <textarea id="edit_campaign_description" name="description" rows="3" 
                              placeholder="Descripción de la campaña"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="edit_campaign_color">Color</label>
                    <input type="color" id="edit_campaign_color" name="color" value="#3b82f6">
                </div>
                
                <div class="form-actions">
                    <button type="button" onclick="closeEditCampaignModal()" class="btn-secondary">
                        <i class="fas fa-times mr-2"></i>Cancelar
                    </button>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save mr-2"></i>Crear Campaña
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include '../footer.php'; ?>
</body>

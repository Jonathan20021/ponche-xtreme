<?php
session_start();
require_once '../db.php';
require_once '../lib/email_functions.php';
require_once '../lib/logging_functions.php';

// Check permissions
ensurePermission('hr_employees');

$success = null;
$error = null;
$emailWarning = null;

if (isset($_POST['register'])) {
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $birth_date = trim($_POST['birth_date'] ?? '');
    $hire_date = trim($_POST['hire_date'] ?? date('Y-m-d'));
    $position = trim($_POST['position'] ?? '');
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $hourly_rate = !empty($_POST['hourly_rate']) ? (float)$_POST['hourly_rate'] : 0.00;
    $id_card_number = trim($_POST['id_card_number'] ?? '');
    $bank_id = !empty($_POST['bank_id']) ? (int)$_POST['bank_id'] : null;
    $bank_account_number = trim($_POST['bank_account_number'] ?? '');
    $schedule_template_id = !empty($_POST['schedule_template_id']) ? (int)$_POST['schedule_template_id'] : null;
    $password = !empty($_POST['password']) ? trim($_POST['password']) : 'defaultpassword';
    $role = !empty($_POST['role']) ? trim($_POST['role']) : 'AGENT';
    $hourly_rate_dop = !empty($_POST['hourly_rate_dop']) ? (float)$_POST['hourly_rate_dop'] : 0.00;
    $monthly_salary_usd = !empty($_POST['monthly_salary_usd']) ? (float)$_POST['monthly_salary_usd'] : 0.00;
    $monthly_salary_dop = !empty($_POST['monthly_salary_dop']) ? (float)$_POST['monthly_salary_dop'] : 0.00;
    $preferred_currency = !empty($_POST['preferred_currency']) ? strtoupper(trim($_POST['preferred_currency'])) : 'USD';

    if ($username === '' || $full_name === '' || $first_name === '' || $last_name === '' || $hire_date === '' || $email === '') {
        $error = 'Los campos Usuario, Nombre completo, Nombre, Apellido, Email y Fecha de ingreso son obligatorios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El formato del correo electrónico no es válido.';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $exists = $stmt->fetch();

        if ($exists && (int)$exists['count'] > 0) {
            $error = "El usuario {$username} ya esta registrado.";
        } else {
            try {
                $pdo->beginTransaction();
                
                // Generate employee code: EMP-YYYY-XXXX
                $currentYear = date('Y');
                
                // Get the highest employee code for the current year
                $codeStmt = $pdo->prepare("SELECT employee_code FROM users WHERE employee_code LIKE ? ORDER BY employee_code DESC LIMIT 1");
                $codeStmt->execute(["EMP-{$currentYear}-%"]);
                $lastCode = $codeStmt->fetch();
                
                if ($lastCode && $lastCode['employee_code']) {
                    // Extract the sequential number and increment it
                    $lastNumber = (int)substr($lastCode['employee_code'], -4);
                    $newNumber = $lastNumber + 1;
                } else {
                    // First employee of the year
                    $newNumber = 1;
                }
                
                $employeeCode = sprintf("EMP-%s-%04d", $currentYear, $newNumber);
                
                // Handle photo upload
                $photoPath = null;
                if (isset($_FILES['employee_photo']) && $_FILES['employee_photo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../uploads/employee_photos/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileExtension = strtolower(pathinfo($_FILES['employee_photo']['name'], PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                    
                    if (in_array($fileExtension, $allowedExtensions)) {
                        $fileName = $employeeCode . '_' . time() . '.' . $fileExtension;
                        $targetPath = $uploadDir . $fileName;
                        
                        if (move_uploaded_file($_FILES['employee_photo']['tmp_name'], $targetPath)) {
                            $photoPath = 'uploads/employee_photos/' . $fileName;
                        }
                    }
                }
                
                // Insert user with employee code and all compensation fields
                $insert = $pdo->prepare("INSERT INTO users (username, employee_code, full_name, password, role, hourly_rate, hourly_rate_dop, monthly_salary, monthly_salary_dop, preferred_currency, department_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert->execute([$username, $employeeCode, $full_name, $password, $role, $hourly_rate, $hourly_rate_dop, $monthly_salary_usd, $monthly_salary_dop, $preferred_currency, $department_id]);
                $userId = $pdo->lastInsertId();
                
                // Insert employee record with new fields
                $employeeInsert = $pdo->prepare("
                    INSERT INTO employees (
                        user_id, employee_code, first_name, last_name, email, phone, 
                        birth_date, hire_date, position, department_id, employment_status,
                        id_card_number, bank_id, bank_account_number, photo_path
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'TRIAL', ?, ?, ?, ?)
                ");
                $employeeInsert->execute([
                    $userId, $employeeCode, $first_name, $last_name, $email, $phone,
                    $birth_date ?: null, $hire_date, $position, $department_id,
                    $id_card_number ?: null, $bank_id, $bank_account_number ?: null, $photoPath
                ]);
                
                $employeeId = $pdo->lastInsertId();
                
                // Create employee schedule if template selected
                if ($schedule_template_id) {
                    createEmployeeScheduleFromTemplate($pdo, $employeeId, $userId, $schedule_template_id, $hire_date);
                }
                
                $pdo->commit();
                
                // Log employee creation
                $employee_data = [
                    'name' => $full_name,
                    'employee_code' => $employeeCode,
                    'username' => $username,
                    'email' => $email,
                    'position' => $position,
                    'department_id' => $department_id,
                    'hire_date' => $hire_date,
                    'hourly_rate' => $hourly_rate
                ];
                log_employee_created($pdo, $_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], $employeeId, $employee_data);
                
                // Send welcome email to the new employee
                if (!empty($email)) {
                    // Get department name
                    $departmentName = 'N/A';
                    if ($department_id) {
                        $deptStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                        $deptStmt->execute([$department_id]);
                        $deptResult = $deptStmt->fetch();
                        if ($deptResult) {
                            $departmentName = $deptResult['name'];
                        }
                    }
                    
                    $emailData = [
                        'email' => $email,
                        'employee_name' => $full_name,
                        'username' => $username,
                        'password' => $password,
                        'employee_code' => $employeeCode,
                        'position' => $position ?: 'Agente',
                        'department' => $departmentName,
                        'hire_date' => $hire_date
                    ];
                    
                    $emailResult = sendWelcomeEmail($emailData);
                    
                    if ($emailResult['success']) {
                        $success = "Usuario {$username} creado correctamente con código de empleado {$employeeCode}. El empleado está en período de prueba. Se ha enviado un correo de bienvenida a {$email}.";
                    } else {
                        $success = "Usuario {$username} creado correctamente con código de empleado {$employeeCode}. El empleado está en período de prueba.";
                        $emailWarning = "Advertencia: No se pudo enviar el correo de bienvenida. " . $emailResult['message'];
                    }
                } else {
                    $success = "Usuario {$username} creado correctamente con código de empleado {$employeeCode}. El empleado está en período de prueba.";
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error al crear el usuario: " . $e->getMessage();
            }
        }
    }
}

$theme = $_SESSION['theme'] ?? 'dark';
if (!in_array($theme, ['dark', 'light'], true)) {
    $theme = 'dark';
}
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
$themeLabel = $theme === 'light' ? 'Modo Oscuro' : 'Modo Claro';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <title>Nuevo Empleado - HR</title>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-5xl mx-auto">
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-white mb-2">
                        <i class="fas fa-user-plus text-blue-400 mr-3"></i>
                        Nuevo Empleado
                    </h1>
                    <p class="text-slate-400">Registro completo de empleado y usuario del sistema</p>
                </div>
                <a href="employees.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver a Empleados
                </a>
            </div>
            
            <div class="glass-card">
            <?php if ($error): ?>
                <div class="status-banner error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="status-banner success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($emailWarning): ?>
                <div class="status-banner" style="background: linear-gradient(135deg, #f59e0b15 0%, #d9770615 100%); border-left: 4px solid #f59e0b; color: #d97706;">
                    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($emailWarning) ?>
                </div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <h3 class="text-lg font-semibold text-white mb-3 border-b border-slate-700 pb-2">
                    <i class="fas fa-user-circle text-blue-400 mr-2"></i>
                    Información de Usuario del Sistema
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label for="username">Usuario *</label>
                        <input type="text" id="username" name="username" required placeholder="ej. agente02">
                        <p class="text-xs text-slate-400 mt-1">Usuario para acceder al sistema</p>
                    </div>
                    <div class="form-group">
                        <label for="password">Contraseña *</label>
                        <input type="text" id="password" name="password" placeholder="defaultpassword" value="defaultpassword">
                        <p class="text-xs text-slate-400 mt-1">El empleado podrá cambiarla después</p>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label for="role">Rol del Sistema *</label>
                        <select id="role" name="role" required>
                            <option value="AGENT">Agente</option>
                            <?php
                            $roles = getAllRoles($pdo);
                            foreach ($roles as $r) {
                                if ($r['name'] !== 'AGENT') {
                                    echo '<option value="' . htmlspecialchars($r['name']) . '">' . htmlspecialchars($r['label']) . '</option>';
                                }
                            }
                            ?>
                        </select>
                        <p class="text-xs text-slate-400 mt-1">Determina los permisos en el sistema</p>
                    </div>
                    <div class="form-group">
                        <label for="full_name">Nombre completo *</label>
                        <input type="text" id="full_name" name="full_name" required placeholder="Nombre y apellido">
                    </div>
                </div>
                
                <h3 class="text-lg font-semibold text-white mt-6 mb-3 border-b border-slate-700 pb-2">
                    <i class="fas fa-id-badge text-blue-400 mr-2"></i>
                    Información Personal del Empleado
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label for="first_name">Nombre *</label>
                        <input type="text" id="first_name" name="first_name" required placeholder="Nombre">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Apellido *</label>
                        <input type="text" id="last_name" name="last_name" required placeholder="Apellido">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required placeholder="correo@ejemplo.com">
                        <p class="text-xs text-slate-400 mt-1">Se enviará un correo con las credenciales de acceso</p>
                    </div>
                    <div class="form-group">
                        <label for="phone">Teléfono</label>
                        <input type="tel" id="phone" name="phone" placeholder="(809) 000-0000">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label for="birth_date">Fecha de nacimiento</label>
                        <input type="date" id="birth_date" name="birth_date">
                    </div>
                    <div class="form-group">
                        <label for="hire_date">Fecha de ingreso *</label>
                        <input type="date" id="hire_date" name="hire_date" required value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label for="position">Posición</label>
                        <input type="text" id="position" name="position" placeholder="ej. Agente de Soporte">
                    </div>
                    <div class="form-group">
                        <label for="department_id">Departamento</label>
                        <select id="department_id" name="department_id">
                            <option value="">Seleccionar departamento</option>
                            <?php
                            $departments = getAllDepartments($pdo);
                            foreach ($departments as $dept) {
                                echo '<option value="' . htmlspecialchars($dept['id']) . '">' . htmlspecialchars($dept['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                
                <h3 class="text-lg font-semibold text-white mt-6 mb-3 border-b border-slate-700 pb-2">
                    <i class="fas fa-dollar-sign text-blue-400 mr-2"></i>
                    Compensación y Salario
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label for="hourly_rate">Tarifa por hora (USD)</label>
                        <input type="number" id="hourly_rate" name="hourly_rate" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label for="hourly_rate_dop">Tarifa por hora (DOP)</label>
                        <input type="number" id="hourly_rate_dop" name="hourly_rate_dop" step="0.01" min="0" placeholder="0.00">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label for="monthly_salary_usd">Salario mensual (USD)</label>
                        <input type="number" id="monthly_salary_usd" name="monthly_salary_usd" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label for="monthly_salary_dop">Salario mensual (DOP)</label>
                        <input type="number" id="monthly_salary_dop" name="monthly_salary_dop" step="0.01" min="0" placeholder="0.00">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="preferred_currency">Moneda Preferida</label>
                    <select id="preferred_currency" name="preferred_currency">
                        <option value="USD">USD (Dólares)</option>
                        <option value="DOP">DOP (Pesos Dominicanos)</option>
                    </select>
                    <p class="text-xs text-slate-400 mt-1">Moneda principal para reportes y nómina</p>
                </div>
                
                <h3 class="text-lg font-semibold text-white mt-6 mb-3">
                    <i class="fas fa-clock text-blue-400 mr-2"></i>
                    Horario de Trabajo
                </h3>
                
                <div class="form-group">
                    <label for="schedule_template_id">Turno / Horario</label>
                    <div class="flex gap-2">
                        <select id="schedule_template_id" name="schedule_template_id" class="flex-1" onchange="updateScheduleButtons()">
                            <option value="">Usar horario global del sistema</option>
                            <?php
                            $scheduleTemplates = getAllScheduleTemplates($pdo);
                            foreach ($scheduleTemplates as $template) {
                                $timeInfo = date('g:i A', strtotime($template['entry_time'])) . ' - ' . date('g:i A', strtotime($template['exit_time']));
                                echo '<option value="' . htmlspecialchars($template['id']) . '">';
                                echo htmlspecialchars($template['name']) . ' (' . $timeInfo . ')';
                                echo '</option>';
                            }
                            ?>
                        </select>
                        <button type="button" onclick="openNewScheduleModal()" class="btn-secondary px-3 whitespace-nowrap" title="Crear nuevo turno">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button type="button" id="editScheduleBtn" onclick="editSelectedSchedule()" class="btn-secondary px-3 whitespace-nowrap hidden" title="Editar turno seleccionado">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" id="deleteScheduleBtn" onclick="deleteSelectedSchedule()" class="btn-secondary px-3 whitespace-nowrap hidden" title="Eliminar turno seleccionado" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">
                        <i class="fas fa-info-circle"></i>
                        Selecciona el horario de trabajo del empleado o crea uno nuevo.
                    </p>
                </div>
                
                <h3 class="text-lg font-semibold text-white mt-6 mb-3">
                    <i class="fas fa-id-card text-blue-400 mr-2"></i>
                    Información Bancaria e Identificación
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label for="id_card_number">Número de Cédula</label>
                        <input type="text" id="id_card_number" name="id_card_number" placeholder="000-0000000-0">
                    </div>
                    <div class="form-group">
                        <label for="bank_id">Banco</label>
                        <select id="bank_id" name="bank_id">
                            <option value="">Seleccionar banco</option>
                            <?php
                            $banks = getAllBanks($pdo);
                            foreach ($banks as $bank) {
                                echo '<option value="' . htmlspecialchars($bank['id']) . '">' . htmlspecialchars($bank['name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="bank_account_number">Número de Cuenta Bancaria</label>
                    <input type="text" id="bank_account_number" name="bank_account_number" placeholder="Número de cuenta">
                </div>
                
                <div class="form-group">
                    <label for="employee_photo">Foto del Empleado</label>
                    <input type="file" id="employee_photo" name="employee_photo" accept="image/jpeg,image/png,image/gif,image/jpg" class="block w-full text-sm text-slate-400
                        file:mr-4 file:py-2 file:px-4
                        file:rounded-lg file:border-0
                        file:text-sm file:font-semibold
                        file:bg-blue-500 file:text-white
                        hover:file:bg-blue-600
                        file:cursor-pointer">
                    <p class="text-xs text-slate-400 mt-1">Formatos permitidos: JPG, PNG, GIF (Máx. 5MB)</p>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" name="register" class="btn-primary flex-1">
                        <i class="fas fa-user-plus"></i>
                        Registrar Empleado y Usuario
                    </button>
                    <a href="employees.php" class="btn-secondary flex-1 text-center">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </a>
                </div>
            </form>
            </div>
        </div>
    </div>
    
    <?php include '../footer.php'; ?>

    <!-- Modal para crear nuevo turno -->
    <div id="newScheduleModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto">
        <div class="glass-card m-4" style="width: min(600px, 95%); max-height: 90vh; overflow-y: auto;">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-clock text-blue-400 mr-2"></i>
                Crear Nuevo Turno
            </h3>
            <form id="newScheduleForm" onsubmit="saveNewSchedule(event)">
                <div class="form-group mb-4">
                    <label for="new_schedule_name">Nombre del Turno *</label>
                    <input type="text" id="new_schedule_name" name="name" required placeholder="Ej: Turno Especial 8am-5pm">
                </div>

                <div class="form-group mb-4">
                    <label for="new_schedule_description">Descripción</label>
                    <textarea id="new_schedule_description" name="description" rows="2" placeholder="Descripción opcional del turno"></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="new_entry_time">Hora de Entrada *</label>
                        <input type="time" id="new_entry_time" name="entry_time" required value="10:00">
                    </div>
                    <div class="form-group">
                        <label for="new_exit_time">Hora de Salida *</label>
                        <input type="time" id="new_exit_time" name="exit_time" required value="19:00">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="new_lunch_time">Hora de Almuerzo</label>
                        <input type="time" id="new_lunch_time" name="lunch_time" value="14:00">
                    </div>
                    <div class="form-group">
                        <label for="new_break_time">Hora de Descanso</label>
                        <input type="time" id="new_break_time" name="break_time" value="17:00">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="form-group">
                        <label for="new_lunch_minutes">Minutos Almuerzo</label>
                        <input type="number" id="new_lunch_minutes" name="lunch_minutes" min="0" value="45">
                    </div>
                    <div class="form-group">
                        <label for="new_break_minutes">Minutos Descanso</label>
                        <input type="number" id="new_break_minutes" name="break_minutes" min="0" value="15">
                    </div>
                    <div class="form-group">
                        <label for="new_scheduled_hours">Horas Programadas</label>
                        <input type="number" id="new_scheduled_hours" name="scheduled_hours" step="0.25" min="0" value="8.00">
                    </div>
                </div>

                <div id="scheduleFormMessage" class="mb-4 hidden"></div>

                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-save"></i>
                        Guardar Turno
                    </button>
                    <button type="button" onclick="closeNewScheduleModal()" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let isEditMode = false;
        let editingScheduleId = null;

        function updateScheduleButtons() {
            const select = document.getElementById('schedule_template_id');
            const editBtn = document.getElementById('editScheduleBtn');
            const deleteBtn = document.getElementById('deleteScheduleBtn');
            
            if (select.value && select.value !== '') {
                editBtn.classList.remove('hidden');
                deleteBtn.classList.remove('hidden');
            } else {
                editBtn.classList.add('hidden');
                deleteBtn.classList.add('hidden');
            }
        }

        function openNewScheduleModal() {
            isEditMode = false;
            editingScheduleId = null;
            document.getElementById('newScheduleModal').classList.remove('hidden');
            document.getElementById('newScheduleForm').reset();
            document.getElementById('scheduleFormMessage').classList.add('hidden');
            document.querySelector('#newScheduleModal h3').innerHTML = '<i class="fas fa-clock text-blue-400 mr-2"></i>Crear Nuevo Turno';
        }

        function closeNewScheduleModal() {
            document.getElementById('newScheduleModal').classList.add('hidden');
            isEditMode = false;
            editingScheduleId = null;
        }

        function editSelectedSchedule() {
            const select = document.getElementById('schedule_template_id');
            const scheduleId = select.value;
            
            if (!scheduleId) return;
            
            // Load schedule data
            fetch('../get_schedule_template.php?id=' + scheduleId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const template = data.template;
                        isEditMode = true;
                        editingScheduleId = template.id;
                        
                        // Fill form
                        document.getElementById('new_schedule_name').value = template.name;
                        document.getElementById('new_schedule_description').value = template.description || '';
                        document.getElementById('new_entry_time').value = template.entry_time.substring(0, 5);
                        document.getElementById('new_exit_time').value = template.exit_time.substring(0, 5);
                        document.getElementById('new_lunch_time').value = template.lunch_time ? template.lunch_time.substring(0, 5) : '14:00';
                        document.getElementById('new_break_time').value = template.break_time ? template.break_time.substring(0, 5) : '17:00';
                        document.getElementById('new_lunch_minutes').value = template.lunch_minutes;
                        document.getElementById('new_break_minutes').value = template.break_minutes;
                        document.getElementById('new_scheduled_hours').value = template.scheduled_hours;
                        
                        // Update modal title
                        document.querySelector('#newScheduleModal h3').innerHTML = '<i class="fas fa-edit text-blue-400 mr-2"></i>Editar Turno';
                        
                        // Open modal
                        document.getElementById('newScheduleModal').classList.remove('hidden');
                        document.getElementById('scheduleFormMessage').classList.add('hidden');
                    } else {
                        alert('Error al cargar el turno: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error al cargar el turno: ' + error.message);
                });
        }

        function deleteSelectedSchedule() {
            const select = document.getElementById('schedule_template_id');
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
                    // Remove option from select
                    select.remove(select.selectedIndex);
                    updateScheduleButtons();
                    alert(data.message);
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error al eliminar el turno: ' + error.message);
            });
        }

        function closeNewScheduleModal() {
            document.getElementById('newScheduleModal').classList.add('hidden');
            isEditMode = false;
            editingScheduleId = null;
        }

        function saveNewSchedule(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            const messageDiv = document.getElementById('scheduleFormMessage');
            
            // Add ID if editing
            if (isEditMode && editingScheduleId) {
                formData.append('id', editingScheduleId);
            }
            
            // Show loading
            messageDiv.className = 'status-banner mb-4';
            messageDiv.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Guardando turno...';
            messageDiv.classList.remove('hidden');
            
            const endpoint = isEditMode ? '../update_schedule_template.php' : '../save_schedule_template.php';
            
            fetch(endpoint, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageDiv.className = 'status-banner success mb-4';
                    messageDiv.innerHTML = '<i class="fas fa-check-circle mr-2"></i>' + data.message;
                    
                    const select = document.getElementById('schedule_template_id');
                    const template = data.template;
                    const entryTime = new Date('2000-01-01 ' + template.entry_time);
                    const exitTime = new Date('2000-01-01 ' + template.exit_time);
                    const timeInfo = entryTime.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'}) + 
                                   ' - ' + exitTime.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
                    
                    if (isEditMode) {
                        // Update existing option
                        const option = select.querySelector('option[value="' + template.id + '"]');
                        if (option) {
                            option.textContent = template.name + ' (' + timeInfo + ')';
                        }
                    } else {
                        // Add new option
                        const option = document.createElement('option');
                        option.value = template.id;
                        option.textContent = template.name + ' (' + timeInfo + ')';
                        option.selected = true;
                        select.appendChild(option);
                    }
                    
                    updateScheduleButtons();
                    
                    // Close modal after 1 second
                    setTimeout(() => {
                        closeNewScheduleModal();
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
        document.getElementById('newScheduleModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeNewScheduleModal();
            }
        });
    </script>
</body>
</html>

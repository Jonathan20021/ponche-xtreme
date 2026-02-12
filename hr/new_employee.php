<?php
session_start();
require_once '../db.php';
require_once '../lib/email_functions.php';
require_once '../lib/logging_functions.php';

// Check permissions
ensurePermission('hr_employees', '../unauthorized.php');

$success = null;
$error = null;
$emailWarning = null;

if (!function_exists('normalizeScheduleTimeValue')) {
    function normalizeScheduleTimeValue(?string $value, string $fallback): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            $value = $fallback;
        }
        if (strlen($value) === 5) {
            $value .= ':00';
        }
        return $value;
    }
}

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
    $department_id = !empty($_POST['department_id']) && (int) $_POST['department_id'] > 0 ? (int) $_POST['department_id'] : null;
    $compensation_type = !empty($_POST['compensation_type']) ? trim($_POST['compensation_type']) : 'hourly';
    $hourly_rate = !empty($_POST['hourly_rate']) ? (float) $_POST['hourly_rate'] : 0.00;
    $id_card_number = trim($_POST['id_card_number'] ?? '');
    $bank_id = !empty($_POST['bank_id']) && (int) $_POST['bank_id'] > 0 ? (int) $_POST['bank_id'] : null;
    $bank_account_number = trim($_POST['bank_account_number'] ?? '');
    $bank_account_type = !empty($_POST['bank_account_type']) ? trim($_POST['bank_account_type']) : null;
    $schedule_template_id = !empty($_POST['schedule_template_id']) && (int) $_POST['schedule_template_id'] > 0 ? (int) $_POST['schedule_template_id'] : null;
    $scheduleAssignmentsJson = trim($_POST['schedule_assignments_json'] ?? '');
    $scheduleAssignments = [];
    if ($scheduleAssignmentsJson !== '') {
        $decodedAssignments = json_decode($scheduleAssignmentsJson, true);
        if (is_array($decodedAssignments)) {
            $scheduleAssignments = $decodedAssignments;
        }
    }
    if (empty($scheduleAssignments) && $schedule_template_id) {
        $stmt = $pdo->prepare("SELECT * FROM schedule_templates WHERE id = ? LIMIT 1");
        $stmt->execute([$schedule_template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($template) {
            $daysOfWeek = null;
            if (!empty($_POST['schedule_days_new']) && is_array($_POST['schedule_days_new'])) {
                $days = array_filter(array_map('intval', $_POST['schedule_days_new']));
                $daysOfWeek = $days ? implode(',', $days) : null;
            }
            $effectiveDate = !empty($_POST['assignment_effective_date']) ? $_POST['assignment_effective_date'] : $hire_date;
            $endDate = !empty($_POST['assignment_end_date']) ? $_POST['assignment_end_date'] : null;
            $scheduleAssignments[] = [
                'schedule_name' => $template['name'] ?? 'Horario',
                'entry_time' => $template['entry_time'] ?? null,
                'exit_time' => $template['exit_time'] ?? null,
                'lunch_time' => $template['lunch_time'] ?? null,
                'break_time' => $template['break_time'] ?? null,
                'lunch_minutes' => $template['lunch_minutes'] ?? 0,
                'break_minutes' => $template['break_minutes'] ?? 0,
                'scheduled_hours' => $template['scheduled_hours'] ?? 0,
                'effective_date' => $effectiveDate,
                'end_date' => $endDate,
                'notes' => $template['description'] ? "Asignado desde template: {$template['name']}" : null,
                'days_of_week' => $daysOfWeek
            ];
        }
    }
    $password = !empty($_POST['password']) ? trim($_POST['password']) : 'defaultpassword';
    $role = !empty($_POST['role']) ? trim($_POST['role']) : 'AGENT';
    $hourly_rate_dop = !empty($_POST['hourly_rate_dop']) ? (float) $_POST['hourly_rate_dop'] : 0.00;
    $monthly_salary_usd = !empty($_POST['monthly_salary_usd']) ? (float) $_POST['monthly_salary_usd'] : 0.00;
    $monthly_salary_dop = !empty($_POST['monthly_salary_dop']) ? (float) $_POST['monthly_salary_dop'] : 0.00;
    $daily_salary_usd = !empty($_POST['daily_salary_usd']) ? (float) $_POST['daily_salary_usd'] : 0.00;
    $daily_salary_dop = !empty($_POST['daily_salary_dop']) ? (float) $_POST['daily_salary_dop'] : 0.00;
    $preferred_currency = !empty($_POST['preferred_currency']) ? strtoupper(trim($_POST['preferred_currency'])) : 'USD';
    $supervisor_id = !empty($_POST['supervisor_id']) && (int) $_POST['supervisor_id'] > 0 ? (int) $_POST['supervisor_id'] : null;
    $campaign_id = !empty($_POST['campaign_id']) && (int) $_POST['campaign_id'] > 0 ? (int) $_POST['campaign_id'] : null;

    if ($username === '' || $full_name === '' || $first_name === '' || $last_name === '' || $hire_date === '' || $email === '') {
        $error = 'Los campos Usuario, Nombre completo, Nombre, Apellido, Email y Fecha de ingreso son obligatorios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El formato del correo electrónico no es válido.';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $exists = $stmt->fetch();

        if ($exists && (int) $exists['count'] > 0) {
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
                    $lastNumber = (int) substr($lastCode['employee_code'], -4);
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
                $insert = $pdo->prepare("INSERT INTO users (username, employee_code, full_name, password, role, compensation_type, hourly_rate, hourly_rate_dop, monthly_salary, monthly_salary_dop, daily_salary_usd, daily_salary_dop, preferred_currency, department_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert->execute([$username, $employeeCode, $full_name, $password, $role, $compensation_type, $hourly_rate, $hourly_rate_dop, $monthly_salary_usd, $monthly_salary_dop, $daily_salary_usd, $daily_salary_dop, $preferred_currency, $department_id]);
                $userId = $pdo->lastInsertId();

                // Insert employee record with new fields
                $employeeInsert = $pdo->prepare("
                    INSERT INTO employees (
                        user_id, employee_code, first_name, last_name, email, phone, 
                        birth_date, hire_date, position, department_id, employment_status,
                        id_card_number, bank_id, bank_account_number, bank_account_type, photo_path,
                        supervisor_id, campaign_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'TRIAL', ?, ?, ?, ?, ?, ?, ?)
                ");
                try {
                    $employeeInsert->execute([
                        $userId,
                        $employeeCode,
                        $first_name,
                        $last_name,
                        $email,
                        $phone,
                        $birth_date ?: null,
                        $hire_date,
                        $position,
                        $department_id,
                        $id_card_number ?: null,
                        $bank_id,
                        $bank_account_number ?: null,
                        $bank_account_type,
                        $photoPath,
                        $supervisor_id,
                        $campaign_id
                    ]);
                } catch (PDOException $e) {
                    error_log("FOREIGN KEY ERROR in new_employee.php (employees table insert): " . $e->getMessage());
                    error_log("Parameters: " . json_encode([
                        'userId' => $userId,
                        'employeeCode' => $employeeCode,
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'email' => $email,
                        'department_id' => $department_id,
                        'bank_id' => $bank_id,
                        'supervisor_id' => $supervisor_id,
                        'campaign_id' => $campaign_id
                    ]));
                    throw $e;
                }

                $employeeId = $pdo->lastInsertId();

                // Create employee schedule
                if (!empty($scheduleAssignments)) {
                    foreach ($scheduleAssignments as $assignment) {
                        $scheduleData = [
                            'schedule_name' => $assignment['schedule_name'] ?? null,
                            'entry_time' => normalizeScheduleTimeValue($assignment['entry_time'] ?? null, '10:00:00'),
                            'exit_time' => normalizeScheduleTimeValue($assignment['exit_time'] ?? null, '19:00:00'),
                            'lunch_time' => normalizeScheduleTimeValue($assignment['lunch_time'] ?? null, '14:00:00'),
                            'break_time' => normalizeScheduleTimeValue($assignment['break_time'] ?? null, '17:00:00'),
                            'lunch_minutes' => (int) ($assignment['lunch_minutes'] ?? 45),
                            'break_minutes' => (int) ($assignment['break_minutes'] ?? 15),
                            'scheduled_hours' => (float) ($assignment['scheduled_hours'] ?? 8.00),
                            'is_active' => 1,
                            'effective_date' => $assignment['effective_date'] ?? $hire_date,
                            'end_date' => $assignment['end_date'] ?? null,
                            'notes' => $assignment['notes'] ?? null,
                            'days_of_week' => !empty($assignment['days_of_week']) ? $assignment['days_of_week'] : null
                        ];
                        createEmployeeSchedule($pdo, $employeeId, (int) $userId, $scheduleData);
                    }
                } elseif ($schedule_template_id) {
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
                    'compensation_type' => $compensation_type,
                    'hourly_rate' => $hourly_rate,
                    'hourly_rate_dop' => $hourly_rate_dop,
                    'monthly_salary_usd' => $monthly_salary_usd,
                    'monthly_salary_dop' => $monthly_salary_dop,
                    'daily_salary_usd' => $daily_salary_usd,
                    'daily_salary_dop' => $daily_salary_dop,
                    'preferred_currency' => $preferred_currency
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
                    <div class="status-banner"
                        style="background: linear-gradient(135deg, #f59e0b15 0%, #d9770615 100%); border-left: 4px solid #f59e0b; color: #d97706;">
                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($emailWarning) ?>
                    </div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data" class="space-y-4" id="newEmployeeForm">
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
                            <input type="text" id="password" name="password" placeholder="defaultpassword"
                                value="defaultpassword">
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
                            <p class="text-xs text-slate-400 mt-1">Se enviará un correo con las credenciales de acceso
                            </p>
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
                        <i class="fas fa-users-cog text-blue-400 mr-2"></i>
                        Asignación de Supervisor y Campaña
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label for="supervisor_id">Supervisor Asignado</label>
                            <select id="supervisor_id" name="supervisor_id">
                                <option value="">Sin supervisor</option>
                                <?php
                                $supervisors = $pdo->query("
                                SELECT u.id, u.full_name, u.employee_code, u.role
                                FROM users u
                                WHERE u.role IN ('Supervisor', 'Admin', 'HR', 'Manager') AND u.is_active = 1
                                ORDER BY u.full_name ASC
                            ")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($supervisors as $supervisor) {
                                    echo '<option value="' . htmlspecialchars($supervisor['id']) . '">';
                                    echo htmlspecialchars($supervisor['full_name']) . ' (' . htmlspecialchars($supervisor['role']) . ')';
                                    echo '</option>';
                                }
                                ?>
                            </select>
                            <p class="text-xs text-slate-400 mt-1">Supervisor responsable del empleado</p>
                        </div>
                        <div class="form-group">
                            <label for="campaign_id">Campaña</label>
                            <div class="flex gap-2">
                                <select id="campaign_id" name="campaign_id" class="flex-1">
                                    <option value="">Sin campaña</option>
                                    <?php
                                    $campaigns = $pdo->query("
                                    SELECT id, name, code, color
                                    FROM campaigns
                                    WHERE is_active = 1
                                    ORDER BY name ASC
                                ")->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($campaigns as $campaign) {
                                        echo '<option value="' . htmlspecialchars($campaign['id']) . '">';
                                        echo htmlspecialchars($campaign['name']) . ' (' . htmlspecialchars($campaign['code']) . ')';
                                        echo '</option>';
                                    }
                                    ?>
                                </select>
                                <button type="button" onclick="openNewCampaignModal()"
                                    class="btn-secondary px-3 whitespace-nowrap" title="Crear nueva campaña">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                            <p class="text-xs text-slate-400 mt-1">Campaña a la que pertenece el agente</p>
                        </div>
                    </div>

                    <h3 class="text-lg font-semibold text-white mt-6 mb-3 border-b border-slate-700 pb-2">
                        <i class="fas fa-dollar-sign text-blue-400 mr-2"></i>
                        Compensación y Salario
                    </h3>

                    <div class="form-group">
                        <label for="compensation_type">Tipo de Compensación *</label>
                        <select id="compensation_type" name="compensation_type" onchange="toggleCompensationFields()"
                            required>
                            <option value="hourly">Salario por Hora</option>
                            <option value="fixed">Salario Fijo (Mensual)</option>
                            <option value="daily">Salario Diario</option>
                        </select>
                        <p class="text-xs text-slate-400 mt-1">Selecciona el tipo de compensación del empleado</p>
                    </div>

                    <!-- Campos para Salario por Hora -->
                    <div id="hourly_fields" class="compensation-fields">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-group">
                                <label for="hourly_rate">Tarifa por hora (USD)</label>
                                <input type="number" id="hourly_rate" name="hourly_rate" step="0.01" min="0"
                                    placeholder="0.00">
                            </div>
                            <div class="form-group">
                                <label for="hourly_rate_dop">Tarifa por hora (DOP)</label>
                                <input type="number" id="hourly_rate_dop" name="hourly_rate_dop" step="0.01" min="0"
                                    placeholder="0.00">
                            </div>
                        </div>
                    </div>

                    <!-- Campos para Salario Fijo -->
                    <div id="fixed_fields" class="compensation-fields hidden">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-group">
                                <label for="monthly_salary_usd">Salario mensual (USD)</label>
                                <input type="number" id="monthly_salary_usd" name="monthly_salary_usd" step="0.01"
                                    min="0" placeholder="0.00">
                            </div>
                            <div class="form-group">
                                <label for="monthly_salary_dop">Salario mensual (DOP)</label>
                                <input type="number" id="monthly_salary_dop" name="monthly_salary_dop" step="0.01"
                                    min="0" placeholder="0.00">
                            </div>
                        </div>
                    </div>

                    <!-- Campos para Salario Diario -->
                    <div id="daily_fields" class="compensation-fields hidden">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-group">
                                <label for="daily_salary_usd">Salario diario (USD)</label>
                                <input type="number" id="daily_salary_usd" name="daily_salary_usd" step="0.01" min="0"
                                    placeholder="0.00">
                            </div>
                            <div class="form-group">
                                <label for="daily_salary_dop">Salario diario (DOP)</label>
                                <input type="number" id="daily_salary_dop" name="daily_salary_dop" step="0.01" min="0"
                                    placeholder="0.00">
                            </div>
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
                            <select id="schedule_template_id" name="schedule_template_id" class="flex-1"
                                onchange="updateScheduleButtons()">
                                <option value="">Usar horario global del sistema</option>
                                <?php
                                $scheduleTemplates = getAllScheduleTemplates($pdo);
                                foreach ($scheduleTemplates as $template) {
                                    $timeInfo = date('g:i A', strtotime($template['entry_time'])) . ' - ' . date('g:i A', strtotime($template['exit_time']));
                                    echo '<option value="' . htmlspecialchars($template['id']) . '"'
                                        . ' data-name="' . htmlspecialchars($template['name'], ENT_QUOTES, 'UTF-8') . '"'
                                        . ' data-entry="' . htmlspecialchars($template['entry_time'], ENT_QUOTES, 'UTF-8') . '"'
                                        . ' data-exit="' . htmlspecialchars($template['exit_time'], ENT_QUOTES, 'UTF-8') . '"'
                                        . ' data-lunch="' . htmlspecialchars($template['lunch_time'], ENT_QUOTES, 'UTF-8') . '"'
                                        . ' data-break="' . htmlspecialchars($template['break_time'], ENT_QUOTES, 'UTF-8') . '"'
                                        . ' data-lunch-minutes="' . htmlspecialchars((string) $template['lunch_minutes'], ENT_QUOTES, 'UTF-8') . '"'
                                        . ' data-break-minutes="' . htmlspecialchars((string) $template['break_minutes'], ENT_QUOTES, 'UTF-8') . '"'
                                        . ' data-hours="' . htmlspecialchars((string) $template['scheduled_hours'], ENT_QUOTES, 'UTF-8') . '">';
                                    echo htmlspecialchars($template['name']) . ' (' . $timeInfo . ')';
                                    echo '</option>';
                                }
                                ?>
                            </select>
                            <button type="button" onclick="openNewScheduleModal()"
                                class="btn-secondary px-3 whitespace-nowrap" title="Crear nuevo turno">
                                <i class="fas fa-plus"></i>
                            </button>
                            <button type="button" id="editScheduleBtn" onclick="editSelectedSchedule()"
                                class="btn-secondary px-3 whitespace-nowrap hidden" title="Editar turno seleccionado">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button type="button" id="deleteScheduleBtn" onclick="deleteSelectedSchedule()"
                                class="btn-secondary px-3 whitespace-nowrap hidden" title="Eliminar turno seleccionado"
                                style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">
                            <i class="fas fa-info-circle"></i>
                            Selecciona el horario de trabajo del empleado o crea uno nuevo.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                        <div class="form-group">
                            <label for="assignment_effective_date">Fecha inicio</label>
                            <input type="date" id="assignment_effective_date" name="assignment_effective_date">
                        </div>
                        <div class="form-group">
                            <label for="assignment_end_date">Fecha fin (opcional)</label>
                            <input type="date" id="assignment_end_date" name="assignment_end_date">
                        </div>
                        <div class="flex items-end">
                            <button type="button" class="btn-secondary w-full" onclick="addScheduleAssignmentNew()">
                                <i class="fas fa-plus mr-1"></i>
                                Agregar horario
                            </button>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="text-xs text-slate-400 block mb-2">Días de la semana (opcional)</label>
                        <div class="flex flex-wrap gap-2">
                            <label
                                class="inline-flex items-center gap-2 text-xs text-slate-200 bg-slate-800/60 px-3 py-2 rounded-lg">
                                <input type="checkbox" name="schedule_days_new[]" value="1" data-schedule-day-new> Lunes
                            </label>
                            <label
                                class="inline-flex items-center gap-2 text-xs text-slate-200 bg-slate-800/60 px-3 py-2 rounded-lg">
                                <input type="checkbox" name="schedule_days_new[]" value="2" data-schedule-day-new>
                                Martes
                            </label>
                            <label
                                class="inline-flex items-center gap-2 text-xs text-slate-200 bg-slate-800/60 px-3 py-2 rounded-lg">
                                <input type="checkbox" name="schedule_days_new[]" value="3" data-schedule-day-new>
                                Miércoles
                            </label>
                            <label
                                class="inline-flex items-center gap-2 text-xs text-slate-200 bg-slate-800/60 px-3 py-2 rounded-lg">
                                <input type="checkbox" name="schedule_days_new[]" value="4" data-schedule-day-new>
                                Jueves
                            </label>
                            <label
                                class="inline-flex items-center gap-2 text-xs text-slate-200 bg-slate-800/60 px-3 py-2 rounded-lg">
                                <input type="checkbox" name="schedule_days_new[]" value="5" data-schedule-day-new>
                                Viernes
                            </label>
                            <label
                                class="inline-flex items-center gap-2 text-xs text-slate-200 bg-slate-800/60 px-3 py-2 rounded-lg">
                                <input type="checkbox" name="schedule_days_new[]" value="6" data-schedule-day-new>
                                Sábado
                            </label>
                            <label
                                class="inline-flex items-center gap-2 text-xs text-slate-200 bg-slate-800/60 px-3 py-2 rounded-lg">
                                <input type="checkbox" name="schedule_days_new[]" value="7" data-schedule-day-new>
                                Domingo
                            </label>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">Si no seleccionas días, el horario aplicará todos los
                            días.</p>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">
                        <i class="fas fa-info-circle"></i>
                        El horario se aplicará automáticamente en el sistema de ponche.
                    </p>
                    <input type="hidden" id="schedule_assignments_json" name="schedule_assignments_json">
                    <div id="schedule_assignments_list" class="mt-3 space-y-2"></div>

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

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label for="bank_account_number">Número de Cuenta Bancaria</label>
                            <input type="text" id="bank_account_number" name="bank_account_number"
                                placeholder="Número de cuenta">
                        </div>
                        <div class="form-group">
                            <label for="bank_account_type">Tipo de Cuenta</label>
                            <select id="bank_account_type" name="bank_account_type">
                                <option value="">Seleccionar tipo de cuenta</option>
                                <option value="AHORROS_DOP">Ahorros en Pesos (DOP)</option>
                                <option value="AHORROS_USD">Ahorros en Dólares (USD)</option>
                                <option value="CORRIENTE_DOP">Corriente en Pesos (DOP)</option>
                                <option value="CORRIENTE_USD">Corriente en Dólares (USD)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="employee_photo">Foto del Empleado</label>
                        <input type="file" id="employee_photo" name="employee_photo"
                            accept="image/jpeg,image/png,image/gif,image/jpg" class="block w-full text-sm text-slate-400
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
    <div id="newScheduleModal"
        class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto">
        <div class="glass-card m-4" style="width: min(600px, 95%); max-height: 90vh; overflow-y: auto;">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-clock text-blue-400 mr-2"></i>
                Crear Nuevo Turno
            </h3>
            <form id="newScheduleForm" method="POST" onsubmit="saveNewSchedule(event); return false;">
                <div class="form-group mb-4">
                    <label for="new_schedule_name">Nombre del Turno *</label>
                    <input type="text" id="new_schedule_name" name="name" required
                        placeholder="Ej: Turno Especial 8am-5pm">
                </div>

                <div class="form-group mb-4">
                    <label for="new_schedule_description">Descripción</label>
                    <textarea id="new_schedule_description" name="description" rows="2"
                        placeholder="Descripción opcional del turno"></textarea>
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
                        <input type="number" id="new_scheduled_hours" name="scheduled_hours" step="0.25" min="0"
                            value="8.00">
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

    <!-- Modal para crear nueva campaña -->
    <div id="newCampaignModal"
        class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto">
        <div class="glass-card m-4" style="width: min(500px, 95%); max-height: 90vh; overflow-y: auto;">
            <h3 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-bullhorn text-blue-400 mr-2"></i>
                Crear Nueva Campaña
            </h3>
            <form id="newCampaignForm" method="POST" onsubmit="saveNewCampaign(event); return false;">
                <div class="form-group mb-4">
                    <label for="campaign_name">Nombre de la Campaña *</label>
                    <input type="text" id="campaign_name" name="name" required placeholder="Ej: Soporte Técnico">
                </div>

                <div class="form-group mb-4">
                    <label for="campaign_code">Código de la Campaña *</label>
                    <input type="text" id="campaign_code" name="code" required placeholder="Ej: TECH-SUPPORT"
                        maxlength="50" style="text-transform: uppercase;">
                    <p class="text-xs text-slate-400 mt-1">Código único para identificar la campaña (sin espacios)</p>
                </div>

                <div class="form-group mb-4">
                    <label for="campaign_description">Descripción</label>
                    <textarea id="campaign_description" name="description" rows="3"
                        placeholder="Descripción opcional de la campaña"></textarea>
                </div>

                <div class="form-group mb-4">
                    <label for="campaign_color">Color de Identificación</label>
                    <div class="flex gap-3 items-center">
                        <input type="color" id="campaign_color" name="color" value="#6366f1"
                            class="h-10 w-20 border-0 rounded cursor-pointer">
                        <span class="text-sm text-slate-400">Selecciona un color para identificar visualmente la
                            campaña</span>
                    </div>
                </div>

                <div id="campaignFormMessage" class="mb-4 hidden"></div>

                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-save"></i>
                        Crear Campaña
                    </button>
                    <button type="button" onclick="closeNewCampaignModal()" class="btn-secondary flex-1">
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

        // Función para mostrar/ocultar campos según el tipo de compensación
        function toggleCompensationFields() {
            const compensationType = document.getElementById('compensation_type').value;
            const hourlyFields = document.getElementById('hourly_fields');
            const fixedFields = document.getElementById('fixed_fields');
            const dailyFields = document.getElementById('daily_fields');

            // Ocultar todos los campos
            hourlyFields.classList.add('hidden');
            fixedFields.classList.add('hidden');
            dailyFields.classList.add('hidden');

            // Limpiar campos no visibles
            if (compensationType !== 'hourly') {
                document.getElementById('hourly_rate').value = '';
                document.getElementById('hourly_rate_dop').value = '';
            }
            if (compensationType !== 'fixed') {
                document.getElementById('monthly_salary_usd').value = '';
                document.getElementById('monthly_salary_dop').value = '';
            }
            if (compensationType !== 'daily') {
                document.getElementById('daily_salary_usd').value = '';
                document.getElementById('daily_salary_dop').value = '';
            }

            // Mostrar campos correspondientes
            if (compensationType === 'hourly') {
                hourlyFields.classList.remove('hidden');
            } else if (compensationType === 'fixed') {
                fixedFields.classList.remove('hidden');
            } else if (compensationType === 'daily') {
                dailyFields.classList.remove('hidden');
            }
        }

        // Inicializar al cargar la página
        document.addEventListener('DOMContentLoaded', function () {
            toggleCompensationFields();
        });

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

        window.scheduleAssignmentsNew = [];

        function escapeScheduleHtml(value) {
            const text = String(value ?? '');
            return text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatScheduleTimeLabelNew(timeStr) {
            if (!timeStr) return '';
            const parts = timeStr.split(':');
            if (parts.length < 2) return timeStr;
            let hour = parseInt(parts[0], 10);
            const minutes = parts[1];
            if (Number.isNaN(hour)) return timeStr;
            const ampm = hour >= 12 ? 'PM' : 'AM';
            hour = hour % 12;
            if (hour === 0) hour = 12;
            return `${hour}:${minutes} ${ampm}`;
        }

        function formatScheduleDaysLabelNew(daysValue) {
            if (!daysValue) return 'Todos los días';
            const map = {
                1: 'Lun',
                2: 'Mar',
                3: 'Mié',
                4: 'Jue',
                5: 'Vie',
                6: 'Sáb',
                7: 'Dom'
            };
            const parts = daysValue.split(',').map(value => map[parseInt(value, 10)]).filter(Boolean);
            return parts.length ? parts.join(', ') : 'Todos los días';
        }

        function renderScheduleAssignmentsNew() {
            const list = document.getElementById('schedule_assignments_list');
            const input = document.getElementById('schedule_assignments_json');
            if (!list || !input) return;

            const assignments = Array.isArray(window.scheduleAssignmentsNew)
                ? window.scheduleAssignmentsNew
                : [];

            input.value = JSON.stringify(assignments);

            if (assignments.length === 0) {
                list.innerHTML = '<div class="text-slate-400 text-sm">Sin horarios asignados (usa el horario global o agrega uno)</div>';
                return;
            }

            list.innerHTML = assignments.map((assignment, index) => {
                const entryLabel = assignment.entry_time_display || formatScheduleTimeLabelNew(assignment.entry_time);
                const exitLabel = assignment.exit_time_display || formatScheduleTimeLabelNew(assignment.exit_time);
                const dateLabel = assignment.effective_date
                    ? `${assignment.effective_date}${assignment.end_date ? ' → ' + assignment.end_date : ''}`
                    : 'Sin fecha';
                const daysLabel = formatScheduleDaysLabelNew(assignment.days_of_week);
                return `
                    <div class="flex items-start justify-between gap-3 p-3 bg-slate-800/60 rounded-lg">
                        <div>
                            <div class="text-slate-200 font-medium">
                                ${escapeScheduleHtml(assignment.schedule_name || 'Horario')}
                            </div>
                            <div class="text-slate-400 text-xs">
                                ${escapeScheduleHtml(entryLabel)} - ${escapeScheduleHtml(exitLabel)} · ${escapeScheduleHtml(dateLabel)} · ${escapeScheduleHtml(daysLabel)}
                            </div>
                            <div class="text-slate-500 text-xs">
                                ${Number(assignment.scheduled_hours || 0).toFixed(2)} horas
                            </div>
                        </div>
                        <button type="button" class="btn-secondary px-3" onclick="removeScheduleAssignmentNew(${index})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                `;
            }).join('');
        }

        function collectSelectedDaysNew() {
            const dayInputs = document.querySelectorAll('[data-schedule-day-new]');
            const selectedDays = Array.from(dayInputs)
                .filter(input => input.checked)
                .map(input => input.value)
                .join(',');
            return selectedDays !== '' ? selectedDays : null;
        }

        function buildAssignmentFromSelectionNew() {
            const select = document.getElementById('schedule_template_id');
            if (!select || !select.value) {
                return null;
            }
            const option = select.options[select.selectedIndex];
            if (!option) {
                return null;
            }
            const effectiveDateInput = document.getElementById('assignment_effective_date');
            const endDateInput = document.getElementById('assignment_end_date');
            const effectiveDate = effectiveDateInput && effectiveDateInput.value
                ? effectiveDateInput.value
                : new Date().toISOString().slice(0, 10);
            const endDate = endDateInput && endDateInput.value ? endDateInput.value : null;

            return {
                schedule_name: option.dataset.name || option.textContent.trim() || 'Horario',
                entry_time: option.dataset.entry || null,
                exit_time: option.dataset.exit || null,
                lunch_time: option.dataset.lunch || null,
                break_time: option.dataset.break || null,
                lunch_minutes: parseInt(option.dataset.lunchMinutes || '0', 10) || 0,
                break_minutes: parseInt(option.dataset.breakMinutes || '0', 10) || 0,
                scheduled_hours: parseFloat(option.dataset.hours || '0') || 0,
                effective_date: effectiveDate,
                end_date: endDate,
                notes: option.dataset.name ? `Asignado desde template: ${option.dataset.name}` : null,
                days_of_week: collectSelectedDaysNew(),
                entry_time_display: null,
                exit_time_display: null
            };
        }

        function addScheduleAssignmentNew() {
            const assignment = buildAssignmentFromSelectionNew();
            if (!assignment) {
                alert('Selecciona un turno para agregar.');
                return;
            }
            window.scheduleAssignmentsNew = window.scheduleAssignmentsNew || [];
            window.scheduleAssignmentsNew.push(assignment);
            renderScheduleAssignmentsNew();

            const select = document.getElementById('schedule_template_id');
            if (select) {
                select.value = '';
            }
            document.querySelectorAll('[data-schedule-day-new]').forEach(input => {
                input.checked = false;
            });
            const endDateInput = document.getElementById('assignment_end_date');
            if (endDateInput) {
                endDateInput.value = '';
            }
        }

        function removeScheduleAssignmentNew(index) {
            if (!Array.isArray(window.scheduleAssignmentsNew)) {
                window.scheduleAssignmentsNew = [];
            }
            window.scheduleAssignmentsNew.splice(index, 1);
            renderScheduleAssignmentsNew();
        }

        document.getElementById('newEmployeeForm')?.addEventListener('submit', function () {
            if (!Array.isArray(window.scheduleAssignmentsNew)) {
                window.scheduleAssignmentsNew = [];
            }
            if (window.scheduleAssignmentsNew.length === 0) {
                const assignment = buildAssignmentFromSelectionNew();
                if (assignment) {
                    window.scheduleAssignmentsNew.push(assignment);
                }
            }
            renderScheduleAssignmentsNew();
        });

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

            // Use simple relative path - go up one directory from hr/
            const endpoint = isEditMode ? '../update_schedule_template.php' : '../save_schedule_template.php';

            console.log('Endpoint:', endpoint);
            console.log('FormData entries:');
            for (let [key, value] of formData.entries()) {
                console.log(key, '=', value);
            }

            fetch(endpoint, {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    console.log('Response status:', response.status);
                    console.log('Response headers:', response.headers);
                    return response.text().then(text => {
                        console.log('Response text:', text);
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('JSON parse error:', e);
                            throw new Error('Respuesta inválida del servidor: ' + text.substring(0, 200));
                        }
                    });
                })
                .then(data => {
                    console.log('Parsed data:', data);
                    if (data.success) {
                        messageDiv.className = 'status-banner success mb-4';
                        messageDiv.innerHTML = '<i class="fas fa-check-circle mr-2"></i>' + data.message;

                        const select = document.getElementById('schedule_template_id');
                        const template = data.template;
                        const entryTime = new Date('2000-01-01 ' + template.entry_time);
                        const exitTime = new Date('2000-01-01 ' + template.exit_time);
                        const timeInfo = entryTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) +
                            ' - ' + exitTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });

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
                    console.error('Fetch error:', error);
                    messageDiv.className = 'status-banner error mb-4';
                    messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Error al guardar: ' + error.message;
                });
        }

        // Close modal when clicking outside
        document.getElementById('newScheduleModal')?.addEventListener('click', function (e) {
            if (e.target === this) {
                closeNewScheduleModal();
            }
        });

        // ============================================
        // Funciones para Modal de Campañas
        // ============================================
        function openNewCampaignModal() {
            document.getElementById('newCampaignModal').classList.remove('hidden');
            document.getElementById('newCampaignForm').reset();
            document.getElementById('campaignFormMessage').classList.add('hidden');
        }

        function closeNewCampaignModal() {
            document.getElementById('newCampaignModal').classList.add('hidden');
        }

        function saveNewCampaign(event) {
            event.preventDefault();

            const form = event.target;
            const messageDiv = document.getElementById('campaignFormMessage');

            const campaignData = {
                name: document.getElementById('campaign_name').value.trim(),
                code: document.getElementById('campaign_code').value.trim().toUpperCase(),
                description: document.getElementById('campaign_description').value.trim(),
                color: document.getElementById('campaign_color').value,
                is_active: 1
            };

            // Validación
            if (!campaignData.name || !campaignData.code) {
                messageDiv.className = 'status-banner error mb-4';
                messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Nombre y código son obligatorios';
                messageDiv.classList.remove('hidden');
                return;
            }

            // Show loading
            messageDiv.className = 'status-banner mb-4';
            messageDiv.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Guardando campaña...';
            messageDiv.classList.remove('hidden');

            fetch('../api/campaigns.php?action=create', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(campaignData)
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        messageDiv.className = 'status-banner success mb-4';
                        messageDiv.innerHTML = '<i class="fas fa-check-circle mr-2"></i>' + data.message;

                        // Add new campaign to select
                        const select = document.getElementById('campaign_id');
                        const option = document.createElement('option');
                        option.value = data.campaign_id;
                        option.textContent = campaignData.name + ' (' + campaignData.code + ')';
                        option.selected = true;
                        select.appendChild(option);

                        // Close modal after 1 second
                        setTimeout(() => {
                            closeNewCampaignModal();
                        }, 1000);
                    } else {
                        messageDiv.className = 'status-banner error mb-4';
                        messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>' + data.error;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    messageDiv.className = 'status-banner error mb-4';
                    messageDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Error al guardar: ' + error.message;
                });
        }

        // Close campaign modal when clicking outside
        document.getElementById('newCampaignModal')?.addEventListener('click', function (e) {
            if (e.target === this) {
                closeNewCampaignModal();
            }
        });
    </script>
</body>

</html>
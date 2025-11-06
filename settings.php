<?php
session_start();
include 'db.php';
require_once 'lib/email_functions.php';

ensurePermission('settings');

$successMessages = [];
$errorMessages = [];
$emailWarning = null;

$sections = [
    // Core System
    'dashboard' => ['label' => 'Dashboard', 'category' => 'Sistema Principal', 'icon' => 'fa-gauge', 'description' => 'Panel principal del sistema'],
    'settings' => ['label' => 'Configuración', 'category' => 'Sistema Principal', 'icon' => 'fa-sliders-h', 'description' => 'Configuración general del sistema'],
    'activity_logs' => ['label' => 'Logs de Actividad', 'category' => 'Sistema Principal', 'icon' => 'fa-history', 'description' => 'Registro completo de todas las acciones del sistema'],
    'login_logs' => ['label' => 'Logs de Acceso', 'category' => 'Sistema Principal', 'icon' => 'fa-shield-alt', 'description' => 'Historial de inicios de sesión'],
    
    // Records & Reports
    'records' => ['label' => 'Registros', 'category' => 'Registros y Reportes', 'icon' => 'fa-table', 'description' => 'Registros de asistencia'],
    'records_qa' => ['label' => 'Registros QA', 'category' => 'Registros y Reportes', 'icon' => 'fa-clipboard-check', 'description' => 'Registros de control de calidad'],
    'view_admin_hours' => ['label' => 'Horas Administrativas', 'category' => 'Registros y Reportes', 'icon' => 'fa-user-clock', 'description' => 'Vista de horas administrativas'],
    'hr_report' => ['label' => 'Reporte HR', 'category' => 'Registros y Reportes', 'icon' => 'fa-briefcase', 'description' => 'Reportes de recursos humanos'],
    'adherence_report' => ['label' => 'Reporte de Adherencia', 'category' => 'Registros y Reportes', 'icon' => 'fa-chart-line', 'description' => 'Análisis de adherencia al horario'],
    'operations_dashboard' => ['label' => 'Dashboard de Operaciones', 'category' => 'Registros y Reportes', 'icon' => 'fa-sitemap', 'description' => 'Panel de operaciones'],
    'download_excel' => ['label' => 'Exportar Excel Mensual', 'category' => 'Registros y Reportes', 'icon' => 'fa-file-excel', 'description' => 'Exportación mensual a Excel'],
    'download_excel_daily' => ['label' => 'Exportar Excel Diario', 'category' => 'Registros y Reportes', 'icon' => 'fa-file-excel', 'description' => 'Exportación diaria a Excel'],
    
    // Attendance
    'register_attendance' => ['label' => 'Registrar Horas', 'category' => 'Asistencia', 'icon' => 'fa-calendar-plus', 'description' => 'Registro de horas administrativas'],
    
    // HR Module
    'hr_dashboard' => ['label' => 'Dashboard HR', 'category' => 'Recursos Humanos', 'icon' => 'fa-chart-pie', 'description' => 'Panel principal de recursos humanos'],
    'hr_employees' => ['label' => 'Empleados', 'category' => 'Recursos Humanos', 'icon' => 'fa-id-card', 'description' => 'Gestión de empleados'],
    'hr_trial_period' => ['label' => 'Período de Prueba', 'category' => 'Recursos Humanos', 'icon' => 'fa-hourglass-half', 'description' => 'Seguimiento de período de prueba (90 días)'],
    'hr_payroll' => ['label' => 'Nómina', 'category' => 'Recursos Humanos', 'icon' => 'fa-money-bill-wave', 'description' => 'Control de nómina y pagos'],
    'hr_birthdays' => ['label' => 'Cumpleaños', 'category' => 'Recursos Humanos', 'icon' => 'fa-birthday-cake', 'description' => 'Calendario de cumpleaños'],
    'hr_permissions' => ['label' => 'Permisos', 'category' => 'Recursos Humanos', 'icon' => 'fa-clipboard-list', 'description' => 'Solicitudes de permisos'],
    'hr_vacations' => ['label' => 'Vacaciones', 'category' => 'Recursos Humanos', 'icon' => 'fa-umbrella-beach', 'description' => 'Solicitudes de vacaciones'],
    'hr_calendar' => ['label' => 'Calendario HR', 'category' => 'Recursos Humanos', 'icon' => 'fa-calendar-alt', 'description' => 'Calendario integrado de eventos'],
    'hr_recruitment' => ['label' => 'Reclutamiento', 'category' => 'Recursos Humanos', 'icon' => 'fa-user-plus', 'description' => 'Gestión de vacantes y solicitudes de empleo'],
    'hr_job_postings' => ['label' => 'Gestión de Vacantes', 'category' => 'Recursos Humanos', 'icon' => 'fa-briefcase', 'description' => 'Crear y administrar vacantes de empleo'],
    
    // Agents
    'agent_dashboard' => ['label' => 'Dashboard de Agentes', 'category' => 'Portal de Agentes', 'icon' => 'fa-chart-bar', 'description' => 'Panel para agentes'],
    'agent_records' => ['label' => 'Registros de Agentes', 'category' => 'Portal de Agentes', 'icon' => 'fa-list', 'description' => 'Registros de agentes'],
    
    // Supervisor
    'supervisor_dashboard' => ['label' => 'Monitor en Tiempo Real', 'category' => 'Supervisión', 'icon' => 'fa-users-cog', 'description' => 'Monitor en tiempo real del estado de todos los agentes'],
    
    // Chat
    'chat_admin' => ['label' => 'Administración de Chat', 'category' => 'Comunicaciones', 'icon' => 'fa-comments', 'description' => 'Gestión de permisos y monitoreo de conversaciones de chat']
];

function sanitize_role_name(string $value): string
{
    return preg_replace('/[^A-Za-z0-9_]/', '', $value);
}

function normalize_hex_color(string $color, string $default = '#6366f1'): string
{
    $color = trim($color);
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        return strtoupper($color);
    }
    return strtoupper($default);
}

if (!function_exists('normalize_exit_time_input')) {
    /**
     * Normalizes a time input to HH:MM:SS or null.
     */
    function normalize_exit_time_input($value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $raw)) {
            $raw .= ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $raw)) {
            return $raw;
        }

        $parsed = strtotime($raw);
        return $parsed !== false ? date('H:i:s', $parsed) : null;
    }
}

$hasUserExitColumn = false;
try {
    $columnStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'exit_time'");
    $hasUserExitColumn = $columnStmt && $columnStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hasUserExitColumn = false;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['delete_department_id']) && !isset($_POST['action'])) {
            $_POST['action'] = 'delete_department';
            $_POST['department_id'] = $_POST['delete_department_id'];
        }
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'create_role':
                $roleKey = sanitize_role_name(trim($_POST['role_key'] ?? ''));
                $roleLabel = trim($_POST['role_label'] ?? '');
                $roleDescription = trim($_POST['role_description'] ?? '');

                if ($roleKey === '') {
                    $errorMessages[] = 'El identificador del rol es obligatorio y solo puede contener letras, numeros o guiones bajos.';
                    break;
                }

                $roleLabel = $roleLabel !== '' ? $roleLabel : $roleKey;

                try {
                    $insertRole = $pdo->prepare("INSERT INTO roles (name, label, description) VALUES (?, ?, ?)");
                    $insertRole->execute([$roleKey, $roleLabel, $roleDescription !== '' ? $roleDescription : null]);
                    ensureRoleExists($pdo, $roleKey, $roleLabel);
                    $successMessages[] = "Rol '{$roleKey}' creado correctamente.";
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $errorMessages[] = "El rol '{$roleKey}' ya existe.";
                    } else {
                        throw $e;
                    }
                }
                break;

            case 'update_roles':
                $labels = $_POST['role_label'] ?? [];
                $descriptions = $_POST['role_description'] ?? [];

                $updateRoleStmt = $pdo->prepare("UPDATE roles SET label = ?, description = ?, updated_at = NOW() WHERE name = ?");
                foreach ($labels as $roleName => $label) {
                    $roleName = sanitize_role_name($roleName);
                    if ($roleName === '') {
                        continue;
                    }
                    $labelValue = trim($label) !== '' ? trim($label) : $roleName;
                    $descriptionValue = trim($descriptions[$roleName] ?? '');
                    $updateRoleStmt->execute([$labelValue, $descriptionValue !== '' ? $descriptionValue : null, $roleName]);
                }

                $successMessages[] = 'Roles actualizados correctamente.';
                break;

            case 'create_user':
                $username = trim($_POST['username'] ?? '');
                $fullName = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = trim($_POST['password'] ?? '');
                $roleInput = trim($_POST['role'] ?? '');
                $role = sanitize_role_name($roleInput);
                $departmentIdRaw = $_POST['department_id'] ?? '';

                if ($username === '' || $fullName === '' || $password === '' || $role === '') {
                    $errorMessages[] = 'Todos los campos son obligatorios para crear un usuario.';
                    break;
                }

                $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $existsStmt->execute([$username]);
                if ($existsStmt->fetchColumn() > 0) {
                    $errorMessages[] = "El usuario '{$username}' ya existe.";
                    break;
                }

                $departmentId = null;
                if ($departmentIdRaw !== '' && is_numeric($departmentIdRaw)) {
                    $departmentCandidate = (int) $departmentIdRaw;
                    if ($departmentCandidate > 0) {
                        $deptCheck = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE id = ?");
                        $deptCheck->execute([$departmentCandidate]);
                        if ((int) $deptCheck->fetchColumn() === 0) {
                            $errorMessages[] = 'El departamento seleccionado no existe.';
                            break;
                        }
                        $departmentId = $departmentCandidate;
                    }
                }

                if ($departmentId === null) {
                    $errorMessages[] = 'Debes seleccionar un departamento valido.';
                    break;
                }

                ensureRoleExists($pdo, $role, $role);

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

                $pdo->beginTransaction();
                
                try {
                    // Create user (sin datos de salario, se configurarán desde HR)
                    $createStmt = $pdo->prepare("
                        INSERT INTO users (username, employee_code, full_name, password, role, department_id)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $createStmt->execute([
                        $username,
                        $employeeCode,
                        $fullName,
                        $password,
                        $role,
                        $departmentId
                    ]);
                    
                    $newUserId = $pdo->lastInsertId();
                    
                    // Auto-create employee record
                    // Split full_name into first_name and last_name
                    $nameParts = explode(' ', $fullName, 2);
                    $firstName = $nameParts[0];
                    $lastName = isset($nameParts[1]) ? $nameParts[1] : '';
                    
                    $createEmployeeStmt = $pdo->prepare("
                        INSERT INTO employees (
                            user_id, 
                            employee_code, 
                            first_name, 
                            last_name, 
                            email,
                            department_id, 
                            hire_date, 
                            employment_status, 
                            employment_type
                        ) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'TRIAL', 'FULL_TIME')
                    ");
                    $createEmployeeStmt->execute([
                        $newUserId,
                        $employeeCode,
                        $firstName,
                        $lastName,
                        $email ?: null,
                        $departmentId
                    ]);
                    
                    $pdo->commit();
                    
                    // Enviar correo de bienvenida si hay email en el registro de empleado
                    $emailWarning = null;
                    $employeeStmt = $pdo->prepare("SELECT email FROM employees WHERE user_id = ?");
                    $employeeStmt->execute([$newUserId]);
                    $employeeData = $employeeStmt->fetch();
                    
                    if ($employeeData && !empty($employeeData['email'])) {
                        // Obtener nombre del departamento
                        $departmentName = 'N/A';
                        if ($departmentId) {
                            $deptStmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                            $deptStmt->execute([$departmentId]);
                            $deptResult = $deptStmt->fetch();
                            if ($deptResult) {
                                $departmentName = $deptResult['name'];
                            }
                        }
                        
                        $emailData = [
                            'email' => $employeeData['email'],
                            'employee_name' => $fullName,
                            'username' => $username,
                            'password' => $password,
                            'employee_code' => $employeeCode,
                            'position' => 'Agente',
                            'department' => $departmentName,
                            'hire_date' => date('Y-m-d')
                        ];
                        
                        $emailResult = sendWelcomeEmail($emailData);
                        
                        if ($emailResult['success']) {
                            $successMessages[] = "Usuario '{$username}' y empleado creados correctamente con código {$employeeCode}. El empleado está en período de prueba (90 días). Se ha enviado un correo de bienvenida a {$employeeData['email']}.";
                        } else {
                            $successMessages[] = "Usuario '{$username}' y empleado creados correctamente con código {$employeeCode}. El empleado está en período de prueba (90 días).";
                            $emailWarning = "Advertencia: No se pudo enviar el correo de bienvenida. " . $emailResult['message'];
                        }
                    } else {
                        $successMessages[] = "Usuario '{$username}' y empleado creados correctamente con código {$employeeCode}. El empleado está en período de prueba (90 días). No se envió correo (email no registrado).";
                    }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errorMessages[] = "Error al crear usuario/empleado: " . $e->getMessage();
                }
                break;

            case 'update_users':
                $hourlyRatesUsd = $_POST['hourly_rate_usd'] ?? [];
                $hourlyRatesDop = $_POST['hourly_rate_dop'] ?? [];
                $monthlySalariesUsd = $_POST['monthly_salary_usd'] ?? [];
                $monthlySalariesDop = $_POST['monthly_salary_dop'] ?? [];
                $roles = $_POST['role'] ?? [];
                $passwords = $_POST['password'] ?? [];
                $departmentSelections = $_POST['department_id'] ?? [];
                $preferredCurrencies = $_POST['preferred_currency'] ?? [];
                $exitTimes = $_POST['exit_time'] ?? [];
                $overtimeMultipliers = $_POST['overtime_multiplier'] ?? [];

                $pdo->beginTransaction();
                $updateWithRoleStmt = $pdo->prepare("UPDATE users SET hourly_rate = ?, monthly_salary = ?, hourly_rate_dop = ?, monthly_salary_dop = ?, preferred_currency = ?, department_id = ?, exit_time = ?, overtime_multiplier = ?, role = ? WHERE id = ?");
                $updateWithoutRoleStmt = $pdo->prepare("UPDATE users SET hourly_rate = ?, monthly_salary = ?, hourly_rate_dop = ?, monthly_salary_dop = ?, preferred_currency = ?, department_id = ?, exit_time = ?, overtime_multiplier = ? WHERE id = ?");
                $passwordStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");

                foreach ($hourlyRatesUsd as $userId => $rateUsdInput) {
                    $userId = (int) $userId;
                    if ($userId <= 0) {
                        continue;
                    }

                    $rateUsd = number_format(max(0, (float) $rateUsdInput), 2, '.', '');
                    $rateDopInput = $hourlyRatesDop[$userId] ?? '0';
                    $rateDop = number_format(max(0, (float) $rateDopInput), 2, '.', '');

                    $monthlyUsdInput = $monthlySalariesUsd[$userId] ?? '0';
                    $monthlyUsd = number_format(max(0, (float) $monthlyUsdInput), 2, '.', '');
                    $monthlyDopInput = $monthlySalariesDop[$userId] ?? '0';
                    $monthlyDop = number_format(max(0, (float) $monthlyDopInput), 2, '.', '');

                    $preferredInput = strtoupper(trim($preferredCurrencies[$userId] ?? 'USD'));
                    $preferredCurrency = in_array($preferredInput, ['USD', 'DOP'], true) ? $preferredInput : 'USD';

                    $newRole = sanitize_role_name($roles[$userId] ?? '');
                    $newPassword = trim($passwords[$userId] ?? '');

                    $departmentValue = $departmentSelections[$userId] ?? '';
                    $departmentId = null;
                    if ($departmentValue !== '' && is_numeric($departmentValue)) {
                        $candidate = (int) $departmentValue;
                        if ($candidate > 0) {
                            $deptCheck = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE id = ?");
                            $deptCheck->execute([$candidate]);
                            if ((int) $deptCheck->fetchColumn() > 0) {
                                $departmentId = $candidate;
                            }
                        }
                    }

                    $exitTimeValue = normalize_exit_time_input($exitTimes[$userId] ?? '');
                    $overtimeMultiplierValue = null;
                    if (isset($overtimeMultipliers[$userId]) && trim($overtimeMultipliers[$userId]) !== '') {
                        $overtimeMultiplierValue = max(1.0, (float) $overtimeMultipliers[$userId]);
                    }

                    if ($newRole !== '') {
                        ensureRoleExists($pdo, $newRole, $newRole);
                        $updateWithRoleStmt->execute([$rateUsd, $monthlyUsd, $rateDop, $monthlyDop, $preferredCurrency, $departmentId, $exitTimeValue, $overtimeMultiplierValue, $newRole, $userId]);
                    } else {
                        $updateWithoutRoleStmt->execute([$rateUsd, $monthlyUsd, $rateDop, $monthlyDop, $preferredCurrency, $departmentId, $exitTimeValue, $overtimeMultiplierValue, $userId]);
                    }
                    
                    // Sync department_id to employees table if employee record exists
                    $syncEmployeeStmt = $pdo->prepare("UPDATE employees SET department_id = ? WHERE user_id = ?");
                    $syncEmployeeStmt->execute([$departmentId, $userId]);

                    if ($newPassword !== '') {
                        $passwordStmt->execute([$newPassword, $userId]);
                    }
                }

                $pdo->commit();
                $successMessages[] = 'Usuarios actualizados correctamente.';
                break;

            case 'update_schedule':
                $entryTime = $_POST['entry_time'] ?? '10:00';
                $exitTime = $_POST['exit_time'] ?? '19:00';
                $lunchTime = $_POST['lunch_time'] ?? '14:00';
                $breakTime = $_POST['break_time'] ?? '17:00';
                $lunchMinutes = max(0, (int) ($_POST['lunch_minutes'] ?? 45));
                $breakMinutes = max(0, (int) ($_POST['break_minutes'] ?? 15));
                $meetingMinutes = max(0, (int) ($_POST['meeting_minutes'] ?? 45));
                $scheduledHours = isset($_POST['scheduled_hours']) ? (float) $_POST['scheduled_hours'] : 8.0;
                $overtimeEnabled = isset($_POST['overtime_enabled']) ? 1 : 0;
                $overtimeMultiplier = max(1.0, (float) ($_POST['overtime_multiplier'] ?? 1.50));
                $overtimeStartMinutes = max(0, (int) ($_POST['overtime_start_minutes'] ?? 0));

                $scheduleStmt = $pdo->prepare("UPDATE schedule_config SET entry_time = ?, exit_time = ?, lunch_time = ?, break_time = ?, lunch_minutes = ?, break_minutes = ?, meeting_minutes = ?, scheduled_hours = ?, overtime_enabled = ?, overtime_multiplier = ?, overtime_start_minutes = ?, updated_at = NOW() WHERE id = 1");
                $scheduleStmt->execute([
                    $entryTime,
                    $exitTime,
                    $lunchTime,
                    $breakTime,
                    $lunchMinutes,
                    $breakMinutes,
                    $meetingMinutes,
                    $scheduledHours,
                    $overtimeEnabled,
                    $overtimeMultiplier,
                    $overtimeStartMinutes
                ]);

                $successMessages[] = 'Metas de horarios actualizadas.';
                break;

            case 'create_department':
                $departmentName = trim($_POST['department_name'] ?? '');
                $departmentDescription = trim($_POST['department_description'] ?? '');

                if ($departmentName === '') {
                    $errorMessages[] = 'El nombre del departamento es obligatorio.';
                    break;
                }

                $departmentId = ensureDepartmentExists($pdo, $departmentName, $departmentDescription !== '' ? $departmentDescription : null);
                if ($departmentId === null) {
                    $errorMessages[] = 'No se pudo registrar el departamento.';
                    break;
                }

                if ($departmentDescription !== '') {
                    $updateDept = $pdo->prepare("UPDATE departments SET description = ?, updated_at = NOW() WHERE id = ?");
                    $updateDept->execute([$departmentDescription, $departmentId]);
                }

                $successMessages[] = "Departamento '{$departmentName}' registrado.";
                break;

            case 'update_departments':
                $names = $_POST['department_name'] ?? [];
                $descriptions = $_POST['department_description'] ?? [];

                $updateDeptStmt = $pdo->prepare("UPDATE departments SET name = ?, description = ?, updated_at = NOW() WHERE id = ?");

                foreach ($names as $deptId => $name) {
                    $deptId = (int) $deptId;
                    $name = trim($name ?? '');
                    $desc = trim($descriptions[$deptId] ?? '');
                    if ($deptId <= 0 || $name === '') {
                        continue;
                    }

                    try {
                        $updateDeptStmt->execute([$name, $desc !== '' ? $desc : null, $deptId]);
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000') {
                            $errorMessages[] = "Ya existe un departamento con el nombre '{$name}'.";
                        } else {
                            throw $e;
                        }
                    }
                }

                if (empty($errorMessages)) {
                    $successMessages[] = 'Departamentos actualizados.';
                }
                break;

            case 'delete_department':
                $departmentId = isset($_POST['department_id']) ? (int) $_POST['department_id'] : 0;
                if ($departmentId <= 0) {
                    $errorMessages[] = 'Departamento invalido.';
                    break;
                }

                $deleteStmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
                $deleteStmt->execute([$departmentId]);
                $successMessages[] = 'Departamento eliminado.';
                break;

            case 'create_attendance_type':
                $label = trim($_POST['attendance_label'] ?? '');
                $slugInput = trim($_POST['attendance_slug'] ?? $label);
                $icon = trim($_POST['attendance_icon'] ?? 'fas fa-circle');
                $shortcut = strtoupper(trim($_POST['attendance_shortcut'] ?? ''));
                $sortOrder = (int) ($_POST['attendance_sort_order'] ?? 0);
                $colorStart = normalize_hex_color($_POST['attendance_color_start'] ?? '#6366f1', '#6366f1');
                $colorEnd = normalize_hex_color($_POST['attendance_color_end'] ?? $colorStart, $colorStart);
                $isUnique = isset($_POST['attendance_unique']) ? 1 : 0;
                $isActive = isset($_POST['attendance_active']) ? 1 : 0;
                $isPaid = isset($_POST['attendance_paid']) ? 1 : 0;

                if ($label === '') {
                    $errorMessages[] = 'El nombre del tipo es obligatorio.';
                    break;
                }

                $slug = sanitizeAttendanceTypeSlug($slugInput);
                if ($slug === '') {
                    $errorMessages[] = 'Debes definir un identificador valido para el tipo.';
                    break;
                }

                $shortcut = $shortcut !== '' ? mb_substr($shortcut, 0, 2) : null;

                $exists = $pdo->prepare("SELECT COUNT(*) FROM attendance_types WHERE slug = ?");
                $exists->execute([$slug]);
                if ((int) $exists->fetchColumn() > 0) {
                    $errorMessages[] = "Ya existe un tipo con el identificador '{$slug}'.";
                    break;
                }

                $insertType = $pdo->prepare("
                    INSERT INTO attendance_types (slug, label, icon_class, shortcut_key, color_start, color_end, sort_order, is_unique_daily, is_active, is_paid)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insertType->execute([
                    $slug,
                    $label,
                    $icon !== '' ? $icon : 'fas fa-circle',
                    $shortcut,
                    $colorStart,
                    $colorEnd,
                    $sortOrder,
                    $isUnique,
                    $isActive,
                    $isPaid
                ]);

                $successMessages[] = "Tipo de asistencia '{$label}' creado correctamente.";
                break;

            case 'update_attendance_types':
                $labels = $_POST['attendance_label'] ?? [];
                $slugs = $_POST['attendance_slug'] ?? [];
                $icons = $_POST['attendance_icon'] ?? [];
                $shortcuts = $_POST['attendance_shortcut'] ?? [];
                $colorStarts = $_POST['attendance_color_start'] ?? [];
                $colorEnds = $_POST['attendance_color_end'] ?? [];
                $sortOrders = $_POST['attendance_sort_order'] ?? [];
                $uniques = $_POST['attendance_unique'] ?? [];
                $actives = $_POST['attendance_active'] ?? [];
                $paids = $_POST['attendance_paid'] ?? [];

                $updated = false;
                $updateStmt = $pdo->prepare("
                    UPDATE attendance_types 
                    SET slug = ?, label = ?, icon_class = ?, shortcut_key = ?, color_start = ?, color_end = ?, sort_order = ?, is_unique_daily = ?, is_active = ?, is_paid = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $duplicateCheck = $pdo->prepare("SELECT COUNT(*) FROM attendance_types WHERE slug = ? AND id <> ?");

                foreach ($labels as $id => $labelValue) {
                    $id = (int) $id;
                    $labelValue = trim($labelValue ?? '');
                    if ($id <= 0 || $labelValue === '') {
                        continue;
                    }

                    $slugValue = sanitizeAttendanceTypeSlug($slugs[$id] ?? $labelValue);
                    if ($slugValue === '') {
                        $errorMessages[] = "El tipo con ID {$id} requiere un identificador valido.";
                        continue;
                    }

                    $duplicateCheck->execute([$slugValue, $id]);
                    if ((int) $duplicateCheck->fetchColumn() > 0) {
                        $errorMessages[] = "Ya existe otro tipo con el identificador '{$slugValue}'.";
                        continue;
                    }

                    $iconValue = trim($icons[$id] ?? 'fas fa-circle');
                    $shortcutValue = strtoupper(trim($shortcuts[$id] ?? ''));
                    $shortcutValue = $shortcutValue !== '' ? mb_substr($shortcutValue, 0, 2) : null;
                    $colorStartValue = normalize_hex_color($colorStarts[$id] ?? '#6366f1', '#6366f1');
                    $colorEndValue = normalize_hex_color($colorEnds[$id] ?? $colorStartValue, $colorStartValue);
                    $sortOrderValue = (int) ($sortOrders[$id] ?? 0);
                    $isUniqueValue = isset($uniques[$id]) ? 1 : 0;
                    $isActiveValue = isset($actives[$id]) ? 1 : 0;
                    $isPaidValue = isset($paids[$id]) ? 1 : 0;

                    $updateStmt->execute([
                        $slugValue,
                        $labelValue,
                        $iconValue !== '' ? $iconValue : 'fas fa-circle',
                        $shortcutValue,
                        $colorStartValue,
                        $colorEndValue,
                        $sortOrderValue,
                        $isUniqueValue,
                        $isActiveValue,
                        $isPaidValue,
                        $id
                    ]);
                    $updated = true;
                }

                if ($updated) {
                    $successMessages[] = 'Tipos de asistencia actualizados correctamente.';
                }
                break;

            case 'delete_attendance_type':
                $typeId = isset($_POST['attendance_type_id']) ? (int) $_POST['attendance_type_id'] : 0;
                if ($typeId <= 0) {
                    $errorMessages[] = 'Tipo de asistencia invalido.';
                    break;
                }

                $deleteType = $pdo->prepare("DELETE FROM attendance_types WHERE id = ?");
                $deleteType->execute([$typeId]);
                $successMessages[] = 'Tipo de asistencia eliminado.';
                break;

            case 'update_permissions':
                $permissions = $_POST['permissions'] ?? [];
                $extraPermissions = $_POST['extra_permissions'] ?? [];

                $pdo->beginTransaction();
                $deleteStmt = $pdo->prepare("DELETE FROM section_permissions WHERE section_key = ?");
                $insertStmt = $pdo->prepare("INSERT INTO section_permissions (section_key, role) VALUES (?, ?)");

                foreach ($sections as $sectionKey => $_label) {
                    $deleteStmt->execute([$sectionKey]);

                    $selectedRoles = $permissions[$sectionKey] ?? [];
                    $extraValue = trim($extraPermissions[$sectionKey] ?? '');
                    if ($extraValue !== '') {
                        $additionalRoles = array_filter(array_map('sanitize_role_name', explode(',', $extraValue)));
                        $selectedRoles = array_merge($selectedRoles, $additionalRoles);
                    }

                    $uniqueRoles = array_unique(array_filter(array_map('sanitize_role_name', $selectedRoles)));
                    foreach ($uniqueRoles as $roleName) {
                        if ($roleName === '') {
                            continue;
                        }
                        ensureRoleExists($pdo, $roleName, $roleName);
                        $insertStmt->execute([$sectionKey, $roleName]);
                    }
                }

                $pdo->commit();
                
                // Clear permission cache for real-time updates
                include_once 'find_accessible_page.php';
                clearPermissionCache();
                
                $successMessages[] = 'Permisos actualizados correctamente.';
                
                // Force page reload to update menu in real-time
                header('Location: settings.php?tab=roles&permissions_updated=1#permissions-section');
                exit;
                break;

            case 'add_rate_change':
                $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
                $rateUsd = isset($_POST['new_rate_usd']) ? (float) $_POST['new_rate_usd'] : 0;
                $rateDop = isset($_POST['new_rate_dop']) ? (float) $_POST['new_rate_dop'] : 0;
                $effectiveDate = trim($_POST['effective_date'] ?? '');
                $notes = trim($_POST['rate_notes'] ?? '');

                if ($userId <= 0) {
                    $errorMessages[] = 'Usuario invalido.';
                    break;
                }

                if ($effectiveDate === '') {
                    $errorMessages[] = 'La fecha efectiva es obligatoria.';
                    break;
                }

                if ($rateUsd < 0 || $rateDop < 0) {
                    $errorMessages[] = 'Las tarifas no pueden ser negativas.';
                    break;
                }

                $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
                
                if (addHourlyRateHistory($pdo, $userId, $rateUsd, $rateDop, $effectiveDate, $createdBy, $notes !== '' ? $notes : null)) {
                    // Also update the current rate in users table
                    $updateStmt = $pdo->prepare("UPDATE users SET hourly_rate = ?, hourly_rate_dop = ? WHERE id = ?");
                    $updateStmt->execute([$rateUsd, $rateDop, $userId]);
                    
                    $successMessages[] = 'Cambio de tarifa registrado correctamente.';
                } else {
                    $errorMessages[] = 'No se pudo registrar el cambio de tarifa.';
                }
                break;

            case 'delete_rate_history':
                $historyId = isset($_POST['history_id']) ? (int) $_POST['history_id'] : 0;
                if ($historyId <= 0) {
                    $errorMessages[] = 'ID de historial invalido.';
                    break;
                }

                if (deleteRateHistoryEntry($pdo, $historyId)) {
                    $successMessages[] = 'Entrada de historial eliminada.';
                } else {
                    $errorMessages[] = 'No se pudo eliminar la entrada de historial.';
                }
                break;

            case 'toggle_user_status':
                $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
                $newStatus = isset($_POST['new_status']) ? (int) $_POST['new_status'] : 0;
                
                if ($userId <= 0) {
                    $errorMessages[] = 'Usuario invalido.';
                    break;
                }

                // Prevent deactivating yourself
                if ($userId === $_SESSION['user_id'] && $newStatus === 0) {
                    $errorMessages[] = 'No puedes desactivar tu propia cuenta.';
                    break;
                }

                $statusStmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                if ($statusStmt->execute([$newStatus, $userId])) {
                    $statusText = $newStatus === 1 ? 'activado' : 'desactivado';
                    $successMessages[] = "Usuario {$statusText} correctamente.";
                } else {
                    $errorMessages[] = 'No se pudo cambiar el estado del usuario.';
                }
                break;

            case 'send_password_reset':
                $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
                
                if ($userId <= 0) {
                    $errorMessages[] = 'Usuario invalido.';
                    break;
                }

                // Get user and employee data
                $userDataStmt = $pdo->prepare("
                    SELECT u.id, u.username, u.full_name, e.email 
                    FROM users u
                    LEFT JOIN employees e ON e.user_id = u.id
                    WHERE u.id = ?
                ");
                $userDataStmt->execute([$userId]);
                $userData = $userDataStmt->fetch(PDO::FETCH_ASSOC);

                if (!$userData) {
                    $errorMessages[] = 'Usuario no encontrado.';
                    break;
                }

                if (empty($userData['email'])) {
                    $errorMessages[] = 'El usuario no tiene un email registrado. Registra un email en el módulo HR primero.';
                    break;
                }

                // Generate reset token
                $resetToken = bin2hex(random_bytes(32));
                $tokenExpiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Store token in database (you might need to create this table)
                try {
                    // Check if password_reset_tokens table exists, if not use session
                    $checkTableStmt = $pdo->query("SHOW TABLES LIKE 'password_reset_tokens'");
                    if ($checkTableStmt->rowCount() > 0) {
                        $saveTokenStmt = $pdo->prepare("
                            INSERT INTO password_reset_tokens (user_id, token, expires_at)
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE token = ?, expires_at = ?
                        ");
                        $saveTokenStmt->execute([$userId, $resetToken, $tokenExpiry, $resetToken, $tokenExpiry]);
                    }
                } catch (Exception $e) {
                    // Table doesn't exist, continue anyway
                }

                // Send password reset email
                $emailData = [
                    'email' => $userData['email'],
                    'full_name' => $userData['full_name'],
                    'username' => $userData['username'],
                    'reset_token' => $resetToken
                ];

                $emailResult = sendPasswordResetEmail($emailData);

                if ($emailResult['success']) {
                    $successMessages[] = "Se ha enviado un correo de reseteo de contraseña a {$userData['email']}";
                } else {
                    $errorMessages[] = "No se pudo enviar el correo: " . $emailResult['message'];
                }
                break;

            case 'delete_user':
                $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
                
                if ($userId <= 0) {
                    $errorMessages[] = 'Usuario invalido.';
                    break;
                }

                // Prevent deleting yourself
                if ($userId === $_SESSION['user_id']) {
                    $errorMessages[] = 'No puedes eliminar tu propia cuenta.';
                    break;
                }

                $pdo->beginTransaction();
                try {
                    // Delete related employee record if exists
                    $deleteEmployeeStmt = $pdo->prepare("DELETE FROM employees WHERE user_id = ?");
                    $deleteEmployeeStmt->execute([$userId]);
                    
                    // Delete user
                    $deleteUserStmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $deleteUserStmt->execute([$userId]);
                    
                    $pdo->commit();
                    $successMessages[] = 'Usuario eliminado correctamente.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errorMessages[] = 'No se pudo eliminar el usuario: ' . $e->getMessage();
                }
                break;
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errorMessages[] = 'Ocurrio un error: ' . $e->getMessage();
}

$scheduleConfig = getScheduleConfig($pdo);

$departments = getAllDepartments($pdo);
$departmentMap = [];
foreach ($departments as $department) {
    $departmentMap[(int) $department['id']] = $department['name'];
}

$userStmt = $pdo->query("
    SELECT 
        u.id,
        u.username,
        u.full_name,
        u.role,
        u.hourly_rate,
        u.monthly_salary,
        u.hourly_rate_dop,
        u.monthly_salary_dop,
        u.preferred_currency,
        u.department_id,
        u.exit_time,
        u.overtime_multiplier,
        u.is_active,
        u.created_at,
        r.label AS role_label,
        d.name AS department_name
    FROM users u
    LEFT JOIN roles r ON r.name = u.role
    LEFT JOIN departments d ON d.id = u.department_id
    ORDER BY u.is_active DESC, u.username
");
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
$departmentUsage = [];
foreach ($users as $userRow) {
    $deptId = isset($userRow['department_id']) ? (int) $userRow['department_id'] : 0;
    if ($deptId > 0) {
        $departmentUsage[$deptId] = ($departmentUsage[$deptId] ?? 0) + 1;
    }
}

$rolesList = getAllRoles($pdo);
$roleNames = array_column($rolesList, 'name');
$roleLabels = [];
foreach ($rolesList as $roleRow) {
    $roleLabels[$roleRow['name']] = $roleRow['label'] ?? $roleRow['name'];
}

$attendanceTypesList = getAttendanceTypes($pdo, false);
$attendanceTypeActiveCount = 0;
$attendanceTypeMap = [];
foreach ($attendanceTypesList as $typeRow) {
    $attendanceTypeMap[$typeRow['slug']] = $typeRow;
    if ((int) ($typeRow['is_active'] ?? 0) === 1) {
        $attendanceTypeActiveCount++;
    }
}

$permStmt = $pdo->query("SELECT section_key, role FROM section_permissions ORDER BY section_key, role");
$permissionsBySection = [];
foreach ($permStmt->fetchAll(PDO::FETCH_ASSOC) as $permission) {
    $permissionsBySection[$permission['section_key']][] = $permission['role'];
}
?>
<?php include 'header.php'; ?>

<section class="space-y-12">
    <div class="max-w-7xl mx-auto px-6 py-10 space-y-10">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="space-y-3">
                <p class="text-muted text-xs uppercase tracking-[0.35em]">Control Center</p>
                <h1 class="text-primary text-3xl md:text-4xl font-bold">Configuracion general del sistema</h1>
                <p class="text-muted max-w-2xl">Administra usuarios, departamentos, roles y metas de horarios desde una sola vista. Los cambios se aplican inmediatamente en todos los reportes y dashboards.</p>
            </div>
            <div class="section-card w-full lg:w-80 p-5 space-y-3">
                <p class="text-muted text-xs uppercase tracking-widest">Referencia rapida</p>
                <ul class="text-sm text-primary space-y-2">
                    <li>- Los roles aceptan letras, numeros y guiones bajos.</li>
                    <li>- Define tarifas por hora y pagos mensuales para analiticas.</li>
                    <li>- Ajusta el horario objetivo antes de revisar adherencia.</li>
                </ul>
            </div>
        </div>

        <?php foreach ($successMessages as $message): ?>
            <div class="status-banner success"><?= htmlspecialchars($message) ?></div>
        <?php endforeach; ?>
        
        <?php if ($emailWarning): ?>
            <div class="status-banner" style="background: linear-gradient(135deg, #f59e0b15 0%, #d9770615 100%); border-left: 4px solid #f59e0b; color: #d97706;">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($emailWarning) ?>
            </div>
        <?php endif; ?>

        <?php foreach ($errorMessages as $message): ?>
            <div class="status-banner error"><?= htmlspecialchars($message) ?></div>
        <?php endforeach; ?>

        <div id="quick-actions-grid" class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <article id="create-user-card" class="glass-card space-y-5">
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-xl bg-cyan-500/15 text-primary flex items-center justify-center">
                        <i class="fas fa-user-plus text-cyan-300"></i>
                    </div>
                    <div>
                        <h2 class="text-primary text-lg font-semibold">Crear usuario</h2>
                        <p class="text-muted text-sm">Registra usuarios administrativos u operativos con su compensacion completa.</p>
                    </div>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create_user">
                    <div class="space-y-3">
                        <div>
                            <label class="form-label">Usuario</label>
                            <input type="text" name="username" required class="input-control" placeholder="ej. jdoe">
                        </div>
                        <div>
                            <label class="form-label">Nombre completo</label>
                            <input type="text" name="full_name" required class="input-control" placeholder="Nombre y apellido">
                        </div>
                        <div>
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="input-control" placeholder="correo@ejemplo.com">
                            <p class="text-muted text-xs mt-1">Se enviará un correo de bienvenida con las credenciales de acceso.</p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="form-label">Contrasena temporal</label>
                                <input type="text" name="password" required class="input-control" placeholder="Define una contrasena inicial">
                            </div>
                            <div>
                                <label class="form-label">Rol</label>
                                <input type="text" name="role" list="role-options" required class="input-control" placeholder="Ej. Admin">
                                <p class="text-muted text-xs mt-1">Puedes crear roles desde la pestana Roles y permisos.</p>
                            </div>
                        </div>
                        <div>
                            <label class="form-label">Departamento</label>
                            <select name="department_id" class="select-control" required <?= empty($departments) ? 'disabled' : '' ?>>
                                <option value="">Selecciona un departamento</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= (int) $department['id'] ?>"><?= htmlspecialchars($department['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($departments)): ?>
                                <p class="text-muted text-xs mt-1">Crea un departamento antes de registrar usuarios.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary w-full justify-center" <?= empty($departments) ? 'disabled' : '' ?>>
                        <i class="fas fa-save"></i>
                        Registrar usuario
                    </button>
                </form>
            </article>

            <article id="create-role-card" class="glass-card space-y-5">
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-xl bg-indigo-500/15 text-primary flex items-center justify-center">
                        <i class="fas fa-layer-group text-indigo-300"></i>
                    </div>
                    <div>
                        <h2 class="text-primary text-lg font-semibold">Crear rol</h2>
                        <p class="text-muted text-sm">Define roles reutilizables para asignar permisos rapidamente.</p>
                    </div>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create_role">
                    <div>
                        <label class="form-label">Identificador</label>
                        <input type="text" name="role_key" required class="input-control" placeholder="Ej. SupportLead">
                    </div>
                    <div>
                        <label class="form-label">Etiqueta</label>
                        <input type="text" name="role_label" class="input-control" placeholder="Nombre amigable">
                    </div>
                    <div>
                        <label class="form-label">Descripcion</label>
                        <textarea name="role_description" class="textarea-control" placeholder="Que permisos o proposito tiene este rol?"></textarea>
                    </div>
                    <button type="submit" class="btn-secondary w-full justify-center">
                        <i class="fas fa-plus-circle"></i>
                        Crear rol
                    </button>
                </form>
            </article>

            <article id="create-department-card" class="glass-card space-y-5">
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-xl bg-emerald-400/15 text-primary flex items-center justify-center">
                        <i class="fas fa-sitemap text-emerald-300"></i>
                    </div>
                    <div>
                        <h2 class="text-primary text-lg font-semibold">Registrar departamento</h2>
                        <p class="text-muted text-sm">Clasifica a tus colaboradores por area para indicadores y reportes.</p>
                    </div>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create_department">
                    <div>
                        <label class="form-label">Nombre del departamento</label>
                        <input type="text" name="department_name" required class="input-control" placeholder="Ej. Operaciones">
                    </div>
                    <div>
                        <label class="form-label">Descripcion</label>
                        <textarea name="department_description" class="textarea-control" placeholder="Resumen o responsabilidades"></textarea>
                    </div>
                    <button type="submit" class="btn-secondary w-full justify-center">
                        <i class="fas fa-check-circle"></i>
                        Guardar departamento
                    </button>
                </form>
            </article>
        </div>

        <article id="create-attendance-type-card" class="glass-card space-y-5">
            <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-xl bg-fuchsia-500/20 border border-fuchsia-500/40 flex items-center justify-center text-fuchsia-300">
                    <i class="fas fa-plus"></i>
                </div>
                <div>
                    <h2 class="text-primary text-lg font-semibold">Crear tipo de asistencia</h2>
                    <p class="text-muted text-sm">Configura nuevos botones para el registro de asistencia.</p>
                </div>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="create_attendance_type">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">Nombre visible</label>
                        <input type="text" name="attendance_label" class="input-control" required placeholder="Ej. Capacitación">
                    </div>
                    <div>
                        <label class="form-label">Identificador (slug)</label>
                        <input type="text" name="attendance_slug" class="input-control" placeholder="Se genera automáticamente si lo dejas vacío">
                    </div>
                    <div>
                        <label class="form-label">Icono (Font Awesome)</label>
                        <input type="text" name="attendance_icon" class="input-control" value="fas fa-circle" placeholder="fas fa-briefcase">
                    </div>
                    <div>
                        <label class="form-label">Atajo de teclado</label>
                        <input type="text" name="attendance_shortcut" class="input-control text-center" maxlength="2" placeholder="Opcional">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="form-label">Color inicio</label>
                        <input type="color" name="attendance_color_start" class="input-control" value="#6366f1">
                    </div>
                    <div>
                        <label class="form-label">Color fin</label>
                        <input type="color" name="attendance_color_end" class="input-control" value="#4338ca">
                    </div>
                    <div>
                        <label class="form-label">Orden</label>
                        <input type="number" name="attendance_sort_order" class="input-control" value="0">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="inline-flex items-center gap-2 text-sm text-muted">
                        <input type="checkbox" name="attendance_unique" value="1" class="accent-cyan-500">
                        Único por día
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-muted">
                        <input type="checkbox" name="attendance_active" value="1" class="accent-cyan-500" checked>
                        Activo
                    </label>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i>
                        Guardar tipo
                    </button>
                </div>
            </form>
        </article>

        <section id="attendance-types-section" class="glass-card space-y-6">
            <div class="panel-heading">
                <div>
                    <h2 class="text-primary text-xl font-semibold">Tipos de asistencia</h2>
                    <p class="text-muted text-sm">Modifica etiquetas, colores e iconos de los botones usados en punch.php.</p>
                </div>
                <span class="chip"><i class="fas fa-fingerprint"></i> <?= $attendanceTypeActiveCount ?> activos</span>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="attendance_type_id" value="">
                <div class="responsive-scroll">
                    <table class="data-table w-full text-sm">
                        <thead>
                            <tr>
                                <th>Orden</th>
                                <th>Identificador</th>
                                <th>Nombre</th>
                                <th>Icono</th>
                                <th>Color inicio</th>
                                <th>Color fin</th>
                                <th>Atajo</th>
                                <th>Único/día</th>
                                <th>Activo</th>
                                <th title="Indica si este tipo de punch cuenta para pago de nómina">Pagado <i class="fas fa-info-circle text-xs text-muted ml-1"></i></th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($attendanceTypesList)): ?>
                                <tr><td colspan="11" class="data-table-empty">Aún no has configurado tipos de asistencia.</td></tr>
                            <?php else: ?>
                                <?php foreach ($attendanceTypesList as $type): ?>
                                    <?php $typeId = (int) $type['id']; ?>
                                    <tr>
                                        <td>
                                            <input type="number" name="attendance_sort_order[<?= $typeId ?>]" value="<?= (int) $type['sort_order'] ?>" class="input-control">
                                        </td>
                                        <td>
                                            <input type="text" name="attendance_slug[<?= $typeId ?>]" value="<?= htmlspecialchars($type['slug']) ?>" class="input-control">
                                        </td>
                                        <td>
                                            <input type="text" name="attendance_label[<?= $typeId ?>]" value="<?= htmlspecialchars($type['label']) ?>" class="input-control">
                                        </td>
                                        <td>
                                            <input type="text" name="attendance_icon[<?= $typeId ?>]" value="<?= htmlspecialchars($type['icon_class'] ?? 'fas fa-circle') ?>" class="input-control">
                                        </td>
                                        <td>
                                            <input type="color" name="attendance_color_start[<?= $typeId ?>]" value="<?= htmlspecialchars($type['color_start'] ?? '#6366f1') ?>" class="input-control h-11">
                                        </td>
                                        <td>
                                            <input type="color" name="attendance_color_end[<?= $typeId ?>]" value="<?= htmlspecialchars($type['color_end'] ?? '#4338ca') ?>" class="input-control h-11">
                                        </td>
                                        <td>
                                            <input type="text" name="attendance_shortcut[<?= $typeId ?>]" value="<?= htmlspecialchars($type['shortcut_key'] ?? '') ?>" class="input-control text-center" maxlength="2">
                                        </td>
                                        <td class="text-center">
                                            <input type="checkbox" name="attendance_unique[<?= $typeId ?>]" value="1" class="accent-cyan-500" <?= ((int) ($type['is_unique_daily'] ?? 0) === 1) ? 'checked' : '' ?>>
                                        </td>
                                        <td class="text-center">
                                            <input type="checkbox" name="attendance_active[<?= $typeId ?>]" value="1" class="accent-cyan-500" <?= ((int) ($type['is_active'] ?? 0) === 1) ? 'checked' : '' ?>>
                                        </td>
                                        <td class="text-center">
                                            <input type="checkbox" name="attendance_paid[<?= $typeId ?>]" value="1" class="accent-green-500" <?= ((int) ($type['is_paid'] ?? 1) === 1) ? 'checked' : '' ?>>
                                        </td>
                                        <td class="text-center">
                                            <button type="submit" name="action" value="delete_attendance_type" class="btn-danger btn-sm w-full justify-center" formnovalidate onclick="this.form.elements['attendance_type_id'].value='<?= $typeId ?>'; return confirm('¿Eliminar el tipo <?= htmlspecialchars($type['label']) ?>?');">
                                                <i class="fas fa-trash-alt"></i>
                                                Eliminar
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($attendanceTypesList)): ?>
                <div class="flex justify-end">
                    <button type="submit" name="action" value="update_attendance_types" class="btn-primary" onclick="this.form.elements['attendance_type_id'].value='';">
                        <i class="fas fa-save"></i>
                        Actualizar tipos
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </section>

        <section id="schedule-card" class="glass-card space-y-6">
            <div class="panel-heading">
                <div>
                    <h2 class="text-primary text-xl font-semibold">Horario objetivo para analiticas</h2>
                    <p class="text-muted text-sm">Estos valores alimentan los calculos de adherencia, horas productivas y alertas.</p>
                </div>
                <span class="chip"><i class="fas fa-clock"></i> Ultima actualizacion <?= htmlspecialchars(date('d/m/Y', strtotime($scheduleConfig['updated_at'] ?? $scheduleConfig['created_at'] ?? 'now'))) ?></span>
            </div>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="action" value="update_schedule">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">Entrada</label>
                        <input type="time" name="entry_time" value="<?= htmlspecialchars(substr($scheduleConfig['entry_time'], 0, 5)) ?>" class="input-control">
                    </div>
                    <div>
                        <label class="form-label">Salida</label>
                        <input type="time" name="exit_time" value="<?= htmlspecialchars(substr($scheduleConfig['exit_time'], 0, 5)) ?>" class="input-control">
                    </div>
                    <div>
                        <label class="form-label">Inicio almuerzo</label>
                        <input type="time" name="lunch_time" value="<?= htmlspecialchars(substr($scheduleConfig['lunch_time'], 0, 5)) ?>" class="input-control">
                    </div>
                    <div>
                        <label class="form-label">Inicio break</label>
                        <input type="time" name="break_time" value="<?= htmlspecialchars(substr($scheduleConfig['break_time'], 0, 5)) ?>" class="input-control">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="form-label">Minutos de almuerzo</label>
                        <input type="number" min="0" name="lunch_minutes" value="<?= htmlspecialchars((string) $scheduleConfig['lunch_minutes']) ?>" class="input-control">
                    </div>
                    <div>
                        <label class="form-label">Minutos de break</label>
                        <input type="number" min="0" name="break_minutes" value="<?= htmlspecialchars((string) $scheduleConfig['break_minutes']) ?>" class="input-control">
                    </div>
                    <div>
                        <label class="form-label">Minutos de reuniones</label>
                        <input type="number" min="0" name="meeting_minutes" value="<?= htmlspecialchars((string) $scheduleConfig['meeting_minutes']) ?>" class="input-control">
                    </div>
                </div>
                <div>
                    <label class="form-label">Horas programadas al dia</label>
                    <input type="number" min="0" step="0.25" name="scheduled_hours" value="<?= htmlspecialchars((string) $scheduleConfig['scheduled_hours']) ?>" class="input-control">
                </div>
                
                <div class="section-card p-5 space-y-4 bg-gradient-to-br from-cyan-500/5 to-blue-500/5 border border-cyan-500/20">
                    <div class="flex items-center gap-3">
                        <div class="h-10 w-10 rounded-xl bg-cyan-500/15 text-primary flex items-center justify-center">
                            <i class="fas fa-clock text-cyan-400"></i>
                        </div>
                        <div>
                            <h3 class="text-primary text-base font-semibold">Configuracion de Horas Extras</h3>
                            <p class="text-muted text-xs">Las horas extras se calculan automaticamente despues de la hora de salida configurada para cada empleado.</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="inline-flex items-center gap-2 text-sm text-primary font-medium mb-2">
                                <input type="checkbox" name="overtime_enabled" value="1" class="accent-cyan-500" <?= ((int) ($scheduleConfig['overtime_enabled'] ?? 1) === 1) ? 'checked' : '' ?>>
                                Activar calculo de horas extras
                            </label>
                            <p class="text-muted text-xs ml-6">Habilita el sistema de horas extras en todos los reportes.</p>
                        </div>
                        <div>
                            <label class="form-label">
                                Multiplicador de pago
                                <i class="fas fa-info-circle text-xs text-muted ml-1" title="Factor por el cual se multiplica la tarifa por hora para calcular el pago de horas extras. Ejemplo: 1.5 = tiempo y medio, 2.0 = tiempo doble"></i>
                            </label>
                            <input type="number" min="1.0" step="0.01" name="overtime_multiplier" value="<?= htmlspecialchars((string) ($scheduleConfig['overtime_multiplier'] ?? 1.50)) ?>" class="input-control" placeholder="1.50">
                            <p class="text-muted text-xs mt-1">Ej: 1.5 = tiempo y medio, 2.0 = doble</p>
                        </div>
                        <div>
                            <label class="form-label">
                                Minutos de gracia
                                <i class="fas fa-info-circle text-xs text-muted ml-1" title="Minutos despues de la hora de salida antes de comenzar a contar horas extras. Ejemplo: 15 minutos = las horas extras comienzan 15 minutos despues de la hora de salida"></i>
                            </label>
                            <input type="number" min="0" step="1" name="overtime_start_minutes" value="<?= htmlspecialchars((string) ($scheduleConfig['overtime_start_minutes'] ?? 0)) ?>" class="input-control" placeholder="0">
                            <p class="text-muted text-xs mt-1">Minutos despues de la salida para iniciar conteo</p>
                        </div>
                    </div>
                    
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                        <h4 class="text-primary text-sm font-semibold mb-2 flex items-center gap-2">
                            <i class="fas fa-calculator text-blue-500"></i>
                            Como se calculan las horas extras:
                        </h4>
                        <ul class="text-muted text-xs space-y-1 ml-6 list-disc">
                            <li>El sistema detecta la hora de salida (EXIT) de cada empleado</li>
                            <li>Compara con la hora de salida configurada (global o personalizada por empleado)</li>
                            <li>Si trabajo mas alla de la hora de salida + minutos de gracia, se cuentan como horas extras</li>
                            <li>El pago se calcula: (Horas extras × Tarifa por hora × Multiplicador)</li>
                            <li>Cada empleado puede tener su propio multiplicador personalizado desde la seccion de usuarios</li>
                        </ul>
                    </div>
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="btn-secondary">
                        <i class="fas fa-save"></i>
                        Guardar metas
                    </button>
                </div>
            </form>
        </section>

        <section id="manage-users-section" class="glass-card space-y-6">
            <div class="panel-heading">
                <div>
                    <h2 class="text-primary text-xl font-semibold">Gestionar usuarios existentes</h2>
                    <p class="text-muted text-sm">Actualiza roles, tarifas, pagos mensuales y reasigna departamentos.</p>
                </div>
                <span class="chip"><i class="fas fa-users"></i> <?= count($users) ?> usuarios</span>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="update_users">
                <div class="responsive-scroll">
                    <table class="data-table manage-users-table">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th>Usuario</th>
                                <th>Nombre</th>
                                <th>Rol</th>
                                <th>Departamento</th>
                                <th>Tarifa USD</th>
                                <th>Tarifa DOP</th>
                                <th>Mensual USD</th>
                                <th>Mensual DOP</th>
                                <th>Moneda</th>
                                <th title="Hora de salida personalizada para este empleado">
                                    Hora Salida
                                    <i class="fas fa-info-circle text-xs text-muted ml-1" title="Hora de salida personalizada. Si se deja vacio, se usa la hora global del sistema."></i>
                                </th>
                                <th title="Multiplicador personalizado de horas extras">
                                    Mult. HE
                                    <i class="fas fa-info-circle text-xs text-muted ml-1" title="Multiplicador de horas extras personalizado (ej: 1.5, 2.0). Si se deja vacio, se usa el multiplicador global."></i>
                                </th>
                                <th>Nueva contrasena</th>
                                <th>Creado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <?php 
                                    $isActive = isset($user['is_active']) ? (int)$user['is_active'] : 1;
                                    $isCurrentUser = (int)$user['id'] === (int)$_SESSION['user_id'];
                                ?>
                                <tr class="<?= $isActive === 0 ? 'opacity-60' : '' ?>">
                                    <td>
                                        <?php if ($isActive === 1): ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/15 text-emerald-400 border border-emerald-500/20">
                                                <i class="fas fa-check-circle"></i>
                                                Activo
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-red-500/15 text-red-400 border border-red-500/20">
                                                <i class="fas fa-times-circle"></i>
                                                Inactivo
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="font-semibold text-primary"><?= htmlspecialchars($user['username']) ?></div>
                                        <?php if (!empty($user['role_label'])): ?>
                                            <div class="text-muted text-xs"><?= htmlspecialchars($user['role_label']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($isCurrentUser): ?>
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-blue-500/15 text-blue-400 border border-blue-500/20 mt-1">
                                                <i class="fas fa-user"></i>
                                                Tú
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="text-primary"><?= htmlspecialchars($user['full_name']) ?></div>
                                        <div class="text-muted text-xs">ID: <?= (int) $user['id'] ?></div>
                                    </td>
                                    <td>
                                        <input type="text" name="role[<?= (int) $user['id'] ?>]" value="<?= htmlspecialchars($user['role']) ?>" list="role-options" class="input-control">
                                    </td>
                                <td>
                                    <select name="department_id[<?= (int) $user['id'] ?>]" class="select-control">
                                        <option value="">Sin departamento</option>
                                        <?php foreach ($departments as $department): ?>
                                            <option value="<?= (int) $department['id'] ?>" <?php if ((int) ($user['department_id'] ?? 0) === (int) $department['id']) echo 'selected'; ?>><?= htmlspecialchars($department['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" name="hourly_rate_usd[<?= (int) $user['id'] ?>]" value="<?= htmlspecialchars(number_format((float) $user['hourly_rate'], 2, '.', '')) ?>" step="0.01" min="0" class="input-control">
                                </td>
                                <td>
                                    <input type="number" name="hourly_rate_dop[<?= (int) $user['id'] ?>]" value="<?= htmlspecialchars(number_format((float) $user['hourly_rate_dop'], 2, '.', '')) ?>" step="0.01" min="0" class="input-control">
                                </td>
                                <td>
                                    <input type="number" name="monthly_salary_usd[<?= (int) $user['id'] ?>]" value="<?= htmlspecialchars(number_format((float) $user['monthly_salary'], 2, '.', '')) ?>" step="0.01" min="0" class="input-control">
                                </td>
                                <td>
                                    <input type="number" name="monthly_salary_dop[<?= (int) $user['id'] ?>]" value="<?= htmlspecialchars(number_format((float) $user['monthly_salary_dop'], 2, '.', '')) ?>" step="0.01" min="0" class="input-control">
                                </td>
                                <td>
                                    <select name="preferred_currency[<?= (int) $user['id'] ?>]" class="select-control">
                                        <option value="USD" <?= ($user['preferred_currency'] ?? 'USD') === 'USD' ? 'selected' : '' ?>>USD</option>
                                        <option value="DOP" <?= ($user['preferred_currency'] ?? 'USD') === 'DOP' ? 'selected' : '' ?>>DOP</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="time" name="exit_time[<?= (int) $user['id'] ?>]" value="<?= $user['exit_time'] ? htmlspecialchars(substr($user['exit_time'], 0, 5)) : '' ?>" class="input-control" placeholder="HH:MM">
                                    <p class="text-muted text-xs mt-1">Vacio = usar global</p>
                                </td>
                                <td>
                                    <input type="number" name="overtime_multiplier[<?= (int) $user['id'] ?>]" value="<?= $user['overtime_multiplier'] !== null ? htmlspecialchars(number_format((float) $user['overtime_multiplier'], 2, '.', '')) : '' ?>" step="0.01" min="1.0" class="input-control" placeholder="1.50">
                                    <p class="text-muted text-xs mt-1">Vacio = usar global</p>
                                </td>
                                <td>
                                    <input type="text" name="password[<?= (int) $user['id'] ?>]" placeholder="Opcional" class="input-control">
                                    <p class="text-muted text-xs mt-1">Se mantiene si se deja vacio.</p>
                                </td>
                                <td class="text-muted text-xs"><?= htmlspecialchars(date('d/m/Y', strtotime($user['created_at']))) ?></td>
                                <td>
                                    <div class="flex flex-col gap-2">
                                        <?php if (!$isCurrentUser): ?>
                                            <!-- Send Password Reset Email -->
                                            <form method="POST" class="inline" onsubmit="return confirm('¿Enviar email de reseteo de contraseña a este usuario?');">
                                                <input type="hidden" name="action" value="send_password_reset">
                                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-blue-500/15 text-blue-400 border border-blue-500/20 hover:bg-blue-500/25 transition-colors w-full justify-center" title="Enviar email de reseteo de contraseña">
                                                    <i class="fas fa-envelope"></i>
                                                    Reset Password
                                                </button>
                                            </form>
                                            
                                            <div class="flex items-center gap-2">
                                                <!-- Toggle Active/Inactive -->
                                                <form method="POST" class="inline flex-1" onsubmit="return confirm('¿Estás seguro de <?= $isActive === 1 ? 'desactivar' : 'activar' ?> este usuario?');">
                                                    <input type="hidden" name="action" value="toggle_user_status">
                                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                    <input type="hidden" name="new_status" value="<?= $isActive === 1 ? 0 : 1 ?>">
                                                    <?php if ($isActive === 1): ?>
                                                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-orange-500/15 text-orange-400 border border-orange-500/20 hover:bg-orange-500/25 transition-colors w-full justify-center" title="Desactivar usuario">
                                                            <i class="fas fa-ban"></i>
                                                            Desactivar
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500/25 transition-colors w-full justify-center" title="Activar usuario">
                                                            <i class="fas fa-check"></i>
                                                            Activar
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                                
                                                <!-- Delete User -->
                                                <form method="POST" class="inline flex-1" onsubmit="return confirm('¿ADVERTENCIA! ¿Estás seguro de eliminar permanentemente al usuario <?= htmlspecialchars($user['username']) ?>? Esta acción no se puede deshacer.');">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-red-500/15 text-red-400 border border-red-500/20 hover:bg-red-500/25 transition-colors w-full justify-center" title="Eliminar usuario">
                                                        <i class="fas fa-trash-alt"></i>
                                                        Eliminar
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted text-xs italic">Tu cuenta</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="15" class="text-center text-muted py-6">No hay usuarios registrados.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <p class="text-muted text-xs">Actualiza multiples usuarios y luego guarda para aplicar los cambios.</p>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i>
                        Guardar usuarios
                    </button>
                </div>
            </form>
        </section>

        <section id="rate-history-section" class="glass-card space-y-6">
            <div class="panel-heading">
                <div>
                    <h2 class="text-primary text-xl font-semibold">Historial de cambios de tarifas</h2>
                    <p class="text-muted text-sm">Registra aumentos de pago por fecha efectiva. Los registros historicos mantendran su tarifa original.</p>
                </div>
                <span class="chip"><i class="fas fa-history"></i> Gestion de aumentos</span>
            </div>

            <!-- Add Rate Change Form -->
            <div class="section-card p-5 space-y-4 bg-gradient-to-br from-emerald-500/5 to-cyan-500/5 border border-emerald-500/20">
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-xl bg-emerald-500/15 text-primary flex items-center justify-center">
                        <i class="fas fa-dollar-sign text-emerald-400"></i>
                    </div>
                    <div>
                        <h3 class="text-primary text-base font-semibold">Registrar cambio de tarifa</h3>
                        <p class="text-muted text-xs">Define una nueva tarifa con fecha efectiva. Los calculos usaran la tarifa vigente en cada fecha.</p>
                    </div>
                </div>
                
                <form method="POST" class="space-y-4" id="rate-change-form">
                    <input type="hidden" name="action" value="add_rate_change">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="form-label">Usuario</label>
                            <select name="user_id" id="rate-user-select" required class="select-control">
                                <option value="">Selecciona un usuario</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= (int) $user['id'] ?>" 
                                            data-rate-usd="<?= htmlspecialchars(number_format((float) $user['hourly_rate'], 2, '.', '')) ?>"
                                            data-rate-dop="<?= htmlspecialchars(number_format((float) $user['hourly_rate_dop'], 2, '.', '')) ?>">
                                        <?= htmlspecialchars($user['full_name']) ?> (<?= htmlspecialchars($user['username']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">
                                Nueva tarifa USD
                                <span id="current-rate-usd" class="text-xs text-muted ml-1"></span>
                            </label>
                            <input type="number" name="new_rate_usd" id="rate-usd-input" step="0.01" min="0" required class="input-control" placeholder="0.00">
                        </div>
                        <div>
                            <label class="form-label">
                                Nueva tarifa DOP
                                <span id="current-rate-dop" class="text-xs text-muted ml-1"></span>
                            </label>
                            <input type="number" name="new_rate_dop" id="rate-dop-input" step="0.01" min="0" required class="input-control" placeholder="0.00">
                        </div>
                        <div>
                            <label class="form-label">Fecha efectiva</label>
                            <input type="date" name="effective_date" required class="input-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Notas (opcional)</label>
                        <textarea name="rate_notes" class="textarea-control" rows="2" placeholder="Ej: Aumento anual, promocion, ajuste por inflacion..."></textarea>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-plus-circle"></i>
                            Registrar cambio de tarifa
                        </button>
                    </div>
                </form>
            </div>

            <!-- Rate History by User -->
            <div class="space-y-6">
                <?php foreach ($users as $user): ?>
                    <?php 
                        $userId = (int) $user['id'];
                        $rateHistory = getUserRateHistory($pdo, $userId);
                        if (empty($rateHistory)) continue;
                    ?>
                    <div class="section-card p-5 space-y-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-primary text-lg font-semibold"><?= htmlspecialchars($user['full_name']) ?></h3>
                                <p class="text-muted text-sm">
                                    <i class="fas fa-user"></i> <?= htmlspecialchars($user['username']) ?>
                                    <?php if (!empty($user['department_name'])): ?>
                                        | <i class="fas fa-building"></i> <?= htmlspecialchars($user['department_name']) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <div class="text-primary font-semibold">Tarifa actual</div>
                                <div class="text-sm text-muted">
                                    USD: $<?= number_format((float) $user['hourly_rate'], 2) ?> | 
                                    DOP: $<?= number_format((float) $user['hourly_rate_dop'], 2) ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="responsive-scroll">
                            <table class="data-table w-full text-sm">
                                <thead>
                                    <tr>
                                        <th>Fecha efectiva</th>
                                        <th>Tarifa USD</th>
                                        <th>Tarifa DOP</th>
                                        <th>Notas</th>
                                        <th>Registrado por</th>
                                        <th>Fecha registro</th>
                                        <th class="text-center">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rateHistory as $history): ?>
                                        <tr>
                                            <td>
                                                <span class="chip">
                                                    <i class="fas fa-calendar"></i>
                                                    <?= htmlspecialchars(date('d/m/Y', strtotime($history['effective_date']))) ?>
                                                </span>
                                            </td>
                                            <td class="font-semibold text-primary">$<?= number_format((float) $history['hourly_rate_usd'], 2) ?></td>
                                            <td class="font-semibold text-primary">$<?= number_format((float) $history['hourly_rate_dop'], 2) ?></td>
                                            <td class="text-muted text-xs"><?= htmlspecialchars($history['notes'] ?? '-') ?></td>
                                            <td class="text-muted text-xs"><?= htmlspecialchars($history['created_by_username'] ?? 'Sistema') ?></td>
                                            <td class="text-muted text-xs"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($history['created_at']))) ?></td>
                                            <td class="text-center">
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('¿Eliminar esta entrada del historial?');">
                                                    <input type="hidden" name="action" value="delete_rate_history">
                                                    <input type="hidden" name="history_id" value="<?= (int) $history['id'] ?>">
                                                    <button type="submit" class="btn-danger btn-sm">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php 
                    $hasAnyHistory = false;
                    foreach ($users as $user) {
                        if (!empty(getUserRateHistory($pdo, (int) $user['id']))) {
                            $hasAnyHistory = true;
                            break;
                        }
                    }
                    if (!$hasAnyHistory):
                ?>
                    <div class="section-card p-8 text-center">
                        <i class="fas fa-history text-5xl text-muted opacity-30 mb-4"></i>
                        <p class="text-muted">No hay historial de cambios de tarifas registrado.</p>
                        <p class="text-muted text-sm mt-2">Usa el formulario de arriba para registrar el primer cambio.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section id="departments-section" class="glass-card space-y-6">
            <div class="panel-heading">
                <div>
                    <h2 class="text-primary text-xl font-semibold">Departamentos y equipos</h2>
                    <p class="text-muted text-sm">Edita nombres, descripciones o elimina departamentos que ya no utilices.</p>
                </div>
            </div>
            <form method="POST" class="space-y-4">
                <div class="overflow-x-auto">
                    <table class="table-auto w-full text-sm">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Descripcion</th>
                                <th>Colaboradores</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($departments)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-6">Aun no hay departamentos registrados.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($departments as $department): ?>
                                    <?php $deptId = (int) $department['id']; ?>
                                    <tr>
                                        <td>
                                            <input type="text" name="department_name[<?= $deptId ?>]" value="<?= htmlspecialchars($department['name']) ?>" class="input-control">
                                        </td>
                                        <td>
                                            <textarea name="department_description[<?= $deptId ?>]" class="textarea-control" rows="2"><?= htmlspecialchars($department['description'] ?? '') ?></textarea>
                                        </td>
                                        <td>
                                            <span class="chip"><i class="fas fa-user"></i> <?= $departmentUsage[$deptId] ?? 0 ?></span>
                                        </td>
                                        <td class="text-right">
                                            <button type="submit" name="delete_department_id" value="<?= $deptId ?>" class="btn-danger" formnovalidate>
                                                <i class="fas fa-trash-alt"></i>
                                                Eliminar
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end">
                    <button type="submit" name="action" value="update_departments" class="btn-secondary">
                        <i class="fas fa-save"></i>
                        Guardar cambios
                    </button>
                </div>
            </form>
        </section>

        <section id="roles-section" class="glass-card space-y-6">
            <div class="panel-heading">
                <div>
                    <h2 class="text-primary text-xl font-semibold">Roles registrados</h2>
                    <p class="text-muted text-sm">Manten las etiquetas descriptivas para ahorrar tiempo al asignar permisos.</p>
                </div>
                <span class="chip"><i class="fas fa-layer-group"></i> <?= count($rolesList) ?> roles</span>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="update_roles">
                <div class="overflow-x-auto">
                    <table class="table-auto w-full text-sm">
                        <thead>
                            <tr>
                                <th>Identificador</th>
                                <th>Etiqueta</th>
                                <th>Descripcion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rolesList)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-6">Aun no hay roles registrados.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rolesList as $roleRow): ?>
                                    <tr>
                                        <td class="font-semibold text-primary"><?= htmlspecialchars($roleRow['name']) ?></td>
                                        <td>
                                            <input type="text" name="role_label[<?= htmlspecialchars($roleRow['name']) ?>]" value="<?= htmlspecialchars($roleRow['label'] ?? $roleRow['name']) ?>" class="input-control">
                                        </td>
                                        <td>
                                            <textarea name="role_description[<?= htmlspecialchars($roleRow['name']) ?>]" rows="2" class="textarea-control"><?= htmlspecialchars($roleRow['description'] ?? '') ?></textarea>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="btn-secondary">
                        <i class="fas fa-save"></i>
                        Actualizar roles
                    </button>
                </div>
            </form>
        </section>

        <section id="permissions-section" class="glass-card space-y-6">
            <div class="panel-heading">
                <div>
                    <h2 class="text-primary text-xl font-semibold">Permisos por sección</h2>
                    <p class="text-muted text-sm">Gestiona los accesos de cada rol a los diferentes módulos del sistema de forma organizada.</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" onclick="selectAllPermissions()" class="btn-secondary text-sm">
                        <i class="fas fa-check-double"></i> Seleccionar Todo
                    </button>
                    <button type="button" onclick="clearAllPermissions()" class="btn-secondary text-sm">
                        <i class="fas fa-times"></i> Limpiar Todo
                    </button>
                </div>
            </div>

            <!-- Permission Summary -->
            <?php
            $totalSections = count($sections);
            $totalAssignments = 0;
            foreach ($permissionsBySection as $roles) {
                $totalAssignments += count($roles);
            }
            $avgPerSection = $totalSections > 0 ? round($totalAssignments / $totalSections, 1) : 0;
            ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="section-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-blue-500/20 flex items-center justify-center">
                            <i class="fas fa-layer-group text-blue-400"></i>
                        </div>
                        <div>
                            <p class="text-muted text-xs">Total Secciones</p>
                            <p class="text-primary text-2xl font-bold"><?= $totalSections ?></p>
                        </div>
                    </div>
                </div>
                <div class="section-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-green-500/20 flex items-center justify-center">
                            <i class="fas fa-shield-alt text-green-400"></i>
                        </div>
                        <div>
                            <p class="text-muted text-xs">Total Asignaciones</p>
                            <p class="text-primary text-2xl font-bold"><?= $totalAssignments ?></p>
                        </div>
                    </div>
                </div>
                <div class="section-card p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-purple-500/20 flex items-center justify-center">
                            <i class="fas fa-chart-bar text-purple-400"></i>
                        </div>
                        <div>
                            <p class="text-muted text-xs">Promedio por Sección</p>
                            <p class="text-primary text-2xl font-bold"><?= $avgPerSection ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST" class="space-y-6" id="permissions-form">
            <input type="hidden" name="action" value="update_permissions">
            
            <?php
            // Group sections by category
            $sectionsByCategory = [];
            foreach ($sections as $sectionKey => $sectionData) {
                $category = is_array($sectionData) ? $sectionData['category'] : 'General';
                if (!isset($sectionsByCategory[$category])) {
                    $sectionsByCategory[$category] = [];
                }
                $sectionsByCategory[$category][$sectionKey] = $sectionData;
            }
            ?>

            <?php foreach ($sectionsByCategory as $category => $categorySections): ?>
                <div class="space-y-4">
                    <div class="flex items-center gap-3 pb-2 border-b border-slate-700">
                        <i class="fas fa-folder text-cyan-400"></i>
                        <h3 class="text-primary text-lg font-semibold"><?= htmlspecialchars($category) ?></h3>
                        <span class="chip text-xs"><?= count($categorySections) ?> secciones</span>
                    </div>

                    <div class="grid grid-cols-1 gap-4">
                        <?php foreach ($categorySections as $sectionKey => $sectionData): ?>
                            <?php 
                            $assignedRoles = $permissionsBySection[$sectionKey] ?? [];
                            $label = is_array($sectionData) ? $sectionData['label'] : $sectionData;
                            $icon = is_array($sectionData) ? $sectionData['icon'] : 'fa-circle';
                            $description = is_array($sectionData) ? $sectionData['description'] : '';
                            ?>
                            <div class="section-card p-5 space-y-4 hover:border-cyan-500/30 transition-all">
                                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                                    <div class="flex items-start gap-3 flex-1">
                                        <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-cyan-500/20 to-blue-500/20 flex items-center justify-center flex-shrink-0">
                                            <i class="fas <?= htmlspecialchars($icon) ?> text-cyan-400"></i>
                                        </div>
                                        <div class="flex-1">
                                            <h4 class="text-primary text-base font-semibold mb-1"><?= htmlspecialchars($label) ?></h4>
                                            <p class="text-muted text-xs mb-2"><?= htmlspecialchars($description) ?></p>
                                            <p class="text-muted text-xs font-mono bg-slate-900/50 inline-block px-2 py-1 rounded">
                                                <i class="fas fa-code text-slate-500"></i> <?= htmlspecialchars($sectionKey) ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="chip <?= count($assignedRoles) > 0 ? 'bg-green-500/20 text-green-300' : 'bg-slate-700/50' ?>">
                                            <i class="fas fa-users"></i> <?= count($assignedRoles) ?> rol(es)
                                        </span>
                                    </div>
                                </div>

                                <div class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <label class="form-label mb-0">Roles con acceso:</label>
                                        <div class="flex gap-2">
                                            <button type="button" onclick="selectAllInSection('<?= htmlspecialchars($sectionKey) ?>')" class="text-xs text-cyan-400 hover:text-cyan-300">
                                                <i class="fas fa-check-circle"></i> Todos
                                            </button>
                                            <button type="button" onclick="clearAllInSection('<?= htmlspecialchars($sectionKey) ?>')" class="text-xs text-slate-400 hover:text-slate-300">
                                                <i class="fas fa-times-circle"></i> Ninguno
                                            </button>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($roleNames as $roleName): ?>
                                            <?php $isActive = in_array($roleName, $assignedRoles, true); ?>
                                            <label class="pill-option <?= $isActive ? 'is-active' : '' ?>" data-section="<?= htmlspecialchars($sectionKey) ?>">
                                                <input type="checkbox" 
                                                       name="permissions[<?= htmlspecialchars($sectionKey) ?>][]" 
                                                       value="<?= htmlspecialchars($roleName) ?>" 
                                                       <?= $isActive ? 'checked' : '' ?> 
                                                       class="accent-cyan-500"
                                                       onchange="updatePillState(this)">
                                                <span><?= htmlspecialchars($roleLabels[$roleName] ?? $roleName) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="flex justify-between items-center pt-4 border-t border-slate-700">
                <p class="text-muted text-sm">
                    <i class="fas fa-info-circle text-cyan-400"></i>
                    Los cambios se aplicarán inmediatamente y actualizarán los menús de todos los usuarios.
                </p>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i>
                    Guardar todos los permisos
                </button>
            </div>
        </form>
    </section>

    <script>
    function updatePillState(checkbox) {
        const label = checkbox.closest('.pill-option');
        if (checkbox.checked) {
            label.classList.add('is-active');
        } else {
            label.classList.remove('is-active');
        }
    }

    function selectAllInSection(sectionKey) {
        const checkboxes = document.querySelectorAll(`input[name="permissions[${sectionKey}][]"]`);
        checkboxes.forEach(cb => {
            cb.checked = true;
            updatePillState(cb);
        });
    }

    function clearAllInSection(sectionKey) {
        const checkboxes = document.querySelectorAll(`input[name="permissions[${sectionKey}][]"]`);
        checkboxes.forEach(cb => {
            cb.checked = false;
            updatePillState(cb);
        });
    }

    function selectAllPermissions() {
        const checkboxes = document.querySelectorAll('#permissions-form input[type="checkbox"]');
        checkboxes.forEach(cb => {
            cb.checked = true;
            updatePillState(cb);
        });
    }

    function clearAllPermissions() {
        if (confirm('¿Estás seguro de que deseas desmarcar todos los permisos?')) {
            const checkboxes = document.querySelectorAll('#permissions-form input[type="checkbox"]');
            checkboxes.forEach(cb => {
                cb.checked = false;
                updatePillState(cb);
            });
        }
    }
    </script>
</div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabConfig = [
        {
            key: 'users',
            label: 'Usuarios',
            icon: 'fas fa-users-cog',
            selectors: ['#create-user-card', '#manage-users-section']
        },
        {
            key: 'rates',
            label: 'Historial de tarifas',
            icon: 'fas fa-history',
            selectors: ['#rate-history-section']
        },
        {
            key: 'departments',
            label: 'Departamentos',
            icon: 'fas fa-sitemap',
            selectors: ['#create-department-card', '#departments-section']
        },
        {
            key: 'roles',
            label: 'Roles y permisos',
            icon: 'fas fa-user-shield',
            selectors: ['#create-role-card', '#roles-section', '#permissions-section']
        },
        {
            key: 'attendance',
            label: 'Tipos de punch',
            icon: 'fas fa-fingerprint',
            selectors: ['#create-attendance-type-card', '#attendance-types-section']
        },
        {
            key: 'schedule',
            label: 'Horario objetivo',
            icon: 'fas fa-clock',
            selectors: ['#schedule-card']
        }
    ];

    const firstTarget = document.querySelector(tabConfig[0]?.selectors[0] || '');
    if (!firstTarget) {
        return;
    }

    const style = document.createElement('style');
    style.textContent = `
        .settings-tabs-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .settings-tab-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.4rem;
            border-radius: 9999px;
            background: rgba(13, 148, 136, 0.12);
            color: #0f172a;
            font-weight: 600;
            border: 1px solid transparent;
            transition: all 0.2s ease-in-out;
        }
        .settings-tab-button:hover {
            background: rgba(13, 148, 136, 0.2);
            transform: translateY(-1px);
        }
        .settings-tab-button.is-active {
            background: linear-gradient(135deg, #06b6d4, #3b82f6);
            color: #ffffff;
            box-shadow: 0 12px 28px rgba(59, 130, 246, 0.25);
            border-color: rgba(255, 255, 255, 0.18);
        }
        .settings-tab-panel {
            display: none;
        }
        .settings-tab-panel.is-active {
            display: block;
            animation: settingsFade 0.25s ease-in-out;
        }
        @keyframes settingsFade {
            from {
                opacity: 0;
                transform: translateY(14px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    `;
    document.head.appendChild(style);

    const host = document.createElement('div');
    host.className = 'settings-tabs space-y-8';

    const nav = document.createElement('nav');
    nav.className = 'settings-tabs-nav';
    nav.setAttribute('role', 'tablist');
    host.appendChild(nav);

    const panelsWrapper = document.createElement('div');
    panelsWrapper.className = 'settings-tab-panels space-y-12';
    host.appendChild(panelsWrapper);

    const quickGrid = document.getElementById('quick-actions-grid');
    if (quickGrid) {
        quickGrid.parentNode.insertBefore(host, quickGrid);
    } else {
        firstTarget.parentNode.insertBefore(host, firstTarget);
    }

    const panels = new Map();
    const buttons = new Map();

    tabConfig.forEach(function (config) {
        const panel = document.createElement('div');
        panel.className = 'settings-tab-panel space-y-8';
        panel.dataset.tabPanel = config.key;
        panel.id = `settings-panel-${config.key}`;
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-hidden', 'true');
        panel.setAttribute('tabindex', '0');
        panelsWrapper.appendChild(panel);
        panels.set(config.key, panel);

        const button = document.createElement('button');
        button.type = 'button';
        button.id = `settings-tab-${config.key}`;
        button.className = 'settings-tab-button';
        button.dataset.tabTarget = config.key;
        button.setAttribute('role', 'tab');
        button.setAttribute('aria-selected', 'false');
        button.setAttribute('aria-controls', panel.id);
        button.innerHTML = `<i class=\"${config.icon}\"></i><span>${config.label}</span>`;
        nav.appendChild(button);
        buttons.set(config.key, button);

        panel.setAttribute('aria-labelledby', button.id);

        config.selectors.forEach(function (selector) {
            const element = document.querySelector(selector);
            if (element) {
                panel.appendChild(element);
            }
        });
    });

    if (quickGrid && quickGrid.childElementCount === 0) {
        quickGrid.remove();
    }

    function setActiveTab(key) {
        panels.forEach(function (panel, panelKey) {
            const isActive = panelKey === key;
            panel.classList.toggle('is-active', isActive);
            panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        });

        buttons.forEach(function (button, buttonKey) {
            const isActive = buttonKey === key;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            button.setAttribute('tabindex', isActive ? '0' : '-1');
        });

        try {
            localStorage.setItem('settings-active-tab', key);
        } catch (error) {
            /* ignore storage errors */
        }
    }

    let initialTab = tabConfig[0]?.key;
    try {
        const stored = localStorage.getItem('settings-active-tab');
        if (stored && panels.has(stored)) {
            initialTab = stored;
        }
    } catch (error) {
        /* ignore storage errors */
    }

    setActiveTab(initialTab);

    buttons.forEach(function (button, key) {
        button.addEventListener('click', function () {
            setActiveTab(key);
        });
    });

    // Auto-fill rate change form when user is selected
    const rateUserSelect = document.getElementById('rate-user-select');
    const rateUsdInput = document.getElementById('rate-usd-input');
    const rateDopInput = document.getElementById('rate-dop-input');
    const currentRateUsdSpan = document.getElementById('current-rate-usd');
    const currentRateDopSpan = document.getElementById('current-rate-dop');

    if (rateUserSelect && rateUsdInput && rateDopInput) {
        rateUserSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            if (selectedOption.value) {
                const rateUsd = selectedOption.getAttribute('data-rate-usd');
                const rateDop = selectedOption.getAttribute('data-rate-dop');
                
                // Fill the input fields with current rates
                rateUsdInput.value = rateUsd || '0.00';
                rateDopInput.value = rateDop || '0.00';
                
                // Show current rates in labels
                if (currentRateUsdSpan) {
                    currentRateUsdSpan.textContent = '(actual: $' + (rateUsd || '0.00') + ')';
                }
                if (currentRateDopSpan) {
                    currentRateDopSpan.textContent = '(actual: $' + (rateDop || '0.00') + ')';
                }
            } else {
                // Clear fields if no user selected
                rateUsdInput.value = '';
                rateDopInput.value = '';
                if (currentRateUsdSpan) currentRateUsdSpan.textContent = '';
                if (currentRateDopSpan) currentRateDopSpan.textContent = '';
            }
        });
    }

    // Auto-reload page after permission update to reflect changes in real-time
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('permissions_updated')) {
        // Switch to roles tab
        const tabParam = urlParams.get('tab');
        if (tabParam) {
            setActiveTab(tabParam);
        }
        
        // Remove the parameter from URL
        const newUrl = window.location.pathname + window.location.hash;
        window.history.replaceState({}, document.title, newUrl);
        
        // Create and show success message
        const container = document.querySelector('.max-w-7xl.mx-auto.px-6.py-10');
        if (container) {
            const banner = document.createElement('div');
            banner.className = 'status-banner success';
            banner.textContent = 'Permisos actualizados correctamente. Los cambios se reflejan inmediatamente.';
            container.insertBefore(banner, container.firstChild.nextSibling);
            banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Remove banner after 5 seconds
            setTimeout(() => {
                banner.style.transition = 'opacity 0.5s';
                banner.style.opacity = '0';
                setTimeout(() => banner.remove(), 500);
            }, 5000);
        }
    }
});
</script>

<datalist id="role-options">
    <?php foreach ($rolesList as $roleRow): ?>
        <option value="<?= htmlspecialchars($roleRow['name']) ?>" label="<?= htmlspecialchars($roleRow['label'] ?? $roleRow['name']) ?>"></option>
    <?php endforeach; ?>
</datalist>

<?php include 'footer.php'; ?>


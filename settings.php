i<?php
session_start();
include 'db.php';

ensurePermission('settings');

$successMessages = [];
$errorMessages = [];

$sections = [
    'dashboard' => 'Dashboard',
    'records' => 'Records',
    'records_qa' => 'QA Records',
    'view_admin_hours' => 'Administrative Hours',
    'hr_report' => 'HR Report',
    'adherence_report' => 'Adherence Report',
    'operations_dashboard' => 'Operations Dashboard',
    'register_attendance' => 'Register Administrative Hours',
    'login_logs' => 'Login Logs',
    'download_excel' => 'Monthly Excel Export',
    'download_excel_daily' => 'Daily Excel Export',
    'settings' => 'Configuration',
    'agent_dashboard' => 'Agent Dashboard',
    'agent_records' => 'Agent Records'
];

function sanitize_role_name(string $value): string
{
    return preg_replace('/[^A-Za-z0-9_]/', '', $value);
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
                $password = trim($_POST['password'] ?? '');
                $roleInput = trim($_POST['role'] ?? '');
                $role = sanitize_role_name($roleInput);
                $hourlyRateUsdInput = $_POST['hourly_rate_usd'] ?? '0';
                $hourlyRateDopInput = $_POST['hourly_rate_dop'] ?? '0';
                $monthlySalaryUsdInput = $_POST['monthly_salary_usd'] ?? '0';
                $monthlySalaryDopInput = $_POST['monthly_salary_dop'] ?? '0';
                $preferredCurrencyInput = strtoupper(trim($_POST['preferred_currency'] ?? 'USD'));
                $departmentIdRaw = $_POST['department_id'] ?? '';

                if ($username === '' || $fullName === '' || $password === '' || $role === '') {
                    $errorMessages[] = 'Todos los campos son obligatorios para crear un usuario.';
                    break;
                }

                $preferredCurrency = in_array($preferredCurrencyInput, ['USD', 'DOP'], true) ? $preferredCurrencyInput : 'USD';

                $hourlyRateUsd = number_format(max(0, (float) $hourlyRateUsdInput), 2, '.', '');
                $hourlyRateDop = number_format(max(0, (float) $hourlyRateDopInput), 2, '.', '');
                $monthlySalaryUsd = number_format(max(0, (float) $monthlySalaryUsdInput), 2, '.', '');
                $monthlySalaryDop = number_format(max(0, (float) $monthlySalaryDopInput), 2, '.', '');

                if ($preferredCurrency === 'USD' && (float) $hourlyRateUsd <= 0) {
                    $errorMessages[] = 'Debes especificar la tarifa por hora en USD cuando la moneda preferida es USD.';
                    break;
                }

                if ($preferredCurrency === 'DOP' && (float) $hourlyRateDop <= 0) {
                    $errorMessages[] = 'Debes especificar la tarifa por hora en DOP cuando la moneda preferida es DOP.';
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

                $createStmt = $pdo->prepare("
                    INSERT INTO users (username, full_name, password, role, hourly_rate, monthly_salary, hourly_rate_dop, monthly_salary_dop, preferred_currency, department_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $createStmt->execute([
                    $username,
                    $fullName,
                    $password,
                    $role,
                    $hourlyRateUsd,
                    $monthlySalaryUsd,
                    $hourlyRateDop,
                    $monthlySalaryDop,
                    $preferredCurrency,
                    $departmentId
                ]);

                $successMessages[] = "Usuario '{$username}' creado correctamente.";
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

                $pdo->beginTransaction();
                $updateWithRoleStmt = $pdo->prepare("UPDATE users SET hourly_rate = ?, monthly_salary = ?, hourly_rate_dop = ?, monthly_salary_dop = ?, preferred_currency = ?, department_id = ?, role = ? WHERE id = ?");
                $updateWithoutRoleStmt = $pdo->prepare("UPDATE users SET hourly_rate = ?, monthly_salary = ?, hourly_rate_dop = ?, monthly_salary_dop = ?, preferred_currency = ?, department_id = ? WHERE id = ?");
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

                    if ($newRole !== '') {
                        ensureRoleExists($pdo, $newRole, $newRole);
                        $updateWithRoleStmt->execute([$rateUsd, $monthlyUsd, $rateDop, $monthlyDop, $preferredCurrency, $departmentId, $newRole, $userId]);
                    } else {
                        $updateWithoutRoleStmt->execute([$rateUsd, $monthlyUsd, $rateDop, $monthlyDop, $preferredCurrency, $departmentId, $userId]);
                    }

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

                $scheduleStmt = $pdo->prepare("UPDATE schedule_config SET entry_time = ?, exit_time = ?, lunch_time = ?, break_time = ?, lunch_minutes = ?, break_minutes = ?, meeting_minutes = ?, scheduled_hours = ?, updated_at = NOW() WHERE id = 1");
                $scheduleStmt->execute([
                    $entryTime,
                    $exitTime,
                    $lunchTime,
                    $breakTime,
                    $lunchMinutes,
                    $breakMinutes,
                    $meetingMinutes,
                    $scheduledHours
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
                $successMessages[] = 'Permisos actualizados correctamente.';
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
        u.created_at,
        r.label AS role_label,
        d.name AS department_name
    FROM users u
    LEFT JOIN roles r ON r.name = u.role
    LEFT JOIN departments d ON d.id = u.department_id
    ORDER BY u.username
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
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <label class="form-label">Tarifa por hora (USD)</label>
                                    <span class="chip">USD</span>
                                </div>
                                <input type="number" step="0.01" min="0" name="hourly_rate_usd" class="input-control" value="0">
                            </div>
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <label class="form-label">Tarifa por hora (DOP)</label>
                                    <span class="chip">DOP</span>
                                </div>
                                <input type="number" step="0.01" min="0" name="hourly_rate_dop" class="input-control" value="0">
                            </div>
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <label class="form-label">Pago mensual (USD)</label>
                                    <span class="chip">USD</span>
                                </div>
                                <input type="number" step="0.01" min="0" name="monthly_salary_usd" class="input-control" value="0">
                            </div>
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <label class="form-label">Pago mensual (DOP)</label>
                                    <span class="chip">DOP</span>
                                </div>
                                <input type="number" step="0.01" min="0" name="monthly_salary_dop" class="input-control" value="0">
                            </div>
                        </div>
                        <div>
                            <label class="form-label">Moneda preferida</label>
                            <select name="preferred_currency" class="select-control">
                                <option value="USD">USD - Dolares estadounidenses</option>
                                <option value="DOP">DOP - Peso dominicano</option>
                            </select>
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
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <p class="text-muted text-xs">Aplica a todos los reportes de adherencia, HR y paneles operativos.</p>
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
                <div class="overflow-x-auto">
                    <table class="table-auto w-full text-sm">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Nombre</th>
                                <th>Rol</th>
                                <th>Departamento</th>
                                <th>Tarifa USD</th>
                                <th>Tarifa DOP</th>
                                <th>Mensual USD</th>
                                <th>Mensual DOP</th>
                                <th>Moneda</th>
                                <th>Nueva contrasena</th>
                                <th>Creado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="font-semibold text-primary"><?= htmlspecialchars($user['username']) ?></div>
                                        <?php if (!empty($user['role_label'])): ?>
                                            <div class="text-muted text-xs"><?= htmlspecialchars($user['role_label']) ?></div>
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
                                    <input type="text" name="password[<?= (int) $user['id'] ?>]" placeholder="Opcional" class="input-control">
                                    <p class="text-muted text-xs mt-1">Se mantiene si se deja vacio.</p>
                                </td>
                                <td class="text-muted text-xs"><?= htmlspecialchars(date('d/m/Y', strtotime($user['created_at']))) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-6">No hay usuarios registrados.</td>
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
                    <h2 class="text-primary text-xl font-semibold">Permisos por seccion</h2>
                    <p class="text-muted text-sm">Activa o desactiva accesos para cada modulo de la plataforma.</p>
                </div>
            </div>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="action" value="update_permissions">
                <?php foreach ($sections as $sectionKey => $label): ?>
                    <?php $assignedRoles = $permissionsBySection[$sectionKey] ?? []; ?>
                    <div class="section-card p-5 space-y-4">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                            <div>
                                <h3 class="text-primary text-lg font-semibold"><?= htmlspecialchars($label) ?></h3>
                                <p class="text-muted text-xs uppercase tracking-wide">Slug: <?= htmlspecialchars($sectionKey) ?></p>
                            </div>
                            <span class="chip"><i class="fas fa-shield-alt"></i> <?= count($assignedRoles) ?> roles</span>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <?php foreach ($roleNames as $roleName): ?>
                                <?php $isActive = in_array($roleName, $assignedRoles, true); ?>
                                <label class="pill-option <?= $isActive ? 'is-active' : '' ?>">
                                    <input type="checkbox" name="permissions[<?= htmlspecialchars($sectionKey) ?>][]" value="<?= htmlspecialchars($roleName) ?>" <?= $isActive ? 'checked' : '' ?> class="accent-cyan-500">
                                    <span><?= htmlspecialchars($roleLabels[$roleName] ?? $roleName) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div>
                            <label class="form-label">Roles adicionales (separados por coma)</label>
                            <input type="text" name="extra_permissions[<?= htmlspecialchars($sectionKey) ?>]" class="input-control" placeholder="Ej. SupportLead, Auditor">
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="flex justify-end">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i>
                        Guardar permisos
                    </button>
                </div>
            </form>
        </section>
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
});
</script>

<datalist id="role-options">
    <?php foreach ($rolesList as $roleRow): ?>
        <option value="<?= htmlspecialchars($roleRow['name']) ?>" label="<?= htmlspecialchars($roleRow['label'] ?? $roleRow['name']) ?>"></option>
    <?php endforeach; ?>
</datalist>

<?php include 'footer.php'; ?>


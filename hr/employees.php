<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_employees');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle employee update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_employee'])) {
    $employeeId = (int)$_POST['employee_id'];
    
    $pdo->beginTransaction();
    
    try {
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
        
        // Sync to users table
        $fullName = $data['first_name'] . ' ' . $data['last_name'];
        $hourlyRate = !empty($_POST['hourly_rate']) ? (float)$_POST['hourly_rate'] : 0.00;
        
        $userStmt = $pdo->prepare("
            UPDATE users SET full_name = ?, hourly_rate = ?, department_id = ?
            WHERE id = (SELECT user_id FROM employees WHERE id = ?)
        ");
        $userStmt->execute([$fullName, $hourlyRate, $data['department_id'], $employeeId]);
        
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
    SELECT e.*, u.username, u.hourly_rate, u.role, d.name as department_name,
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
                <a href="../register.php" class="btn-primary">
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

                <div class="form-group mb-4">
                    <label for="edit_hourly_rate">Tarifa por hora (USD)</label>
                    <input type="number" id="edit_hourly_rate" name="hourly_rate" step="0.01" min="0">
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
            document.getElementById('edit_hourly_rate').value = employee.hourly_rate || '';
            
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
            
            document.getElementById('editModal').classList.remove('hidden');
        }
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>

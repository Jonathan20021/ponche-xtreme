<?php
include 'db.php';

$success = null;
$error = null;

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
    $password = 'defaultpassword';
    $role = 'AGENT';

    if ($username === '' || $full_name === '' || $first_name === '' || $last_name === '' || $hire_date === '') {
        $error = 'Los campos Usuario, Nombre completo, Nombre, Apellido y Fecha de ingreso son obligatorios.';
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
                
                // Insert user with employee code
                $insert = $pdo->prepare("INSERT INTO users (username, employee_code, full_name, password, role, hourly_rate, department_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $insert->execute([$username, $employeeCode, $full_name, $password, $role, $hourly_rate, $department_id]);
                $userId = $pdo->lastInsertId();
                
                // Insert employee record
                $employeeInsert = $pdo->prepare("
                    INSERT INTO employees (
                        user_id, employee_code, first_name, last_name, email, phone, 
                        birth_date, hire_date, position, department_id, employment_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'TRIAL')
                ");
                $employeeInsert->execute([
                    $userId, $employeeCode, $first_name, $last_name, $email, $phone,
                    $birth_date ?: null, $hire_date, $position, $department_id
                ]);
                
                $pdo->commit();
                $success = "Usuario {$username} creado correctamente con código de empleado {$employeeCode}. El empleado está en período de prueba.";
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
    <link href="assets/css/theme.css" rel="stylesheet">
    <title>Registro de agentes</title>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <div class="login-wrapper">
        <div class="login-card glass-card" style="width: min(900px, 95%); max-height: 90vh; overflow-y: auto;">
            <div class="text-center mb-5">
                <span class="tag-pill">Alta de nuevo empleado</span>
                <h2 class="mt-3 font-semibold text-white">Registro completo de empleado</h2>
                <p class="text-sm text-slate-400">Se asignara una contrasena por defecto. El empleado podra actualizarla luego.</p>
            </div>
            <?php if ($error): ?>
                <div class="status-banner error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="status-banner success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <form method="POST" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label for="username">Usuario *</label>
                        <input type="text" id="username" name="username" required placeholder="ej. agente02">
                    </div>
                    <div class="form-group">
                        <label for="full_name">Nombre completo *</label>
                        <input type="text" id="full_name" name="full_name" required placeholder="Nombre y apellido">
                    </div>
                </div>
                
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
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="correo@ejemplo.com">
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
                
                <div class="form-group">
                    <label for="hourly_rate">Tarifa por hora (USD)</label>
                    <input type="number" id="hourly_rate" name="hourly_rate" step="0.01" min="0" placeholder="0.00">
                </div>
                
                <button type="submit" name="register" class="w-full btn-primary justify-center">
                    <i class="fas fa-user-plus"></i>
                    Registrar empleado
                </button>
            </form>
            <div class="text-center mt-4 text-sm">
                <a href="punch.php" class="text-slate-300 hover:text-white transition-colors">Ir al portal de marcaciones</a>
            </div>
        </div>
        <form action="theme_toggle.php" method="post" class="mt-6">
            <button type="submit" class="btn-secondary px-4 py-2 rounded-lg inline-flex items-center gap-2">
                <i class="fas fa-adjust text-sm"></i>
                <span><?= htmlspecialchars($themeLabel) ?></span>
            </button>
        </form>
    </div>
</body>
</html>

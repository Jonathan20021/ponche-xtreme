<?php
session_start();
require_once 'db.php';

$success = null;
$error = null;
$step = 'verify'; // verify, reset

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify_identity'])) {
        // Step 1: Verify identity using username and ID card
        $username = trim($_POST['username'] ?? '');
        $idCard = trim($_POST['id_card_number'] ?? '');
        
        if ($username === '' || $idCard === '') {
            $error = 'Por favor completa todos los campos.';
        } else {
            // Verify user exists and ID card matches (for admin users)
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.full_name, u.role, e.id_card_number 
                FROM users u
                INNER JOIN employees e ON e.user_id = u.id
                WHERE u.username = ? AND e.id_card_number = ?
                AND u.role IN ('Admin', 'OperationsManager', 'IT', 'HR', 'GeneralManager', 'Supervisor')
            ");
            $stmt->execute([$username, $idCard]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Identity verified, allow password reset
                $_SESSION['reset_user_id'] = $user['id'];
                $_SESSION['reset_username'] = $user['username'];
                $_SESSION['reset_role'] = $user['role'];
                $step = 'reset';
            } else {
                $error = 'Usuario o número de cédula incorrectos.';
            }
        }
    } elseif (isset($_POST['reset_password'])) {
        // Step 2: Reset password
        if (!isset($_SESSION['reset_user_id'])) {
            $error = 'Sesión expirada. Por favor verifica tu identidad nuevamente.';
            $step = 'verify';
        } else {
            $newPassword = trim($_POST['new_password'] ?? '');
            $confirmPassword = trim($_POST['confirm_password'] ?? '');
            
            if ($newPassword === '' || $confirmPassword === '') {
                $error = 'Por favor completa todos los campos.';
                $step = 'reset';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Las contraseñas no coinciden.';
                $step = 'reset';
            } elseif (strlen($newPassword) < 6) {
                $error = 'La contraseña debe tener al menos 6 caracteres.';
                $step = 'reset';
            } else {
                // Update password
                $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                if ($updateStmt->execute([$newPassword, $_SESSION['reset_user_id']])) {
                    $success = 'Contraseña actualizada correctamente. Ahora puedes iniciar sesión con tu nueva contraseña.';
                    unset($_SESSION['reset_user_id']);
                    unset($_SESSION['reset_username']);
                    unset($_SESSION['reset_role']);
                    $step = 'verify';
                } else {
                    $error = 'Error al actualizar la contraseña. Intenta nuevamente.';
                    $step = 'reset';
                }
            }
        }
    }
}

$theme = $_SESSION['theme'] ?? 'dark';
if (!in_array($theme, ['dark', 'light'], true)) {
    $theme = 'dark';
}
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/theme.css" rel="stylesheet">
    <title>Recuperar Contraseña - Administración</title>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <div class="login-wrapper">
        <div class="login-card glass-card" style="width: min(450px, 100%);">
            <div class="text-center mb-5">
                <span class="tag-pill">
                    <i class="fas fa-key"></i>
                    Recuperar Contraseña
                </span>
                <h2 class="mt-3 font-semibold text-white">
                    <?= $step === 'verify' ? 'Verifica tu Identidad' : 'Nueva Contraseña' ?>
                </h2>
                <p class="text-sm text-slate-400">
                    <?= $step === 'verify' 
                        ? 'Ingresa tu usuario y número de cédula para verificar tu identidad.' 
                        : 'Ingresa tu nueva contraseña.' ?>
                </p>
            </div>
            
            <?php if ($error): ?>
                <div class="status-banner error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="status-banner success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
                <div class="mt-4 text-center">
                    <a href="index.php" class="btn-primary inline-flex items-center gap-2">
                        <i class="fas fa-sign-in-alt"></i>
                        Ir a Iniciar Sesión
                    </a>
                </div>
            <?php elseif ($step === 'verify'): ?>
                <!-- Step 1: Verify Identity -->
                <form method="POST" class="space-y-4">
                    <div class="form-group">
                        <label for="username">
                            <i class="fas fa-user mr-1"></i>
                            Usuario
                        </label>
                        <input 
                            type="text" 
                            name="username" 
                            id="username" 
                            required 
                            placeholder="ej. admin"
                            autocomplete="username">
                    </div>
                    
                    <div class="form-group">
                        <label for="id_card_number">
                            <i class="fas fa-id-card mr-1"></i>
                            Número de Cédula
                        </label>
                        <input 
                            type="text" 
                            name="id_card_number" 
                            id="id_card_number" 
                            required 
                            placeholder="000-0000000-0"
                            pattern="\d{3}-?\d{7}-?\d{1}"
                            title="Formato: 000-0000000-0">
                        <p class="text-xs text-slate-400 mt-1">
                            <i class="fas fa-info-circle"></i>
                            Ingresa tu cédula en formato: 000-0000000-0
                        </p>
                    </div>
                    
                    <button type="submit" name="verify_identity" class="w-full btn-primary justify-center">
                        <i class="fas fa-check-circle"></i>
                        Verificar Identidad
                    </button>
                    
                    <div class="text-center mt-3">
                        <a href="index.php" class="text-sm text-slate-400 hover:text-blue-400">
                            <i class="fas fa-arrow-left"></i>
                            Volver al inicio de sesión
                        </a>
                    </div>
                </form>
            <?php else: ?>
                <!-- Step 2: Reset Password -->
                <div class="bg-blue-500/10 border border-blue-500/30 rounded-lg p-3 mb-4">
                    <p class="text-sm text-blue-300">
                        <i class="fas fa-user-check mr-2"></i>
                        Identidad verificada: <strong><?= htmlspecialchars($_SESSION['reset_username'] ?? '') ?></strong>
                        <span class="ml-2 text-xs bg-blue-500/20 px-2 py-1 rounded"><?= htmlspecialchars($_SESSION['reset_role'] ?? '') ?></span>
                    </p>
                </div>
                
                <form method="POST" class="space-y-4">
                    <div class="form-group">
                        <label for="new_password">
                            <i class="fas fa-lock mr-1"></i>
                            Nueva Contraseña
                        </label>
                        <input 
                            type="password" 
                            name="new_password" 
                            id="new_password" 
                            required 
                            minlength="6"
                            placeholder="Mínimo 6 caracteres"
                            autocomplete="new-password">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">
                            <i class="fas fa-lock mr-1"></i>
                            Confirmar Contraseña
                        </label>
                        <input 
                            type="password" 
                            name="confirm_password" 
                            id="confirm_password" 
                            required 
                            minlength="6"
                            placeholder="Repite tu contraseña"
                            autocomplete="new-password">
                    </div>
                    
                    <div class="bg-slate-700/30 rounded-lg p-3">
                        <p class="text-xs text-slate-300">
                            <i class="fas fa-shield-alt text-blue-400 mr-2"></i>
                            <strong>Requisitos de contraseña:</strong>
                        </p>
                        <ul class="text-xs text-slate-400 mt-2 ml-6 space-y-1">
                            <li>• Mínimo 6 caracteres</li>
                            <li>• Evita usar información personal obvia</li>
                            <li>• Combina letras y números para mayor seguridad</li>
                        </ul>
                    </div>
                    
                    <button type="submit" name="reset_password" class="w-full btn-primary justify-center">
                        <i class="fas fa-save"></i>
                        Cambiar Contraseña
                    </button>
                    
                    <div class="text-center mt-3">
                        <a href="index.php" class="text-sm text-slate-400 hover:text-blue-400">
                            <i class="fas fa-times"></i>
                            Cancelar
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

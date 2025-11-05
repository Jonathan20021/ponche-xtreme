<?php
session_start();
include 'db.php'; // Archivo de conexión a la base de datos

// Validar token
if (!isset($_GET['token'])) {
    die('Solicitud inválida.');
}

$token = $_GET['token'];
$error = '';
$success = '';
$user = null;

// Verificar el token en la base de datos (tabla password_reset_tokens)
try {
    // Primero verificar si la tabla existe
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'password_reset_tokens'");
    
    if ($tableCheck && $tableCheck->rowCount() > 0) {
        // Buscar token en la tabla password_reset_tokens
        $query = "SELECT prt.*, u.id as user_id, u.username, u.full_name 
                  FROM password_reset_tokens prt
                  INNER JOIN users u ON u.id = prt.user_id
                  WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.used_at IS NULL";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Si hay error con la tabla, continuar sin ella
}

if (!$user) {
    $error = 'Token inválido o expirado. El enlace solo es válido por 1 hora.';
}

// Procesar formulario de cambio de contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $new_password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);

    if ($new_password && $confirm_password) {
        if (strlen($new_password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } elseif ($new_password === $confirm_password) {
            try {
                // Actualizar la contraseña
                $updateQuery = "UPDATE users SET password = ? WHERE id = ?";
                $updateStmt = $pdo->prepare($updateQuery);
                $updateStmt->execute([$new_password, $user['user_id']]);

                // Marcar el token como usado si la tabla existe
                try {
                    $markUsed = $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE token = ?");
                    $markUsed->execute([$token]);
                } catch (Exception $e) {
                    // Tabla no existe, continuar
                }

                $success = 'Tu contraseña ha sido actualizada exitosamente.';
                $user = null; // Para ocultar el formulario
            } catch (Exception $e) {
                $error = 'Error al actualizar la contraseña. Intenta nuevamente.';
            }
        } else {
            $error = 'Las contraseñas no coinciden.';
        }
    } else {
        $error = 'Por favor completa todos los campos.';
    }
}
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
    <title>Restablecer Contraseña - Evallish BPO Control</title>
</head>
<body class="theme-dark">
    <div class="login-wrapper">
        <div class="login-card glass-card" style="width: min(450px, 100%);">
            <div class="text-center mb-5">
                <span class="tag-pill">
                    <i class="fas fa-key"></i>
                    Restablecer Contraseña
                </span>
                <h2 class="mt-3 font-semibold text-white">
                    Nueva Contraseña
                </h2>
                <?php if ($user): ?>
                    <p class="text-sm text-slate-400 mt-2">
                        <i class="fas fa-user-check"></i>
                        Usuario: <strong><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></strong>
                    </p>
                <?php endif; ?>
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
                <div class="mt-4 text-center space-y-3">
                    <a href="index.php" class="btn-primary inline-flex items-center gap-2">
                        <i class="fas fa-sign-in-alt"></i>
                        Ir a Iniciar Sesión
                    </a>
                </div>
            <?php elseif ($user): ?>
                <form method="POST" class="space-y-4">
                    <div class="form-group">
                        <label for="password">
                            <i class="fas fa-lock mr-1"></i>
                            Nueva Contraseña
                        </label>
                        <input 
                            type="password" 
                            name="password" 
                            id="password" 
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
                    
                    <button type="submit" class="w-full btn-primary justify-center">
                        <i class="fas fa-save"></i>
                        Cambiar Contraseña
                    </button>
                </form>
            <?php else: ?>
                <div class="text-center space-y-3">
                    <a href="password_recovery.php" class="btn-secondary inline-flex items-center gap-2">
                        <i class="fas fa-redo"></i>
                        Solicitar nuevo enlace
                    </a>
                    <p class="text-xs text-slate-400">
                        <i class="fas fa-info-circle"></i>
                        Si necesitas ayuda, contacta al administrador
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>


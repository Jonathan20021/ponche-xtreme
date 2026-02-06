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
        
        // Remove any non-numeric characters from ID card (allow with or without dashes)
        $idCard = preg_replace('/[^0-9]/', '', $idCard);
        
        if ($username === '' || $idCard === '') {
            $error = 'Por favor completa todos los campos.';
        } else {
            // Step 1: Verify user exists
            $userStmt = $pdo->prepare("
                SELECT id, username, full_name, role
                FROM users
                WHERE username = ?
            ");
            $userStmt->execute([$username]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $error = 'Usuario no encontrado.';
            } else {
                // Step 2: Check if employee record exists and verify ID card
                $empStmt = $pdo->prepare("SELECT identification_number FROM employees WHERE user_id = ?");
                $empStmt->execute([$user['id']]);
                $empData = $empStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$empData) {
                    // No employee record - allow password reset anyway
                    $_SESSION['reset_user_id'] = $user['id'];
                    $_SESSION['reset_username'] = $user['username'];
                    $step = 'reset';
                } elseif (!$empData['identification_number']) {
                    // Employee exists but no ID card registered - allow password reset
                    $_SESSION['reset_user_id'] = $user['id'];
                    $_SESSION['reset_username'] = $user['username'];
                    $step = 'reset';
                } else {
                    // Normalize stored ID card (remove dashes)
                    $storedIdCard = preg_replace('/[^0-9]/', '', $empData['identification_number']);
                    
                    if ($storedIdCard === $idCard) {
                        // ID card matches - allow password reset
                        $_SESSION['reset_user_id'] = $user['id'];
                        $_SESSION['reset_username'] = $user['username'];
                        $step = 'reset';
                    } else {
                        // ID card doesn't match
                        $error = 'Número de cédula incorrecto.';
                    }
                }
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
$themeLabel = $theme === 'light' ? 'Modo Oscuro' : 'Modo Claro';
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
    <link href="assets/css/theme.css?v=<?= time() ?>" rel="stylesheet">
    <title>Recuperar Contraseña - Agentes</title>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <div class="split-login-container">
        <div class="split-login-wrapper">
            <!-- Left Panel - Brand -->
            <div class="split-panel brand-panel">
                <div class="brand-content">
                    <img src="assets/logo.png" alt="Evallish BPO" class="panel-logo">
                    <h1 class="panel-title">Recuperación<br>de Contraseña</h1>
                    <p class="panel-description">Sigue los pasos para restablecer tu contraseña de forma segura.</p>
                    
                    <div class="features-list">
                        <div class="feature-item">
                            <i class="fas fa-shield-alt"></i>
                            <span>Verificación de identidad segura</span>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-lock"></i>
                            <span>Proceso protegido y confidencial</span>
                        </div>
                        <div class="feature-item">
                            <i class="fas fa-check-circle"></i>
                            <span>Rápido y fácil de completar</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Panel - Recovery Form -->
            <div class="split-panel form-panel">
                <div class="form-content">
                    <div class="form-icon">
                        <i class="fas fa-key"></i>
                    </div>
                    
                    <div class="form-header">
                        <span class="form-welcome"><?= $step === 'verify' ? 'Paso 1 de 2' : 'Paso 2 de 2' ?></span>
                        <h2 class="form-title"><?= $step === 'verify' ? 'Verifica tu identidad' : 'Nueva contraseña' ?></h2>
                        <p class="form-subtitle">
                            <?= $step === 'verify' 
                                ? 'Ingresa tu usuario y número de cédula' 
                                : 'Crea tu nueva contraseña segura' ?>
                        </p>
                    </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert" style="margin-bottom: 1.5rem;">
                    <i class="fas fa-exclamation-circle" style="margin-right: 0.5rem;"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success" role="alert" style="margin-bottom: 1.5rem;">
                    <i class="fas fa-check-circle" style="margin-right: 0.5rem;"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
                <div style="text-align: center; margin-top: 1.5rem;">
                    <a href="login_agent.php" class="split-submit-btn" style="display: inline-flex; text-decoration: none; width: auto; padding: 0.875rem 2rem;">
                        <i class="fas fa-sign-in-alt"></i>
                        Ir a Iniciar Sesión
                    </a>
                </div>
            <?php elseif ($step === 'verify'): ?>
                <!-- Step 1: Verify Identity -->
                <form method="POST" action="">
                    <div class="form-field-split">
                        <label for="username">
                            <i class="fas fa-user"></i>
                            Usuario
                        </label>
                        <input 
                            type="text" 
                            name="username" 
                            id="username" 
                            required 
                            placeholder="Ingresa tu usuario"
                            autocomplete="username">
                    </div>
                    
                    <div class="form-field-split">
                        <label for="id_card_number">
                            <i class="fas fa-id-card"></i>
                            Número de Cédula
                        </label>
                        <input 
                            type="text" 
                            name="id_card_number" 
                            id="id_card_number" 
                            required 
                            placeholder="Ej: 12345678901"
                            pattern="\d{11}"
                            title="Ingresa 11 dígitos">
                        <small style="display: block; margin-top: 0.5rem; font-size: 0.75rem; color: #94a3b8;">
                            <i class="fas fa-info-circle"></i>
                            Ingresa los 11 dígitos de tu cédula (sin guiones)
                        </small>
                    </div>
                    
                    <button type="submit" name="verify_identity" class="split-submit-btn">
                        <i class="fas fa-check-circle"></i>
                        Verificar Identidad
                    </button>
                    
                    <div style="text-align: center; margin-top: 1.5rem;">
                        <a href="login_agent.php" class="split-forgot-link">
                            <i class="fas fa-arrow-left"></i>
                            Volver al inicio de sesión
                        </a>
                    </div>
                </form>
            <?php else: ?>
                <!-- Step 2: Reset Password -->
                <div style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 12px; padding: 1rem; margin-bottom: 1.5rem;">
                    <p style="font-size: 0.875rem; color: #93c5fd; margin: 0;">
                        <i class="fas fa-user-check" style="margin-right: 0.5rem;"></i>
                        Identidad verificada: <strong><?= htmlspecialchars($_SESSION['reset_username'] ?? '') ?></strong>
                    </p>
                </div>
                
                <form method="POST" action="">
                    <div class="form-field-split">
                        <label for="new_password">
                            <i class="fas fa-lock"></i>
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
                    
                    <div class="form-field-split">
                        <label for="confirm_password">
                            <i class="fas fa-lock"></i>
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
                    
                    <div style="background: rgba(51, 65, 85, 0.3); border-radius: 12px; padding: 1rem; margin-bottom: 1.5rem;">
                        <p style="font-size: 0.75rem; color: #cbd5e1; margin: 0 0 0.5rem 0;">
                            <i class="fas fa-shield-alt" style="color: #60a5fa; margin-right: 0.5rem;"></i>
                            <strong>Requisitos de contraseña:</strong>
                        </p>
                        <ul style="font-size: 0.75rem; color: #94a3b8; margin: 0; padding-left: 1.5rem; line-height: 1.6;">
                            <li>Mínimo 6 caracteres</li>
                            <li>Evita usar información personal obvia</li>
                            <li>Combina letras y números para mayor seguridad</li>
                        </ul>
                    </div>
                    
                    <button type="submit" name="reset_password" class="split-submit-btn">
                        <i class="fas fa-save"></i>
                        Cambiar Contraseña
                    </button>
                    
                    <div style="text-align: center; margin-top: 1.5rem;">
                        <a href="login_agent.php" class="split-forgot-link">
                            <i class="fas fa-times"></i>
                            Cancelar
                        </a>
                    </div>
                </form>
            <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Theme Toggle Button -->
    <button id="theme-toggle" class="theme-toggle-btn" aria-label="<?= htmlspecialchars($themeLabel) ?>">
        <i class="fas fa-<?= $theme === 'light' ? 'moon' : 'sun' ?>"></i>
    </button>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const themeToggle = document.getElementById('theme-toggle');
        
        if (themeToggle) {
            themeToggle.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Enviar solicitud al servidor para cambiar el tema
                fetch('theme_toggle.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'ajax=1'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Recargar la página para aplicar el nuevo tema
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    // Recargar de todos modos si hay error
                    location.reload();
                });
            });
        }
    });
    </script>
</body>
</html>

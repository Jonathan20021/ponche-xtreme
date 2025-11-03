<?php
/**
 * Script de Prueba para el Sistema de Notificaciones por Email
 * 
 * Este script permite probar la configuración de email antes de usarla en producción.
 * Ejecuta este archivo desde el navegador o línea de comandos.
 */

require_once 'lib/email_functions.php';

// Configuración
$testMode = isset($_GET['mode']) ? $_GET['mode'] : 'form';
$testEmail = isset($_POST['test_email']) ? trim($_POST['test_email']) : '';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prueba de Sistema de Email</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            padding: 30px;
            max-width: 800px;
            margin: 0 auto;
        }
        .result-box {
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }
        .error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }
        .info {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            color: #1e40af;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        .btn-secondary:hover {
            background: #e5e7eb;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1 class="text-3xl font-bold text-gray-800 mb-2">
            <i class="fas fa-envelope text-purple-600"></i>
            Prueba de Sistema de Email
        </h1>
        <p class="text-gray-600 mb-6">Verifica que la configuración de email esté funcionando correctamente</p>
        
        <?php if ($testMode === 'form'): ?>
            
            <!-- Formulario de Prueba -->
            <div class="info result-box">
                <i class="fas fa-info-circle"></i>
                <strong>Antes de comenzar:</strong> Asegúrate de haber configurado correctamente el archivo 
                <code>config/email_config.php</code> con tus credenciales de cPanel.
            </div>
            
            <form method="POST" action="?mode=test" class="space-y-6">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        <i class="fas fa-at text-purple-600"></i> Email de Prueba
                    </label>
                    <input 
                        type="email" 
                        name="test_email" 
                        required 
                        placeholder="tu_email@ejemplo.com"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                    >
                    <p class="text-sm text-gray-500 mt-1">
                        Se enviará un correo de prueba a esta dirección
                    </p>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" name="action" value="test_config" class="btn btn-primary flex-1">
                        <i class="fas fa-paper-plane"></i>
                        Probar Configuración
                    </button>
                    <button type="submit" name="action" value="test_welcome" class="btn btn-secondary flex-1">
                        <i class="fas fa-user-plus"></i>
                        Probar Email de Bienvenida
                    </button>
                </div>
            </form>
            
            <!-- Información de Configuración -->
            <div class="mt-8 p-4 bg-gray-50 rounded-lg">
                <h3 class="font-semibold text-gray-800 mb-3">
                    <i class="fas fa-cog text-purple-600"></i> Configuración Actual
                </h3>
                <?php
                $config = require 'config/email_config.php';
                ?>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <span class="text-gray-600">Servidor SMTP:</span>
                        <strong class="text-gray-800"><?= htmlspecialchars($config['smtp_host']) ?></strong>
                    </div>
                    <div>
                        <span class="text-gray-600">Puerto:</span>
                        <strong class="text-gray-800"><?= htmlspecialchars($config['smtp_port']) ?></strong>
                    </div>
                    <div>
                        <span class="text-gray-600">Seguridad:</span>
                        <strong class="text-gray-800"><?= strtoupper(htmlspecialchars($config['smtp_secure'])) ?></strong>
                    </div>
                    <div>
                        <span class="text-gray-600">Usuario:</span>
                        <strong class="text-gray-800"><?= htmlspecialchars($config['smtp_username']) ?></strong>
                    </div>
                    <div>
                        <span class="text-gray-600">Remitente:</span>
                        <strong class="text-gray-800"><?= htmlspecialchars($config['from_email']) ?></strong>
                    </div>
                    <div>
                        <span class="text-gray-600">Modo Debug:</span>
                        <strong class="text-gray-800"><?= $config['debug_mode'] ? 'Activado' : 'Desactivado' ?></strong>
                    </div>
                </div>
            </div>
            
        <?php elseif ($testMode === 'test' && !empty($testEmail)): ?>
            
            <!-- Resultados de la Prueba -->
            <?php
            $action = $_POST['action'] ?? 'test_config';
            
            if ($action === 'test_config') {
                echo '<h2 class="text-xl font-bold text-gray-800 mb-4">
                        <i class="fas fa-flask"></i> Prueba de Configuración
                      </h2>';
                
                $result = testEmailConfiguration($testEmail);
                
                if ($result['success']) {
                    echo '<div class="success result-box">
                            <i class="fas fa-check-circle"></i>
                            <strong>¡Éxito!</strong> ' . htmlspecialchars($result['message']) . '
                          </div>';
                    echo '<div class="info result-box">
                            <i class="fas fa-info-circle"></i>
                            Revisa tu bandeja de entrada (y spam) en <strong>' . htmlspecialchars($testEmail) . '</strong>
                          </div>';
                } else {
                    echo '<div class="error result-box">
                            <i class="fas fa-times-circle"></i>
                            <strong>Error:</strong> ' . htmlspecialchars($result['message']) . '
                          </div>';
                    echo '<div class="info result-box">
                            <strong>Posibles soluciones:</strong>
                            <ul class="list-disc ml-6 mt-2">
                                <li>Verifica las credenciales en config/email_config.php</li>
                                <li>Asegúrate de que el puerto no esté bloqueado</li>
                                <li>Prueba cambiar de SSL (465) a TLS (587) o viceversa</li>
                                <li>Verifica que la cuenta de email existe en cPanel</li>
                                <li>Contacta a tu proveedor de hosting</li>
                            </ul>
                          </div>';
                }
                
            } elseif ($action === 'test_welcome') {
                echo '<h2 class="text-xl font-bold text-gray-800 mb-4">
                        <i class="fas fa-user-plus"></i> Prueba de Email de Bienvenida
                      </h2>';
                
                $employeeData = [
                    'email' => $testEmail,
                    'employee_name' => 'Juan Pérez (Prueba)',
                    'username' => 'jperez_test',
                    'password' => 'password123',
                    'employee_code' => 'EMP-2025-TEST',
                    'position' => 'Agente de Prueba',
                    'department' => 'Departamento de Pruebas',
                    'hire_date' => date('Y-m-d')
                ];
                
                $result = sendWelcomeEmail($employeeData);
                
                if ($result['success']) {
                    echo '<div class="success result-box">
                            <i class="fas fa-check-circle"></i>
                            <strong>¡Éxito!</strong> ' . htmlspecialchars($result['message']) . '
                          </div>';
                    echo '<div class="info result-box">
                            <i class="fas fa-info-circle"></i>
                            <strong>Email de bienvenida enviado con:</strong>
                            <ul class="list-disc ml-6 mt-2">
                                <li>Usuario: jperez_test</li>
                                <li>Contraseña: password123</li>
                                <li>Código: EMP-2025-TEST</li>
                            </ul>
                            <p class="mt-2">Revisa tu bandeja de entrada en <strong>' . htmlspecialchars($testEmail) . '</strong></p>
                          </div>';
                } else {
                    echo '<div class="error result-box">
                            <i class="fas fa-times-circle"></i>
                            <strong>Error:</strong> ' . htmlspecialchars($result['message']) . '
                          </div>';
                }
            }
            ?>
            
            <div class="mt-6">
                <a href="?mode=form" class="btn btn-secondary inline-block">
                    <i class="fas fa-arrow-left"></i>
                    Volver a Pruebas
                </a>
                <a href="hr/new_employee.php" class="btn btn-primary inline-block ml-3">
                    <i class="fas fa-user-plus"></i>
                    Ir a Registro de Empleados
                </a>
            </div>
            
        <?php else: ?>
            
            <div class="error result-box">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Error:</strong> Debes proporcionar un email de prueba válido.
            </div>
            <a href="?mode=form" class="btn btn-secondary inline-block mt-4">
                <i class="fas fa-arrow-left"></i>
                Volver
            </a>
            
        <?php endif; ?>
        
        <!-- Footer -->
        <div class="mt-8 pt-6 border-t border-gray-200 text-center text-sm text-gray-600">
            <p>
                <i class="fas fa-book"></i>
                Consulta <strong>EMAIL_NOTIFICATION_SYSTEM.md</strong> para más información
            </p>
        </div>
    </div>
</body>
</html>

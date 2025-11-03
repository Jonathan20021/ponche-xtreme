<?php
/**
 * Welcome Email Template for New Employees
 * 
 * This template is used when a new employee is registered in the system.
 * It provides login credentials and instructions for using the system.
 */

function getWelcomeEmailTemplate($data) {
    $employeeName = htmlspecialchars($data['employee_name']);
    $username = htmlspecialchars($data['username']);
    $password = htmlspecialchars($data['password']);
    $employeeCode = htmlspecialchars($data['employee_code']);
    $position = htmlspecialchars($data['position'] ?? 'Agente');
    $department = htmlspecialchars($data['department'] ?? 'N/A');
    $hireDate = htmlspecialchars($data['hire_date']);
    $loginUrl = htmlspecialchars($data['login_url']);
    $punchUrl = htmlspecialchars($data['punch_url']);
    $dashboardUrl = htmlspecialchars($data['dashboard_url']);
    $supportEmail = htmlspecialchars($data['support_email']);
    $appName = htmlspecialchars($data['app_name']);
    
    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienvenido a {$appName}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f7fa;
            color: #333333;
        }
        .email-wrapper {
            max-width: 650px;
            margin: 0 auto;
            background-color: #ffffff;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 30px;
            text-align: center;
            color: #ffffff;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }
        .header p {
            margin: 10px 0 0 0;
            font-size: 16px;
            opacity: 0.95;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            color: #1a202c;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        .info-box {
            background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);
            border-left: 4px solid #667eea;
            padding: 20px;
            margin: 25px 0;
            border-radius: 8px;
        }
        .info-box h3 {
            margin: 0 0 15px 0;
            color: #667eea;
            font-size: 16px;
            font-weight: 600;
        }
        .credential-item {
            display: flex;
            margin: 12px 0;
            align-items: center;
        }
        .credential-label {
            font-weight: 600;
            color: #4a5568;
            min-width: 140px;
            font-size: 14px;
        }
        .credential-value {
            background-color: #f7fafc;
            padding: 8px 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            color: #2d3748;
            font-size: 14px;
            border: 1px solid #e2e8f0;
            flex: 1;
        }
        .button-container {
            text-align: center;
            margin: 30px 0;
        }
        .button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            padding: 15px 40px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            transition: transform 0.2s;
        }
        .button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }
        .instructions {
            background-color: #f7fafc;
            padding: 25px;
            border-radius: 8px;
            margin: 25px 0;
        }
        .instructions h3 {
            margin: 0 0 20px 0;
            color: #2d3748;
            font-size: 18px;
            font-weight: 600;
        }
        .step {
            margin: 15px 0;
            padding-left: 35px;
            position: relative;
            line-height: 1.6;
        }
        .step-number {
            position: absolute;
            left: 0;
            top: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 12px;
        }
        .step-title {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }
        .step-description {
            color: #4a5568;
            font-size: 14px;
        }
        .tips-box {
            background-color: #fffbeb;
            border-left: 4px solid #f59e0b;
            padding: 20px;
            margin: 25px 0;
            border-radius: 8px;
        }
        .tips-box h4 {
            margin: 0 0 12px 0;
            color: #d97706;
            font-size: 15px;
            font-weight: 600;
        }
        .tips-box ul {
            margin: 0;
            padding-left: 20px;
        }
        .tips-box li {
            color: #78350f;
            margin: 8px 0;
            font-size: 14px;
            line-height: 1.5;
        }
        .footer {
            background-color: #1a202c;
            color: #a0aec0;
            padding: 30px;
            text-align: center;
            font-size: 13px;
            line-height: 1.6;
        }
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
        .divider {
            height: 1px;
            background: linear-gradient(to right, transparent, #e2e8f0, transparent);
            margin: 30px 0;
        }
        @media only screen and (max-width: 600px) {
            .content {
                padding: 25px 20px;
            }
            .header {
                padding: 30px 20px;
            }
            .credential-item {
                flex-direction: column;
                align-items: flex-start;
            }
            .credential-label {
                margin-bottom: 5px;
            }
            .credential-value {
                width: 100%;
                box-sizing: border-box;
            }
        }
    </style>
</head>
<body>
    <div class="email-wrapper">
        <!-- Header -->
        <div class="header">
            <h1>🎉 ¡Bienvenido a {$appName}!</h1>
            <p>Tu cuenta ha sido creada exitosamente</p>
        </div>
        
        <!-- Content -->
        <div class="content">
            <div class="greeting">
                Hola <strong>{$employeeName}</strong>,
                <br><br>
                ¡Nos complace darte la bienvenida al equipo! Tu cuenta en el sistema {$appName} ha sido creada exitosamente. 
                A continuación encontrarás toda la información necesaria para comenzar a usar el sistema.
            </div>
            
            <!-- Credentials Box -->
            <div class="info-box">
                <h3>🔐 Credenciales de Acceso</h3>
                <div class="credential-item">
                    <span class="credential-label">Código de Empleado:</span>
                    <span class="credential-value">{$employeeCode}</span>
                </div>
                <div class="credential-item">
                    <span class="credential-label">Usuario:</span>
                    <span class="credential-value">{$username}</span>
                </div>
                <div class="credential-item">
                    <span class="credential-label">Contraseña:</span>
                    <span class="credential-value">{$password}</span>
                </div>
                <div class="credential-item">
                    <span class="credential-label">Posición:</span>
                    <span class="credential-value">{$position}</span>
                </div>
                <div class="credential-item">
                    <span class="credential-label">Departamento:</span>
                    <span class="credential-value">{$department}</span>
                </div>
                <div class="credential-item">
                    <span class="credential-label">Fecha de Ingreso:</span>
                    <span class="credential-value">{$hireDate}</span>
                </div>
            </div>
            
            <!-- Login Button -->
            <div class="button-container">
                <a href="{$loginUrl}" class="button">Acceder al Sistema</a>
            </div>
            
            <div class="divider"></div>
            
            <!-- Instructions -->
            <div class="instructions">
                <h3>📋 Cómo Usar el Sistema de Marcaciones</h3>
                
                <div class="step">
                    <div class="step-number">1</div>
                    <div class="step-title">Accede al Sistema de Ponche</div>
                    <div class="step-description">
                        Ingresa a <a href="{$loginUrl}">{$loginUrl}</a> y usa tus credenciales para iniciar sesión.
                        Luego accede al módulo de ponche para registrar tus marcaciones.
                    </div>
                </div>
                
                <div class="step">
                    <div class="step-number">2</div>
                    <div class="step-title">Marca tu Entrada</div>
                    <div class="step-description">
                        Al llegar al trabajo, ve al <strong>módulo de ponche</strong> y haz clic en el botón <strong>"Ponchar Entrada"</strong>. 
                        El sistema registrará automáticamente la hora exacta de tu llegada.
                    </div>
                </div>
                
                <div class="step">
                    <div class="step-number">3</div>
                    <div class="step-title">Registra tus Descansos</div>
                    <div class="step-description">
                        Durante tu jornada, usa el <strong>módulo de ponche</strong> para marcar tus descansos (almuerzo, breaks). 
                        Esto ayuda a calcular correctamente tus horas trabajadas.
                    </div>
                </div>
                
                <div class="step">
                    <div class="step-number">4</div>
                    <div class="step-title">Marca tu Salida</div>
                    <div class="step-description">
                        Al finalizar tu jornada, regresa al <strong>módulo de ponche</strong> y haz clic en <strong>"Ponchar Salida"</strong>. 
                        El sistema calculará automáticamente tus horas trabajadas del día.
                    </div>
                </div>
                
                <div class="step">
                    <div class="step-number">5</div>
                    <div class="step-title">Consulta tus Registros en el Dashboard</div>
                    <div class="step-description">
                        Puedes ver tu historial de marcaciones, horas trabajadas y reportes de productividad en tu 
                        <strong>Dashboard del Agente</strong>. Allí encontrarás estadísticas detalladas de tu desempeño.
                    </div>
                </div>
            </div>
            
            <!-- Tips Box -->
            <div class="tips-box">
                <h4>💡 Consejos Importantes</h4>
                <ul>
                    <li><strong>Cambia tu contraseña:</strong> Por seguridad, te recomendamos cambiar tu contraseña después del primer inicio de sesión.</li>
                    <li><strong>Puntualidad:</strong> Recuerda marcar tu entrada y salida a tiempo para un registro preciso de tus horas.</li>
                    <li><strong>Acceso móvil:</strong> Puedes acceder al sistema desde tu teléfono móvil usando el mismo enlace.</li>
                    <li><strong>Soporte:</strong> Si tienes alguna duda o problema, contacta a Recursos Humanos o al equipo de soporte.</li>
                </ul>
            </div>
            
            <div class="divider"></div>
            
            <!-- Quick Links -->
            <div style="text-align: center; margin: 25px 0;">
                <p style="color: #4a5568; margin-bottom: 15px; font-weight: 600;">Enlaces Rápidos</p>
                <p style="margin: 8px 0;">
                    <a href="{$loginUrl}" style="color: #667eea; text-decoration: none; font-weight: 500;">
                        🔑 Portal de Inicio de Sesión
                    </a>
                </p>
                <p style="margin: 8px 0;">
                    <a href="{$punchUrl}" style="color: #667eea; text-decoration: none; font-weight: 500;">
                        ⏱️ Módulo de Ponche (Registrar Marcaciones)
                    </a>
                </p>
                <p style="margin: 8px 0;">
                    <a href="{$dashboardUrl}" style="color: #667eea; text-decoration: none; font-weight: 500;">
                        📊 Dashboard del Agente (Ver Estadísticas)
                    </a>
                </p>
                <p style="margin: 8px 0;">
                    <a href="mailto:{$supportEmail}" style="color: #667eea; text-decoration: none; font-weight: 500;">
                        📧 Contactar Soporte
                    </a>
                </p>
            </div>
            
            <div class="divider"></div>
            
            <p style="color: #718096; font-size: 14px; line-height: 1.6; text-align: center;">
                Si tienes alguna pregunta o necesitas ayuda, no dudes en contactarnos en 
                <a href="mailto:{$supportEmail}" style="color: #667eea; text-decoration: none;">{$supportEmail}</a>
            </p>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p style="margin: 0 0 10px 0; font-weight: 600; color: #e2e8f0;">
                {$appName}
            </p>
            <p style="margin: 0 0 15px 0;">
                Sistema de Gestión de Recursos Humanos y Control de Asistencia
            </p>
            <p style="margin: 0; font-size: 12px;">
                Este es un correo automático, por favor no respondas a este mensaje.<br>
                Para soporte, contacta a <a href="mailto:{$supportEmail}">{$supportEmail}</a>
            </p>
        </div>
    </div>
</body>
</html>
HTML;
}

<?php
/**
 * Email Functions for Employee Notifications
 * 
 * This file contains functions for sending emails using PHPMailer and cPanel SMTP.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Send welcome email to new employee
 * 
 * @param array $employeeData Employee information
 * @return array Result with 'success' boolean and 'message' string
 */
function sendWelcomeEmail($employeeData) {
    try {
        // Load email configuration
        $config = require __DIR__ . '/../config/email_config.php';
        
        // Validate required employee data
        $requiredFields = ['email', 'employee_name', 'username', 'password', 'employee_code'];
        foreach ($requiredFields as $field) {
            if (empty($employeeData[$field])) {
                return [
                    'success' => false,
                    'message' => "Campo requerido faltante: {$field}"
                ];
            }
        }
        
        // Validate email format
        if (!filter_var($employeeData['email'], FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Dirección de correo electrónico inválida'
            ];
        }
        
        // Create PHPMailer instance
        $mail = new PHPMailer(true);
        
        // Server settings
        if ($config['debug_mode']) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        } else {
            $mail->SMTPDebug = 0;
        }
        
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_username'];
        $mail->Password = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port = $config['smtp_port'];
        $mail->CharSet = $config['charset'];
        
        // Recipients
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($employeeData['email'], $employeeData['employee_name']);
        
        // Add reply-to if configured
        if (!empty($config['reply_to_email'])) {
            $mail->addReplyTo($config['reply_to_email'], $config['reply_to_name']);
        }
        
        // Prepare email data
        $emailTemplateData = [
            'employee_name' => $employeeData['employee_name'],
            'username' => $employeeData['username'],
            'password' => $employeeData['password'],
            'employee_code' => $employeeData['employee_code'],
            'position' => $employeeData['position'] ?? 'Agente',
            'department' => $employeeData['department'] ?? 'N/A',
            'hire_date' => !empty($employeeData['hire_date']) ? date('d/m/Y', strtotime($employeeData['hire_date'])) : date('d/m/Y'),
            'login_url' => $config['app_url'] . '/login_agent.php',
            'punch_url' => $config['app_url'] . '/punch.php',
            'dashboard_url' => $config['app_url'] . '/agent_dashboard.php',
            'support_email' => $config['support_email'],
            'app_name' => $config['app_name']
        ];
        
        // Load email template
        require_once __DIR__ . '/../templates/welcome_email.php';
        $htmlContent = getWelcomeEmailTemplate($emailTemplateData);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = "¡Bienvenido a {$config['app_name']}! - Credenciales de Acceso";
        $mail->Body = $htmlContent;
        
        // Plain text alternative
        $mail->AltBody = generatePlainTextWelcomeEmail($emailTemplateData);
        
        // Send email
        $mail->send();
        
        return [
            'success' => true,
            'message' => 'Correo de bienvenida enviado exitosamente a ' . $employeeData['email']
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error al enviar el correo: ' . $mail->ErrorInfo
        ];
    }
}

/**
 * Generate plain text version of welcome email
 * 
 * @param array $data Email data
 * @return string Plain text email content
 */
function generatePlainTextWelcomeEmail($data) {
    return <<<TEXT
¡BIENVENIDO A {$data['app_name']}!

Hola {$data['employee_name']},

Nos complace darte la bienvenida al equipo. Tu cuenta en el sistema {$data['app_name']} ha sido creada exitosamente.

CREDENCIALES DE ACCESO
======================
Código de Empleado: {$data['employee_code']}
Usuario: {$data['username']}
Contraseña: {$data['password']}
Posición: {$data['position']}
Departamento: {$data['department']}
Fecha de Ingreso: {$data['hire_date']}

ACCESO AL SISTEMA
=================
Ingresa al sistema usando el siguiente enlace:
{$data['login_url']}

CÓMO USAR EL SISTEMA DE MARCACIONES
====================================

1. ACCEDE AL SISTEMA DE PONCHE
   Ingresa a {$data['login_url']} y usa tus credenciales para iniciar sesión.
   Luego accede al módulo de ponche para registrar tus marcaciones.

2. MARCA TU ENTRADA
   Al llegar al trabajo, ve al módulo de ponche y haz clic en el botón "Ponchar Entrada".
   El sistema registrará automáticamente la hora exacta de tu llegada.

3. REGISTRA TUS DESCANSOS
   Durante tu jornada, usa el módulo de ponche para marcar tus descansos (almuerzo, breaks).
   Esto ayuda a calcular correctamente tus horas trabajadas.

4. MARCA TU SALIDA
   Al finalizar tu jornada, regresa al módulo de ponche y haz clic en "Ponchar Salida".
   El sistema calculará automáticamente tus horas trabajadas del día.

5. CONSULTA TUS REGISTROS EN EL DASHBOARD
   Puedes ver tu historial de marcaciones, horas trabajadas y reportes de productividad en tu
   Dashboard del Agente. Allí encontrarás estadísticas detalladas de tu desempeño.

CONSEJOS IMPORTANTES
====================
- Cambia tu contraseña después del primer inicio de sesión por seguridad.
- Recuerda marcar tu entrada y salida a tiempo para un registro preciso.
- Puedes acceder al sistema desde tu teléfono móvil usando el mismo enlace.
- Si tienes dudas, contacta a Recursos Humanos o al equipo de soporte.

ENLACES RÁPIDOS
===============
Portal de Inicio de Sesión: {$data['login_url']}
Módulo de Ponche (Registrar Marcaciones): {$data['punch_url']}
Dashboard del Agente (Ver Estadísticas): {$data['dashboard_url']}
Soporte: {$data['support_email']}

Si tienes alguna pregunta o necesitas ayuda, no dudes en contactarnos en {$data['support_email']}

---
{$data['app_name']}
Sistema de Gestión de Recursos Humanos y Control de Asistencia

Este es un correo automático, por favor no respondas a este mensaje.
Para soporte, contacta a {$data['support_email']}
TEXT;
}

/**
 * Test email configuration
 * 
 * @param string $testEmail Email address to send test to
 * @return array Result with 'success' boolean and 'message' string
 */
function testEmailConfiguration($testEmail) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';
        
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Dirección de correo electrónico inválida'
            ];
        }
        
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_username'];
        $mail->Password = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port = $config['smtp_port'];
        $mail->CharSet = $config['charset'];
        
        // Recipients
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($testEmail);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Prueba de Configuración de Email - ' . $config['app_name'];
        $mail->Body = '<h2>Prueba Exitosa</h2><p>La configuración de email está funcionando correctamente.</p>';
        $mail->AltBody = 'Prueba Exitosa - La configuración de email está funcionando correctamente.';
        
        $mail->send();
        
        return [
            'success' => true,
            'message' => 'Email de prueba enviado exitosamente a ' . $testEmail
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error al enviar email de prueba: ' . $mail->ErrorInfo
        ];
    }
}

/**
 * Send password reset email
 * 
 * @param array $userData User information including email, name, reset_token
 * @return array Result with 'success' boolean and 'message' string
 */
function sendPasswordResetEmail($userData) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';
        
        if (empty($userData['email']) || empty($userData['reset_token'])) {
            return [
                'success' => false,
                'message' => 'Datos de usuario incompletos'
            ];
        }
        
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_username'];
        $mail->Password = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port = $config['smtp_port'];
        $mail->CharSet = $config['charset'];
        
        // Recipients
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($userData['email'], $userData['full_name'] ?? '');
        
        $resetUrl = $config['app_url'] . '/reset_password.php?token=' . $userData['reset_token'];
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Recuperación de Contraseña - ' . $config['app_name'];
        $mail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; background: #f9f9f9; }
        .button { display: inline-block; padding: 12px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Recuperación de Contraseña</h2>
        </div>
        <div class="content">
            <p>Hola,</p>
            <p>Hemos recibido una solicitud para restablecer tu contraseña. Haz clic en el siguiente botón para crear una nueva contraseña:</p>
            <p style="text-align: center;">
                <a href="{$resetUrl}" class="button">Restablecer Contraseña</a>
            </p>
            <p>O copia y pega este enlace en tu navegador:</p>
            <p style="word-break: break-all; background: white; padding: 10px; border-radius: 5px;">{$resetUrl}</p>
            <p><strong>Este enlace expirará en 1 hora.</strong></p>
            <p>Si no solicitaste este cambio, puedes ignorar este correo.</p>
        </div>
        <div class="footer">
            <p>{$config['app_name']} - Sistema de Recursos Humanos</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        $mail->AltBody = "Recuperación de Contraseña\n\nHaz clic en el siguiente enlace para restablecer tu contraseña:\n{$resetUrl}\n\nEste enlace expirará en 1 hora.";
        
        $mail->send();
        
        return [
            'success' => true,
            'message' => 'Email de recuperación enviado exitosamente'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error al enviar email: ' . $mail->ErrorInfo
        ];
    }
}

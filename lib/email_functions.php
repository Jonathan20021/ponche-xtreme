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
 * Send application confirmation email to candidate
 * 
 * @param array $applicationData Application information
 * @return array Result with 'success' boolean and 'message' string
 */
function sendApplicationConfirmationEmail($applicationData) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';
        
        $requiredFields = ['email', 'first_name', 'last_name', 'application_code', 'job_title'];
        foreach ($requiredFields as $field) {
            if (empty($applicationData[$field])) {
                return ['success' => false, 'message' => "Campo requerido faltante: {$field}"];
            }
        }
        
        if (!filter_var($applicationData['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Dirección de correo electrónico inválida'];
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
        $mail->addAddress($applicationData['email'], $applicationData['first_name'] . ' ' . $applicationData['last_name']);
        
        if (!empty($config['reply_to_email'])) {
            $mail->addReplyTo($config['reply_to_email'], $config['reply_to_name']);
        }
        
        $trackingUrl = $config['app_url'] . '/track_application.php?code=' . $applicationData['application_code'];
        $positionsText = isset($applicationData['positions_count']) && $applicationData['positions_count'] > 1 
            ? "Has aplicado a {$applicationData['positions_count']} vacantes con el mismo CV." 
            : '';
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = '¡Solicitud Recibida! - ' . $config['app_name'];
        $mail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
        .content { padding: 30px; background: #f9f9f9; }
        .info-box { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #667eea; }
        .button { display: inline-block; padding: 14px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .highlight { color: #667eea; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ ¡Solicitud Recibida!</h1>
        </div>
        <div class="content">
            <p>Hola <strong>{$applicationData['first_name']} {$applicationData['last_name']}</strong>,</p>
            
            <p>¡Gracias por tu interés en unirte a nuestro equipo! Hemos recibido tu solicitud para la posición de <strong>{$applicationData['job_title']}</strong>.</p>
            
            {$positionsText}
            
            <div class="info-box">
                <h3 style="margin-top: 0;">📋 Información de tu Solicitud</h3>
                <p><strong>Código de Seguimiento:</strong> <span class="highlight">{$applicationData['application_code']}</span></p>
                <p><strong>Posición Principal:</strong> {$applicationData['job_title']}</p>
                <p><strong>Fecha de Aplicación:</strong> {$applicationData['applied_date']}</p>
                <p><strong>Estado:</strong> Nueva - En Revisión</p>
            </div>
            
            <h3>📍 ¿Qué Sigue?</h3>
            <ol>
                <li><strong>Revisión Inicial:</strong> Nuestro equipo de Recursos Humanos revisará tu CV y perfil.</li>
                <li><strong>Evaluación:</strong> Si tu perfil coincide con nuestros requisitos, te contactaremos para los siguientes pasos.</li>
                <li><strong>Entrevista:</strong> Los candidatos preseleccionados serán invitados a una entrevista.</li>
            </ol>
            
            <p style="text-align: center;">
                <a href="{$trackingUrl}" class="button">🔍 Rastrear Mi Solicitud</a>
            </p>
            
            <p style="background: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107;">
                <strong>💡 Consejo:</strong> Guarda tu código de seguimiento <strong>{$applicationData['application_code']}</strong> para consultar el estado de tu solicitud en cualquier momento.
            </p>
            
            <p>Te mantendremos informado sobre cualquier actualización en el proceso de selección.</p>
            
            <p>¡Mucha suerte!</p>
            <p><strong>Equipo de Recursos Humanos</strong><br>{$config['app_name']}</p>
        </div>
        <div class="footer">
            <p>{$config['app_name']} - Sistema de Reclutamiento</p>
            <p>Este es un correo automático, por favor no respondas. Para consultas: {$config['support_email']}</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        $mail->AltBody = "¡Solicitud Recibida!\n\nHola {$applicationData['first_name']} {$applicationData['last_name']},\n\nHemos recibido tu solicitud para: {$applicationData['job_title']}\n\nCódigo de Seguimiento: {$applicationData['application_code']}\n\nRastrear solicitud: {$trackingUrl}\n\n{$config['app_name']}";
        
        $mail->send();
        
        return ['success' => true, 'message' => 'Email de confirmación enviado exitosamente'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error al enviar email: ' . $mail->ErrorInfo];
    }
}

/**
 * Send status change notification email to candidate
 * 
 * @param array $data Status change information
 * @return array Result with 'success' boolean and 'message' string
 */
function sendStatusChangeEmail($data) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';
        
        $requiredFields = ['email', 'first_name', 'last_name', 'application_code', 'job_title', 'new_status'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'message' => "Campo requerido faltante: {$field}"];
            }
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
        $mail->addAddress($data['email'], $data['first_name'] . ' ' . $data['last_name']);
        
        $statusLabels = [
            'new' => 'Nueva',
            'reviewing' => 'En Revisión',
            'shortlisted' => 'Preseleccionado/a',
            'interview_scheduled' => 'Entrevista Agendada',
            'interviewed' => 'Entrevistado/a',
            'offer_extended' => 'Oferta Extendida',
            'hired' => 'Contratado/a',
            'rejected' => 'No Seleccionado/a',
            'withdrawn' => 'Retirado'
        ];
        
        $statusLabel = $statusLabels[$data['new_status']] ?? $data['new_status'];
        $trackingUrl = $config['app_url'] . '/track_application.php?code=' . $data['application_code'];
        $notes = !empty($data['notes']) ? "<p><strong>Nota:</strong> {$data['notes']}</p>" : '';
        
        // Different messages based on status
        $statusMessage = '';
        $statusIcon = '📋';
        
        switch ($data['new_status']) {
            case 'reviewing':
                $statusIcon = '👀';
                $statusMessage = 'Tu solicitud está siendo revisada por nuestro equipo de Recursos Humanos.';
                break;
            case 'shortlisted':
                $statusIcon = '⭐';
                $statusMessage = '¡Felicidades! Has sido preseleccionado/a. Pronto nos pondremos en contacto contigo.';
                break;
            case 'interview_scheduled':
                $statusIcon = '📅';
                $statusMessage = 'Se ha agendado una entrevista. Revisa los detalles y prepárate para el siguiente paso.';
                break;
            case 'interviewed':
                $statusIcon = '✅';
                $statusMessage = 'Gracias por asistir a la entrevista. Estamos evaluando tu perfil.';
                break;
            case 'offer_extended':
                $statusIcon = '🎉';
                $statusMessage = '¡Excelentes noticias! Te hemos extendido una oferta de empleo. Pronto recibirás más detalles.';
                break;
            case 'hired':
                $statusIcon = '🎊';
                $statusMessage = '¡Bienvenido/a al equipo! Nos complace informarte que has sido contratado/a.';
                break;
            case 'rejected':
                $statusIcon = '📝';
                $statusMessage = 'Lamentamos informarte que en esta ocasión no continuaremos con tu proceso. Agradecemos tu interés y te deseamos éxito en tu búsqueda laboral.';
                break;
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = "Actualización de Solicitud: {$statusLabel} - {$config['app_name']}";
        $mail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
        .content { padding: 30px; background: #f9f9f9; }
        .status-box { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center; border: 2px solid #667eea; }
        .button { display: inline-block; padding: 14px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$statusIcon} Actualización de Solicitud</h1>
        </div>
        <div class="content">
            <p>Hola <strong>{$data['first_name']} {$data['last_name']}</strong>,</p>
            
            <p>Tenemos una actualización sobre tu solicitud para la posición de <strong>{$data['job_title']}</strong>.</p>
            
            <div class="status-box">
                <h2 style="margin: 0; color: #667eea;">Nuevo Estado: {$statusLabel}</h2>
            </div>
            
            <p>{$statusMessage}</p>
            
            {$notes}
            
            <p><strong>Código de Seguimiento:</strong> {$data['application_code']}</p>
            
            <p style="text-align: center;">
                <a href="{$trackingUrl}" class="button">Ver Detalles de Mi Solicitud</a>
            </p>
            
            <p>Gracias por tu interés en formar parte de nuestro equipo.</p>
            
            <p><strong>Equipo de Recursos Humanos</strong><br>{$config['app_name']}</p>
        </div>
        <div class="footer">
            <p>{$config['app_name']} - Sistema de Reclutamiento</p>
            <p>Para consultas: {$config['support_email']}</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        $mail->AltBody = "Actualización de Solicitud\n\nHola {$data['first_name']},\n\nNuevo Estado: {$statusLabel}\n\n{$statusMessage}\n\nCódigo: {$data['application_code']}\nRastrear: {$trackingUrl}\n\n{$config['app_name']}";
        
        $mail->send();
        
        return ['success' => true, 'message' => 'Email de actualización enviado exitosamente'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error al enviar email: ' . $mail->ErrorInfo];
    }
}

/**
 * Send interview notification email to candidate
 * 
 * @param array $data Interview information
 * @return array Result with 'success' boolean and 'message' string
 */
function sendInterviewNotificationEmail($data) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';
        
        $requiredFields = ['email', 'first_name', 'last_name', 'application_code', 'job_title', 'interview_date', 'interview_type'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'message' => "Campo requerido faltante: {$field}"];
            }
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
        $mail->addAddress($data['email'], $data['first_name'] . ' ' . $data['last_name']);
        
        $interviewTypes = [
            'phone_screening' => 'Llamada de Filtro',
            'technical' => 'Entrevista Técnica',
            'hr' => 'Entrevista de Recursos Humanos',
            'manager' => 'Entrevista con Gerente',
            'final' => 'Entrevista Final'
        ];
        
        $interviewTypeLabel = $interviewTypes[$data['interview_type']] ?? $data['interview_type'];
        $interviewDate = date('d/m/Y', strtotime($data['interview_date']));
        $interviewTime = date('h:i A', strtotime($data['interview_date']));
        $duration = !empty($data['duration_minutes']) ? $data['duration_minutes'] . ' minutos' : 'Por definir';
        $location = !empty($data['location']) ? "<p><strong>📍 Ubicación/Link:</strong> {$data['location']}</p>" : '';
        $notes = !empty($data['notes']) ? "<p><strong>📝 Notas:</strong> {$data['notes']}</p>" : '';
        $trackingUrl = $config['app_url'] . '/track_application.php?code=' . $data['application_code'];
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = "📅 Entrevista Agendada - {$data['job_title']} - {$config['app_name']}";
        $mail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
        .content { padding: 30px; background: #f9f9f9; }
        .interview-box { background: white; padding: 25px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #667eea; }
        .button { display: inline-block; padding: 14px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .highlight { color: #667eea; font-weight: bold; font-size: 18px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📅 ¡Entrevista Agendada!</h1>
        </div>
        <div class="content">
            <p>Hola <strong>{$data['first_name']} {$data['last_name']}</strong>,</p>
            
            <p>¡Excelentes noticias! Hemos agendado una entrevista contigo para la posición de <strong>{$data['job_title']}</strong>.</p>
            
            <div class="interview-box">
                <h3 style="margin-top: 0; color: #667eea;">📋 Detalles de la Entrevista</h3>
                <p><strong>Tipo:</strong> {$interviewTypeLabel}</p>
                <p><strong>📅 Fecha:</strong> <span class="highlight">{$interviewDate}</span></p>
                <p><strong>🕐 Hora:</strong> <span class="highlight">{$interviewTime}</span></p>
                <p><strong>⏱️ Duración:</strong> {$duration}</p>
                {$location}
                {$notes}
            </div>
            
            <div style="background: #e3f2fd; padding: 15px; border-radius: 5px; border-left: 4px solid #2196f3; margin: 20px 0;">
                <h4 style="margin-top: 0;">💡 Consejos para la Entrevista</h4>
                <ul style="margin: 10px 0;">
                    <li>Llega 10 minutos antes (o conéctate con anticipación si es virtual)</li>
                    <li>Revisa tu CV y prepara ejemplos de tu experiencia</li>
                    <li>Investiga sobre nuestra empresa</li>
                    <li>Prepara preguntas sobre la posición y el equipo</li>
                    <li>Viste de manera profesional</li>
                </ul>
            </div>
            
            <p style="text-align: center;">
                <a href="{$trackingUrl}" class="button">Ver Detalles de Mi Solicitud</a>
            </p>
            
            <p>Si necesitas reprogramar o tienes alguna pregunta, por favor contáctanos a <strong>{$config['support_email']}</strong></p>
            
            <p>¡Te deseamos mucho éxito!</p>
            
            <p><strong>Equipo de Recursos Humanos</strong><br>{$config['app_name']}</p>
        </div>
        <div class="footer">
            <p>{$config['app_name']} - Sistema de Reclutamiento</p>
            <p>Para consultas: {$config['support_email']}</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        $mail->AltBody = "¡Entrevista Agendada!\n\nHola {$data['first_name']},\n\nTipo: {$interviewTypeLabel}\nFecha: {$interviewDate}\nHora: {$interviewTime}\nDuración: {$duration}\n\nCódigo: {$data['application_code']}\n\n{$config['app_name']}";
        
        $mail->send();
        
        return ['success' => true, 'message' => 'Email de entrevista enviado exitosamente'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error al enviar email: ' . $mail->ErrorInfo];
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

/**
 * Send daily absence report via email
 * 
 * @param string $htmlContent HTML content of the report
 * @param array $recipients Array of email addresses
 * @param array $reportData Report data including statistics
 * @return array Result with 'success' boolean and 'message' string
 */
function sendDailyAbsenceReport($htmlContent, $recipients, $reportData) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';
        
        if (empty($recipients)) {
            return [
                'success' => false,
                'message' => 'No se especificaron destinatarios'
            ];
        }
        
        if (empty($htmlContent)) {
            return [
                'success' => false,
                'message' => 'El contenido del reporte está vacío'
            ];
        }
        
        $mail = new PHPMailer(true);
        
        // Server settings - EXACTLY like password reset
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_username'];
        $mail->Password = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port = $config['smtp_port'];
        $mail->CharSet = $config['charset'];
        
        // Recipients - Set from FIRST like other functions
        $mail->setFrom($config['from_email'], $config['from_name']);
        
        // Add all recipients
        $validRecipients = 0;
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($recipient);
                $validRecipients++;
            }
        }
        
        if ($validRecipients === 0) {
            return [
                'success' => false,
                'message' => 'No se encontraron destinatarios válidos'
            ];
        }
        
        // Content
        $dateFormatted = $reportData['date_formatted'] ?? date('l, F j, Y');
        $totalAbsences = $reportData['total_absences'] ?? 0;
        $withoutJustification = count($reportData['absences_without_justification'] ?? []);
        
        $mail->isHTML(true);
        $mail->Subject = "Reporte Diario de Ausencias - $dateFormatted"; // Sin emoji para evitar filtros de spam
        $mail->Body = $htmlContent;
        
        // Plain text alternative
        $plainText = "REPORTE DIARIO DE AUSENCIAS\n";
        $plainText .= "============================\n\n";
        $plainText .= "Fecha: $dateFormatted\n";
        $plainText .= "Total Empleados: {$reportData['total_employees']}\n";
        $plainText .= "Total Ausencias: $totalAbsences\n";
        $plainText .= "Sin Justificación: $withoutJustification\n";
        $plainText .= "Con Justificación: " . count($reportData['absences_with_justification'] ?? []) . "\n\n";
        
        if (!empty($reportData['absences_without_justification'])) {
            $plainText .= "AUSENCIAS SIN JUSTIFICACIÓN:\n";
            $plainText .= "----------------------------\n";
            foreach ($reportData['absences_without_justification'] as $emp) {
                $plainText .= "- {$emp['full_name']} ({$emp['employee_code']}) - {$emp['position']} - {$emp['department']}\n";
            }
            $plainText .= "\n";
        }
        
        if (!empty($reportData['absences_with_justification'])) {
            $plainText .= "AUSENCIAS JUSTIFICADAS:\n";
            $plainText .= "----------------------\n";
            foreach ($reportData['absences_with_justification'] as $emp) {
                $plainText .= "- {$emp['full_name']} ({$emp['employee_code']}) - {$emp['position']}\n";
                
                if (!empty($emp['permissions'])) {
                    foreach ($emp['permissions'] as $perm) {
                        $plainText .= "  • Permiso: {$perm['request_type']} ({$perm['start_date']} - {$perm['end_date']})\n";
                    }
                }
                
                if (!empty($emp['vacations'])) {
                    foreach ($emp['vacations'] as $vac) {
                        $type = $vac['vacation_type'] ?? 'regular';
                        $plainText .= "  • Vacaciones: $type ({$vac['start_date']} - {$vac['end_date']})\n";
                    }
                }
                
                if (!empty($emp['medical_leaves'])) {
                    foreach ($emp['medical_leaves'] as $leave) {
                        $plainText .= "  • Licencia Médica: {$leave['leave_type']} ({$leave['start_date']} - {$leave['end_date']})\n";
                    }
                }
                
                $plainText .= "\n";
            }
        }
        
        $plainText .= "\n---\n";
        $plainText .= "Sistema de Control de Asistencia - {$config['app_name']}\n";
        $plainText .= "Generado el: {$reportData['generated_at']}\n";
        
        $mail->AltBody = $plainText;
        
        // Send email - EXACTLY like password reset
        $mail->send();
        
        return [
            'success' => true,
            'message' => "Reporte enviado exitosamente a $validRecipients destinatario(s)"
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error al enviar email: ' . $e->getMessage()
        ];
    }
}

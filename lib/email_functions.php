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

/**
 * Send daily login hours (Entry/Exit/breaks) report via email.
 *
 * @param string $htmlContent Rendered HTML body.
 * @param array  $recipients  List of email addresses.
 * @param array  $reportData  Report data array from generateDailyLoginHoursReport().
 * @return array{success: bool, message: string}
 */
function sendDailyLoginHoursReport($htmlContent, $recipients, $reportData) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';

        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No se especificaron destinatarios'];
        }
        if (empty($htmlContent)) {
            return ['success' => false, 'message' => 'El contenido del reporte está vacío'];
        }

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = $config['charset'];

        $mail->setFrom($config['from_email'], $config['from_name']);

        $validRecipients = 0;
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($recipient);
                $validRecipients++;
            }
        }
        if ($validRecipients === 0) {
            return ['success' => false, 'message' => 'No se encontraron destinatarios válidos'];
        }

        $dateFormatted = $reportData['date_formatted'] ?? ($reportData['date'] ?? date('Y-m-d'));
        $totals = $reportData['totals'] ?? [];

        $mail->isHTML(true);
        $mail->Subject = "Reporte Diario de Horas de Login - $dateFormatted";
        $mail->Body    = $htmlContent;

        // Plain-text alternative
        $plain  = "REPORTE DIARIO DE HORAS DE LOGIN\n";
        $plain .= "================================\n\n";
        $plain .= "Fecha: $dateFormatted\n";
        $plain .= "Empleados con registro: " . ($totals['employees_with_activity'] ?? 0) . "\n";
        $plain .= "Llegadas tarde: "         . ($totals['late_count']              ?? 0) . "\n";
        $plain .= "Sin registro de Exit: "   . ($totals['no_exit_count']           ?? 0) . "\n";
        $plain .= "Exceso de breaks: "       . ($totals['break_excess_count']      ?? 0) . "\n\n";

        foreach (($reportData['employees'] ?? []) as $emp) {
            $entry = $emp['first_entry'] ? date('H:i', strtotime($emp['first_entry'])) : '---';
            $exit  = $emp['last_exit']   ? date('H:i', strtotime($emp['last_exit']))   : '---';
            $hrs   = round(($emp['work_seconds'] ?? 0) / 3600, 2);
            $brk   = (int) round(($emp['break_seconds'] ?? 0) / 60);
            $plain .= "- {$emp['full_name']} [{$emp['department']}] Entry {$entry} / Exit {$exit} / {$hrs}h netas / {$brk}m break ({$emp['break_count']}x)\n";
        }

        $plain .= "\n---\nSistema de Control de Asistencia - " . ($config['app_name'] ?? '') . "\n";
        $plain .= "Generado: " . ($reportData['generated_at'] ?? date('Y-m-d H:i:s')) . "\n";

        $mail->AltBody = $plain;

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

/**
 * Send daily login logs (security audit) report via email.
 *
 * @param string $htmlContent Rendered HTML body.
 * @param array  $recipients  List of email addresses.
 * @param array  $reportData  Report data from generateDailyLoginLogsReport().
 * @return array{success: bool, message: string}
 */
function sendDailyLoginLogsReport($htmlContent, $recipients, $reportData) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';

        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No se especificaron destinatarios'];
        }
        if (empty($htmlContent)) {
            return ['success' => false, 'message' => 'El contenido del reporte está vacío'];
        }

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = $config['charset'];

        $mail->setFrom($config['from_email'], $config['from_name']);

        $validRecipients = 0;
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($recipient);
                $validRecipients++;
            }
        }
        if ($validRecipients === 0) {
            return ['success' => false, 'message' => 'No se encontraron destinatarios válidos'];
        }

        $dateFormatted = $reportData['date_formatted'] ?? ($reportData['date'] ?? date('Y-m-d'));
        $totals = $reportData['totals'] ?? [];

        $mail->isHTML(true);
        $mail->Subject = "Auditoría de accesos - $dateFormatted ({$totals['total_logins']} accesos · {$totals['unique_users']} usuarios)";
        $mail->Body    = $htmlContent;

        // Plain-text alternative
        $plain  = "AUDITORÍA DE ACCESOS AL SISTEMA\n";
        $plain .= "================================\n\n";
        $plain .= "Fecha: $dateFormatted\n\n";
        $plain .= "Total accesos:    " . ($totals['total_logins']     ?? 0) . "\n";
        $plain .= "Usuarios únicos:  " . ($totals['unique_users']     ?? 0) . "\n";
        $plain .= "IPs únicas:       " . ($totals['unique_ips']       ?? 0) . "\n";
        $plain .= "IPs compartidas:  " . ($totals['shared_ips']       ?? 0) . "\n";
        $plain .= "Fuera de horario: " . ($totals['off_hours']        ?? 0) . "\n";
        $plain .= "Accesos excesivos:" . ($totals['excessive_users']  ?? 0) . "\n\n";

        if (!empty($reportData['shared_ips'])) {
            $plain .= "IPs COMPARTIDAS:\n";
            foreach ($reportData['shared_ips'] as $ip) {
                $users = implode(', ', $ip['users']);
                $plain .= sprintf("  - %s [%s]: %d usuarios (%s)\n", $ip['ip'], $ip['location'] ?? '—', $ip['users_count'], $users);
            }
            $plain .= "\n";
        }

        if (!empty($reportData['off_hours'])) {
            $plain .= "FUERA DE HORARIO:\n";
            foreach ($reportData['off_hours'] as $o) {
                $plain .= sprintf("  - %s %s (%s) desde %s\n",
                    date('H:i', strtotime($o['login_time'])), $o['username'], $o['role'], $o['ip']);
            }
        }

        $plain .= "\n---\nSistema de Control de Asistencia - " . ($config['app_name'] ?? '') . "\n";
        $plain .= "Generado: " . ($reportData['generated_at'] ?? date('Y-m-d H:i:s')) . "\n";

        $mail->AltBody = $plain;

        $mail->send();

        return ['success' => true, 'message' => "Reporte enviado a $validRecipients destinatario(s)"];

    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error al enviar email: ' . $e->getMessage()];
    }
}

/**
 * Send daily activity logs report (admin audit) via email.
 *
 * @param string $htmlContent Rendered HTML body.
 * @param array  $recipients  List of email addresses.
 * @param array  $reportData  Report data from generateDailyActivityLogsReport().
 * @return array{success: bool, message: string}
 */
function sendDailyActivityLogsReport($htmlContent, $recipients, $reportData) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';

        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No se especificaron destinatarios'];
        }
        if (empty($htmlContent)) {
            return ['success' => false, 'message' => 'El contenido del reporte está vacío'];
        }

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = $config['charset'];

        $mail->setFrom($config['from_email'], $config['from_name']);

        $validRecipients = 0;
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($recipient);
                $validRecipients++;
            }
        }
        if ($validRecipients === 0) {
            return ['success' => false, 'message' => 'No se encontraron destinatarios válidos'];
        }

        $dateFormatted = $reportData['date_formatted'] ?? ($reportData['date'] ?? date('Y-m-d'));
        $totals = $reportData['totals'] ?? [];

        $mail->isHTML(true);
        $mail->Subject = "Auditoría de actividad - $dateFormatted ("
            . (int) ($totals['total_actions'] ?? 0) . " acciones · "
            . (int) ($totals['unique_users'] ?? 0) . " usuarios · "
            . (int) ($totals['sensitive'] ?? 0) . " sensibles)";
        $mail->Body    = $htmlContent;

        // Plain-text alternative
        $plain  = "AUDITORÍA DE ACTIVIDAD ADMINISTRATIVA\n";
        $plain .= "=======================================\n\n";
        $plain .= "Fecha: $dateFormatted\n\n";
        $plain .= "Total acciones:    " . (int) ($totals['total_actions']   ?? 0) . "\n";
        $plain .= "Módulos tocados:   " . (int) ($totals['modules_touched'] ?? 0) . "\n";
        $plain .= "Usuarios activos:  " . (int) ($totals['unique_users']    ?? 0) . "\n";
        $plain .= "Tipos de acción:   " . (int) ($totals['unique_actions']  ?? 0) . "\n";
        $plain .= "Acciones sensibles:" . (int) ($totals['sensitive']       ?? 0) . "\n";
        $plain .= "Eliminaciones:     " . (int) ($totals['deletes']         ?? 0) . "\n\n";

        if (!empty($reportData['by_module'])) {
            $plain .= "POR MÓDULO:\n";
            foreach ($reportData['by_module'] as $mod => $count) {
                $plain .= sprintf("  - %-20s %d\n", $mod, $count);
            }
            $plain .= "\n";
        }

        if (!empty($reportData['sensitive'])) {
            $plain .= "ACCIONES SENSIBLES:\n";
            foreach ($reportData['sensitive'] as $r) {
                $plain .= sprintf("  - %s [%s/%s] %s: %s\n",
                    date('H:i', strtotime((string) $r['created_at'])),
                    (string) $r['user_name'],
                    strtoupper((string) $r['user_role']),
                    (string) $r['module'] . '.' . (string) $r['action'],
                    (string) ($r['description'] ?? ''));
            }
            $plain .= "\n";
        }

        if (!empty($reportData['deletes'])) {
            $plain .= "ELIMINACIONES:\n";
            foreach ($reportData['deletes'] as $r) {
                $plain .= sprintf("  - %s [%s] %s: %s\n",
                    date('H:i', strtotime((string) $r['created_at'])),
                    (string) $r['user_name'],
                    (string) $r['module'],
                    (string) ($r['description'] ?? ''));
            }
        }

        $plain .= "\n---\nSistema de Control de Asistencia - " . ($config['app_name'] ?? '') . "\n";
        $plain .= "Generado: " . ($reportData['generated_at'] ?? date('Y-m-d H:i:s')) . "\n";

        $mail->AltBody = $plain;

        $mail->send();

        return ['success' => true, 'message' => "Reporte enviado a $validRecipients destinatario(s)"];

    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error al enviar email: ' . $e->getMessage()];
    }
}

/**
 * Send daily executive dashboard summary ("cierre del día") via email.
 *
 * @param string $htmlContent Rendered HTML body.
 * @param array  $recipients  List of email addresses.
 * @param array  $reportData  Report data from generateDailyExecutiveDashboardReport().
 * @return array{success: bool, message: string}
 */
function sendDailyExecutiveDashboardReport($htmlContent, $recipients, $reportData) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';

        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No se especificaron destinatarios'];
        }
        if (empty($htmlContent)) {
            return ['success' => false, 'message' => 'El contenido del reporte está vacío'];
        }

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = $config['charset'];

        $mail->setFrom($config['from_email'], $config['from_name']);

        $validRecipients = 0;
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($recipient);
                $validRecipients++;
            }
        }
        if ($validRecipients === 0) {
            return ['success' => false, 'message' => 'No se encontraron destinatarios válidos'];
        }

        $dateFormatted = $reportData['date_formatted'] ?? ($reportData['date'] ?? date('Y-m-d'));
        $totals = $reportData['totals'] ?? [];
        $workforce = $reportData['workforce'] ?? [];

        $mail->isHTML(true);
        $mail->Subject = "Cierre ejecutivo - $dateFormatted ("
            . (int) ($totals['worked_today'] ?? 0) . '/' . (int) ($totals['eligible'] ?? 0)
            . ' presentes · '
            . '$' . number_format((float) ($totals['earnings_combined_usd'] ?? 0), 2)
            . ' USD)';
        $mail->Body    = $htmlContent;

        // Plain-text alternative
        $plain  = "CIERRE EJECUTIVO DEL DÍA\n";
        $plain .= "==========================\n\n";
        $plain .= "Fecha: $dateFormatted\n\n";
        $plain .= "Asistencia:         " . (int) ($totals['worked_today'] ?? 0) . ' / ' . (int) ($totals['eligible'] ?? 0)
            . ' (' . (float) ($totals['attendance_rate_pct'] ?? 0) . "%)\n";
        $plain .= "Horas pagadas:      " . number_format((float) ($totals['hours_total'] ?? 0), 2) . " h\n";
        $plain .= "Horas prom./pers:   " . number_format((float) ($totals['hours_avg_per_worker'] ?? 0), 2) . " h\n";
        $plain .= "Costo USD:          $" . number_format((float) ($totals['earnings_usd'] ?? 0), 2) . "\n";
        $plain .= "Costo DOP:          RD$" . number_format((float) ($totals['earnings_dop'] ?? 0), 2) . "\n";
        $plain .= "Costo USD equiv.:   $" . number_format((float) ($totals['earnings_combined_usd'] ?? 0), 2) . "\n";
        $plain .= "Tasa de cambio:     RD$" . number_format((float) ($reportData['exchange_rate'] ?? 58.5), 2) . " / USD\n\n";

        $plain .= "FUERZA LABORAL:\n";
        $plain .= "  Activos:      " . (int) ($workforce['active_employees']    ?? 0) . "\n";
        $plain .= "  En prueba:    " . (int) ($workforce['trial_employees']     ?? 0) . "\n";
        $plain .= "  Ausentes hoy: " . (int) ($workforce['absent_employees']    ?? 0) . "\n\n";

        if (!empty($reportData['campaigns'])) {
            $plain .= "CAMPAÑAS:\n";
            foreach ($reportData['campaigns'] as $c) {
                $hours = (float) ($c['hours_usd'] ?? 0) + (float) ($c['hours_dop'] ?? 0);
                $plain .= sprintf("  - %-25s %d/%d · %.2fh · $%s USD · RD$%s DOP\n",
                    (string) $c['name'],
                    (int) $c['worked_today'],
                    (int) $c['employees'],
                    $hours,
                    number_format((float) $c['cost_usd'], 2),
                    number_format((float) $c['cost_dop'], 2));
            }
        }

        $plain .= "\n---\nSistema de Control de Asistencia - " . ($config['app_name'] ?? '') . "\n";
        $plain .= "Generado: " . ($reportData['generated_at'] ?? date('Y-m-d H:i:s')) . "\n";

        $mail->AltBody = $plain;

        $mail->send();

        return ['success' => true, 'message' => "Reporte enviado a $validRecipients destinatario(s)"];

    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error al enviar email: ' . $e->getMessage()];
    }
}

/**
 * Send daily workforce (active vs. absent) report via email.
 *
 * @param string $htmlContent Rendered HTML body.
 * @param array  $recipients  List of email addresses.
 * @param array  $reportData  Report data from generateDailyWorkforceReport().
 * @return array{success: bool, message: string}
 */
function sendDailyWorkforceReport($htmlContent, $recipients, $reportData) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';

        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No se especificaron destinatarios'];
        }
        if (empty($htmlContent)) {
            return ['success' => false, 'message' => 'El contenido del reporte está vacío'];
        }

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = $config['charset'];

        $mail->setFrom($config['from_email'], $config['from_name']);

        $validRecipients = 0;
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($recipient);
                $validRecipients++;
            }
        }
        if ($validRecipients === 0) {
            return ['success' => false, 'message' => 'No se encontraron destinatarios válidos'];
        }

        $dateFormatted = $reportData['date_formatted'] ?? ($reportData['date'] ?? date('Y-m-d'));
        $totals = $reportData['totals'] ?? [];

        $mail->isHTML(true);
        $mail->Subject = "Fuerza laboral - $dateFormatted ({$totals['present_today']} presentes / {$totals['absent_today']} ausentes)";
        $mail->Body    = $htmlContent;

        // Plain-text alternative
        $plain  = "FUERZA LABORAL DEL DÍA\n";
        $plain .= "======================\n\n";
        $plain .= "Fecha: $dateFormatted\n\n";
        $plain .= "Total elegibles: " . ($totals['total_eligible'] ?? 0) . "\n";
        $plain .= "  - Activos:     " . ($totals['active_employees'] ?? 0) . "\n";
        $plain .= "  - En Prueba:   " . ($totals['trial_employees'] ?? 0) . "\n\n";
        $plain .= "Presentes hoy:   " . ($totals['present_today'] ?? 0) . " (" . ($totals['present_rate_pct'] ?? 0) . "%)\n";
        $plain .= "Ausentes hoy:    " . ($totals['absent_today'] ?? 0) . " (" . ($totals['absent_rate_pct'] ?? 0) . "%)\n";
        $plain .= "En prueba ausentes: " . ($totals['trial_absent'] ?? 0) . "\n\n";

        if (!empty($reportData['trial_absent'])) {
            $plain .= "⚠ EN PRUEBA AUSENTES:\n";
            foreach ($reportData['trial_absent'] as $t) {
                $days = $t['days_since_last_punch'] === null ? 'nunca' : "hace {$t['days_since_last_punch']}d";
                $plain .= "  - {$t['full_name']} [{$t['department']}] último punch: $days\n";
            }
            $plain .= "\n";
        }

        if (!empty($reportData['absent'])) {
            $plain .= "LISTADO DE AUSENTES:\n";
            foreach ($reportData['absent'] as $a) {
                $days = $a['days_since_last_punch'] === null ? 'nunca' : "hace {$a['days_since_last_punch']}d";
                $plain .= sprintf("  - %-35s [%s / %s] %s / último: %s\n",
                    $a['full_name'], $a['role'], $a['department'], $a['employment_status'], $days);
            }
        } else {
            $plain .= "¡Asistencia al 100% hoy!\n";
        }

        $plain .= "\n---\nSistema de Control de Asistencia - " . ($config['app_name'] ?? '') . "\n";
        $plain .= "Generado: " . ($reportData['generated_at'] ?? date('Y-m-d H:i:s')) . "\n";

        $mail->AltBody = $plain;

        $mail->send();

        return ['success' => true, 'message' => "Reporte enviado a $validRecipients destinatario(s)"];

    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error al enviar email: ' . $e->getMessage()];
    }
}

/**
 * Send daily tardiness alert report via email.
 *
 * @param string $htmlContent Rendered HTML body.
 * @param array  $recipients  List of email addresses.
 * @param array  $reportData  Report data from generateDailyTardinessReport().
 * @return array{success: bool, message: string}
 */
function sendDailyTardinessReport($htmlContent, $recipients, $reportData) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';

        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No se especificaron destinatarios'];
        }
        if (empty($htmlContent)) {
            return ['success' => false, 'message' => 'El contenido del reporte está vacío'];
        }

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = $config['charset'];

        $mail->setFrom($config['from_email'], $config['from_name']);

        $validRecipients = 0;
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($recipient);
                $validRecipients++;
            }
        }
        if ($validRecipients === 0) {
            return ['success' => false, 'message' => 'No se encontraron destinatarios válidos'];
        }

        $dateFormatted = $reportData['date_formatted'] ?? ($reportData['date'] ?? date('Y-m-d'));
        $totals = $reportData['totals'] ?? [];
        $tardyCount = $totals['tardies_today'] ?? 0;
        $todayRate = $totals['today_rate_pct'] ?? 0;

        $mail->isHTML(true);
        $mail->Subject = "Tardanzas del día - $dateFormatted ({$tardyCount} tardanzas · {$todayRate}%)";
        $mail->Body    = $htmlContent;

        // Plain-text alternative
        $plain  = "REPORTE DE TARDANZAS DEL DÍA\n";
        $plain .= "============================\n\n";
        $plain .= "Fecha: $dateFormatted\n";
        $plain .= "Tolerancia: " . ($reportData['tolerance_minutes'] ?? 10) . " minutos\n\n";
        $plain .= "Tardanzas hoy:          " . $tardyCount . " de " . ($totals['total_entries_today'] ?? 0) . "\n";
        $plain .= "Tasa del día:           " . $todayRate . "%\n";
        $plain .= "Tasa acumulada del mes: " . ($totals['month_rate_pct'] ?? 0) . "% ({$totals['month_late_entries']}/{$totals['month_total_entries']})\n";
        $plain .= "Retraso promedio:       " . ($totals['avg_late_minutes'] ?? 0) . " min\n\n";

        if (!empty($reportData['tardies'])) {
            $plain .= "LISTADO:\n";
            foreach ($reportData['tardies'] as $t) {
                $actual = date('H:i', strtotime($t['actual_entry']));
                $plain .= sprintf("  - %-40s [%s] prog %s / real %s / +%d min\n",
                    $t['full_name'], $t['department'], $t['scheduled_entry'], $actual, $t['late_minutes']);
            }
        } else {
            $plain .= "Sin tardanzas hoy. ¡Excelente!\n";
        }

        if (!empty($reportData['recurring'])) {
            $plain .= "\nRECURRENTES DEL MES (3+ días):\n";
            foreach ($reportData['recurring'] as $o) {
                $plain .= sprintf("  - %s: %d días\n", $o['full_name'], $o['count']);
            }
        }

        $plain .= "\n---\nSistema de Control de Asistencia - " . ($config['app_name'] ?? '') . "\n";
        $plain .= "Generado: " . ($reportData['generated_at'] ?? date('Y-m-d H:i:s')) . "\n";

        $mail->AltBody = $plain;

        $mail->send();

        return ['success' => true, 'message' => "Reporte enviado a $validRecipients destinatario(s)"];

    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error al enviar email: ' . $e->getMessage()];
    }
}

/**
 * Send daily payroll month-to-date report via email (with Excel attachment).
 *
 * @param string      $htmlContent     Rendered HTML body.
 * @param array       $recipients      List of email addresses.
 * @param array       $reportData      Report data from generateDailyPayrollReport().
 * @param string|null $attachmentPath  Absolute path of the .xls file to attach, or null to skip.
 * @return array{success: bool, message: string}
 */
function sendDailyPayrollReport($htmlContent, $recipients, $reportData, $attachmentPath = null) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';

        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No se especificaron destinatarios'];
        }
        if (empty($htmlContent)) {
            return ['success' => false, 'message' => 'El contenido del reporte está vacío'];
        }

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = $config['charset'];

        $mail->setFrom($config['from_email'], $config['from_name']);

        $validRecipients = 0;
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($recipient);
                $validRecipients++;
            }
        }
        if ($validRecipients === 0) {
            return ['success' => false, 'message' => 'No se encontraron destinatarios válidos'];
        }

        // Attach Excel if provided (detect MIME by extension: .xlsx uses OOXML MIME)
        if ($attachmentPath && is_string($attachmentPath) && file_exists($attachmentPath)) {
            $attachName = basename($attachmentPath);
            $ext = strtolower(pathinfo($attachmentPath, PATHINFO_EXTENSION));
            $mime = $ext === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'application/vnd.ms-excel';
            $mail->addAttachment($attachmentPath, $attachName, 'base64', $mime);
        }

        $period   = $reportData['period_label'] ?? (($reportData['start_date'] ?? '') . ' al ' . ($reportData['end_date'] ?? ''));
        $totals   = $reportData['totals']    ?? [];
        $payUsd   = number_format((float) ($totals['pay_usd'] ?? 0), 2);
        $payDop   = number_format((float) ($totals['pay_dop'] ?? 0), 2);

        $mail->isHTML(true);
        $mail->Subject = "Corte diario de nómina - $period (USD \$$payUsd · RD\$$payDop)";
        $mail->Body    = $htmlContent;

        // Plain-text alternative
        $plain  = "CORTE DIARIO DE NÓMINA ACUMULADA\n";
        $plain .= "=================================\n\n";
        $plain .= "Período: $period\n";
        $plain .= "Pago acumulado USD: \$$payUsd\n";
        $plain .= "Pago acumulado DOP: RD\$$payDop\n";
        $plain .= "Horas productivas:  " . number_format((float) ($totals['hours'] ?? 0), 2) . "\n";
        $plain .= "Colaboradores con actividad: " . ($totals['users_with_rows'] ?? 0) . "\n\n";

        if (!empty($reportData['by_department'])) {
            $plain .= "POR DEPARTAMENTO:\n";
            foreach ($reportData['by_department'] as $d) {
                $plain .= sprintf("  - %-30s | %3d colabs | %7.2f hrs | \$%10.2f USD | RD\$%10.2f DOP\n",
                    $d['name'], $d['users'], $d['hours'], $d['pay_usd'], $d['pay_dop']);
            }
            $plain .= "\n";
        }

        if (!empty($reportData['by_user'])) {
            $plain .= "TOP 15 COLABORADORES (USD):\n";
            foreach (array_slice($reportData['by_user'], 0, 15) as $u) {
                $plain .= sprintf("  - %-35s [%s] %7.2f hrs | \$%10.2f USD | RD\$%10.2f DOP\n",
                    $u['full_name'], $u['department'], $u['hours'], $u['pay_usd'], $u['pay_dop']);
            }
        }

        $plain .= "\n(Se adjunta el Excel con el detalle completo día por día.)\n";
        $plain .= "\n---\nSistema de Control de Asistencia - " . ($config['app_name'] ?? '') . "\n";
        $plain .= "Generado: " . ($reportData['generated_at'] ?? date('Y-m-d H:i:s')) . "\n";

        $mail->AltBody = $plain;

        $mail->send();

        $msg = "Reporte enviado a $validRecipients destinatario(s)";
        if ($attachmentPath && file_exists($attachmentPath)) {
            $msg .= ' con Excel adjunto.';
        }
        return ['success' => true, 'message' => $msg];

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error al enviar email: ' . $e->getMessage()
        ];
    }
}

/**
 * Send daily quality alerts report via email.
 *
 * @param string $htmlContent Rendered HTML body.
 * @param array  $recipients  List of email addresses.
 * @param array  $reportData  Report data array from generateDailyQualityAlertsReport().
 * @return array{success: bool, message: string}
 */
function sendDailyQualityAlertsReport($htmlContent, $recipients, $reportData) {
    try {
        $config = require __DIR__ . '/../config/email_config.php';

        if (empty($recipients)) {
            return ['success' => false, 'message' => 'No se especificaron destinatarios'];
        }
        if (empty($htmlContent)) {
            return ['success' => false, 'message' => 'El contenido del reporte está vacío'];
        }

        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = $config['charset'];

        $mail->setFrom($config['from_email'], $config['from_name']);

        $validRecipients = 0;
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($recipient);
                $validRecipients++;
            }
        }
        if ($validRecipients === 0) {
            return ['success' => false, 'message' => 'No se encontraron destinatarios válidos'];
        }

        $dateFormatted = $reportData['date_formatted'] ?? ($reportData['date'] ?? date('Y-m-d'));
        $totals   = $reportData['totals']    ?? [];
        $threshold = $reportData['threshold'] ?? 80;

        $mail->isHTML(true);
        $mail->Subject = "Alertas Críticas de Calidad - $dateFormatted ({$totals['total_alerts']} alertas)";
        $mail->Body    = $htmlContent;

        // Plain-text alternative
        $plain  = "ALERTAS CRÍTICAS DE CALIDAD\n";
        $plain .= "============================\n\n";
        $plain .= "Fecha: $dateFormatted\n";
        $plain .= "Umbral: {$threshold}%\n";
        $plain .= "Total alertas: "       . ($totals['total_alerts']       ?? 0) . "\n";
        $plain .= "Agentes afectados: "   . ($totals['agents_affected']    ?? 0) . "\n";
        $plain .= "Campañas afectadas: "  . ($totals['campaigns_affected'] ?? 0) . "\n";
        $plain .= "Score promedio: "      . ($totals['avg_score']          ?? 0) . "%\n\n";

        foreach (($reportData['by_agent'] ?? []) as $ag) {
            $camps = $ag['campaigns'] ? ' · ' . implode(', ', $ag['campaigns']) : '';
            $plain .= "- {$ag['full_name']} [{$ag['username']}]: {$ag['count']} alertas · min " . number_format($ag['min_score'], 2) . "% · avg " . number_format($ag['avg_score'], 2) . "%{$camps}\n";
        }

        $plain .= "\n---\nSistema de Control de Asistencia - " . ($config['app_name'] ?? '') . "\n";
        $plain .= "Generado: " . ($reportData['generated_at'] ?? date('Y-m-d H:i:s')) . "\n";

        $mail->AltBody = $plain;

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

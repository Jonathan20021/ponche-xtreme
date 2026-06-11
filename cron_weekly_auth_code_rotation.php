<?php
/**
 * Cron Job: Rotación Semanal de Código de Autorización
 *
 * Genera un nuevo código de autorización temporal cada semana (por defecto
 * los lunes), desactiva el código de la semana anterior y envía el nuevo
 * código por correo a los destinatarios configurados en settings.
 *
 * Toda la configuración (día, hora, destinatarios, largo del código) se
 * administra desde Configuración > Códigos de Autorización en settings.php.
 *
 * Configuración de Cron (cPanel o crontab) - lunes 7:00 AM:
 * 0 7 * * 1 /usr/bin/php /path/to/ponche-xtreme/cron_weekly_auth_code_rotation.php
 *
 * O con wget/curl (también lunes 7:00 AM):
 * 0 7 * * 1 wget -q -O - "https://yourdomain.com/cron_weekly_auth_code_rotation.php?cron_key=ponche_xtreme_2025"
 *
 * Ejecución manual inmediata (ignora día/hora configurados):
 * https://yourdomain.com/cron_weekly_auth_code_rotation.php?cron_key=ponche_xtreme_2025&force=1
 */

// Prevenir ejecución desde navegador (solo CLI o cron)
if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $cronKey = $_GET['cron_key'] ?? '';
    $expectedKey = 'ponche_xtreme_2025';

    if ($cronKey !== $expectedKey) {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/authorization_functions.php';
require_once __DIR__ . '/lib/email_functions.php';

date_default_timezone_set('America/Santo_Domingo'); // GMT-4

$logPrefix = "[CRON WEEKLY AUTH CODE] ";
$force = isset($_GET['force']) && $_GET['force'] === '1';

try {
    echo $logPrefix . "Starting weekly auth code rotation at " . date('Y-m-d H:i:s') . "\n";

    $config = getWeeklyAuthRotationConfig($pdo);

    if (!$config['enabled']) {
        echo $logPrefix . "Weekly auth code rotation is disabled in settings. Exiting.\n";
        exit(0);
    }

    if (!$force) {
        // Verificar día configurado (1=Lunes ... 7=Domingo)
        $currentDay = (int) date('N');
        if ($currentDay !== $config['day']) {
            echo $logPrefix . "Not the configured rotation day (configured: {$config['day']}, today: $currentDay). Exiting.\n";
            exit(0);
        }

        // Verificar hora configurada (con tolerancia de ±10 minutos, solo CLI)
        if (php_sapi_name() === 'cli') {
            $configuredParts = explode(':', $config['time']);
            $configuredTotalMinutes = ((int) ($configuredParts[0] ?? 7) * 60) + (int) ($configuredParts[1] ?? 0);
            $currentTotalMinutes = ((int) date('H') * 60) + (int) date('i');
            $diff = abs($currentTotalMinutes - $configuredTotalMinutes);

            if ($diff > 10) {
                echo $logPrefix . "Not the configured time yet (configured: {$config['time']}). Difference: $diff minutes. Exiting.\n";
                exit(0);
            }
        }

        // Evitar doble rotación el mismo día
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) FROM authorization_codes
            WHERE code_name = ? AND DATE(created_at) = CURDATE()
        ");
        $checkStmt->execute([WEEKLY_AUTH_CODE_NAME]);
        if ((int) $checkStmt->fetchColumn() > 0) {
            echo $logPrefix . "A weekly code was already generated today. Exiting.\n";
            exit(0);
        }
    }

    // Rotar el código (desactiva el anterior y crea uno nuevo)
    echo $logPrefix . "Rotating weekly authorization code...\n";
    $result = rotateWeeklyAuthorizationCode($pdo, null);

    if (!$result['success']) {
        echo $logPrefix . "❌ Failed to rotate code: {$result['message']}\n";
        exit(1);
    }

    echo $logPrefix . "New code generated (ID: {$result['code_id']}), valid until {$result['valid_until']}.\n";

    // Enviar por correo
    $recipients = $result['recipients'];
    if (empty($recipients)) {
        echo $logPrefix . "⚠️ No valid recipients configured. Code rotated but NOT emailed.\n";
    } else {
        echo $logPrefix . "Sending code to: " . implode(', ', $recipients) . "\n";
        $emailResult = sendWeeklyAuthorizationCodeEmail(
            [
                'code' => $result['code'],
                'valid_from' => $result['valid_from'],
                'valid_until' => $result['valid_until'],
            ],
            $recipients
        );

        if ($emailResult['success']) {
            echo $logPrefix . "✅ {$emailResult['message']}\n";
        } else {
            echo $logPrefix . "❌ Email failed: {$emailResult['message']}\n";
        }
    }

    // Registrar en auditoría
    try {
        require_once __DIR__ . '/lib/logging_functions.php';
        log_custom_action(
            $pdo,
            0, // System user
            'CRON System',
            'system',
            'authorization',
            'rotate',
            "Código de autorización semanal rotado automáticamente (vence: {$result['valid_until']})",
            'authorization_code',
            $result['code_id'],
            [
                'recipients_count' => count($recipients),
                'valid_until' => $result['valid_until'],
                'automated' => !$force
            ]
        );
    } catch (Exception $e) {
        echo $logPrefix . "Warning: Could not log action: " . $e->getMessage() . "\n";
    }

    echo $logPrefix . "Done.\n";
    exit(0);

} catch (Exception $e) {
    echo $logPrefix . "❌ ERROR: " . $e->getMessage() . "\n";
    error_log($logPrefix . "Error in cron job: " . $e->getMessage());
    exit(1);
}

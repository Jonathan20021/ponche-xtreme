<?php
/**
 * Cron Job: Daily Absence Report
 * Este script debe ejecutarse diariamente a las 8:00 AM GMT-4
 * 
 * Configuración de Cron (cPanel o crontab):
 * 0 8 * * * /usr/bin/php /path/to/ponche-xtreme/cron_daily_absence_report.php
 * 
 * O con wget/curl:
 * 0 8 * * * wget -q -O - https://yourdomain.com/ponche-xtreme/cron_daily_absence_report.php
 */

// Prevenir ejecución desde navegador (solo CLI o cron)
if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_KEY'])) {
    // Opcionalmente permite ejecución web con clave secreta
    $cronKey = $_GET['cron_key'] ?? '';
    $expectedKey = 'ponche_xtreme_2025'; // Cambiar por una clave segura
    
    if ($cronKey !== $expectedKey) {
        http_response_code(403);
        die('Access denied. This script should be run via cron job.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/daily_absence_report.php';

// Set timezone
date_default_timezone_set('America/Santo_Domingo'); // GMT-4

$logPrefix = "[CRON ABSENCE REPORT] ";

try {
    echo $logPrefix . "Starting daily absence report at " . date('Y-m-d H:i:s') . "\n";
    
    // Check if report is enabled
    $stmt = $pdo->prepare("
        SELECT setting_value 
        FROM system_settings 
        WHERE setting_key = 'absence_report_enabled'
    ");
    $stmt->execute();
    $enabled = $stmt->fetchColumn();
    
    if ($enabled !== '1') {
        echo $logPrefix . "Absence report is disabled in settings. Exiting.\n";
        exit(0);
    }
    
    // Get configured time
    $stmt = $pdo->prepare("
        SELECT setting_value 
        FROM system_settings 
        WHERE setting_key = 'absence_report_time'
    ");
    $stmt->execute();
    $configuredTime = $stmt->fetchColumn() ?: '08:00';
    
    echo $logPrefix . "Configured time: $configuredTime\n";
    echo $logPrefix . "Current time: " . date('H:i') . "\n";
    
    // Verificar si es la hora correcta (con tolerancia de ±5 minutos)
    $currentHour = (int)date('H');
    $currentMinute = (int)date('i');
    $configuredParts = explode(':', $configuredTime);
    $configuredHour = (int)($configuredParts[0] ?? 8);
    $configuredMinute = (int)($configuredParts[1] ?? 0);
    
    $currentTotalMinutes = ($currentHour * 60) + $currentMinute;
    $configuredTotalMinutes = ($configuredHour * 60) + $configuredMinute;
    $diff = abs($currentTotalMinutes - $configuredTotalMinutes);
    
    if ($diff > 5 && php_sapi_name() === 'cli') {
        echo $logPrefix . "Not the configured time yet. Difference: $diff minutes. Exiting.\n";
        exit(0);
    }
    
    // Get recipients
    $recipients = getReportRecipients($pdo);
    
    if (empty($recipients)) {
        echo $logPrefix . "No recipients configured. Exiting.\n";
        exit(0);
    }
    
    echo $logPrefix . "Recipients found: " . count($recipients) . "\n";
    echo $logPrefix . "Recipients: " . implode(', ', $recipients) . "\n";
    
    // Generate report
    echo $logPrefix . "Generating report...\n";
    $reportData = generateDailyAbsenceReport($pdo);
    
    echo $logPrefix . "Report generated:\n";
    echo $logPrefix . "  - Total employees: {$reportData['total_employees']}\n";
    echo $logPrefix . "  - Total absences: {$reportData['total_absences']}\n";
    echo $logPrefix . "  - Without justification: " . count($reportData['absences_without_justification']) . "\n";
    echo $logPrefix . "  - With justification: " . count($reportData['absences_with_justification']) . "\n";
    
    // Send email
    echo $logPrefix . "Sending report via email...\n";
    $sent = sendReportByEmail($pdo, $reportData, $recipients);
    
    if ($sent) {
        echo $logPrefix . "✅ Report sent successfully!\n";
        
        // Log the automated send
        try {
            require_once __DIR__ . '/lib/logging_functions.php';
            log_custom_action(
                $pdo,
                0, // System user
                'CRON System',
                'system',
                'reports',
                'send',
                "Reporte de ausencias enviado automáticamente",
                'absence_report',
                null,
                [
                    'recipients_count' => count($recipients),
                    'total_absences' => $reportData['total_absences'],
                    'date' => $reportData['date'],
                    'automated' => true
                ]
            );
        } catch (Exception $e) {
            echo $logPrefix . "Warning: Could not log action: " . $e->getMessage() . "\n";
        }
        
        exit(0);
    } else {
        echo $logPrefix . "❌ Failed to send report. Check logs for details.\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo $logPrefix . "❌ ERROR: " . $e->getMessage() . "\n";
    echo $logPrefix . "Stack trace: " . $e->getTraceAsString() . "\n";
    error_log($logPrefix . "Error in cron job: " . $e->getMessage());
    exit(1);
}

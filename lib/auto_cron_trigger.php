<?php
/**
 * Auto Cron Trigger
 * Este archivo se incluye en páginas principales para ejecutar automáticamente
 * el reporte de ausencias una vez al día sin necesidad de cron jobs externos.
 */

// Solo ejecutar si no estamos en CLI
if (php_sapi_name() === 'cli') {
    return;
}

// Archivo para guardar la última ejecución
$lastRunFile = __DIR__ . '/../cache/last_absence_report_run.txt';
$cacheDir = __DIR__ . '/../cache';

// Crear directorio cache si no existe
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

// Verificar si ya se ejecutó hoy
$today = date('Y-m-d');
$lastRun = file_exists($lastRunFile) ? trim(file_get_contents($lastRunFile)) : '';

// Si ya se ejecutó hoy, no hacer nada
if ($lastRun === $today) {
    return;
}

// Verificar si es la hora configurada (con tolerancia de 1 hora)
$currentHour = (int)date('H');
$targetHour = 6; // 6 AM

// Solo ejecutar entre las 6:00 AM y 7:00 AM
if ($currentHour !== $targetHour) {
    return;
}

// Ejecutar el reporte en segundo plano (sin bloquear la página)
try {
    // Marcar como ejecutado ANTES de ejecutar para evitar múltiples ejecuciones
    file_put_contents($lastRunFile, $today);
    
    // Ejecutar en segundo plano usando exec
    $scriptPath = realpath(__DIR__ . '/../cron_daily_absence_report.php');
    
    if ($scriptPath && file_exists($scriptPath)) {
        // Ejecutar en segundo plano sin esperar respuesta
        if (stripos(PHP_OS, 'WIN') === 0) {
            // Windows
            pclose(popen("start /B php \"$scriptPath\" > NUL 2>&1", "r"));
        } else {
            // Linux/Unix
            exec("php \"$scriptPath\" > /dev/null 2>&1 &");
        }
        
        // Log opcional
        error_log("[AUTO CRON] Triggered absence report at " . date('Y-m-d H:i:s'));
    }
} catch (Exception $e) {
    error_log("[AUTO CRON] Error: " . $e->getMessage());
}

<?php
/**
 * Script de prueba para el nuevo diseño del reporte de ausencias
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';
require_once 'lib/daily_absence_report.php';
require_once 'lib/email_functions.php';

echo "=== Prueba de Nuevo Diseño de Reporte de Ausencias ===\n\n";

// Generar reporte
echo "1. Generando reporte...\n";
$reportData = generateDailyAbsenceReport($pdo);

echo "2. Datos del reporte:\n";
echo "   - Total empleados: {$reportData['total_employees']}\n";
echo "   - Total ausencias: {$reportData['total_absences']}\n";
echo "   - Sin justificar: " . count($reportData['absences_without_justification']) . "\n";
echo "   - Justificadas: " . count($reportData['absences_with_justification']) . "\n\n";

// Obtener destinatarios
echo "3. Obteniendo destinatarios...\n";
$recipients = getReportRecipients($pdo);
echo "   - Destinatarios: " . implode(', ', $recipients) . "\n\n";

if (empty($recipients)) {
    echo "ERROR: No hay destinatarios configurados.\n";
    exit;
}

// Generar HTML
echo "4. Generando HTML con nuevo diseño...\n";
$html = generateReportHTML($reportData);
echo "   - Tamaño del HTML: " . strlen($html) . " bytes\n";
echo "   - Contiene tablas: " . (strpos($html, '<table>') !== false ? 'SI' : 'NO') . "\n";
echo "   - Contiene badges: " . (strpos($html, 'badge') !== false ? 'SI' : 'NO') . "\n";
echo "   - Contiene stats-grid: " . (strpos($html, 'stats-grid') !== false ? 'SI' : 'NO') . "\n\n";

// Enviar email
echo "5. Enviando correo con nuevo diseño...\n";
$result = sendDailyAbsenceReport($html, $recipients, $reportData);

if ($result['success']) {
    echo "   ✓ EXITO: " . $result['message'] . "\n";
    echo "\nRevisa tu correo en: " . implode(', ', $recipients) . "\n";
    echo "\nEl nuevo diseño incluye:\n";
    echo "  - Grid de estadísticas con 4 tarjetas de colores\n";
    echo "  - Tablas profesionales con todos los datos de empleados\n";
    echo "  - Código, nombre, puesto, departamento\n";
    echo "  - Badges de estado (Sin Justificar, Permiso, Vacaciones, Licencia)\n";
    echo "  - Detalles completos de justificaciones con fechas y estados\n";
    echo "  - Diseño responsive para móvil\n";
} else {
    echo "   ✗ ERROR: " . $result['message'] . "\n";
}

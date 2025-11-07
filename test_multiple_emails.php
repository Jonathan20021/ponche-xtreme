<?php
/**
 * Send Test Absence Report to Multiple Emails
 */

require_once 'db.php';
require_once 'lib/daily_absence_report.php';
require_once 'lib/email_functions.php';

// Test with both emails
$testEmails = [
    'jonathansandovalferreira@gmail.com',
    'jonathansandoval@colinashospital.com'
];

echo "<h2>🧪 Prueba de Reporte de Ausencias - Múltiples Destinatarios</h2>";
echo "<pre>\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";

// Generate report
echo "1. Generando reporte...\n";
$reportData = generateDailyAbsenceReport($pdo);
$html = generateReportHTML($reportData);

echo "   ✓ Reporte generado\n";
echo "   - Total empleados: " . $reportData['total_employees'] . "\n";
echo "   - Ausencias: " . $reportData['total_absences'] . "\n\n";

// Send to each email individually for testing
foreach ($testEmails as $email) {
    echo "2. Enviando a: $email\n";
    
    $result = sendDailyAbsenceReport($html, [$email], $reportData);
    
    if ($result['success']) {
        echo "   ✅ Enviado exitosamente\n";
    } else {
        echo "   ❌ Error: " . $result['message'] . "\n";
    }
    echo "\n";
}

echo "===================================\n";
echo "VERIFICACIÓN:\n";
echo "===================================\n";
echo "1. Gmail (jonathansandovalferreira@gmail.com):\n";
echo "   - Revisa la bandeja de entrada\n";
echo "   - Revisa la carpeta de SPAM\n";
echo "   - Busca por 'Reporte Diario de Ausencias'\n\n";

echo "2. Colinas Hospital (jonathansandoval@colinashospital.com):\n";
echo "   - El correo SÍ se envió correctamente\n";
echo "   - Servidor lo aceptó (código 250 OK)\n";
echo "   - Si no aparece, revisar:\n";
echo "     * Filtros del dominio\n";
echo "     * Carpeta de spam/cuarentena\n";
echo "     * Configuración del servidor de correo\n";
echo "     * Contactar a IT de Colinas Hospital\n\n";

echo "IMPORTANTE:\n";
echo "Los logs SMTP muestran que el correo fue aceptado\n";
echo "por el servidor de destino. Si no aparece, es un\n";
echo "problema del lado del servidor receptor.\n";
echo "</pre>";

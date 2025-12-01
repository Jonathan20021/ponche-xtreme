<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';
require_once '../lib/payroll_email_functions.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Check permissions
ensurePermission('hr_payroll', '../unauthorized.php');

$periodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;
$employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$format = isset($_GET['format']) ? $_GET['format'] : 'html';

if (!$periodId || !$employeeId) {
    die('Parámetros requeridos no especificados');
}

// Get payroll data
$payrollData = getEmployeePayrollData($pdo, $periodId, $employeeId);

if (!$payrollData) {
    die('Datos de nómina no encontrados');
}

// If PDF format requested, generate PDF
if ($format === 'pdf') {
    $htmlContent = generatePayrollSlipHTML($payrollData);
    
    // Configure Dompdf
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($htmlContent);
    $dompdf->setPaper('Letter', 'portrait');
    $dompdf->render();
    
    // Output PDF
    $filename = 'Volante_' . str_replace(' ', '_', $payrollData['employee_code']) . '_' . str_replace(' ', '_', $payrollData['period_name']) . '.pdf';
    $dompdf->stream($filename, ['Attachment' => false]);
    exit;
}

// Display HTML version
echo generatePayrollSlipHTML($payrollData);
?>

<script>
// Add print functionality
document.addEventListener('DOMContentLoaded', function() {
    // Add print button
    const container = document.querySelector('.container');
    if (container) {
        const printButton = document.createElement('div');
        printButton.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 1000; display: flex; gap: 10px;';
        printButton.innerHTML = `
            <button onclick="window.print()" style="background: #2563eb; color: white; border: none; padding: 10px 15px; border-radius: 5px; cursor: pointer; font-size: 14px;">
                <i class="fas fa-print"></i> Imprimir
            </button>
            <a href="?period_id=<?= $periodId ?>&employee_id=<?= $employeeId ?>&format=pdf" target="_blank" style="background: #dc2626; color: white; text-decoration: none; padding: 10px 15px; border-radius: 5px; font-size: 14px;">
                <i class="fas fa-file-pdf"></i> PDF
            </a>
            <button onclick="window.close()" style="background: #6b7280; color: white; border: none; padding: 10px 15px; border-radius: 5px; cursor: pointer; font-size: 14px;">
                <i class="fas fa-times"></i> Cerrar
            </button>
        `;
        document.body.appendChild(printButton);
    }
});

// Print styles
const printStyles = `
    @media print {
        .print-button { display: none !important; }
        body { margin: 0; }
        .container { box-shadow: none; margin: 0; }
    }
`;
const styleSheet = document.createElement('style');
styleSheet.textContent = printStyles;
document.head.appendChild(styleSheet);
</script>

<?php
session_start();
require_once '../db.php';
require_once '../vendor/autoload.php';
require_once '../lib/contract_documents.php';

use Dompdf\Dompdf;
use Dompdf\Options;

ensurePermission('hr_employees', '../unauthorized.php');

// Get data from session
$contractData = $_SESSION['contract_data'] ?? null;

if (!$contractData) {
    header('Location: contracts.php');
    exit;
}

$employeeName = $contractData['employee_name'];
$idCard = $contractData['id_card'];
$idType = isset($contractData['id_type']) && strtoupper($contractData['id_type']) === 'PASAPORTE' ? 'PASAPORTE' : 'CEDULA';
$contractDate = $contractData['contract_date'];

$isPassport = $idType === 'PASAPORTE';
$documentIntroText = $isPassport
    ? "provisto(a) del pasaporte No. <strong>$idCard</strong>"
    : "provisto de la cédula de identidad No. <strong>$idCard</strong>";
$documentShortLabel = $isPassport ? 'Pasaporte' : 'Cédula';

// Format date for contract
$dateObj = new DateTime($contractDate);
$months = [
    1 => 'enero',
    2 => 'febrero',
    3 => 'marzo',
    4 => 'abril',
    5 => 'mayo',
    6 => 'junio',
    7 => 'julio',
    8 => 'agosto',
    9 => 'septiembre',
    10 => 'octubre',
    11 => 'noviembre',
    12 => 'diciembre'
];
$day = $dateObj->format('d');
$month = $months[(int) $dateObj->format('m')];
$year = $dateObj->format('Y');

// El cuerpo del documento vive en lib/contract_documents.php, la MISMA fuente
// que usa view_contract.php. Antes cada pantalla armaba su propio HTML y por eso
// los contratos de confidencialidad guardados se veian con el texto del contrato
// de trabajo al abrirlos desde el listado.
$html = buildConfidentialityContractHtml([
    'employee_name' => $employeeName,
    'id_card'       => $idCard,
    'id_type'       => $idType,
    'contract_date' => $contractDate,
]);

// Generate PDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Times New Roman');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('Letter', 'portrait');
$dompdf->render();

// Output PDF
$filename = 'Contrato_Confidencialidad_' . str_replace(' ', '_', $employeeName) . '_' . date('Y-m-d') . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);

<?php
session_start();
require_once '../db.php';
require_once '../vendor/autoload.php';

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
$contractDate = $contractData['contract_date'];

// Format date for contract
$dateObj = new DateTime($contractDate);
$months = [
    1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
    5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
    9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
];
$day = $dateObj->format('d');
$month = $months[(int)$dateObj->format('m')];
$year = $dateObj->format('Y');

// Prepare logo for embedding in PDF
$logoPath = dirname(__DIR__) . '/assets/logo.png';
$logoData = '';
if (file_exists($logoPath)) {
    $logoData = base64_encode(file_get_contents($logoPath));
}

// Generate HTML for Confidentiality Contract PDF
$html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 2cm;
        }
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 11pt;
            line-height: 1.6;
            text-align: justify;
            color: #000;
        }
        .header-logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .header-logo img {
            max-height: 60px;
            width: auto;
        }
        h1 {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 10px;
        }
        h2 {
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 20px;
        }
        p {
            margin-bottom: 12px;
        }
        .section-title {
            font-weight: bold;
            margin-top: 15px;
            margin-bottom: 10px;
            text-align: center;
        }
        ul {
            margin-left: 40px;
            margin-bottom: 12px;
        }
        li {
            margin-bottom: 6px;
        }
        .signature-section {
            margin-top: 60px;
        }
        .signature-line {
            border-top: 2px solid #000;
            width: 300px;
            margin: 40px 0 5px 0;
        }
        .underline {
            border-bottom: 1px solid #000;
            display: inline-block;
            min-width: 200px;
        }
    </style>
</head>
<body>
HTML;

if ($logoData) {
    $html .= <<<HTML
    <div class="header-logo">
        <img src="data:image/png;base64,$logoData" alt="Evallish BPO Logo">
    </div>
HTML;
}

$html .= <<<HTML
    <h1>CONTRATO DE CONFIDENCIALIDAD</h1>
    <h2>EVALLISH SRL. RNC 1-3263745-3</h2>
    
    <p><strong>ENTRE: EVALLISH SRL.</strong>, empresa constituida y existente de conformidad con las leyes dominicanas y debidamente representada por el Sr. Hugo Hidalgo cédula 031-0411132-7 identificado con el RNC No. 1-3263745-3, con su domicilio y asiento social en la Calle Proyecto 4 No. 6, Reparto Oquet, de esta ciudad de Santiago de los Caballeros, en lo adelante llamada <strong>EL EMPLEADOR</strong> y <strong>$employeeName</strong>, mayor de edad, domiciliado y residente en la ciudad de Santiago de los Caballeros, provisto de la cédula de identidad No. <strong>$idCard</strong>, en lo adelante llamado <strong>EL EMPLEADO</strong>.</p>

    <p class="section-title">SE HA PACTADO LO SIGUIENTE:</p>

    <p class="section-title">ACUERDO DE CONFIDENCIALIDAD Y PROTECCIÓN DE INFORMACIÓN SENSIBLE<br>EVALLISH SRL</p>

    <p><strong>PRIMERO:</strong> El colaborador reconoce que, durante el desempeño de sus funciones, tendrá acceso a información confidencial, sensible y estratégica de la empresa y de sus clientes, incluyendo, pero no limitado a: bases de datos, procesos operativos, estrategias comerciales, documentación interna, políticas, listas de clientes, precios, negociaciones, reportes financieros, conversaciones internas y cualquier dato relacionado con la operación de Evallish SRL o de sus clientes.</p>

    <p><strong>SEGUNDO:</strong> El colaborador se compromete a no divulgar, compartir, reproducir, copiar o utilizar dicha información confidencial para beneficio propio, de terceros o de empresas competidoras, ni durante la relación laboral ni por un período de <strong>doce (12) meses</strong> posteriores a la finalización de su contrato, conforme al <strong>artículo 88, numeral 14 del Código de Trabajo de la República Dominicana</strong>, que establece como falta grave:</p>

    <p style="font-style: italic; margin-left: 40px;">"Revelar los secretos de fabricación o dar a conocer asuntos de carácter reservado, con perjuicio de la empresa."</p>

    <p><strong>TERCERO:</strong> Esta obligación de confidencialidad aplica a cualquier medio o canal de comunicación, incluyendo pero no limitado a:</p>

    <ul>
        <li>Documentos físicos o digitales.</li>
        <li>Conversaciones verbales.</li>
        <li>Mensajes de texto en grupos de WhatsApp internos de la empresa o con clientes.</li>
        <li>Llamadas telefónicas.</li>
        <li>Sesiones de calidad, feedback o coaching.</li>
        <li>Correos electrónicos y plataformas de mensajería.</li>
        <li>Cualquier sistema, aplicación o software utilizado por la empresa para gestionar su operación.</li>
    </ul>

    <p><strong>CUARTO:</strong> Al finalizar la relación laboral, el colaborador se compromete a devolver de inmediato todos los documentos, equipos, dispositivos o materiales físicos o digitales que contengan información confidencial. Asimismo, deberá <strong>eliminar cualquier información confidencial almacenada en dispositivos personales</strong>, incluyendo chats, correos o notas relacionadas con la empresa o sus clientes. Esta devolución y eliminación son requisitos indispensables para el cierre formal del contrato laboral y la entrega de cualquier certificación laboral.</p>

    <p><strong>QUINTO:</strong> El incumplimiento de este acuerdo constituye una <strong>falta grave</strong>, sancionable conforme al reglamento interno y al <strong>Código de Trabajo</strong>, con medidas como:</p>

    <ul>
        <li>Amonestación escrita.</li>
        <li>Suspensión sin disfrute de salario.</li>
        <li><strong>Despido inmediato por causa justificada</strong>, conforme al artículo 88 del Código de Trabajo.</li>
    </ul>

    <p><strong>SEXTO:</strong> El uso de celulares personales está permitido exclusivamente al <strong>personal administrativo</strong>, siempre que no afecte el desempeño laboral ni implique manejo de información confidencial. En áreas operativas, el uso de celulares personales está prohibido salvo autorización expresa de la gerencia.</p>

    <p><strong>SÉPTIMO:</strong> El colaborador no podrá, directa o indirectamente, utilizar la información confidencial obtenida durante su relación laboral para beneficio de empresas competidoras ni para su beneficio personal, por un período de <strong>doce (12) meses</strong> después de finalizado el contrato. Cualquier intento de ofrecer o prestar servicios a clientes o proveedores clave de Evallish SRL basándose en información adquirida dentro de la empresa.</p>

    <p><strong>OCTAVO:</strong> Las violaciones precedentemente indicadas están previstas y sancionadas por la <strong>Ley No. 53-07</strong>, del 23 de abril de 2008, sobre Crímenes y Delitos de Alta Tecnología. Al firmar el presente contrato, <strong>EL EMPLEADO</strong> concede a <strong>EL EMPLEADOR</strong> el derecho de iniciar las acciones legales correspondientes ante cualquier violación a este acuerdo de confidencialidad.</p>

    <p><strong>NOVENO:</strong> La violación de cualquiera de las cláusulas contenidas en este documento autoriza a Evallish SRL a proceder con acciones legales y demandas por daños y perjuicios, sin perjuicio de las sanciones laborales aplicables.</p>

    <div class="signature-section">
        <p><strong>Leído y aceptado por:</strong></p>
        
        <p style="margin-top: 30px;">Colaborador: <strong>$employeeName</strong></p>
        <p>Cédula: <strong>$idCard</strong></p>
        <p>Fecha: <strong>$day de $month de $year</strong></p>

        <div style="margin-top: 35px;">
            <p style="margin-bottom: 8px;"><strong>Firma del empleado:</strong></p>
            <div class="signature-line"></div>
            <p style="margin-top: 0;">$employeeName</p>
        </div>
        
        <p style="margin-top: 40px;">Por Evallish SRL: <strong>Hugo Antonio Hidalgo Núñez</strong></p>
        <p>Cargo: <strong>Gerente General</strong></p>
        <p>Fecha: <strong>$day de $month de $year</strong></p>
    </div>
</body>
</html>
HTML;

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

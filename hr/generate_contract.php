<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../db.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

ensurePermission('hr_employees', '../unauthorized.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contracts.php');
    exit;
}

// Log the request for debugging
error_log("Contract generation started for: " . ($_POST['employee_name'] ?? 'unknown'));

// Validate required fields
$requiredFields = ['employee_name', 'id_card', 'province', 'position', 'salary', 'payment_type', 'work_schedule', 'contract_date', 'city'];
foreach ($requiredFields as $field) {
    if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
        die("Error: El campo '$field' es requerido.");
    }
}

// Get manual input data from form
$employeeName = trim($_POST['employee_name']);
$idCard = trim($_POST['id_card']);
$province = trim($_POST['province']);
$position = trim($_POST['position']);
$salary = (float) $_POST['salary'];
$paymentType = trim($_POST['payment_type']);
$workSchedule = trim($_POST['work_schedule']);
$contractDate = $_POST['contract_date'];
$city = trim($_POST['city']);
$action = $_POST['action'] ?? 'employment';

error_log("Processing contract: Action=$action, Employee=$employeeName");

// Save contract to database (employee_id is NULL for manual contracts)
$contractType = ($action === 'confidentiality') ? 'CONFIDENCIALIDAD' : 'TRABAJO';

try {
    // Check if payment_type column exists, if not add it
    try {
        $pdo->exec("ALTER TABLE employment_contracts ADD COLUMN payment_type VARCHAR(20) DEFAULT 'mensual' AFTER salary");
    } catch (PDOException $e) {
        // Column might already exist, ignore error
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO employment_contracts 
        (employee_id, employee_name, id_card, province, position, contract_date, salary, payment_type, work_schedule, city, contract_type, created_by, created_at)
        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $insertStmt->execute([
        $employeeName,
        $idCard,
        $province,
        $position,
        $contractDate,
        $salary,
        $paymentType,
        $workSchedule,
        $city,
        $contractType,
        $_SESSION['user_id']
    ]);

    $contractId = $pdo->lastInsertId();
    error_log("Contract saved to database with ID: $contractId");
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    die("Error al guardar el contrato en la base de datos: " . $e->getMessage());
}

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

// Prepare logo for embedding in PDF (only if GD is enabled)
$logoData = '';
if (extension_loaded('gd')) {
    $logoPath = dirname(__DIR__) . '/assets/logo.png';
    if (file_exists($logoPath)) {
        $logoData = base64_encode(file_get_contents($logoPath));
    }
}

// Generate HTML for PDF
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
            line-height: 1.5;
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
            margin-bottom: 20px;
        }
        p {
            margin-bottom: 12px;
            text-indent: 0;
        }
        .section-title {
            font-weight: bold;
            margin-top: 15px;
            margin-bottom: 10px;
        }
        .signature-section {
            margin-top: 60px;
            page-break-inside: avoid;
        }
        .signature-line {
            border-top: 2px solid #000;
            width: 250px;
            margin: 50px auto 5px auto;
            text-align: center;
        }
        .signature-name {
            text-align: center;
            font-weight: bold;
            margin-top: 5px;
        }
        .signature-title {
            text-align: center;
            margin-top: 2px;
        }
        .signatures {
            display: table;
            width: 100%;
            margin-top: 60px;
        }
        .signature-col {
            display: table-cell;
            width: 50%;
            text-align: center;
            vertical-align: top;
        }
        ul {
            margin-left: 20px;
        }
        li {
            margin-bottom: 8px;
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
    <h1>CONTRATO DE TRABAJO</h1>
    
    <p><strong>ENTRE: EVALLISH SRL</strong>, empresa constituida y existente de conformidad con las leyes dominicanas, identificada con el RNC No. 1-32637453, con su domicilio principal y asiento social en la Calle 6 No. 6, Reparto Conet de esta ciudad de Santiago, debidamente representada por el señor <strong>Hugo Antonio Hidalgo Núñez</strong>, dominicano, mayor de edad, casado, empresario, portador de la cédula de identidad No.031-0411132-7, domiciliado y residente en esta ciudad la cual sociedad en lo adelante del presente contrato, se denominará <strong>EL EMPLEADOR</strong>; y de la otra parte <strong>$employeeName</strong>, dominicano(a), mayor de edad, (a), residente de la Provincia <strong>$province</strong>, República Dominicana, quien en lo sucesivo se denominará <strong>EL EMPLEADO</strong>, provisto de la cédula de identidad y electoral <strong>No. $idCard</strong> domiciliado(a) y residente en la Provincia <strong>$province</strong>, República Dominicana, quien en lo sucesivo se denominará <strong>EL EMPLEADO</strong>.</p>

    <p class="section-title">SE HA PACTADO LO SIGUIENTE:</p>

    <p><strong>PRIMERO: EL EMPLEADO</strong> se compromete formalmente a prestar sus servicios a <strong>EL EMPLEADOR</strong>, en el desempeño del cargo de <strong>$position</strong>, y en tal calidad, se compromete asimismo a representar a <strong>EL EMPLEADOR</strong> dentro del marco del ejercicio de sus funciones.</p>

    <p><strong>PÁRRAFO I:</strong> El empleado prestará los servicios de asistencia, ventas, encuestas o soporte, vía telefónica o por cualquier otro medio físico o electrónico, a los usuarios o consumidores finales de los clientes que contratan los servicios de <strong>EL EMPLEADOR</strong> de conformidad a los procedimientos y normas dictadas de tiempo en tiempo por la empresa.</p>

    <p><strong>PÁRRAFO II:</strong> Es entendido y acordado entre las partes que <strong>EL EMPLEADO</strong> deberá dar asistencia al Centro Central en cualquier de las líneas de negocios que requiera <strong>EL EMPLEADOR</strong>, siempre y cuando no se vean afectadas negativamente las condiciones salariales vigentes al momento de solicitar el cambio o trato de negocios o proyectos <strong>EL EMPLEADO</strong> reconoce y acepta que negarse a ejecutar dicho cambio se considerará un acto de desobediencia a <strong>EL EMPLEADOR</strong>, sus gerentes, supervisores o representantes respecto del servicio contratado, lo que constituirá una causal de terminación del contrato de trabajo por la vía del despido, según lo establecido la legislación laboral vigente.</p>

    <p><strong>PÁRRAFO III:</strong> Es entendido y acordado que el <strong>EMPLEADO</strong> ha sido evaluado y aprobado en el idioma <strong>Español</strong>. En tal sentido <strong>EL EMPLEADOR</strong> reconoce y acepta que <strong>EL EMPLEADOR</strong> podrá solicitarle que preste los servicios contratados en el idioma indicado anteriormente o cualquier línea de negocios siempre que no se vean disminuidos sus ingresos salariales por hora de labor rendida. La negativa del <strong>EMPLEADO</strong> a prestar los servicios en cualquier de estos idiomas se considerará una insubordinación y desobediencia a su empleador respecto del servicio contratado.</p>

    <p><strong>SEGUNDO:</strong> Como contraprestación a los servicios laborales prestados <strong>EL EMPLEADO</strong> recibirá de <strong>EL EMPLEADOR</strong> la suma de RD$ <strong>$salary</strong> Pesos Dominicanos 
HTML;

if ($paymentType === 'por_hora') {
    $html .= " por cada <strong>hora laborada</strong> (RD$ $salary/hora)";
} else {
    $html .= " <strong>mensuales fijos</strong> (RD$ $salary/mes)";
}

$html .= <<<HTML
, a ser pagada de acuerdo con el horario de trabajo establecido por el <strong>EMPLEADOR</strong>. Sin que en ningún caso el total devengado dentro de un mes sea inferior al salario mínimo base legalmente establecido a este tipo de empresa.</p>


    <p><strong>TERCERO: EL EMPLEADO</strong> desempeñará su labor dentro del período de tiempo establecido por el artículo 147 del Código de Trabajo, de 44 horas semanalmente con días libres y turnos rotativos en horarios de <strong>$workSchedule</strong>, establecido por <strong>EL EMPLEADOR</strong>, según el Código Laboral.</p>

    <p><strong>PÁRRAFO:</strong> De conformidad con lo anterior, es entendido entre las partes que el servicio que presta el <strong>EMPLEADOR</strong> es un servicio telefónico, razón por la cual la empresa no está obligada a tener sus operaciones los días legalmente declarados no laborables, según lo establece el artículo 169 del Código Laboral.</p>

    <p><strong>CUARTO:</strong> El presente contrato será por tiempo indefinido, sin embargo, tanto <strong>EL EMPLEADOR</strong> como <strong>EL EMPLEADO</strong>, podrán ponerle término al mismo, en cualquier momento, siempre y cuando se observen las disposiciones del artículo 76 del Código de Trabajo.</p>

    <p><strong>QUINTO: EL EMPLEADO</strong> acuerda que las informaciones a que tenga acceso o maneje como resultado de las labores que realiza, así como cualquier información que reciba durante la vigencia de este Contrato, concerniente a asuntos técnicos, financieros u operaciones de <strong>EL EMPLEADOR</strong>, serán tratadas con absoluta discreción y no podrán ser reveladas a otras firmas, empresas u organizaciones.</p>

    <p><strong>SEXTO: EL EMPLEADO</strong> se responsabiliza de cumplir con las siguientes obligaciones relativas al cliente del <strong>EMPLEADOR</strong>:</p>

    <ol>
        <li><strong>EL EMPLEADO</strong> podrá recibir, admitir o tener acceso a informaciones altamente confidenciales, las cuales serán de gran valor y pertenecen a <strong>EL EMPLEADOR</strong> y a sus clientes, que no serán de dominio público ni estarán disponibles al público.</li>
        
        <li>Estas informaciones pueden incluir nombres, direcciones y números de teléfonos de clientes de <strong>EL EMPLEADOR</strong>, así como información técnica y procesos de trabajo, entre otras;</li>
        
        <li>Dichas informaciones confidenciales pertenecerán a <strong>EL EMPLEADOR</strong> y no podrán ser impuestas por <strong>EL EMPLEADO</strong> a disposición de nadie que no sean específicamente autorizadas por <strong>EL EMPLEADOR</strong>;</li>
        
        <li><strong>EL EMPLEADO</strong> no podrá, sin el consentimiento previo y escrito de <strong>EL EMPLEADOR</strong>, revelar o dar acceso a la información a cualquier tercero.</li>
        
        <li>Asimismo, <strong>EL EMPLEADO</strong> no podrá dar ningún uso a la información, ni en su propio provecho personal ni en provecho de terceros.</li>
        
        <li><strong>EL EMPLEADO</strong> no hará, autorizará o permitirá la realización de duplicado o copiado de cualquier material que contenga información sin el consentimiento previo por escrito de <strong>EL EMPLEADOR</strong>.</li>
        
        <li>Todos los originales y las copias de todos los materiales que contengan información, una vez logrados los resultados de las operaciones realizadas por <strong>EL EMPLEADO</strong>, les serán entregados y/o devueltos a <strong>EL EMPLEADOR</strong>.</li>
        
        <li>Sin limitar todo lo anterior, <strong>EL EMPLEADO</strong> acuerda mantener el acceso a la información de otros empleados de <strong>EL EMPLEADOR</strong> que deban tener acceso a la misma, y que sean autorizados previamente por <strong>EL EMPLEADOR</strong>.</li>
    </ol>

    <p><strong>SÉPTIMO: EL EMPLEADO</strong> se compromete a no divulgar a terceras partes ninguna información del CLIENTE durante y por <strong>diez (10) años</strong> subsecuentes a la terminación de este contrato, y no hará uso de dicha información excepto como expresamente esté emitido por <strong>EL EMPLEADOR</strong>.</p>

    <p><strong>PÁRRAFO: EL EMPLEADO</strong> no retirará del CENTRO ni de los predios de <strong>EL EMPLEADOR</strong> ninguna lista de clientes, documentos, archivos, fichas, notas, correspondencias u otros papeles (incluyendo copias) relacionadas con los negocios del CLIENTE, excepto cuando así <strong>EL EMPLEADOR</strong> lo requiera y con el permiso del CLIENTE, en esos casos <strong>EL EMPLEADO</strong> retornará prontamente esos artículos al CLIENTE o a <strong>EL EMPLEADOR</strong>, cuando así lo requiera o cuando cese este contrato.</p>

    <p><strong>OCTAVO: EL EMPLEADO</strong> velará por los intereses de su <strong>EMPLEADOR</strong> y garantiza proteger y cuidar la calidad del servicio que se ha establecido, según los objetivos establecidos por <strong>EL EMPLEADOR</strong> y/o CLIENTE.</p>

    <p><strong>NOVENO:</strong> Las violaciones precedentemente indicadas están previstas y sancionadas por el artículo 88, ordinal 9 del Código de Trabajo y por el artículo 378 del Código Penal Dominicano.</p>

    <p><strong>DÉCIMO: EL EMPLEADO</strong>, al firmar el presente contrato, concede el derecho a <strong>EL EMPLEADOR</strong>, de realizar chequeos periódicos a su respectivo record históricos y/o de referencias durante el período de vigencia de este Contrato de Trabajo.</p>

    <p><strong>DÉCIMO PRIMERO: Sobre las obligaciones laborales, uso de equipos, feriados, horas extras y consecuencias por incumplimiento</strong></p>

    <p>El colaborador reconoce que el cumplimiento de las normas laborales y contractuales es parte esencial de la relación de trabajo. En ese sentido:</p>

    <ol>
        <li><strong>EL EMPLEADO</strong> es responsable por el uso adecuado y el cuidado de todos los equipos y herramientas asignadas por la empresa para el desempeño de sus funciones, incluyendo, pero no limitado a: computadoras, headsets, flotas telefónicas, UPS, teclados, mouse y cualquier otro dispositivo o herramienta proporcionada por <strong>EL EMPLEADOR</strong>.</li>
        
        <li>Al finalizar la relación laboral, <strong>EL EMPLEADO</strong> deberá devolver estos equipos en <strong>buen estado</strong>, salvo el desgaste normal por uso. Cualquier daño, pérdida o mal uso atribuible al colaborador podrá ser descontado de su liquidación final o sujeto a sanciones internas y legales.</li>
        
        <li><strong>EL EMPLEADO</strong> reconoce que los días feriados trabajados serán remunerados conforme lo establece el <strong>Artículo 196 del Código de Trabajo de la República Dominicana</strong>, es decir, <strong>con el pago doble correspondiente</strong>.</li>
        
        <li>Las horas extras, en caso de ser necesarias, serán previamente solicitadas y autorizadas por <strong>EL EMPLEADOR</strong>, la empresa. <strong>EL EMPLEADO</strong> será informado con antelación y dichas horas se pagarán conforme al <strong>Artículo 203 del Código de Trabajo</strong>, que establece el pago adicional correspondiente por cada hora extra laborada siempre y cuando las mismas excedan las 44 horas semanales. El trabajo extraordinario voluntario, salvo situaciones excepcionales debidamente justificadas por la empresa.</li>
        
        <li>El incumplimiento de cualquiera de las obligaciones contractuales, incluidas las establecidas en este documento, podrá dar lugar a sanciones disciplinarias tales como:
            <ul style="list-style-type: none; margin-left: 20px;">
                <li>5.1. Amonestación escrita.</li>
                <li>5.2. Suspensión sin disfrute de salario.</li>
                <li>5.3. Descuento en nómina por reposición o reparación de equipos.</li>
                <li>5.4. <strong>Terminación del contrato por causa justificada</strong>, conforme al artículo <strong>88 del Código de Trabajo</strong>, cuando la violación constituya una falta grave o afecte directamente la productividad o reputación de la empresa.</li>
            </ul>
        </li>
    </ol>

    <p class="section-title">ADICIÓN A LA CLÁUSULA DE CONFIDENCIALIDAD:</p>

    <p>El colaborador reconoce que cualquier idea, documento, proceso, mejora, propuesta o desarrollo generado durante el ejercicio de sus funciones, o como resultado directo de su trabajo en <strong>EVALLISH SRL</strong>, será propiedad exclusiva de la empresa, sin que esto genere derecho adicional a compensación o reconocimiento económico, salvo acuerdo expreso por escrito.</p>

    <p>Asimismo, el colaborador se <strong>abstendrá de realizar cualquier publicación, comentario o referencia en redes sociales</strong>, foros públicos o <strong>cualquier medio digital o físico</strong>, que pueda afectar la imagen, reputación o intereses de <strong>EVALLISH SRL</strong>, sus directivos, empleados o clientes. Igualmente, queda prohibido divulgar en dichas plataformas cualquier información relacionada con procesos internos, datos confidenciales, estrategias comerciales, decisiones operativas o cualquier tema vinculado a la operación de la empresa o sus clientes.</p>

    <p>El incumplimiento de esta disposición se considerará una falta grave y podrá dar lugar a sanciones disciplinarias, incluyendo <strong>despido por causa justificada</strong>, conforme a lo dispuesto en el <strong>Artículo 88 del Código de Trabajo de la República Dominicana</strong>, sin perjuicio de las acciones legales por daños y perjuicios que la empresa considere necesarias.</p>

    <p>El colaborador acepta y reconoce que el cumplimiento de sus deberes, el cuidado de los bienes del <strong>EL EMPLEADOR</strong>, y el respeto a las normas laborales son esenciales para el mantenimiento de su contrato y el buen desempeño de sus funciones.</p>

    <p>Hecho y firmado en dos (2) originales, uno para cada una de las partes y dos (2) para ser depositados en el departamento de trabajo del Ministerio de trabajo. En la Ciudad de Santiago, República Dominicana, a los <strong>$day ($day)</strong> días del mes de <strong>$month</strong> del año <strong>$year</strong>.</p>

    <div class="signatures">
        <div class="signature-col">
            <p style="margin-top: 80px;">POR EVALLISH SRL.</p>
            <p style="margin-top: 10px;">EL EMPLEADOR</p>
            <div class="signature-line"></div>
            <p class="signature-name">HUGO ANTONIO HIDALGO</p>
            <p class="signature-title">Gerente General</p>
        </div>
        <div class="signature-col">
            <p style="margin-top: 80px;">&nbsp;</p>
            <p style="margin-top: 10px;">EL EMPLEADO</p>
            <div class="signature-line"></div>
            <p class="signature-name">$employeeName</p>
            <p class="signature-title">&nbsp;</p>
        </div>
    </div>
</body>
</html>
HTML;

// Handle different actions
if ($action === 'confidentiality') {
    // Save confidentiality contract to database
    $confInsertStmt = $pdo->prepare("
        INSERT INTO employment_contracts 
        (employee_id, employee_name, id_card, province, contract_date, salary, work_schedule, city, contract_type, created_by, created_at)
        VALUES (NULL, ?, ?, ?, ?, 0, '', '', 'CONFIDENCIALIDAD', ?, NOW())
    ");
    $confInsertStmt->execute([
        $employeeName,
        $idCard,
        $province,
        $contractDate,
        $_SESSION['user_id']
    ]);

    // Redirect to confidentiality contract generator
    $_SESSION['contract_data'] = [
        'employee_name' => $employeeName,
        'id_card' => $idCard,
        'contract_date' => $contractDate
    ];
    header('Location: generate_confidentiality_contract.php');
    exit;
} elseif ($action === 'both') {
    // Save both contract types
    $bothInsertStmt = $pdo->prepare("
        INSERT INTO employment_contracts 
        (employee_id, employee_name, id_card, province, contract_date, salary, work_schedule, city, contract_type, created_by, created_at)
        VALUES (NULL, ?, ?, ?, ?, 0, '', '', 'CONFIDENCIALIDAD', ?, NOW())
    ");
    $bothInsertStmt->execute([
        $employeeName,
        $idCard,
        $province,
        $contractDate,
        $_SESSION['user_id']
    ]);
    // Generate both contracts - show selection page
    $_SESSION['contract_data'] = [
        'employee_name' => $employeeName,
        'id_card' => $idCard,
        'province' => $province,
        'position' => $position,
        'salary' => $salary,
        'work_schedule' => $workSchedule,
        'contract_date' => $contractDate,
        'city' => $city
    ];
    header('Location: download_both_contracts.php');
    exit;
}

// Generate Employment Contract PDF (default action)
try {
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Times New Roman');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('Letter', 'portrait');
    $dompdf->render();

    // Output PDF
    $filename = 'Contrato_Trabajo_' . str_replace(' ', '_', $employeeName) . '_' . date('Y-m-d') . '.pdf';
    $dompdf->stream($filename, ['Attachment' => false]);

    error_log("Contract generated successfully: $filename");
} catch (Exception $e) {
    error_log("Error generating contract: " . $e->getMessage());
    die("Error al generar el contrato: " . $e->getMessage());
}

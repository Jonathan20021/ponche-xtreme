<?php
/**
 * lib/contract_documents.php
 *
 * Cuerpos de los contratos, en UN solo lugar.
 *
 * Antes cada pantalla armaba su propio HTML: generate_confidentiality_contract.php
 * tenia el texto de confidencialidad, pero view_contract.php SIEMPRE armaba el
 * contrato de trabajo, sin mirar contract_type. Resultado: los 208 contratos de
 * confidencialidad guardados se veian con el contenido del contrato laboral y el
 * titulo de confidencialidad. Con los cuerpos centralizados aqui, la pantalla que
 * los muestra no puede volver a equivocarse de documento.
 */

if (!function_exists('contractDocumentLogoData')) {
    /** Logo en base64 para incrustarlo en el PDF (si GD esta disponible). */
    function contractDocumentLogoData(): string
    {
        if (!extension_loaded('gd')) {
            return '';
        }
        $logoPath = dirname(__DIR__) . '/assets/logo.png';
        if (!file_exists($logoPath)) {
            return '';
        }
        $data = @file_get_contents($logoPath);
        return $data !== false ? base64_encode($data) : '';
    }
}

if (!function_exists('contractDocumentSpanishDateParts')) {
    /** @return array{day:string,month:string,year:string} */
    function contractDocumentSpanishDateParts(string $date): array
    {
        $months = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];
        try {
            $d = new DateTime($date);
        } catch (Throwable $e) {
            $d = new DateTime();
        }
        return [
            'day'   => $d->format('d'),
            'month' => $months[(int) $d->format('m')] ?? '',
            'year'  => $d->format('Y'),
        ];
    }
}

if (!function_exists('buildConfidentialityContractHtml')) {
    /**
     * HTML del CONTRATO DE CONFIDENCIALIDAD.
     *
     * @param array{employee_name:string,id_card:string,id_type?:string,contract_date:string} $data
     */
    function buildConfidentialityContractHtml(array $data): string
    {
        $employeeName = (string) ($data['employee_name'] ?? '');
        $idCard       = (string) ($data['id_card'] ?? '');
        $idType       = strtoupper((string) ($data['id_type'] ?? 'CEDULA')) === 'PASAPORTE' ? 'PASAPORTE' : 'CEDULA';
        $contractDate = (string) ($data['contract_date'] ?? date('Y-m-d'));

        $isPassport = $idType === 'PASAPORTE';
        $documentIntroText = $isPassport
            ? "provisto(a) del pasaporte No. <strong>$idCard</strong>"
            : "provisto de la cédula de identidad No. <strong>$idCard</strong>";
        $documentShortLabel = $isPassport ? 'Pasaporte' : 'Cédula';

        $parts = contractDocumentSpanishDateParts($contractDate);
        $day   = $parts['day'];
        $month = $parts['month'];
        $year  = $parts['year'];

        $logoData = contractDocumentLogoData();

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
    
    <p><strong>ENTRE: EVALLISH SRL.</strong>, empresa constituida y existente de conformidad con las leyes dominicanas y debidamente representada por el Sr. Hugo Hidalgo cédula 031-0411132-7 identificado con el RNC No. 1-3263745-3, con su domicilio y asiento social en la Calle Proyecto 4 No. 6, Reparto Oquet, de esta ciudad de Santiago de los Caballeros, en lo adelante llamada <strong>EL EMPLEADOR</strong> y <strong>$employeeName</strong>, mayor de edad, domiciliado y residente en la ciudad de Santiago de los Caballeros, $documentIntroText, en lo adelante llamado <strong>EL EMPLEADO</strong>.</p>

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
        <p>$documentShortLabel: <strong>$idCard</strong></p>
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

        return $html;
    }
}

if (!function_exists('buildEmploymentContractHtml')) {
    /**
     * HTML del CONTRATO DE TRABAJO.
     *
     * @param array{employee_name:string,id_card:string,id_type?:string,province?:string,
     *              position?:string,salary?:string|float,payment_type?:string,
     *              work_schedule?:string,contract_date:string} $data
     */
    function buildEmploymentContractHtml(array $data): string
    {
        $employeeName = (string) ($data['employee_name'] ?? '');
        $idCard       = (string) ($data['id_card'] ?? '');
        $idType       = strtoupper((string) ($data['id_type'] ?? 'CEDULA')) === 'PASAPORTE' ? 'PASAPORTE' : 'CEDULA';
        $province     = (string) ($data['province'] ?? '');
        $position     = (string) ($data['position'] ?? 'Representante de Servicios');
        $salary       = (string) ($data['salary'] ?? '');
        $paymentType  = (string) ($data['payment_type'] ?? 'mensual');
        $workSchedule = (string) ($data['work_schedule'] ?? '');
        $contractDate = (string) ($data['contract_date'] ?? date('Y-m-d'));

        $isPassport = $idType === 'PASAPORTE';
        $nationalityText = $isPassport ? 'extranjero(a)' : 'dominicano(a)';
        $documentText = $isPassport
            ? "provisto(a) del pasaporte <strong>No. $idCard</strong>"
            : "provisto de la cédula de identidad y electoral <strong>No. $idCard</strong>";
        $documentShortLabel = $isPassport ? 'Pasaporte' : 'Cédula';

        $parts = contractDocumentSpanishDateParts($contractDate);
        $day   = $parts['day'];
        $month = $parts['month'];
        $year  = $parts['year'];

        $logoData = contractDocumentLogoData();

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
    
    <p><strong>ENTRE: EVALLISH SRL</strong>, empresa constituida y existente de conformidad con las leyes dominicanas, identificada con el RNC No. 1-32637453, con su domicilio principal y asiento social en la Calle 6 No. 6, Reparto Conet de esta ciudad de Santiago, debidamente representada por el señor <strong>Hugo Antonio Hidalgo Núñez</strong>, dominicano, mayor de edad, casado, empresario, portador de la cédula de identidad No.031-0411132-7, domiciliado y residente en esta ciudad la cual sociedad en lo adelante del presente contrato, se denominará <strong>EL EMPLEADOR</strong>; y de la otra parte <strong>$employeeName</strong>, $nationalityText, mayor de edad, residente de la Provincia <strong>$province</strong>, República Dominicana, quien en lo sucesivo se denominará <strong>EL EMPLEADO</strong>, $documentText, domiciliado(a) y residente en la Provincia <strong>$province</strong>, República Dominicana, quien en lo sucesivo se denominará <strong>EL EMPLEADO</strong>.</p>

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

        return $html;
    }
}

<?php
/**
 * Impresion de la SOLICITUD DE EMPLEO con los datos que el candidato lleno en el
 * formulario publico (careers.php). Reproduce el formato fisico que usaba RRHH
 * para que no haya que transcribir la informacion dos veces.
 *
 * Los datos viven en el snapshot JSON de job_applications.cover_letter; las mismas
 * claves que consume hr/view_application.php.
 */
session_start();
require_once '../db.php';

ensurePermission('hr_recruitment', '../unauthorized.php');

$application_id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT a.*, j.title AS job_title, j.department, j.location
    FROM job_applications a
    LEFT JOIN job_postings j ON a.job_posting_id = j.id
    WHERE a.id = ?
");
$stmt->execute([$application_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    header('Location: recruitment.php');
    exit;
}

$payload = [];
if (!empty($application['cover_letter'])) {
    $decoded = json_decode((string) $application['cover_letter'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['form_version'])) {
        $payload = $decoded;
    }
}

/** Lee una clave anidada del snapshot: value('educacion.que_estudia') */
function value(string $key, $default = '')
{
    global $payload;
    $current = $payload;
    foreach (explode('.', $key) as $k) {
        if (is_array($current) && isset($current[$k])) {
            $current = $current[$k];
        } else {
            return $default;
        }
    }
    return $current;
}

/** Texto listo para imprimir: nunca "N/A", en papel se ve mejor un espacio en blanco. */
function out(string $key, string $fallback = ''): string
{
    $v = value($key, '');
    if (is_array($v)) {
        $v = implode(', ', array_filter($v, 'is_scalar'));
    }
    $v = trim((string) $v);
    return htmlspecialchars($v !== '' ? $v : $fallback, ENT_QUOTES, 'UTF-8');
}

function esc($v): string
{
    return htmlspecialchars(trim((string) $v), ENT_QUOTES, 'UTF-8');
}

/** Casilla marcada / sin marcar del formulario fisico */
function box(bool $checked): string
{
    return $checked ? '<span class="box on">&#10005;</span>' : '<span class="box"></span>';
}

/** El payload guarda SI/NO como texto */
function isYes(string $key): bool
{
    return strtoupper(trim((string) value($key, ''))) === 'SI';
}

function yesNoMarks(string $key): string
{
    $v = strtoupper(trim((string) value($key, '')));
    return box($v === 'SI') . ' Sí &nbsp;&nbsp;' . box($v === 'NO') . ' No';
}

// La cedula dominicana empieza por la serie (3 digitos). El formulario fisico las
// pide en casillas separadas.
$cedulaFull = trim((string) (value('cedula', '') ?: ($application['cedula'] ?? '')));
$serie = '';
$cedulaResto = $cedulaFull;
if (preg_match('/^(\d{3})[\s\-]?(.+)$/', $cedulaFull, $m)) {
    $serie = $m[1];
    $cedulaResto = $m[2];
}

// Nivel academico: el payload guarda la etiqueta completa, el formulario fisico
// tiene una casilla por nivel.
$niveles = value('educacion.nivel', []);
if (!is_array($niveles)) {
    $niveles = [$niveles];
}
$nivelTexto = mb_strtolower(implode(' ', array_filter($niveles, 'is_scalar')), 'UTF-8');
$hasNivel = function (string $needle) use ($nivelTexto): bool {
    return mb_strpos($nivelTexto, $needle) !== false;
};

$experiencias = value('experiencias', []);
if (!is_array($experiencias)) {
    $experiencias = [];
}
// El formulario fisico trae dos bloques: se imprimen al menos dos para que RRHH
// pueda completar a mano si hiciera falta.
while (count($experiencias) < 2) {
    $experiencias[] = [];
}

$cursos = value('educacion.otros_cursos', []);
if (!is_array($cursos)) {
    $cursos = [];
}
while (count($cursos) < 3) {
    $cursos[] = [];
}

$idiomas = value('idiomas', []);
if (!is_array($idiomas)) {
    $idiomas = [];
}
while (count($idiomas) < 2) {
    $idiomas[] = [];
}

$puestoAplicado = trim((string) value('puesto_aplicado', '')) ?: (string) ($application['job_title'] ?? '');
$rolInteres = (string) ($application['role_interest'] ?? value('rol_interes', ''));

$nombreCompleto = trim(
    (string) value('nombres', $application['first_name'] ?? '') . ' ' .
    (string) value('apellido_paterno', $application['last_name'] ?? '') . ' ' .
    (string) value('apellido_materno', '')
);

$firma = trim((string) value('adicional.firma', '')) ?: $nombreCompleto;
$firmaFecha = trim((string) value('adicional.firma_fecha', ''));
$fechaSolicitud = !empty($application['applied_date']) ? date('d/m/Y', strtotime($application['applied_date'])) : '';

$medioVacante = value('adicional.medio_vacante', []);
if (!is_array($medioVacante)) {
    $medioVacante = [$medioVacante];
}
$medioVacante = array_map(fn($m) => mb_strtolower(trim((string) $m), 'UTF-8'), $medioVacante);
$medioOtro = trim((string) value('adicional.medio_vacante_otro', ''));
$tieneMedio = fn(string $needle) => in_array($needle, $medioVacante, true);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Solicitud de Empleo - <?php echo esc($nombreCompleto); ?></title>
    <style>
        * { box-sizing: border-box; }

        :root {
            --brand: #244886;
            --brand-soft: #eef2f9;
            --line: #9aa7bd;
            --ink: #10192b;
            --muted: #5b6779;
        }

        body {
            font-family: "Segoe UI", Calibri, Arial, Helvetica, sans-serif;
            font-size: 9pt;
            color: var(--ink);
            background: #eef1f6;
            margin: 0;
            padding: 24px 0;
        }

        .sheet {
            width: 8.5in;
            margin: 0 auto;
            padding: 0.45in 0.5in 0.5in;
            background: #fff;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .18);
        }

        /* ---------- Membrete ---------- */
        .letterhead {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 10px;
            border-bottom: 3px solid var(--brand);
        }

        .brand-logo { width: 58px; height: 58px; object-fit: contain; }

        .brand-name {
            font-size: 17pt;
            font-weight: 700;
            color: var(--brand);
            letter-spacing: .5px;
            line-height: 1.05;
        }

        .brand-sub {
            font-size: 8pt;
            color: var(--muted);
            letter-spacing: 1.4px;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .doc-meta { margin-left: auto; text-align: right; }

        .doc-title {
            font-size: 13pt;
            font-weight: 700;
            color: var(--brand);
            letter-spacing: 1.5px;
        }

        .doc-meta dl {
            margin: 4px 0 0;
            font-size: 7.5pt;
            color: var(--muted);
            line-height: 1.5;
        }

        .doc-meta b { color: var(--ink); font-weight: 600; }

        .applied-for {
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin: 10px 0 12px;
            font-size: 9.5pt;
        }

        .applied-for span:first-child {
            font-weight: 700;
            color: var(--brand);
            text-transform: uppercase;
            letter-spacing: .6px;
            font-size: 8pt;
            white-space: nowrap;
        }

        .applied-for span:last-child {
            flex: 1;
            border-bottom: 1px solid var(--line);
            font-weight: 600;
            padding-bottom: 1px;
        }

        /* ---------- Secciones ---------- */
        h2 {
            font-size: 8.5pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            color: #fff;
            background: var(--brand);
            margin: 12px 0 5px;
            padding: 4px 8px;
            border-radius: 2px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 4px;
        }

        td, th {
            border: 1px solid var(--line);
            padding: 3px 5px;
            vertical-align: top;
            word-wrap: break-word;
            text-align: left;
        }

        th {
            background: var(--brand-soft);
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .lbl {
            font-size: 6.8pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: var(--muted);
            display: block;
            line-height: 1.2;
        }

        .val {
            font-size: 9pt;
            min-height: 13px;
            display: block;
            padding-top: 1px;
            font-weight: 500;
        }

        .box {
            display: inline-block;
            width: 9px;
            height: 9px;
            border: 1.2px solid var(--brand);
            border-radius: 1px;
            margin-right: 3px;
            text-align: center;
            line-height: 8px;
            font-size: 8pt;
            vertical-align: -1px;
        }

        .box.on {
            background: var(--brand);
            color: #fff;
            font-weight: bold;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .intro {
            font-size: 7.6pt;
            color: var(--muted);
            margin: 0 0 5px;
            line-height: 1.35;
        }

        .opt { font-size: 7.8pt; line-height: 1.4; }

        /* ---------- Firma ---------- */
        .firma-line { margin-top: 22px; text-align: center; }

        .firma-nombre {
            font-family: "Segoe Script", "Brush Script MT", cursive;
            font-size: 15pt;
            color: #10245c;
            border-bottom: 1px solid var(--ink);
            display: inline-block;
            min-width: 3.4in;
            padding: 0 10px 2px;
        }

        .firma-caption {
            font-size: 8pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
            margin-top: 4px;
        }

        .firma-nota { font-size: 7pt; color: var(--muted); margin-top: 2px; }

        .blank-line {
            display: inline-block;
            border-bottom: 1px solid var(--line);
            min-width: 1.5in;
        }

        .obs-box { height: 1in; }

        .doc-footer {
            margin-top: 14px;
            padding-top: 6px;
            border-top: 1px solid var(--line);
            font-size: 6.8pt;
            color: var(--muted);
            display: flex;
            justify-content: space-between;
        }

        /* ---------- Barra de acciones (no se imprime) ---------- */
        .toolbar {
            width: 8.5in;
            margin: 0 auto 12px;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .toolbar button, .toolbar a {
            border: none;
            border-radius: 8px;
            padding: 9px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            background: #dde3ec;
            color: #0f172a;
        }

        .toolbar button { background: var(--brand); color: #fff; }

        @page { size: letter; margin: 0.4in; }

        @media print {
            body { background: #fff; padding: 0; font-size: 8.6pt; }
            .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
            .toolbar { display: none; }
            tr, td, table { page-break-inside: avoid; }
            h2 { page-break-after: avoid; }
        }
    </style>
</head>

<body>
    <div class="toolbar">
        <a href="view_application.php?id=<?php echo (int) $application_id; ?>">&#8592; Volver a la ficha</a>
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>

    <div class="sheet">
        <header class="letterhead">
            <img src="../assets/logo.png" alt="Evallish BPO" class="brand-logo">
            <div>
                <div class="brand-name">Evallish BPO</div>
                <div class="brand-sub">Departamento de Recursos Humanos</div>
            </div>
            <div class="doc-meta">
                <div class="doc-title">SOLICITUD DE EMPLEO</div>
                <dl>
                    <div>Código: <b><?php echo esc($application['application_code']); ?></b></div>
                    <div>Fecha de solicitud: <b><?php echo esc($fechaSolicitud); ?></b></div>
                </dl>
            </div>
        </header>

        <div class="applied-for">
            <span>Puesto al que está aplicando</span>
            <span><?php echo esc($puestoAplicado); ?></span>
        </div>

        <table>
            <tr>
                <td style="width:9%"><span class="lbl">Serie</span><span class="val"><?php echo esc($serie); ?></span></td>
                <td style="width:22%"><span class="lbl">Cédula</span><span class="val"><?php echo esc($cedulaResto); ?></span></td>
                <td style="width:49%"><span class="lbl">Dirección: (Calle, No. Urbanización, Ciudad)</span><span class="val"><?php echo out('direccion', (string) ($application['address'] ?? '')); ?></span></td>
                <td style="width:20%"><span class="lbl">Teléfono</span><span class="val"><?php echo out('telefono', (string) ($application['phone'] ?? '')); ?></span></td>
            </tr>
        </table>

        <h2>DATOS PERSONALES DEL SOLICITANTE</h2>
        <table>
            <tr>
                <td><span class="lbl">Apellido Paterno</span><span class="val"><?php echo out('apellido_paterno'); ?></span></td>
                <td><span class="lbl">Apellido Materno</span><span class="val"><?php echo out('apellido_materno'); ?></span></td>
                <td><span class="lbl">Nombre(s)</span><span class="val"><?php echo out('nombres', (string) ($application['first_name'] ?? '')); ?></span></td>
                <td><span class="lbl">Apodo</span><span class="val"><?php echo out('apodo'); ?></span></td>
            </tr>
        </table>

        <table>
            <tr>
                <td style="width:17%"><span class="lbl">Fecha de Nacimiento</span><span class="val"><?php
                    $fn = trim((string) value('fecha_nacimiento', (string) ($application['date_of_birth'] ?? '')));
                    echo esc($fn !== '' && strtotime($fn) ? date('d/m/Y', strtotime($fn)) : $fn);
                ?></span></td>
                <td style="width:9%"><span class="lbl">Edad</span><span class="val"><?php echo out('edad'); ?></span></td>
                <td style="width:26%"><span class="lbl">Lugar de Nacimiento</span><span class="val"><?php echo out('lugar_nacimiento'); ?></span></td>
                <td style="width:26%"><span class="lbl">País de Nacimiento</span><span class="val"><?php echo out('pais_nacimiento'); ?></span></td>
                <td style="width:22%"><span class="lbl">Nacionalidad</span><span class="val"><?php echo out('nacionalidad'); ?></span></td>
            </tr>
        </table>

        <table>
            <tr>
                <td style="width:16%"><span class="lbl">Sexo</span><span class="val"><?php echo out('sexo'); ?></span></td>
                <td style="width:36%"><span class="lbl">Estado Civil</span><span class="val"><?php echo out('estado_civil'); ?></span></td>
                <td style="width:16%"><span class="lbl">Tipo de Sangre</span><span class="val"><?php echo out('tipo_sangre'); ?></span></td>
                <td style="width:16%"><span class="lbl">Estatura</span><span class="val"><?php echo out('estatura'); ?></span></td>
                <td style="width:16%"><span class="lbl">Peso</span><span class="val"><?php echo out('peso'); ?></span></td>
            </tr>
        </table>

        <table>
            <tr>
                <td style="width:33%"><span class="lbl">Con quién vive (Parentesco)</span><span class="val"><?php echo out('vive_con'); ?></span></td>
                <td style="width:27%"><span class="lbl">Cuántas personas dependen de usted</span><span class="val"><?php echo out('personas_dependen'); ?></span></td>
                <td style="width:40%">
                    <span class="lbl">Tiene hijos: <?php echo yesNoMarks('tiene_hijos'); ?></span>
                    <span class="val">Cantidad: <?php echo out('cantidad_hijos'); ?> &nbsp;|&nbsp; Edad de sus hijos: <?php echo out('edad_hijos'); ?></span>
                </td>
            </tr>
        </table>

        <table>
            <tr>
                <td style="width:50%"><span class="lbl">¿Vive en casa propia?</span><span class="val"><?php echo yesNoMarks('casa_propia'); ?></span></td>
                <td style="width:50%"><span class="lbl">¿Con cuántas personas vive?</span><span class="val"><?php echo out('personas_vive'); ?></span></td>
            </tr>
        </table>

        <h2>DISPONIBILIDAD DE HORARIO</h2>
        <p class="intro">Evallish opera en horarios rotativos desde las 7:30 a.m. hasta las 10:30 p.m., de lunes a domingo, con un día
            libre semanal. De lunes a viernes de 8:30 a.m. a 5:30 p.m.</p>
        <table>
            <tr>
                <td class="opt" style="width:34%"><?php echo box(isYes('disponibilidad.turno_rotativo')); ?> Estoy disponible para cualquier turno rotativo (7:30 a.m. a 10:30 p.m., con 1 día libre a la semana).</td>
                <td class="opt" style="width:33%"><?php echo box(isYes('disponibilidad.lunes_viernes')); ?> Solo estoy disponible de lunes a viernes de 8:30 a.m. a 5:30 p.m.</td>
                <td class="opt" style="width:33%"><?php echo box(isYes('disponibilidad.otro')); ?> Tengo otra disponibilidad (especifique):<br><span class="val"><?php echo out('disponibilidad.otro_texto'); ?></span></td>
            </tr>
        </table>

        <h2>MODALIDAD DE TRABAJO SOLICITADO</h2>
        <table>
            <tr>
                <td class="opt" style="width:25%"><?php echo box(isYes('modalidad.presencial')); ?> Prefiero trabajar en la empresa (modalidad presencial)</td>
                <td class="opt" style="width:25%"><?php echo box(isYes('modalidad.hibrida')); ?> No tengo inconveniente, puedo trabajar tanto presencial como desde casa.</td>
                <td class="opt" style="width:25%"><?php echo box(isYes('modalidad.remota')); ?> Solo me interesa trabajar desde casa (modalidad remota).</td>
                <td class="opt" style="width:25%"><?php echo box(isYes('modalidad.otro')); ?> Tengo otra disponibilidad (especifique):<br><span class="val"><?php echo out('modalidad.otro_texto'); ?></span></td>
            </tr>
        </table>

        <h2>¿CÓMO TE TRASLADARÍAS A EVALLISH?</h2>
        <table>
            <tr>
                <td class="opt" style="width:14%"><?php echo box(isYes('transporte.carro_publico')); ?> Carro público</td>
                <td class="opt" style="width:14%"><?php echo box(isYes('transporte.motoconcho')); ?> Motoconcho</td>
                <td class="opt" style="width:11%"><?php echo box(isYes('transporte.a_pie')); ?> A pie</td>
                <td class="opt" style="width:21%"><?php echo box(isYes('transporte.otro')); ?> Tengo otro (especifique):<br><span class="val"><?php echo out('transporte.otro_texto'); ?></span></td>
                <td class="opt" style="width:40%">
                    Si utilizas transporte público o motoconcho:<br>
                    ¿Cuáles son las rutas o letras de conchos que utilizas? <span class="val"><?php echo out('transporte.rutas'); ?></span>
                    ¿Cuánto tiempo estimas que te tomaría llegar desde tu casa a Evallish? <span class="val"><?php echo out('transporte.tiempo_llegada', out('transporte.detalles')); ?></span>
                </td>
            </tr>
        </table>

        <h2>ÚLTIMO NIVEL ACADÉMICO</h2>
        <table>
            <tr>
                <td class="opt"><?php echo box($hasNivel('primaria')); ?> Educación básica (Primaria)</td>
                <td class="opt"><?php echo box($hasNivel('bachillerato')); ?> Educación media (Bachillerato)</td>
                <td class="opt"><?php echo box($hasNivel('estudiante')); ?> Estudiante universitario (en curso)</td>
                <td class="opt"><?php echo box($hasNivel('técnico') || $hasNivel('tecnico')); ?> Técnico o curso especializado (indique):<br><span class="val"><?php echo out('educacion.nivel_tecnico_detalle'); ?></span></td>
                <td class="opt"><?php echo box($hasNivel('carrera')); ?> Carrera universitaria completa (indique):<br><span class="val"><?php echo out('educacion.nivel_carrera_detalle'); ?></span></td>
                <td class="opt"><?php echo box($hasNivel('postgrado') || $hasNivel('maestría')); ?> Postgrado / Maestría (indique):<br><span class="val"><?php echo out('educacion.nivel_postgrado_detalle'); ?></span></td>
            </tr>
        </table>

        <table>
            <tr>
                <td colspan="3" style="border-bottom:none"><span class="lbl">Si estudia actualmente favor completar: <?php echo yesNoMarks('educacion.estudia_actualmente'); ?></span></td>
            </tr>
            <tr>
                <td style="width:28%"><span class="lbl">Qué Estudia</span><span class="val"><?php echo out('educacion.que_estudia'); ?></span></td>
                <td style="width:28%"><span class="lbl">Dónde estudia</span><span class="val"><?php echo out('educacion.donde_estudia'); ?></span></td>
                <td style="width:44%"><span class="lbl">Horario de clases (cuáles días y en qué horario asiste a clases)</span><span class="val"><?php echo out('educacion.horario_clases'); ?></span></td>
            </tr>
        </table>

        <table>
            <tr>
                <td colspan="3" style="text-align:center"><span class="lbl">Otros Conocimientos y cursos (especifique)</span></td>
            </tr>
            <tr>
                <th style="width:45%"><span class="lbl">Otros cursos realizados</span></th>
                <th style="width:35%"><span class="lbl">Institución</span></th>
                <th style="width:20%"><span class="lbl">Fecha</span></th>
            </tr>
            <?php foreach ($cursos as $curso): ?>
                <tr>
                    <td><span class="val"><?php echo esc($curso['curso'] ?? ''); ?></span></td>
                    <td><span class="val"><?php echo esc($curso['institucion'] ?? ''); ?></span></td>
                    <td><span class="val"><?php echo esc($curso['fecha'] ?? ''); ?></span></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <table>
            <tr>
                <th style="width:40%"><span class="lbl">Idiomas que domina</span></th>
                <th style="width:20%"><span class="lbl">Habla</span></th>
                <th style="width:20%"><span class="lbl">Lee</span></th>
                <th style="width:20%"><span class="lbl">Escribe</span></th>
            </tr>
            <?php foreach ($idiomas as $idioma): ?>
                <tr>
                    <td><span class="val"><?php echo esc($idioma['idioma'] ?? ''); ?></span></td>
                    <td><span class="val"><?php echo esc($idioma['habla'] ?? ''); ?></span></td>
                    <td><span class="val"><?php echo esc($idioma['lee'] ?? ''); ?></span></td>
                    <td><span class="val"><?php echo esc($idioma['escribe'] ?? ''); ?></span></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2>EXPERIENCIAS LABORALES</h2>
        <?php foreach ($experiencias as $exp): ?>
            <table>
                <tr>
                    <th style="width:20%"><span class="lbl">Empresa</span></th>
                    <th style="width:17%"><span class="lbl">Superior Inmediato</span></th>
                    <th style="width:15%"><span class="lbl">Tiempo Trabajado</span></th>
                    <th style="width:15%"><span class="lbl">Teléfono</span></th>
                    <th style="width:18%"><span class="lbl">Cargo</span></th>
                    <th style="width:15%"><span class="lbl">Sueldo</span></th>
                </tr>
                <tr>
                    <td><span class="val"><?php echo esc($exp['empresa'] ?? ''); ?></span></td>
                    <td><span class="val"><?php echo esc($exp['superior'] ?? ''); ?></span></td>
                    <td><span class="val"><?php echo esc($exp['tiempo'] ?? ''); ?></span></td>
                    <td><span class="val"><?php echo esc($exp['telefono'] ?? ''); ?></span></td>
                    <td><span class="val"><?php echo esc($exp['cargo'] ?? ''); ?></span></td>
                    <td><span class="val"><?php echo esc($exp['sueldo'] ?? ''); ?></span></td>
                </tr>
                <tr>
                    <td colspan="2"><span class="lbl">Tareas Principales</span></td>
                    <td colspan="4"><span class="val"><?php echo nl2br(esc($exp['tareas'] ?? '')); ?></span></td>
                </tr>
                <tr>
                    <td colspan="2"><span class="lbl">Razón de la salida</span></td>
                    <td colspan="4"><span class="val"><?php echo esc($exp['razon_salida'] ?? ''); ?></span></td>
                </tr>
            </table>
        <?php endforeach; ?>

        <table>
            <tr>
                <td style="width:28%"><span class="lbl">¿Cuál ha sido su mayor logro?</span></td>
                <td style="width:72%"><span class="val"><?php echo nl2br(out('adicional.mayor_logro')); ?></span></td>
            </tr>
            <tr>
                <td><span class="lbl">¿Cuáles son sus expectativas salariales?</span></td>
                <td><span class="val"><?php echo out('adicional.expectativas_salariales', (string) ($application['expected_salary'] ?? '')); ?></span></td>
            </tr>
            <tr>
                <td><span class="lbl">Tiene algún problema de incapacidad o limitación</span></td>
                <td><span class="val"><?php echo yesNoMarks('adicional.incapacidad'); ?> &nbsp;&nbsp; ¿Cuál? <?php echo out('adicional.incapacidad_cual'); ?></span></td>
            </tr>
            <tr>
                <td><span class="lbl">¿Está dispuesto a trabajar horas extras?</span></td>
                <td><span class="val"><?php echo yesNoMarks('adicional.horas_extras'); ?></span></td>
            </tr>
            <tr>
                <td><span class="lbl">¿Está dispuesto a trabajar días de fiesta?</span></td>
                <td><span class="val"><?php echo yesNoMarks('adicional.dias_fiestas'); ?></span></td>
            </tr>
            <tr>
                <td><span class="lbl">¿Conoce algún empleado de la empresa?</span></td>
                <td><span class="val"><?php echo yesNoMarks('adicional.conoce_empleado'); ?> &nbsp;&nbsp; Nombre: <?php echo out('adicional.conoce_empleado_nombre'); ?></span></td>
            </tr>
            <tr>
                <td><span class="lbl">¿Por cuál medio se enteró de la vacante?</span></td>
                <td class="opt">
                    <?php echo box($tieneMedio('whatsapp')); ?> WhatsApp &nbsp;
                    <?php echo box($tieneMedio('instagram')); ?> Instagram &nbsp;
                    <?php echo box($tieneMedio('telegrama')); ?> Telegrama &nbsp;
                    <?php echo box($tieneMedio('internet')); ?> Internet &nbsp;
                    <?php echo box($tieneMedio('portal de empleos')); ?> Portal de empleos &nbsp;
                    <?php echo box($tieneMedio('amigo que trabaja en la empresa')); ?> Amigo que trabaja en la empresa &nbsp;
                    <?php echo box($medioOtro !== ''); ?> Otro, especifique: <?php echo esc($medioOtro); ?>
                </td>
            </tr>
            <?php if ($rolInteres !== ''): ?>
                <tr>
                    <td><span class="lbl">Rol de su interés</span></td>
                    <td><span class="val"><?php echo esc($rolInteres); ?></span></td>
                </tr>
            <?php endif; ?>
        </table>

        <h2>DATOS DEL SOLICITANTE</h2>
        <p class="intro">Doy fe que todos los datos suministrados en esta solicitud son verdaderos y autorizo a cualquier
            investigación sobre mis declaraciones.</p>
        <div class="firma-line">
            <div class="firma-nombre"><?php echo esc($firma); ?></div>
            <div class="firma-caption">Firma del Solicitante</div>
            <?php if ($firmaFecha !== '' && strtotime($firmaFecha)): ?>
                <div class="firma-nota">Aceptado electrónicamente el <?php echo esc(date('d/m/Y \a \l\a\s H:i', strtotime($firmaFecha))); ?>
                    · Solicitud <?php echo esc($application['application_code']); ?></div>
            <?php endif; ?>
        </div>

        <h2>PARA USO EXCLUSIVO DEL EVALUADOR</h2>
        <p class="intro" style="text-align:left">
            Nombre del evaluador: <span class="blank-line"><?php echo out('evaluador.nombre'); ?></span>
            &nbsp; Fecha de evaluación: <span class="blank-line"><?php echo out('evaluador.fecha'); ?></span>
            &nbsp; Puesto: <span class="blank-line"><?php echo out('evaluador.puesto'); ?></span>
        </p>
        <table>
            <tr>
                <td><span class="lbl">OBSERVACIONES GENERALES SOBRE LA ENTREVISTA:</span>
                    <div class="obs-box"><span class="val"><?php echo nl2br(out('evaluador.observaciones')); ?></span></div>
                </td>
            </tr>
        </table>

        <div class="doc-footer">
            <span>Evallish BPO · Documento confidencial de uso interno de Recursos Humanos</span>
            <span>Impreso el <?php echo esc(date('d/m/Y H:i')); ?> · <?php echo esc($application['application_code']); ?></span>
        </div>
    </div>

    <script>
        // Se abre desde la ficha con el unico proposito de imprimir
        window.addEventListener('load', () => setTimeout(() => window.print(), 350));
    </script>
</body>

</html>

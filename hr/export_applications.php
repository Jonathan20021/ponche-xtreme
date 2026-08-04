<?php
session_start();
require_once '../db.php';

ensurePermission('hr_recruitment', '../unauthorized.php');

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$job_filter = $_GET['job'] ?? 'all';
$role_filter = $_GET['role'] ?? 'all';
$search = trim((string) ($_GET['search'] ?? ''));

$allowed_roles = ['Inglés', 'Español', 'APPOINT'];
if ($role_filter !== 'all' && !in_array($role_filter, $allowed_roles, true)) {
    $role_filter = 'all';
}

$allowed_statuses = ['new', 'reviewing', 'shortlisted', 'interview_scheduled', 'interviewed', 'offer_extended', 'hired', 'rejected', 'withdrawn'];
if ($status_filter !== 'all' && !in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = 'all';
}

if ($job_filter !== 'all') {
    $job_filter = (int) $job_filter;
    if ($job_filter <= 0) {
        $job_filter = 'all';
    }
}

$query = "
    SELECT a.*, j.title as job_title, j.department, u.full_name as assigned_to_name
    FROM job_applications a
    LEFT JOIN job_postings j ON a.job_posting_id = j.id
    LEFT JOIN users u ON a.assigned_to = u.id
    WHERE 1=1
";

$params = [];

if ($status_filter !== 'all') {
    $query .= " AND a.status = :status";
    $params['status'] = $status_filter;
}

if ($job_filter !== 'all') {
    $query .= " AND a.job_posting_id = :job_id";
    $params['job_id'] = (int) $job_filter;
}

if ($role_filter !== 'all') {
    $query .= " AND a.role_interest = :role_interest";
    $params['role_interest'] = $role_filter;
}

if (!empty($search)) {
    $query .= " AND (a.first_name LIKE :search OR a.last_name LIKE :search OR a.email LIKE :search OR a.application_code LIKE :search)";
    $params['search'] = "%$search%";
}

$query .= " ORDER BY a.applied_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment;filename="solicitudes_empleo_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');

/**
 * Los datos personales extendidos (nacionalidad, estado civil, hijos, cursos,
 * idiomas...) viajan en el JSON del formulario guardado en cover_letter.
 */
function formPayload(array $app): array
{
    if (empty($app['cover_letter'])) {
        return [];
    }
    $decoded = json_decode((string) $app['cover_letter'], true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['form_version']))
        ? $decoded
        : [];
}

function payloadValue(array $payload, string $key, string $default = ''): string
{
    $v = $payload[$key] ?? $default;
    return is_scalar($v) ? (string) $v : $default;
}

/**
 * Lee una clave anidada del snapshot: pv($payload, 'educacion.que_estudia').
 * Los datos de la solicitud fisica viven en sub-arrays (educacion, transporte,
 * modalidad, adicional...), no en el primer nivel.
 */
function pv(array $payload, string $path, string $default = ''): string
{
    $current = $payload;
    foreach (explode('.', $path) as $key) {
        if (is_array($current) && isset($current[$key])) {
            $current = $current[$key];
        } else {
            return $default;
        }
    }
    if (is_array($current)) {
        return implode(', ', array_filter($current, 'is_scalar'));
    }
    return is_scalar($current) ? (string) $current : $default;
}

/** Convierte el grupo de SI/NO de una seccion en la etiqueta marcada */
function pickLabel(array $payload, string $prefix, array $map, string $otroKey = ''): string
{
    foreach ($map as $key => $label) {
        if (strtoupper(pv($payload, $prefix . '.' . $key)) === 'SI') {
            if ($otroKey !== '' && $key === 'otro') {
                $detalle = pv($payload, $prefix . '.' . $otroKey);
                return $detalle !== '' ? $label . ': ' . $detalle : $label;
            }
            return $label;
        }
    }
    return '';
}

function experienciaRows(array $payload): array
{
    $rows = $payload['experiencias'] ?? [];
    if (!is_array($rows)) {
        return [];
    }
    return array_values(array_filter($rows, fn($r) => is_array($r) && trim(implode('', array_filter($r, 'is_scalar'))) !== ''));
}

/** Las experiencias a partir de la segunda se resumen en una sola celda */
function flattenExperiencias(array $rows): string
{
    $out = [];
    foreach ($rows as $exp) {
        $partes = array_filter([
            $exp['empresa'] ?? '',
            !empty($exp['cargo'])  ? 'Cargo: ' . $exp['cargo']   : '',
            !empty($exp['tiempo']) ? 'Tiempo: ' . $exp['tiempo'] : '',
            !empty($exp['sueldo']) ? 'Sueldo: ' . $exp['sueldo'] : '',
            !empty($exp['razon_salida']) ? 'Salida: ' . $exp['razon_salida'] : '',
        ]);
        if ($partes) {
            $out[] = implode(' - ', $partes);
        }
    }
    return implode(' | ', $out);
}

function flattenCursos(array $payload): string
{
    $rows = $payload['educacion']['otros_cursos'] ?? [];
    if (!is_array($rows)) {
        return '';
    }
    $out = [];
    foreach ($rows as $c) {
        $parts = array_filter([$c['curso'] ?? '', $c['institucion'] ?? '', $c['fecha'] ?? '']);
        if ($parts) {
            $out[] = implode(' - ', $parts);
        }
    }
    return implode(' | ', $out);
}

function flattenIdiomas(array $payload): string
{
    $rows = $payload['idiomas'] ?? [];
    if (!is_array($rows)) {
        return '';
    }
    $out = [];
    foreach ($rows as $i) {
        if (empty($i['idioma'])) {
            continue;
        }
        $niveles = array_filter([
            !empty($i['habla'])   ? 'habla: ' . $i['habla']     : '',
            !empty($i['lee'])     ? 'lee: ' . $i['lee']         : '',
            !empty($i['escribe']) ? 'escribe: ' . $i['escribe'] : '',
        ]);
        $out[] = $i['idioma'] . ($niveles ? ' (' . implode(', ', $niveles) . ')' : '');
    }
    return implode(' | ', $out);
}

function excelCell($value): string
{
    $value = (string) ($value ?? '');
    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        $value = "'" . $value;
    }
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Etiquetas de los grupos de casillas de la solicitud fisica
$dispMap = [
    'turno_rotativo' => 'Cualquier turno rotativo (7:30 a.m. a 10:30 p.m.)',
    'lunes_viernes'  => 'Solo Lunes a Viernes de 8:30 a.m. a 5:30 p.m.',
    'otro'           => 'Otra disponibilidad',
];
$modalidadMap = [
    'presencial' => 'Presencial',
    'hibrida'    => 'Presencial o desde casa',
    'remota'     => 'Solo desde casa (remota)',
    'otro'       => 'Otra modalidad',
];
$transporteMap = [
    'carro_publico' => 'Carro público',
    'motoconcho'    => 'Motoconcho',
    'a_pie'         => 'A pie',
    'otro'          => 'Otro',
];

// Enlace descargable al CV (la hoja se comparte fuera del sistema)
$exportBaseUrl = '';
$emailCfgExport = @include __DIR__ . '/../config/email_config.php';
if (is_array($emailCfgExport) && !empty($emailCfgExport['app_url'])) {
    $exportBaseUrl = rtrim($emailCfgExport['app_url'], '/');
}

// Cada columna: [encabezado, callback($app, $payload)]
$columns = [
    // --- Seguimiento ---
    ['Código', fn($a, $p) => $a['application_code']],
    ['Estado', fn($a, $p) => $a['status']],
    ['Fecha de Aplicación', fn($a, $p) => !empty($a['applied_date']) ? date('d/m/Y H:i', strtotime($a['applied_date'])) : ''],
    ['Vacante', fn($a, $p) => $a['job_title']],
    ['Departamento', fn($a, $p) => $a['department']],
    ['Puesto al que Aplica', fn($a, $p) => pv($p, 'puesto_aplicado')],
    ['Rol de Interés', fn($a, $p) => $a['role_interest'] ?: pv($p, 'rol_interes')],
    ['Asignado a', fn($a, $p) => $a['assigned_to_name'] ?? ''],
    ['Calificación', fn($a, $p) => $a['overall_rating'] ?? ''],

    // --- Datos personales ---
    ['Nombres', fn($a, $p) => pv($p, 'nombres') ?: $a['first_name']],
    ['Apellido Paterno', fn($a, $p) => pv($p, 'apellido_paterno')],
    ['Apellido Materno', fn($a, $p) => pv($p, 'apellido_materno')],
    ['Apodo', fn($a, $p) => pv($p, 'apodo')],
    ['Cédula', fn($a, $p) => $a['cedula'] ?: pv($p, 'cedula')],
    ['Teléfono', fn($a, $p) => $a['phone']],
    ['Email', fn($a, $p) => $a['email'] === 'sin-correo@evallish.local' ? '' : $a['email']],
    ['Dirección', fn($a, $p) => $a['address'] ?: pv($p, 'direccion')],
    ['Fecha de Nacimiento', fn($a, $p) => !empty($a['date_of_birth']) ? date('d/m/Y', strtotime($a['date_of_birth'])) : pv($p, 'fecha_nacimiento')],
    ['Edad', fn($a, $p) => pv($p, 'edad')],
    ['Lugar de Nacimiento', fn($a, $p) => pv($p, 'lugar_nacimiento')],
    ['País de Nacimiento', fn($a, $p) => pv($p, 'pais_nacimiento')],
    ['Nacionalidad', fn($a, $p) => pv($p, 'nacionalidad')],
    ['Sexo', fn($a, $p) => pv($p, 'sexo')],
    ['Estado Civil', fn($a, $p) => pv($p, 'estado_civil')],
    ['Tipo de Sangre', fn($a, $p) => pv($p, 'tipo_sangre')],
    ['Estatura', fn($a, $p) => pv($p, 'estatura')],
    ['Peso', fn($a, $p) => pv($p, 'peso')],

    // --- Nucleo familiar ---
    ['Con Quién Vive', fn($a, $p) => pv($p, 'vive_con')],
    ['Personas con las que Vive', fn($a, $p) => pv($p, 'personas_vive')],
    ['Personas que Dependen', fn($a, $p) => pv($p, 'personas_dependen')],
    ['Tiene Hijos', fn($a, $p) => pv($p, 'tiene_hijos')],
    ['Cantidad de Hijos', fn($a, $p) => pv($p, 'cantidad_hijos')],
    ['Edad de los Hijos', fn($a, $p) => pv($p, 'edad_hijos')],
    ['Vivienda Propia', fn($a, $p) => pv($p, 'casa_propia')],

    // --- Disponibilidad y logistica ---
    ['Disponibilidad de Horario', fn($a, $p) => pickLabel($p, 'disponibilidad', $GLOBALS['dispMap'], 'otro_texto')],
    ['Detalle de Disponibilidad', fn($a, $p) => pv($p, 'disponibilidad.otro_texto')],
    ['Modalidad Solicitada', fn($a, $p) => pickLabel($p, 'modalidad', $GLOBALS['modalidadMap'], 'otro_texto')],
    ['Detalle de Modalidad', fn($a, $p) => pv($p, 'modalidad.otro_texto')],
    ['Medio de Transporte', fn($a, $p) => pickLabel($p, 'transporte', $GLOBALS['transporteMap'], 'otro_texto')],
    ['Rutas / Conchos', fn($a, $p) => pv($p, 'transporte.rutas')],
    ['Tiempo de Traslado', fn($a, $p) => pv($p, 'transporte.tiempo_llegada') ?: pv($p, 'transporte.detalles')],
    ['Dispuesto a Horas Extras', fn($a, $p) => pv($p, 'adicional.horas_extras')],
    ['Dispuesto a Días de Fiesta', fn($a, $p) => pv($p, 'adicional.dias_fiestas')],
    ['Tiene Otro Empleo', fn($a, $p) => pv($p, 'adicional.otro_empleo')],
    ['Detalle de Otro Empleo', fn($a, $p) => pv($p, 'adicional.otro_empleo_detalle')],

    // --- Educacion ---
    ['Nivel Académico', fn($a, $p) => $a['education_level'] ?: pv($p, 'educacion.nivel')],
    ['Detalle del Nivel', fn($a, $p) => trim(implode(' ', array_filter([
        pv($p, 'educacion.nivel_tecnico_detalle'),
        pv($p, 'educacion.nivel_carrera_detalle'),
        pv($p, 'educacion.nivel_postgrado_detalle'),
    ])))],
    ['Estudia Actualmente', fn($a, $p) => pv($p, 'educacion.estudia_actualmente')],
    ['Qué Estudia', fn($a, $p) => pv($p, 'educacion.que_estudia')],
    ['Dónde Estudia', fn($a, $p) => pv($p, 'educacion.donde_estudia')],
    ['Horario de Clases', fn($a, $p) => pv($p, 'educacion.horario_clases')],
    ['Cursos / Capacitaciones', fn($a, $p) => flattenCursos($p)],
    ['Idiomas', fn($a, $p) => flattenIdiomas($p)],

    // --- Experiencia laboral ---
    ['Experiencia (años)', fn($a, $p) => $a['years_of_experience']],
    ['Exp. 1 - Empresa', fn($a, $p) => experienciaRows($p)[0]['empresa'] ?? ($a['current_company'] ?? '')],
    ['Exp. 1 - Cargo', fn($a, $p) => experienciaRows($p)[0]['cargo'] ?? ($a['current_position'] ?? '')],
    ['Exp. 1 - Superior Inmediato', fn($a, $p) => experienciaRows($p)[0]['superior'] ?? ''],
    ['Exp. 1 - Tiempo Trabajado', fn($a, $p) => experienciaRows($p)[0]['tiempo'] ?? ''],
    ['Exp. 1 - Teléfono', fn($a, $p) => experienciaRows($p)[0]['telefono'] ?? ''],
    ['Exp. 1 - Sueldo', fn($a, $p) => experienciaRows($p)[0]['sueldo'] ?? ''],
    ['Exp. 1 - Tareas Principales', fn($a, $p) => experienciaRows($p)[0]['tareas'] ?? ''],
    ['Exp. 1 - Razón de Salida', fn($a, $p) => experienciaRows($p)[0]['razon_salida'] ?? ''],
    ['Otras Experiencias', fn($a, $p) => flattenExperiencias(array_slice(experienciaRows($p), 1))],
    ['Expectativa Salarial', fn($a, $p) => $a['expected_salary'] ?: pv($p, 'adicional.expectativas_salariales')],

    // --- Informacion adicional ---
    ['Mayor Logro', fn($a, $p) => pv($p, 'adicional.mayor_logro')],
    ['Incapacidad o Limitación', fn($a, $p) => pv($p, 'adicional.incapacidad')],
    ['Cuál Incapacidad', fn($a, $p) => pv($p, 'adicional.incapacidad_cual')],
    ['Conoce a un Empleado', fn($a, $p) => pv($p, 'adicional.conoce_empleado')],
    ['Nombre del Empleado', fn($a, $p) => pv($p, 'adicional.conoce_empleado_nombre')],
    ['Medio por el que se Enteró', fn($a, $p) => trim(implode(' / ', array_filter([
        pv($p, 'adicional.medio_vacante'),
        pv($p, 'adicional.medio_vacante_otro'),
    ]))) ?: ($a['source'] ?? '')],
    ['LinkedIn / Portafolio', fn($a, $p) => $a['linkedin_url'] ?? ''],
    ['Firma del Solicitante', fn($a, $p) => pv($p, 'adicional.firma')],
    ['Autoriza uso de Datos', fn($a, $p) => pv($p, 'adicional.acepta_datos')],
    ['CV', function ($a, $p) use ($exportBaseUrl) {
        if (empty($a['cv_path'])) {
            return '';
        }
        return $exportBaseUrl !== '' ? $exportBaseUrl . '/' . ltrim($a['cv_path'], '/') : $a['cv_path'];
    }],
];

// Output Excel content
echo "\xEF\xBB\xBF";
echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
echo '<head><meta charset="UTF-8">';
echo '<style>';
echo 'th { background-color:#244886; color:#FFFFFF; font-weight:bold; text-align:left; vertical-align:middle; }';
echo 'td, th { border:1px solid #BFBFBF; padding:3px 5px; font-family:Calibri,Arial,sans-serif; font-size:11pt; vertical-align:top; }';
echo 'td { mso-number-format:"\@"; }'; // todo como texto: evita que Excel destroce cedulas y telefonos
echo '</style></head>';
echo '<body>';
echo '<table border="1">';

echo '<tr>';
foreach ($columns as $col) {
    echo '<th>' . excelCell($col[0]) . '</th>';
}
echo '</tr>';

foreach ($applications as $app) {
    $payload = formPayload($app);
    echo '<tr>';
    foreach ($columns as $col) {
        echo '<td>' . excelCell($col[1]($app, $payload)) . '</td>';
    }
    echo '</tr>';
}

echo '</table>';
echo '</body>';
echo '</html>';
exit;

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

// Output Excel content
echo "\xEF\xBB\xBF";
echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
echo '<head><meta charset="UTF-8"></head>';
echo '<body>';
echo '<table border="1">';
echo '<tr>';
echo '<th>Código</th>';
echo '<th>Nombre</th>';
echo '<th>Apellido</th>';
echo '<th>Email</th>';
echo '<th>Teléfono</th>';
echo '<th>Vacante</th>';
echo '<th>Rol de Interés</th>';
echo '<th>Departamento</th>';
echo '<th>Estado</th>';
echo '<th>Fecha de Nacimiento</th>';
echo '<th>Edad</th>';
echo '<th>Nacionalidad</th>';
echo '<th>Estado Civil</th>';
echo '<th>Tipo de Sangre</th>';
echo '<th>Estatura</th>';
echo '<th>Peso</th>';
echo '<th>Con Quién Vive</th>';
echo '<th>Personas que Dependen</th>';
echo '<th>Tiene Hijos</th>';
echo '<th>Cantidad de Hijos</th>';
echo '<th>Vivienda Propia</th>';
echo '<th>Cursos / Capacitaciones</th>';
echo '<th>Idiomas</th>';
echo '<th>Educación</th>';
echo '<th>Experiencia (años)</th>';
echo '<th>Puesto Actual</th>';
echo '<th>Empresa Actual</th>';
echo '<th>Expectativa Salarial</th>';
echo '<th>Fecha de Aplicación</th>';
echo '<th>Asignado a</th>';
echo '<th>Calificación</th>';
echo '</tr>';

foreach ($applications as $app) {
    $payload = formPayload($app);
    echo '<tr>';
    echo '<td>' . excelCell($app['application_code']) . '</td>';
    echo '<td>' . excelCell($app['first_name']) . '</td>';
    echo '<td>' . excelCell($app['last_name']) . '</td>';
    echo '<td>' . excelCell($app['email']) . '</td>';
    echo '<td>' . excelCell($app['phone']) . '</td>';
    echo '<td>' . excelCell($app['job_title']) . '</td>';
    echo '<td>' . excelCell($app['role_interest'] ?? payloadValue($payload, 'rol_interes')) . '</td>';
    echo '<td>' . excelCell($app['department']) . '</td>';
    echo '<td>' . excelCell($app['status']) . '</td>';
    echo '<td>' . excelCell(!empty($app['date_of_birth']) ? date('d/m/Y', strtotime($app['date_of_birth'])) : payloadValue($payload, 'fecha_nacimiento')) . '</td>';
    echo '<td>' . excelCell(payloadValue($payload, 'edad')) . '</td>';
    echo '<td>' . excelCell(payloadValue($payload, 'nacionalidad')) . '</td>';
    echo '<td>' . excelCell(payloadValue($payload, 'estado_civil')) . '</td>';
    echo '<td>' . excelCell(payloadValue($payload, 'tipo_sangre')) . '</td>';
    echo '<td>' . excelCell(payloadValue($payload, 'estatura')) . '</td>';
    echo '<td>' . excelCell(payloadValue($payload, 'peso')) . '</td>';
    echo '<td>' . excelCell(payloadValue($payload, 'vive_con')) . '</td>';
    echo '<td>' . excelCell(payloadValue($payload, 'personas_dependen')) . '</td>';
    echo '<td>' . excelCell(payloadValue($payload, 'tiene_hijos')) . '</td>';
    echo '<td>' . excelCell(payloadValue($payload, 'cantidad_hijos')) . '</td>';
    echo '<td>' . excelCell(payloadValue($payload, 'casa_propia')) . '</td>';
    echo '<td>' . excelCell(flattenCursos($payload)) . '</td>';
    echo '<td>' . excelCell(flattenIdiomas($payload)) . '</td>';
    echo '<td>' . excelCell($app['education_level']) . '</td>';
    echo '<td>' . excelCell($app['years_of_experience']) . '</td>';
    echo '<td>' . excelCell($app['current_position'] ?? '') . '</td>';
    echo '<td>' . excelCell($app['current_company'] ?? '') . '</td>';
    echo '<td>' . excelCell($app['expected_salary'] ?? '') . '</td>';
    echo '<td>' . excelCell(!empty($app['applied_date']) ? date('d/m/Y H:i', strtotime($app['applied_date'])) : '') . '</td>';
    echo '<td>' . excelCell($app['assigned_to_name'] ?? '') . '</td>';
    echo '<td>' . excelCell($app['overall_rating'] ?? '') . '</td>';
    echo '</tr>';
}

echo '</table>';
echo '</body>';
echo '</html>';
exit;

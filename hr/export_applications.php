<?php
session_start();
require_once '../db.php';

ensurePermission('hr_recruitment', '../unauthorized.php');

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$job_filter = $_GET['job'] ?? 'all';
$search = trim((string) ($_GET['search'] ?? ''));

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
echo '<th>Departamento</th>';
echo '<th>Estado</th>';
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
    echo '<tr>';
    echo '<td>' . excelCell($app['application_code']) . '</td>';
    echo '<td>' . excelCell($app['first_name']) . '</td>';
    echo '<td>' . excelCell($app['last_name']) . '</td>';
    echo '<td>' . excelCell($app['email']) . '</td>';
    echo '<td>' . excelCell($app['phone']) . '</td>';
    echo '<td>' . excelCell($app['job_title']) . '</td>';
    echo '<td>' . excelCell($app['department']) . '</td>';
    echo '<td>' . excelCell($app['status']) . '</td>';
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

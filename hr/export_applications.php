<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$job_filter = $_GET['job'] ?? 'all';
$search = $_GET['search'] ?? '';

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
    $params['job_id'] = $job_filter;
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
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="solicitudes_empleo_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');

// Output Excel content
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
    echo '<td>' . htmlspecialchars($app['application_code']) . '</td>';
    echo '<td>' . htmlspecialchars($app['first_name']) . '</td>';
    echo '<td>' . htmlspecialchars($app['last_name']) . '</td>';
    echo '<td>' . htmlspecialchars($app['email']) . '</td>';
    echo '<td>' . htmlspecialchars($app['phone']) . '</td>';
    echo '<td>' . htmlspecialchars($app['job_title']) . '</td>';
    echo '<td>' . htmlspecialchars($app['department']) . '</td>';
    echo '<td>' . htmlspecialchars($app['status']) . '</td>';
    echo '<td>' . htmlspecialchars($app['education_level']) . '</td>';
    echo '<td>' . htmlspecialchars($app['years_of_experience']) . '</td>';
    echo '<td>' . htmlspecialchars($app['current_position'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($app['current_company'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($app['expected_salary'] ?? '') . '</td>';
    echo '<td>' . date('d/m/Y H:i', strtotime($app['applied_date'])) . '</td>';
    echo '<td>' . htmlspecialchars($app['assigned_to_name'] ?? '') . '</td>';
    echo '<td>' . ($app['overall_rating'] ?? '') . '</td>';
    echo '</tr>';
}

echo '</table>';
echo '</body>';
echo '</html>';
exit;

<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

include 'db.php';

// Filtros
$date_filter = $_GET['month'] ?? date('Y-m');
$start_date = $date_filter . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

// Consulta para obtener los datos
$query = "
    SELECT 
        u.full_name AS employee,
        u.username,
        DATE(a.timestamp) AS work_date,
        MIN(CASE WHEN a.type IN ('Entry') THEN a.timestamp END) AS first_entry,
        COALESCE(
            MAX(CASE WHEN a.type IN ('Exit') THEN a.timestamp END),
            CONCAT(DATE(a.timestamp), ' 19:00:00')
        ) AS last_exit,
        SUM(CASE WHEN a.type = 'Lunch' THEN TIMESTAMPDIFF(SECOND, a.timestamp, (
            SELECT MIN(a2.timestamp) 
            FROM attendance a2 
            WHERE a2.user_id = a.user_id 
            AND a2.timestamp > a.timestamp 
            AND a2.type NOT IN ('Lunch')
        )) ELSE 0 END) AS total_lunch,
        SUM(CASE WHEN a.type = 'Break' THEN TIMESTAMPDIFF(SECOND, a.timestamp, (
            SELECT MIN(a2.timestamp) 
            FROM attendance a2 
            WHERE a2.user_id = a.user_id 
            AND a2.timestamp > a.timestamp 
            AND a2.type NOT IN ('Break')
        )) ELSE 0 END) AS total_break,
        SUM(CASE WHEN a.type IN ('Meeting', 'Coaching') THEN TIMESTAMPDIFF(SECOND, a.timestamp, (
            SELECT MIN(a2.timestamp) 
            FROM attendance a2 
            WHERE a2.user_id = a.user_id 
            AND a2.timestamp > a.timestamp 
            AND a2.type NOT IN ('Meeting', 'Coaching')
        )) ELSE 0 END) AS total_meeting_coaching,
        GREATEST(
            TIMESTAMPDIFF(SECOND, 
                MIN(CASE WHEN a.type IN ('Entry') THEN a.timestamp END), 
                COALESCE(
                    MAX(CASE WHEN a.type IN ('Exit') THEN a.timestamp END),
                    CONCAT(DATE(a.timestamp), ' 19:00:00')
                )
            ) 
            - SUM(CASE WHEN a.type = 'Lunch' THEN TIMESTAMPDIFF(SECOND, a.timestamp, (
                SELECT MIN(a2.timestamp) 
                FROM attendance a2 
                WHERE a2.user_id = a.user_id 
                AND a2.timestamp > a.timestamp 
                AND a2.type NOT IN ('Lunch')
            )) ELSE 0 END)
            - SUM(CASE WHEN a.type = 'Break' THEN TIMESTAMPDIFF(SECOND, a.timestamp, (
                SELECT MIN(a2.timestamp) 
                FROM attendance a2 
                WHERE a2.user_id = a.user_id 
                AND a2.timestamp > a.timestamp 
                AND a2.type NOT IN ('Break')
            )) ELSE 0 END),
            0
        ) AS total_work_time
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    WHERE a.timestamp BETWEEN ? AND ?
    GROUP BY u.full_name, u.username, DATE(a.timestamp)
    ORDER BY u.full_name, DATE(a.timestamp);
";

$stmt = $pdo->prepare($query);
$stmt->execute([$start_date, $end_date]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Encabezados del archivo XLSX
$columns = [
    'Employee', 'Username', 'Date', 'First Entry', 'Last Exit',
    'Lunch Time', 'Break Time', 'Meeting/Coaching Time', 'Work Time'
];

// Generar contenido del archivo XLSX
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' .
    ' xmlns:o="urn:schemas-microsoft-com:office:office"' .
    ' xmlns:x="urn:schemas-microsoft-com:office:excel"' .
    ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' .
    ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

$xml .= '<Worksheet ss:Name="Report"><Table>' . "\n";

// Encabezados
$xml .= '<Row>';
foreach ($columns as $column) {
    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($column) . '</Data></Cell>';
}
$xml .= '</Row>' . "\n";

// Filas de datos
foreach ($data as $row) {
    $xml .= '<Row>';
    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($row['employee']) . '</Data></Cell>';
    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($row['username']) . '</Data></Cell>';
    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($row['work_date']) . '</Data></Cell>';
    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($row['first_entry'] ?: 'N/A') . '</Data></Cell>';
    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars($row['last_exit'] ?: 'N/A') . '</Data></Cell>';
    $xml .= '<Cell><Data ss:Type="String">' . gmdate('H:i:s', $row['total_lunch'] ?: 0) . '</Data></Cell>';
    $xml .= '<Cell><Data ss:Type="String">' . gmdate('H:i:s', $row['total_break'] ?: 0) . '</Data></Cell>';
    $xml .= '<Cell><Data ss:Type="String">' . gmdate('H:i:s', $row['total_meeting_coaching'] ?: 0) . '</Data></Cell>';
    $xml .= '<Cell><Data ss:Type="String">' . gmdate('H:i:s', $row['total_work_time'] ?: 0) . '</Data></Cell>';
    $xml .= '</Row>' . "\n";
}

$xml .= '</Table></Worksheet></Workbook>';

// Encabezados para la descarga
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="employee_performance_' . $date_filter . '.xls"');

// Salida del contenido XML
echo $xml;
exit;

<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['IT', 'HR'])) {
    header('Location: index.php');
    exit;
}

$date_filter = $_GET['date'] ?? date('Y-m-d');

// Fetch the report data
$report_query = "
    SELECT 
        users.full_name AS agent_name,
        users.username,
        DATE(attendance.timestamp) AS report_date,
        TIME_FORMAT(SEC_TO_TIME(SUM(CASE WHEN attendance.type = 'Entry' THEN TIMESTAMPDIFF(SECOND, attendance.timestamp, (
            SELECT MAX(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.type = 'Exit'
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END)), '%H:%i:%s') AS login_time,
        TIME_FORMAT(SEC_TO_TIME(SUM(CASE WHEN attendance.type IN ('Break', 'Lunch') THEN TIMESTAMPDIFF(SECOND, attendance.timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND a.type NOT IN ('Break', 'Lunch')
        )) ELSE 0 END)), '%H:%i:%s') AS unpaid_time,
        TIME_FORMAT(SEC_TO_TIME(SUM(CASE WHEN attendance.type = 'Entry' THEN TIMESTAMPDIFF(SECOND, attendance.timestamp, (
            SELECT MAX(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.type = 'Exit'
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END)
        - SUM(CASE WHEN attendance.type IN ('Break', 'Lunch') THEN TIMESTAMPDIFF(SECOND, attendance.timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND a.type NOT IN ('Break', 'Lunch')
        )) ELSE 0 END)), '%H:%i:%s') AS paid_time
    FROM attendance
    JOIN users ON attendance.user_id = users.id
    WHERE DATE(attendance.timestamp) = ?
    GROUP BY users.full_name, users.username, DATE(attendance.timestamp)
    ORDER BY users.full_name;
";

$stmt = $pdo->prepare($report_query);
$stmt->execute([$date_filter]);
$report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Define hourly rates
$hourly_rates = [
    'ematos' => 200.00,
    'Jcoronado' => 200.00,
    'Jmirabel' => 200.00,
    'Gbonilla' => 110.00,
    'Ecapellan' => 110.00,
    'Rmota' => 110.00,
    'abatista' => 200.00,
    'ydominguez' => 110.00,
    'elara@presta-max.com' => 200.00,
    'omorel' => 110.00,
    'rbueno' => 200.00,
    'xalfonso' => 200.00,
    'jalmonte' => 110.00
];

// Prepare headers for Excel output
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"Daily_Attendance_Report_{$date_filter}.xls\"");
header("Cache-Control: max-age=0");

// Start the table
echo "<table border='1'>";
echo "<thead>";
echo "<tr>
    <th>Agent Name</th>
    <th>Login Time</th>
    <th>Unpaid Time</th>
    <th>Paid Time</th>
    <th>Amount Earned</th>
</tr>";
echo "</thead>";
echo "<tbody>";

// Write rows
foreach ($report_data as $row) {
    $login_seconds = strtotime($row['login_time']) - strtotime('TODAY');
    $unpaid_seconds = strtotime($row['unpaid_time']) - strtotime('TODAY');
    $paid_seconds = strtotime($row['paid_time']) - strtotime('TODAY');
    $hourly_rate = $hourly_rates[$row['username']] ?? 0;
    $earned = ($paid_seconds / 3600) * $hourly_rate;

    echo "<tr>
        <td>" . htmlspecialchars($row['agent_name']) . "</td>
        <td>" . htmlspecialchars($row['login_time']) . "</td>
        <td>" . htmlspecialchars($row['unpaid_time']) . "</td>
        <td>" . htmlspecialchars($row['paid_time']) . "</td>
        <td>$" . number_format($earned, 2) . "</td>
    </tr>";
}

echo "</tbody>";
echo "</table>";
exit;

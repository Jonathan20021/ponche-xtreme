<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: index.php'); // Redirige a la página de login si no hay sesión activa
    exit;
}

// Verificar si el usuario tiene un rol permitido (IT o HR)
if (!in_array($_SESSION['role'], ['IT', 'HR'])) {
    header('Location: unauthorized.php'); // Redirige a una página de acceso denegado
    exit;
}



// Definir las tarifas por hora para los empleados
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

// Obtener la fecha seleccionada
$date_filter = $_GET['date'] ?? date('Y-m-d');

// Consulta para el reporte diario
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
?>

<?php include 'header.php'; ?>
<div class="container mx-auto mt-6">
    <h2 class="text-2xl font-bold mb-4">Daily Attendance Report</h2>
    <form method="GET" class="mb-4">
    <label for="date" class="font-bold">Select Date:</label>
    <input type="date" name="date" id="date" value="<?= htmlspecialchars($date_filter) ?>" class="p-2 border rounded">
    <button type="submit" class="bg-green-500 text-white py-2 px-4 ml-2 rounded hover:bg-green-700">Filter</button>
    <a href="download_excel_daily.php?date=<?= htmlspecialchars($date_filter) ?>" 
       class="bg-blue-500 text-white py-2 px-4 ml-2 rounded hover:bg-blue-700">
        Download Excel
    </a>
</form>

    <table class="w-full border-collapse mt-4 bg-white shadow-md rounded">
        <thead>
            <tr class="bg-gray-200">
                <th class="p-2 border">Agent Name</th>
                <th class="p-2 border">Login Time</th>
                <th class="p-2 border">Unpaid Time</th>
                <th class="p-2 border">Paid Time</th>
                <th class="p-2 border">Amount Earned</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $total_login = $total_unpaid = $total_paid = $total_earned = 0; 
            foreach ($report_data as $row): 
                $login_seconds = strtotime($row['login_time']) - strtotime('TODAY');
                $unpaid_seconds = strtotime($row['unpaid_time']) - strtotime('TODAY');
                $paid_seconds = strtotime($row['paid_time']) - strtotime('TODAY');
                $total_login += $login_seconds;
                $total_unpaid += $unpaid_seconds;
                $total_paid += $paid_seconds;

                // Calcular el dinero generado
                $hourly_rate = $hourly_rates[$row['username']] ?? 0;
                $earned = ($paid_seconds / 3600) * $hourly_rate;
                $total_earned += $earned;
            ?>
                <tr>
                    <td class="p-2 border"><?= htmlspecialchars($row['agent_name']) ?></td>
                    <td class="p-2 border"><?= $row['login_time'] ?></td>
                    <td class="p-2 border"><?= $row['unpaid_time'] ?></td>
                    <td class="p-2 border"><?= $row['paid_time'] ?></td>
                    <td class="p-2 border">$<?= number_format($earned, 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="bg-gray-200 font-bold">
                <td class="p-2 border">Total</td>
                <td class="p-2 border"><?= gmdate("H:i:s", $total_login) ?></td>
                <td class="p-2 border"><?= gmdate("H:i:s", $total_unpaid) ?></td>
                <td class="p-2 border"><?= gmdate("H:i:s", $total_paid) ?></td>
                <td class="p-2 border">$<?= number_format($total_earned, 2) ?></td>
            </tr>
        </tbody>
    </table>
</div>






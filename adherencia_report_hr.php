<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Verificar rol de usuario
if (!in_array($_SESSION['role'], ['IT', 'HR'])) {
    header('Location: unauthorized.php');
    exit;
}

include 'db.php';

// Definir las metas para los horarios
$goals = [
    'sch_in' => '10:00:00', // Hora de entrada
    'sch_out' => '19:00:00', // Hora de salida
    'lunch' => 45 * 60, // 45 minutos (en segundos)
    'break' => 15 * 60, // 15 minutos (en segundos)
    'meeting_coaching' => 45 * 60 // 45 minutos (en segundos)
];

// Horas programadas por día (en segundos)
$scheduled_hours_per_day = 8 * 3600; // 8 horas

// Tarifas por hora para los empleados
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

$date_filter = $_GET['month'] ?? date('Y-m');
$start_date = $date_filter . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

// Configuración de paginación
$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Consulta para el reporte detallado diario con paginación
$query_daily = "
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
    ORDER BY u.full_name, DATE(a.timestamp)
    LIMIT $offset, $records_per_page;
";

$stmt = $pdo->prepare($query_daily);
$stmt->execute([$start_date, $end_date]);
$report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular el total de páginas
$total_records = $pdo->query("
    SELECT COUNT(DISTINCT u.full_name, DATE(a.timestamp)) AS total
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    WHERE a.timestamp BETWEEN '$start_date' AND '$end_date'
")->fetchColumn();
$total_pages = ceil($total_records / $records_per_page);

// Consulta para el reporte mensual consolidado
$query_monthly = "
    SELECT 
        u.full_name AS employee,
        u.username,
        SUM(
            CASE 
                WHEN a.type = 'Lunch' THEN 
                    TIMESTAMPDIFF(SECOND, a.timestamp, (
                        SELECT MIN(a2.timestamp) 
                        FROM attendance a2 
                        WHERE a2.user_id = a.user_id 
                        AND a2.timestamp > a.timestamp 
                        AND a2.type NOT IN ('Lunch')
                    )) 
                ELSE 0 
            END
        ) AS total_lunch,
        SUM(
            CASE 
                WHEN a.type = 'Break' THEN 
                    TIMESTAMPDIFF(SECOND, a.timestamp, (
                        SELECT MIN(a2.timestamp) 
                        FROM attendance a2 
                        WHERE a2.user_id = a.user_id 
                        AND a2.timestamp > a.timestamp 
                        AND a2.type NOT IN ('Break')
                    )) 
                ELSE 0 
            END
        ) AS total_break,
        SUM(
            CASE 
                WHEN a.type IN ('Meeting', 'Coaching') THEN 
                    TIMESTAMPDIFF(SECOND, a.timestamp, (
                        SELECT MIN(a2.timestamp) 
                        FROM attendance a2 
                        WHERE a2.user_id = a.user_id 
                        AND a2.timestamp > a.timestamp 
                        AND a2.type NOT IN ('Meeting', 'Coaching')
                    )) 
                ELSE 0 
            END
        ) AS total_meeting_coaching,
        SUM(
            CASE 
                WHEN a.type = 'Entry' THEN 
                    TIMESTAMPDIFF(SECOND, a.timestamp, (
                        SELECT MAX(a2.timestamp) 
                        FROM attendance a2 
                        WHERE a2.user_id = a.user_id 
                        AND a2.timestamp > a.timestamp 
                        AND a2.type = 'Exit'
                    )) 
                ELSE 0 
            END
        ) AS total_work_time
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    WHERE a.timestamp BETWEEN ? AND ?
    GROUP BY u.full_name, u.username
    ORDER BY u.full_name;
";



$stmt_monthly = $pdo->prepare($query_monthly);
$stmt_monthly->execute([$start_date, $end_date]);
$monthly_data = $stmt_monthly->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'header.php'; ?>
<div class="container mx-auto mt-6">
    <h2 class="text-2xl font-bold mb-4">Monthly Employee Performance Report</h2>
    <form method="GET" class="mb-4">
    <label for="month" class="font-bold">Select Month:</label>
    <input type="month" name="month" id="month" value="<?= htmlspecialchars($date_filter) ?>" class="p-2 border rounded">
    <button type="submit" class="bg-green-500 text-white py-2 px-4 ml-2 rounded hover:bg-green-700">
        Filter
    </button>
    <a href="download_excel.php?month=<?= htmlspecialchars($date_filter) ?>" 
   class="bg-blue-500 text-white py-2 px-4 ml-2 rounded hover:bg-blue-700">
   Download Excel
</a>

</form>



    <table class="w-full border-collapse bg-white shadow-md rounded mt-4">
        <thead>
            <tr class="bg-gray-200">
                <th class="p-2 border">Employee</th>
                <th class="p-2 border">Date</th>
                <th class="p-2 border">First Entry</th>
                <th class="p-2 border">Last Exit</th>
                <th class="p-2 border">Lunch Time</th>
                <th class="p-2 border">Break Time</th>
                <th class="p-2 border">Meeting/Coaching</th>
                <th class="p-2 border">Work Time</th>
                <th class="p-2 border">Late?</th>
                <th class="p-2 border">ABS (%)</th>
                <th class="p-2 border">Amount of Hours Paid</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($report_data as $row): ?>
                <?php
                $work_time = $row['total_work_time'] ?: 0;

                // Calcula ABS (%) basado en las horas programadas
                $abs_percent = ($work_time / $scheduled_hours_per_day) * 100;

                $late = (strtotime($row['first_entry']) > strtotime($row['work_date'] . ' ' . $goals['sch_in']));

                // Calcular el monto de horas pagadas
                $hourly_rate = $hourly_rates[$row['username']] ?? 0;
                $earned = ($work_time / 3600) * $hourly_rate;
                ?>
                <tr>
                    <td class="p-2 border"><?= htmlspecialchars($row['employee']) ?></td>
                    <td class="p-2 border"><?= htmlspecialchars($row['work_date']) ?></td>
                    <td class="p-2 border"><?= htmlspecialchars($row['first_entry'] ?: 'N/A') ?></td>
                    <td class="p-2 border"><?= htmlspecialchars($row['last_exit'] ?: 'N/A') ?></td>
                    <td class="p-2 border"><?= gmdate('H:i:s', $row['total_lunch'] ?: 0) ?></td>
                    <td class="p-2 border"><?= gmdate('H:i:s', $row['total_break'] ?: 0) ?></td>
                    <td class="p-2 border"><?= gmdate('H:i:s', $row['total_meeting_coaching'] ?: 0) ?></td>
                    <td class="p-2 border"><?= gmdate('H:i:s', $work_time) ?></td>
                    <td class="p-2 border"><?= $late ? 'Yes' : 'No' ?></td>
                    <td class="p-2 border"><?= number_format($abs_percent, 2) ?>%</td>
                    <td class="p-2 border">$<?= number_format($earned, 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <nav aria-label="Table Pagination">
        <ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= ($i == $current_page) ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&month=<?= htmlspecialchars($date_filter) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
</div>

<!-- Sección de Reporte Mensual Consolidado -->
<div class="container mx-auto mt-6">
    <h2 class="text-2xl font-bold mb-4">Monthly Consolidated Employee Report</h2>
    <table class="w-full border-collapse bg-white shadow-md rounded mt-4">
        <thead>
            <tr class="bg-gray-200">
                <th class="p-2 border">Employee</th>
                <th class="p-2 border">Total Lunch Time</th>
                <th class="p-2 border">Total Break Time</th>
                <th class="p-2 border">Total Meeting/Coaching</th>
                <th class="p-2 border">Total Work Time</th>
                <th class="p-2 border">ADH (%)</th>
                <th class="p-2 border">Amount Earned</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            foreach ($monthly_data as $row): 
                $username = $row['username'];
                $work_time = $row['total_work_time'] ?: 0; // Total Work Time en segundos

                // Calcular el monto ganado (Amount Earned)
                $hourly_rate = $hourly_rates[$username] ?? 0; // Tarifa por hora
                $earned = ($work_time / 3600) * $hourly_rate; // Convertir a horas y multiplicar por la tarifa

                // Calcular ADH basado en el tiempo trabajado y las horas programadas
                $adh_percent = ($work_time / ($scheduled_hours_per_day * count($report_data))) * 100;
            ?>
                <tr>
                    <td class="p-2 border"><?= htmlspecialchars($row['employee']) ?></td>
                    <td class="p-2 border"><?= gmdate('H:i:s', $row['total_lunch'] ?: 0) ?></td>
                    <td class="p-2 border"><?= gmdate('H:i:s', $row['total_break'] ?: 0) ?></td>
                    <td class="p-2 border"><?= gmdate('H:i:s', $row['total_meeting_coaching'] ?: 0) ?></td>
                    <td class="p-2 border"><?= gmdate('H:i:s', $work_time) ?></td>
                    <td class="p-2 border"><?= number_format($adh_percent, 2) ?>%</td>
                    <td class="p-2 border">$<?= number_format($earned, 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>


<!-- Scripts DataTables -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#employeeTable').DataTable({
            paging: true,
            searching: true,
            ordering: true,
            pageLength: 10,
            lengthChange: true
        });
    });
</script>
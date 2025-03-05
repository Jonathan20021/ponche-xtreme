<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login_agent.php');
    exit;
}

include 'db.php';

$user_id = $_SESSION['user_id'];
$date_filter = $_GET['dates'] ?? date('Y-m-d');

$query = "
    SELECT 
        attendance.type,
        DATE(attendance.timestamp) AS record_date,
        TIME(attendance.timestamp) AS record_time,
        attendance.ip_address
    FROM attendance
    WHERE attendance.user_id = ? AND DATE(attendance.timestamp) = ?
    ORDER BY attendance.timestamp ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$user_id, $date_filter]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Consulta modificada para calcular el tiempo total excluyendo lunch y break
$time_summary_query = "
    SELECT 
        SUM(CASE WHEN type = 'Break' THEN TIMESTAMPDIFF(SECOND, timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS total_break,
        SUM(CASE WHEN type = 'Lunch' THEN TIMESTAMPDIFF(SECOND, timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS total_lunch,
        SUM(CASE WHEN type = 'Follow Up' THEN TIMESTAMPDIFF(SECOND, timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS total_follow_up,
        SUM(CASE WHEN type = 'Ready' THEN TIMESTAMPDIFF(SECOND, timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS total_ready,
        (
            SUM(CASE WHEN type = 'Entry' THEN TIMESTAMPDIFF(SECOND, timestamp, (
                SELECT MAX(a.timestamp) 
                FROM attendance a 
                WHERE a.user_id = attendance.user_id 
                AND DATE(a.timestamp) = DATE(attendance.timestamp)
                AND a.type = 'Exit'
            )) ELSE 0 END) -
            SUM(CASE WHEN type = 'Break' THEN TIMESTAMPDIFF(SECOND, timestamp, (
                SELECT MIN(a.timestamp) 
                FROM attendance a 
                WHERE a.user_id = attendance.user_id 
                AND a.timestamp > attendance.timestamp 
                AND DATE(a.timestamp) = DATE(attendance.timestamp)
            )) ELSE 0 END) -
            SUM(CASE WHEN type = 'Lunch' THEN TIMESTAMPDIFF(SECOND, timestamp, (
                SELECT MIN(a.timestamp) 
                FROM attendance a 
                WHERE a.user_id = attendance.user_id 
                AND a.timestamp > attendance.timestamp 
                AND DATE(a.timestamp) = DATE(attendance.timestamp)
            )) ELSE 0 END)
        ) AS total_work
    FROM attendance
    WHERE user_id = ? AND DATE(timestamp) = ?
";

$time_summary_stmt = $pdo->prepare($time_summary_query);
$time_summary_stmt->execute([$user_id, $date_filter]);
$time_summary = $time_summary_stmt->fetch(PDO::FETCH_ASSOC);

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

$username = $_SESSION['username'];
$hourly_rate = $hourly_rates[$username] ?? 0;
$total_work_hours = $time_summary['total_work'] / 3600;
$total_payment = round($total_work_hours * $hourly_rate, 2);

include 'header_agent.php'; 
?>

<div class="container mx-auto mt-6">
    <h2 class="text-2xl font-bold mb-4">Work Time Summary (Daily)</h2>
    <form method="GET" class="mb-4">
        <label for="dates" class="block text-lg font-bold mb-2">Select Date:</label>
        <input type="date" name="dates" id="dates" value="<?= htmlspecialchars($date_filter) ?>" class="p-2 border rounded w-full md:w-1/3">
        <button type="submit" class="bg-blue-500 text-white py-2 px-4 mt-2 rounded hover:bg-blue-700">Filter</button>
    </form>

    <div class="bg-white p-6 rounded shadow-md">
        <table class="w-full border-collapse bg-white shadow-md rounded mt-4">
            <thead>
                <tr class="bg-gray-200">
                    <th class="p-2 border">Break Time (hh:mm:ss)</th>
                    <th class="p-2 border">Lunch Time (hh:mm:ss)</th>
                    <th class="p-2 border">Follow Up Time (hh:mm:ss)</th>
                    <th class="p-2 border">Ready Time (hh:mm:ss)</th>
                    <th class="p-2 border">Work Time (hh:mm:ss)</th>
                    <th class="p-2 border">Total Payment ($)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="p-2 border"><?= gmdate('H:i:s', $time_summary['total_break'] ?: 0) ?></td>
                    <td class="p-2 border"><?= gmdate('H:i:s', $time_summary['total_lunch'] ?: 0) ?></td>
                    <td class="p-2 border"><?= gmdate('H:i:s', $time_summary['total_follow_up'] ?: 0) ?></td>
                    <td class="p-2 border"><?= gmdate('H:i:s', $time_summary['total_ready'] ?: 0) ?></td>
                    <td class="p-2 border"><?= gmdate('H:i:s', $time_summary['total_work'] ?: 0) ?></td>
                    <td class="p-2 border">$<?= number_format($total_payment, 2) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-lg mt-8">
        <h3 class="text-lg font-semibold mb-4">Attendance Records</h3>
        <table class="w-full border-collapse bg-white shadow-md rounded mt-4">
            <thead>
                <tr class="bg-gray-200">
                    <th class="p-2 border">Type</th>
                    <th class="p-2 border">Date</th>
                    <th class="p-2 border">Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($records)): ?>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td class="p-2 border"><?= htmlspecialchars($record['type']) ?></td>
                            <td class="p-2 border"><?= htmlspecialchars($record['record_date']) ?></td>
                            <td class="p-2 border"><?= htmlspecialchars($record['record_time']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="text-center p-4">No records found for the selected date.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

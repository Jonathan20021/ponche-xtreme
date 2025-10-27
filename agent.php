<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login_agent.php');
    exit;
}

include 'db.php';

$user_id = $_SESSION['user_id'];
$date_filter = $_GET['dates'] ?? date('Y-m-d');
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Query for daily records
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

// Query for daily time summary
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

// New query for accumulated payments
$accumulated_payments_query = "
    SELECT 
        DATE(timestamp) as work_date,
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
        )) ELSE 0 END) as total_work_seconds
    FROM attendance
    WHERE user_id = ? AND DATE(timestamp) BETWEEN ? AND ?
    GROUP BY DATE(timestamp)
    ORDER BY work_date ASC
";

$time_summary_stmt = $pdo->prepare($time_summary_query);
$time_summary_stmt->execute([$user_id, $date_filter]);
$time_summary = $time_summary_stmt->fetch(PDO::FETCH_ASSOC);

$accumulated_payments_stmt = $pdo->prepare($accumulated_payments_query);
$accumulated_payments_stmt->execute([$user_id, $start_date, $end_date]);
$accumulated_payments = $accumulated_payments_stmt->fetchAll(PDO::FETCH_ASSOC);

$hourly_rates = getUserHourlyRates($pdo);

$username = $_SESSION['username'];
$hourly_rate = $hourly_rates[$username] ?? 0;
$total_work_hours = $time_summary['total_work'] / 3600;
$total_payment = round($total_work_hours * $hourly_rate, 2);

// Calculate accumulated payments
$accumulated_total = 0;
foreach ($accumulated_payments as $payment) {
    $accumulated_total += ($payment['total_work_seconds'] / 3600) * $hourly_rate;
}

include 'header_agent.php'; 
?>

<div class="container mx-auto px-4 py-8">
    <!-- Daily Summary Section -->
    <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
        <h2 class="text-2xl font-bold mb-6 text-gray-800">Daily Work Summary</h2>
        
        <form method="GET" class="mb-6">
            <div class="flex flex-wrap gap-4 items-end">
                <div class="flex-1 min-w-[200px]">
                    <label for="dates" class="block text-sm font-medium text-gray-700 mb-1">Select Date:</label>
                    <input type="date" name="dates" id="dates" value="<?= htmlspecialchars($date_filter) ?>" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                </div>
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 transition-colors">
                    Filter
                </button>
            </div>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-blue-50 p-4 rounded-lg">
                <h3 class="text-lg font-semibold text-blue-800 mb-2">Work Time</h3>
                <p class="text-2xl font-bold text-blue-600"><?= gmdate('H:i:s', $time_summary['total_work'] ?: 0) ?></p>
            </div>
            <div class="bg-green-50 p-4 rounded-lg">
                <h3 class="text-lg font-semibold text-green-800 mb-2">Daily Payment</h3>
                <p class="text-2xl font-bold text-green-600">$<?= number_format($total_payment, 2) ?></p>
            </div>
            <div class="bg-purple-50 p-4 rounded-lg">
                <h3 class="text-lg font-semibold text-purple-800 mb-2">Break Time</h3>
                <p class="text-2xl font-bold text-purple-600"><?= gmdate('H:i:s', $time_summary['total_break'] ?: 0) ?></p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (!empty($records)): ?>
                        <?php foreach ($records as $record): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($record['type']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($record['record_date']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($record['record_time']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="px-6 py-4 text-center text-gray-500">No records found for the selected date.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Accumulated Payments Section -->
    <div class="bg-white rounded-lg shadow-lg p-6">
        <h2 class="text-2xl font-bold mb-6 text-gray-800">Accumulated Payments</h2>
        
        <form method="GET" class="mb-6">
            <div class="flex flex-wrap gap-4 items-end">
                <div class="flex-1 min-w-[200px]">
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date:</label>
                    <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date:</label>
                    <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                </div>
                <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded-md hover:bg-green-700 transition-colors">
                    Calculate
                </button>
            </div>
        </form>

        <div class="bg-green-50 p-6 rounded-lg mb-6">
            <h3 class="text-lg font-semibold text-green-800 mb-2">Total Accumulated Payment</h3>
            <p class="text-3xl font-bold text-green-600">$<?= number_format($accumulated_total, 2) ?></p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Work Hours</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($accumulated_payments as $payment): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($payment['work_date']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= number_format($payment['total_work_seconds'] / 3600, 2) ?> hrs</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">$<?= number_format(($payment['total_work_seconds'] / 3600) * $hourly_rate, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add any interactive features here
    const dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach(input => {
        input.addEventListener('change', function() {
            this.form.submit();
        });
    });
});
</script>
<?php include 'footer.php'; ?>


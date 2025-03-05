<?php
session_start();
if (!isset($_SESSION['employee_id'])) {
    header('Location: login_agent.php'); // Redirige si no hay sesión activa
    exit;
}

include '../db.php';

$employee_id = $_SESSION['employee_id'];
$username = $_SESSION['username'];

// Consultar registros de asistencia del usuario logueado
$records_query = "
    SELECT 
        id, 
        type, 
        DATE(timestamp) AS record_date, 
        TIME(timestamp) AS record_time 
    FROM attendance 
    WHERE user_id = ? 
    ORDER BY timestamp DESC
";
$stmt = $pdo->prepare($records_query);
$stmt->execute([$employee_id]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular métricas de tardanzas
$tardiness_query = "
    SELECT 
        COUNT(CASE WHEN type = 'Entry' AND TIME(timestamp) > '10:05:00' THEN 1 END) AS late_entries,
        COUNT(CASE WHEN type = 'Lunch' AND TIME(timestamp) > '14:00:00' THEN 1 END) AS late_lunches,
        COUNT(CASE WHEN type = 'Break' AND TIME(timestamp) > '17:00:00' THEN 1 END) AS late_breaks,
        COUNT(*) AS total_entries
    FROM attendance 
    WHERE user_id = ?
";
$tardiness_stmt = $pdo->prepare($tardiness_query);
$tardiness_stmt->execute([$employee_id]);
$tardiness_data = $tardiness_stmt->fetch(PDO::FETCH_ASSOC);

$total_tardiness = 0;
if ($tardiness_data['total_entries'] > 0) {
    $total_tardiness = round(
        (($tardiness_data['late_entries'] + $tardiness_data['late_lunches'] + $tardiness_data['late_breaks']) 
        / $tardiness_data['total_entries']) * 100, 2
    );
}
?>

<?php include 'header_agent.php'; ?>
<div class="container mx-auto mt-6">
    <h2 class="text-2xl font-bold mb-4">My Attendance Records</h2>

    <div class="bg-white p-6 rounded shadow-md mb-6">
        <h3 class="text-lg font-semibold">Tardiness Metrics</h3>
        <p class="text-4xl font-bold <?= $total_tardiness > 50 ? 'text-red-500' : ($total_tardiness > 25 ? 'text-yellow-500' : 'text-green-500') ?>">
            <?= $total_tardiness ?>%
        </p>
    </div>

    <div class="bg-white p-6 rounded shadow-md">
        <table class="table w-full mt-4">
            <thead>
                <tr>
                    <th class="p-2 border">ID</th>
                    <th class="p-2 border">Type</th>
                    <th class="p-2 border">Date</th>
                    <th class="p-2 border">Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td class="p-2 border"><?= $record['id'] ?></td>
                        <td class="p-2 border"><?= htmlspecialchars($record['type']) ?></td>
                        <td class="p-2 border"><?= htmlspecialchars($record['record_date']) ?></td>
                        <td class="p-2 border"><?= htmlspecialchars($record['record_time']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="4" class="text-center p-4">No records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>

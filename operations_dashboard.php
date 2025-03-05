<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php'); // Redirige a la página de login si no hay sesión activa
    exit;
}

include 'db.php';

// Obtener la fecha seleccionada
$date_filter = $_GET['date'] ?? date('Y-m-d');

// Consulta para la distribución de tipos de actividad
$type_distribution_query = "
    SELECT 
        type, 
        TIME_FORMAT(SEC_TO_TIME(SUM(TIMESTAMPDIFF(SECOND, timestamp, (
            SELECT MIN(a.timestamp)
            FROM attendance a
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )))), '%H:%i:%s') AS total_time
    FROM attendance
    WHERE DATE(timestamp) = ?
    GROUP BY type;
";

$type_stmt = $pdo->prepare($type_distribution_query);
$type_stmt->execute([$date_filter]);
$type_distribution = $type_stmt->fetchAll(PDO::FETCH_ASSOC);

// Formatear los datos para Chart.js
$type_labels = json_encode(array_column($type_distribution, 'type'));
$type_data = json_encode(array_map(function ($item) {
    return (int) gmdate("H", strtotime($item['total_time'])) * 60 + (int) gmdate("i", strtotime($item['total_time']));
}, $type_distribution));

// Consulta para los empleados que no marcaron "Entry"
$no_entry_query = "
    SELECT 
        users.full_name, 
        users.username, 
        MIN(attendance.timestamp) AS first_activity_time, 
        attendance.type AS first_activity_type
    FROM attendance
    JOIN users ON attendance.user_id = users.id
    WHERE DATE(attendance.timestamp) = ?
    AND attendance.type != 'Entry'
    GROUP BY users.id
    HAVING COUNT(CASE WHEN attendance.type = 'Entry' THEN 1 END) = 0;
";

$no_entry_stmt = $pdo->prepare($no_entry_query);
$no_entry_stmt->execute([$date_filter]);
$no_entry_data = $no_entry_stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<?php include 'header.php'; ?>
<div class="container mx-auto mt-6">
    <h2 class="text-2xl font-bold mb-4">Attendance Dashboard</h2>

    <!-- Filtro por fecha -->
    <form method="GET" class="mb-4">
        <label for="date" class="font-bold">Select Date:</label>
        <input type="date" name="date" id="date" value="<?= htmlspecialchars($date_filter) ?>" class="p-2 border rounded">
        <button type="submit" class="bg-green-500 text-white py-2 px-4 ml-2 rounded hover:bg-green-700">Filter</button>
    </form>

    <!-- Gráfico de distribución por tipo de actividad -->
    <div class="bg-white p-6 rounded shadow-md mb-6">
        <h3 class="text-xl font-bold mb-4">Type Distribution</h3>
        <canvas id="typeDistributionChart" width="400" height="200"></canvas>
    </div>

    <!-- Empleados sin "Entry" -->
    <div class="bg-white p-6 rounded shadow-md mb-6">
        <h3 class="text-xl font-bold mb-4">Employees Without Entry</h3>
        <table class="w-full border-collapse">
            <thead>
                <tr class="bg-gray-200">
                    <th class="p-2 border">Full Name</th>
                    <th class="p-2 border">Username</th>
                    <th class="p-2 border">First Activity</th>
                    <th class="p-2 border">First Activity Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($no_entry_data as $row): ?>
                    <tr>
                        <td class="p-2 border"><?= htmlspecialchars($row['full_name']) ?></td>
                        <td class="p-2 border"><?= htmlspecialchars($row['username']) ?></td>
                        <td class="p-2 border"><?= htmlspecialchars($row['first_activity_type']) ?></td>
                        <td class="p-2 border"><?= htmlspecialchars($row['first_activity_time']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($no_entry_data)): ?>
                    <tr>
                        <td colspan="4" class="text-center p-4">All employees marked Entry today.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('typeDistributionChart').getContext('2d');
        const typeDistributionChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= $type_labels ?>,
                datasets: [{
                    label: 'Minutes Spent',
                    data: <?= $type_data ?>,
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.2)',
                        'rgba(54, 162, 235, 0.2)',
                        'rgba(255, 206, 86, 0.2)',
                        'rgba(75, 192, 192, 0.2)',
                        'rgba(153, 102, 255, 0.2)',
                        'rgba(255, 159, 64, 0.2)'
                    ],
                    borderColor: [
                        'rgba(255, 99, 132, 1)',
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 206, 86, 1)',
                        'rgba(75, 192, 192, 1)',
                        'rgba(153, 102, 255, 1)',
                        'rgba(255, 159, 64, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    });
</script>


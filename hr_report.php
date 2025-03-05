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
    'elara' => 200.00,
    'omorel' => 110.00,
    'rbueno' => 200.00,
    'xalfonso' => 200.00,
    'jalmonte' => 110.00
];

// Obtener los filtros
$date_filter = $_GET['date'] ?? date('Y-m-d');
$employee_filter = $_GET['employee'] ?? 'all';
$payroll_start_date = $_GET['payroll_start'] ?? date('Y-m-01');
$payroll_end_date = $_GET['payroll_end'] ?? date('Y-m-t');

// Consulta para obtener empleados
$emp_query = "SELECT id, full_name FROM users ORDER BY full_name";
$emp_stmt = $pdo->query($emp_query);
$employees = $emp_stmt->fetchAll(PDO::FETCH_ASSOC);

// Consulta para la nómina mejorada
$payroll_query = "
    WITH daily_hours AS (
        SELECT 
            users.id,
            users.full_name,
            users.username,
            DATE(attendance.timestamp) as work_date,
            SUM(CASE 
                WHEN attendance.type = 'Entry' THEN 
                    TIMESTAMPDIFF(SECOND, attendance.timestamp, 
                        COALESCE(
                            (SELECT MIN(a.timestamp) 
                            FROM attendance a 
                            WHERE a.user_id = attendance.user_id 
                            AND a.timestamp > attendance.timestamp 
                            AND a.type = 'Exit'
                            AND DATE(a.timestamp) = DATE(attendance.timestamp)),
                            attendance.timestamp
                        )
                    )
                ELSE 0 
            END) as total_seconds,
            SUM(CASE 
                WHEN attendance.type IN ('Break', 'Lunch') THEN 
                    TIMESTAMPDIFF(SECOND, attendance.timestamp, 
                        COALESCE(
                            (SELECT MIN(a.timestamp) 
                            FROM attendance a 
                            WHERE a.user_id = attendance.user_id 
                            AND a.timestamp > attendance.timestamp 
                            AND a.type NOT IN ('Break', 'Lunch')
                            AND DATE(a.timestamp) = DATE(attendance.timestamp)),
                            attendance.timestamp
                        )
                    )
                ELSE 0 
            END) as break_seconds
        FROM attendance
        JOIN users ON attendance.user_id = users.id
        WHERE DATE(attendance.timestamp) BETWEEN ? AND ?
        GROUP BY users.id, users.full_name, users.username, DATE(attendance.timestamp)
    )
    SELECT 
        id,
        full_name AS agent_name,
        username,
        COUNT(DISTINCT work_date) as days_worked,
        TIME_FORMAT(SEC_TO_TIME(SUM(total_seconds - break_seconds)), '%H:%i:%s') as total_paid_time,
        SUM(total_seconds - break_seconds) as total_seconds
    FROM daily_hours
    GROUP BY id, full_name, username
    ORDER BY full_name;
";

$payroll_stmt = $pdo->prepare($payroll_query);
$payroll_stmt->execute([$payroll_start_date, $payroll_end_date]);
$payroll_data = $payroll_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular totales de nómina con mayor precisión
$total_payroll = 0;
$total_days = 0;
$total_hours = 0;

foreach ($payroll_data as $row) {
    $paid_seconds = $row['total_seconds'];
    $hourly_rate = $hourly_rates[$row['username']] ?? 0;
    $row['total_payment'] = ($paid_seconds / 3600) * $hourly_rate;
    $total_payroll += $row['total_payment'];
    $total_days += $row['days_worked'];
    $total_hours += $paid_seconds / 3600;
}

// Consulta principal mejorada
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
        )) ELSE 0 END)), '%H:%i:%s') AS paid_time,
        COUNT(DISTINCT CASE WHEN attendance.type = 'Entry' THEN DATE(attendance.timestamp) END) as attendance_days
    FROM attendance
    JOIN users ON attendance.user_id = users.id
    WHERE DATE(attendance.timestamp) = ?
    " . ($employee_filter !== 'all' ? "AND users.id = ?" : "") . "
    GROUP BY users.full_name, users.username, DATE(attendance.timestamp)
    ORDER BY users.full_name;
";

$stmt = $pdo->prepare($report_query);
$params = [$date_filter];
if ($employee_filter !== 'all') $params[] = $employee_filter;
$stmt->execute($params);
$report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular estadísticas generales
$total_employees = count($report_data);
$total_hours = 0;
$total_earned = 0;
$avg_hours_per_employee = 0;

foreach ($report_data as $row) {
    $paid_seconds = strtotime($row['paid_time']) - strtotime('TODAY');
    $total_hours += $paid_seconds / 3600;
    $hourly_rate = $hourly_rates[$row['username']] ?? 0;
    $total_earned += ($paid_seconds / 3600) * $hourly_rate;
}

$avg_hours_per_employee = $total_employees > 0 ? $total_hours / $total_employees : 0;
?>

<?php include 'header.php'; ?>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="min-h-screen bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <!-- Header Section -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-800 rounded-t-xl p-6 mb-6">
            <div class="flex justify-between items-center">
                <h2 class="text-3xl font-bold text-white">HR Analytics Dashboard</h2>
                <div class="flex space-x-4">
                    <a href="download_excel_daily.php?date=<?= htmlspecialchars($date_filter) ?>" 
                       class="inline-flex items-center px-4 py-2 bg-white text-blue-600 rounded-lg hover:bg-blue-50 transition duration-200">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Export Excel
                    </a>
                    <button onclick="window.print()" 
                            class="inline-flex items-center px-4 py-2 bg-white text-blue-600 rounded-lg hover:bg-blue-50 transition duration-200">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                        </svg>
                        Print Report
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="bg-white rounded-b-xl shadow-xl p-6">
            <!-- Payroll Section -->
            <div class="mb-12">
                <h3 class="text-2xl font-bold text-gray-800 mb-6">Payroll Management</h3>
                
                <!-- Payroll Filters -->
                <form method="GET" class="mb-8">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-200">
                            <label for="payroll_start" class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                            <input type="date" name="payroll_start" id="payroll_start" 
                                   value="<?= htmlspecialchars($payroll_start_date) ?>" 
                                   class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200">
                        </div>
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-200">
                            <label for="payroll_end" class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                            <input type="date" name="payroll_end" id="payroll_end" 
                                   value="<?= htmlspecialchars($payroll_end_date) ?>" 
                                   class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200">
                        </div>
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 flex items-end">
                            <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 transition duration-200 flex items-center justify-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                                </svg>
                                Generate Payroll
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Payroll Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 p-6 rounded-xl text-white transform hover:scale-105 transition duration-200">
                        <h3 class="text-lg font-semibold mb-2">Total Payroll</h3>
                        <p class="text-3xl font-bold">$<?= number_format($total_payroll, 2) ?></p>
                    </div>
                    <div class="bg-gradient-to-br from-pink-500 to-pink-600 p-6 rounded-xl text-white transform hover:scale-105 transition duration-200">
                        <h3 class="text-lg font-semibold mb-2">Total Days Worked</h3>
                        <p class="text-3xl font-bold"><?= $total_days ?></p>
                    </div>
                    <div class="bg-gradient-to-br from-orange-500 to-orange-600 p-6 rounded-xl text-white transform hover:scale-105 transition duration-200">
                        <h3 class="text-lg font-semibold mb-2">Total Hours</h3>
                        <p class="text-3xl font-bold"><?= number_format($total_hours, 2) ?></p>
                    </div>
                </div>

                <!-- Payroll Table -->
                <div class="overflow-x-auto rounded-xl border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Days Worked</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Hours</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hourly Rate</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Payment</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($payroll_data as $row): 
                                $hourly_rate = $hourly_rates[$row['username']] ?? 0;
                                $total_payment = ($row['total_seconds'] / 3600) * $hourly_rate;
                            ?>
                                <tr class="hover:bg-gray-50 transition duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($row['agent_name']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= $row['days_worked'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= $row['total_paid_time'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        $<?= number_format($hourly_rate, 2) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        $<?= number_format($total_payment, 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Existing Attendance Section -->
            <div class="border-t border-gray-200 pt-8">
                <h3 class="text-2xl font-bold text-gray-800 mb-6">Daily Attendance</h3>
                
                <!-- Filters Form -->
                <form method="GET" class="mb-8">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-200">
                            <label for="date" class="block text-sm font-medium text-gray-700 mb-2">Date</label>
                            <input type="date" name="date" id="date" value="<?= htmlspecialchars($date_filter) ?>" 
                                   class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200">
                        </div>
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-200">
                            <label for="employee" class="block text-sm font-medium text-gray-700 mb-2">Employee</label>
                            <select name="employee" id="employee" 
                                    class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200">
                                <option value="all">All Employees</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= $employee_filter === $emp['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 flex items-end">
                            <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 transition duration-200 flex items-center justify-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                                </svg>
                                Apply Filters
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Metrics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <div class="bg-gradient-to-br from-blue-500 to-blue-600 p-6 rounded-xl text-white transform hover:scale-105 transition duration-200">
                        <h3 class="text-lg font-semibold mb-2">Total Employees</h3>
                        <p class="text-3xl font-bold"><?= $total_employees ?></p>
                    </div>
                    <div class="bg-gradient-to-br from-green-500 to-green-600 p-6 rounded-xl text-white transform hover:scale-105 transition duration-200">
                        <h3 class="text-lg font-semibold mb-2">Total Hours</h3>
                        <p class="text-3xl font-bold"><?= number_format($total_hours, 2) ?></p>
                    </div>
                    <div class="bg-gradient-to-br from-purple-500 to-purple-600 p-6 rounded-xl text-white transform hover:scale-105 transition duration-200">
                        <h3 class="text-lg font-semibold mb-2">Total Earned</h3>
                        <p class="text-3xl font-bold">$<?= number_format($total_earned, 2) ?></p>
                    </div>
                    <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 p-6 rounded-xl text-white transform hover:scale-105 transition duration-200">
                        <h3 class="text-lg font-semibold mb-2">Avg Hours/Employee</h3>
                        <p class="text-3xl font-bold"><?= number_format($avg_hours_per_employee, 2) ?></p>
                    </div>
                </div>

                <!-- Charts -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                        <canvas id="hoursChart"></canvas>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                        <canvas id="earningsChart"></canvas>
                    </div>
                </div>

                <!-- Data Table -->
                <div class="overflow-x-auto rounded-xl border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Agent Name</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Login Time</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unpaid Time</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Paid Time</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount Earned</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($report_data as $row): 
                                $paid_seconds = strtotime($row['paid_time']) - strtotime('TODAY');
                                $hourly_rate = $hourly_rates[$row['username']] ?? 0;
                                $earned = ($paid_seconds / 3600) * $hourly_rate;
                            ?>
                                <tr class="hover:bg-gray-50 transition duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($row['agent_name']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= $row['login_time'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= $row['unpaid_time'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= $row['paid_time'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        $<?= number_format($earned, 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Datos para los gráficos
const reportData = <?= json_encode($report_data) ?>;
const names = reportData.map(row => row.agent_name);
const hours = reportData.map(row => {
    const paidSeconds = new Date(`1970-01-01T${row.paid_time}`).getTime() / 1000;
    return paidSeconds / 3600;
});
const earnings = reportData.map(row => {
    const paidSeconds = new Date(`1970-01-01T${row.paid_time}`).getTime() / 1000;
    const hourlyRate = <?= json_encode($hourly_rates) ?>[row.username] || 0;
    return (paidSeconds / 3600) * hourlyRate;
});

// Configuración común para los gráficos
const chartOptions = {
    responsive: true,
    plugins: {
        legend: {
            position: 'top',
            labels: {
                font: {
                    size: 12
                }
            }
        },
        title: {
            display: true,
            text: '',
            font: {
                size: 16,
                weight: 'bold'
            }
        }
    },
    scales: {
        y: {
            beginAtZero: true,
            title: {
                display: true,
                text: '',
                font: {
                    weight: 'bold'
                }
            }
        }
    }
};

// Gráfico de Horas
new Chart(document.getElementById('hoursChart'), {
    type: 'bar',
    data: {
        labels: names,
        datasets: [{
            label: 'Hours Worked',
            data: hours,
            backgroundColor: 'rgba(59, 130, 246, 0.7)',
            borderColor: 'rgb(59, 130, 246)',
            borderWidth: 1,
            borderRadius: 5
        }]
    },
    options: {
        ...chartOptions,
        plugins: {
            ...chartOptions.plugins,
            title: {
                ...chartOptions.plugins.title,
                text: 'Hours Worked by Employee'
            }
        },
        scales: {
            y: {
                ...chartOptions.scales.y,
                title: {
                    ...chartOptions.scales.y.title,
                    text: 'Hours'
                }
            }
        }
    }
});

// Gráfico de Ganancias
new Chart(document.getElementById('earningsChart'), {
    type: 'bar',
    data: {
        labels: names,
        datasets: [{
            label: 'Amount Earned',
            data: earnings,
            backgroundColor: 'rgba(16, 185, 129, 0.7)',
            borderColor: 'rgb(16, 185, 129)',
            borderWidth: 1,
            borderRadius: 5
        }]
    },
    options: {
        ...chartOptions,
        plugins: {
            ...chartOptions.plugins,
            title: {
                ...chartOptions.plugins.title,
                text: 'Amount Earned by Employee'
            }
        },
        scales: {
            y: {
                ...chartOptions.scales.y,
                title: {
                    ...chartOptions.scales.y.title,
                    text: 'Amount ($)'
                }
            }
        }
    }
});
</script>

<?php include 'footer.php'; ?>






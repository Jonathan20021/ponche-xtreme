<?php
session_start();
include 'db.php';

ensurePermission('records');

$scheduleConfig = getScheduleConfig($pdo);
$hourly_rates = getUserHourlyRates($pdo);
$entryThreshold = date('H:i:s', strtotime($scheduleConfig['entry_time'] . ' +5 minutes'));
$lunchThreshold = $scheduleConfig['lunch_time'];
$breakThreshold = $scheduleConfig['break_time'];

$search = $_GET['search'] ?? '';
$user_filter = $_GET['user'] ?? '';
$date_filter = $_GET['dates'] ?? '';
$type_filter = $_GET['type'] ?? '';
$dateValues = [];
$datePlaceholders = '';
if ($date_filter) {
    $dateValues = array_values(array_filter(array_map('trim', explode(',', $date_filter))));
    if (!empty($dateValues)) {
        $datePlaceholders = implode(',', array_fill(0, count($dateValues), '?'));
    }
}

// Consulta para registros

$query = "
    SELECT 
        attendance.id, 
        users.full_name, 
        users.username, 
        attendance.type, 
        DATE(attendance.timestamp) AS record_date, 
        TIME(attendance.timestamp) AS record_time, 
        attendance.ip_address 
    FROM attendance 
    JOIN users ON attendance.user_id = users.id 
    WHERE 1=1
";

$params = [];
if ($search) {
    $query .= " AND (users.full_name LIKE ? OR users.username LIKE ? OR attendance.type LIKE ? OR attendance.ip_address LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($user_filter) {
    $query .= " AND users.username = ?";
    $params[] = $user_filter;
}
if (!empty($dateValues)) {
    $query .= " AND DATE(attendance.timestamp) IN ($datePlaceholders)";
    $params = array_merge($params, $dateValues);
}
if ($type_filter) {
    $query .= " AND attendance.type = ?";
    $params[] = $type_filter;
}

$query .= " ORDER BY attendance.timestamp DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Usuarios unicos para filtro
$users = $pdo->query("SELECT DISTINCT username FROM users ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);

$summary_query = "
    SELECT 
        users.full_name,
        users.username, 
        DATE(attendance.timestamp) AS record_date,
        SUM(CASE WHEN attendance.type = 'Break' THEN TIMESTAMPDIFF(SECOND, attendance.timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS break_time,
        SUM(CASE WHEN attendance.type = 'Lunch' THEN TIMESTAMPDIFF(SECOND, attendance.timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS lunch_time,
        SUM(CASE WHEN attendance.type = 'Follow Up' THEN TIMESTAMPDIFF(SECOND, attendance.timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS follow_up_time,
        SUM(CASE WHEN attendance.type = 'Ready' THEN TIMESTAMPDIFF(SECOND, attendance.timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS ready_time,
        SUM(CASE WHEN attendance.type = 'Entry' THEN TIMESTAMPDIFF(SECOND, attendance.timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND a.type = 'Exit'
        )) ELSE 0 END) - 
        SUM(CASE WHEN attendance.type IN ('Break', 'Lunch') THEN TIMESTAMPDIFF(SECOND, attendance.timestamp, (
            SELECT MIN(a.timestamp) 
            FROM attendance a 
            WHERE a.user_id = attendance.user_id 
            AND a.timestamp > attendance.timestamp 
            AND DATE(a.timestamp) = DATE(attendance.timestamp)
        )) ELSE 0 END) AS work_time
    FROM attendance 
    JOIN users ON attendance.user_id = users.id 
    WHERE 1=1
";

$summary_params = [];
if ($user_filter) {
    $summary_query .= " AND users.username = ?";
    $summary_params[] = $user_filter;
}
if (!empty($dateValues)) {
    $summary_query .= " AND DATE(attendance.timestamp) IN ($datePlaceholders)";
    $summary_params = array_merge($summary_params, $dateValues);
}

$summary_query .= " GROUP BY users.full_name, users.username, record_date ORDER BY record_date DESC";
$summary_stmt = $pdo->prepare($summary_query);
$summary_stmt->execute($summary_params);
$work_summary = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);

// Lista de salarios por usuario

// Agregar columna del monto a pagar
foreach ($work_summary as &$summary) {
    $username = $summary['username'];
    $hourly_rate = isset($hourly_rates[$username]) ? $hourly_rates[$username] : 0;

    // Calcular horas trabajadas (restando el tiempo de lunch y break)
    $work_hours = ($summary['work_time'] / 3600);

    // Calcular el monto a pagar
    $summary['total_payment'] = round($work_hours * $hourly_rate, 2);
}


// Colculo de Porcentaje de Tardanza Diario
$tardiness_query = "
    SELECT 
        users.full_name,
        users.username, 
        DATE(attendance.timestamp) AS record_date,
        COUNT(CASE WHEN attendance.type = 'Entry' AND TIME(attendance.timestamp) > ? THEN 1 END) AS late_entries,
        COUNT(CASE WHEN attendance.type = 'Lunch' AND TIME(attendance.timestamp) > ? THEN 1 END) AS late_lunches,
        COUNT(CASE WHEN attendance.type = 'Break' AND TIME(attendance.timestamp) > ? THEN 1 END) AS late_breaks,
        COUNT(*) AS total_entries
    FROM attendance 
    JOIN users ON attendance.user_id = users.id 
    WHERE 1=1
";

$tardiness_params = [$entryThreshold, $lunchThreshold, $breakThreshold];
if ($user_filter) {
    $tardiness_query .= " AND users.username = ?";
    $tardiness_params[] = $user_filter;
}
if (!empty($dateValues)) {
    $tardiness_query .= " AND DATE(attendance.timestamp) IN ($datePlaceholders)";
    $tardiness_params = array_merge($tardiness_params, $dateValues);
}

$tardiness_query .= " GROUP BY users.full_name, users.username, record_date ORDER BY record_date DESC";
$tardiness_stmt = $pdo->prepare($tardiness_query);
$tardiness_stmt->execute($tardiness_params);
$tardiness_data = $tardiness_stmt->fetchAll(PDO::FETCH_ASSOC);

$missing_entry_query = "
    SELECT 
        users.full_name AS agent_name,
        users.username,
        MIN(attendance.timestamp) AS first_time,
        attendance.type AS first_type
    FROM attendance
    JOIN users ON attendance.user_id = users.id
    WHERE DATE(attendance.timestamp) = CURDATE() 
    AND attendance.user_id NOT IN (
        SELECT DISTINCT user_id 
        FROM attendance 
        WHERE DATE(timestamp) = CURDATE() AND type = 'Entry'
    )
    GROUP BY users.id
    ORDER BY first_time ASC
";

$stmt = $pdo->prepare($missing_entry_query);
$stmt->execute();
$missing_entry_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Query para empleados que no tienen "Exit" en la fecha seleccionada
$missing_exit_query = "
    SELECT 
        users.full_name AS agent_name,
        users.username,
        MIN(attendance.timestamp) AS first_time,
        attendance.type AS first_type
    FROM attendance
    JOIN users ON attendance.user_id = users.id
    WHERE DATE(attendance.timestamp) = ? 
    AND attendance.user_id NOT IN (
        SELECT DISTINCT user_id 
        FROM attendance 
        WHERE DATE(timestamp) = ? AND type = 'Exit'
    )
    GROUP BY users.id
    ORDER BY first_time ASC
";

// Ejecutar la consulta con el filtro de fecha
$stmt_missing_exit = $pdo->prepare($missing_exit_query);
$stmt_missing_exit->execute([$date_filter, $date_filter]);
$missing_exit_data = $stmt_missing_exit->fetchAll(PDO::FETCH_ASSOC);

?>
<?php include 'header.php'; ?>

<div class="container mx-auto px-4 py-8 fade-in">
    <!-- Filtros -->
    <div class="section-card p-6 mb-8">
        <form method="GET" class="space-y-4" id="filterForm">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- Search Input con diseno mejorado -->
                <div class="flex flex-col">
                    <label class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Search</label>
                    <div class="relative">
                        <input type="text" name="search" 
                            class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                            placeholder="Search records..."
                            value="<?= htmlspecialchars($search) ?>">
                        <span class="absolute right-3 top-2.5 text-gray-400">
                            <i class="fas fa-search"></i>
                        </span>
                    </div>
                </div>

                <!-- User Filter mejorado -->
                <div class="flex flex-col">
                    <label class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">User</label>
                    <select name="user" class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        <option value="">All Users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= htmlspecialchars($user) ?>" <?= $user_filter === $user ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date Range Picker mejorado -->
                <div class="flex flex-col">
                    <label class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Date Range</label>
                    <input type="text" name="dates" id="daterange" 
                        class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        value="<?= htmlspecialchars($date_filter) ?>">
                </div>

                <!-- Type Filter mejorado -->
                <div class="flex flex-col">
                    <label class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Type</label>
                    <select name="type" class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        <option value="">All Types</option>
                        <?php
                        $types = ['Entry', 'Break', 'Coaching', 'Exit', 'Lunch', 'Meeting', 'Follow Up', 'Ready'];
                        foreach ($types as $type):
                        ?>
                            <option value="<?= $type ?>" <?= $type_filter === $type ? 'selected' : '' ?>>
                                <?= $type ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end mt-6">
                <button type="submit" class="btn-primary w-full sm:w-auto">
                    <i class="fas fa-filter"></i>
                    Apply Filters
                </button>
                <button type="button" id="reload-button" class="btn-secondary w-full sm:w-auto">
                    <i class="fas fa-sync-alt"></i>
                    Reload Data
                </button>
            </div>
        </form>
    </div>

    <!-- Records Table -->
    <div class="section-card p-6 mb-8">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between mb-6">
            <h2 class="text-2xl font-bold text-gray-800 dark:text-white">
                <i class="fas fa-table mr-2"></i> Record Details
            </h2>
            <div class="table-actions w-full xl:w-auto justify-end">
                <button id="exportCsv" class="btn-secondary w-full sm:w-auto">
                    <i class="fas fa-file-csv"></i>
                    Export CSV
                </button>
                <button id="exportExcel" class="btn-primary w-full sm:w-auto">
                    <i class="fas fa-file-excel"></i>
                    Export Excel
                </button>
                <button id="exportPDF" class="btn-secondary w-full sm:w-auto">
                    <i class="fas fa-file-pdf"></i>
                    Export PDF
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table id="recordsTable" class="data-table js-datatable" data-export-name="attendance-records">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>IP</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= $record['id'] ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= htmlspecialchars($record['full_name']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= htmlspecialchars($record['username']) ?></td>
                            <td class="px-6 py-4">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    <?php
                                        switch($record['type']) {
                                            case 'Entry':
                                                echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                                                break;
                                            case 'Exit':
                                                echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                                                break;
                                            case 'Break':
                                            case 'Lunch':
                                                echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
                                                break;
                                            default:
                                                echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
                                        }
                                    ?>">
                                    <?= htmlspecialchars($record['type']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= htmlspecialchars($record['record_date']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= htmlspecialchars($record['record_time']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= htmlspecialchars($record['ip_address']) ?></td>
                            <td class="px-6 py-4 text-sm text-center">
                                <div class="flex items-center justify-center space-x-2">
                                    <a href="edit_record.php?id=<?= $record['id'] ?>"
                                        class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="delete_record.php" method="POST" class="inline">
                                        <input type="hidden" name="id" value="<?= $record['id'] ?>">
                                        <button type="submit" 
                                            class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                                            onclick="return confirm('Are you sure you want to delete this record?');">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Work Time Summary -->
    <div class="section-card p-6 mb-8">
        <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-6">
            <i class="fas fa-clock mr-2"></i> Work Time Summary
        </h2>
        <div class="overflow-x-auto">
            <table id="summaryTable" class="custom-table w-full">
                <thead>
                    <tr class="bg-gray-100 dark:bg-gray-700">
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Full Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Username</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Break Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Lunch Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Follow Up Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Ready Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Work Time</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Total Payment ($)</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php foreach ($work_summary as $summary): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= htmlspecialchars($summary['full_name']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= htmlspecialchars($summary['username']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= htmlspecialchars($summary['record_date']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= gmdate("H:i:s", $summary['break_time']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= gmdate("H:i:s", $summary['lunch_time']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= gmdate("H:i:s", $summary['follow_up_time']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= gmdate("H:i:s", $summary['ready_time']) ?: '00:00:00' ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100"><?= gmdate("H:i:s", $summary['work_time']) ?></td>
                            <td class="px-6 py-4 text-sm font-medium">
                                <span class="text-green-600 dark:text-green-400">$<?= number_format($summary['total_payment'], 2) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($work_summary)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No summary data found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Employees Without Entry Today -->
    <h2 class="text-2xl font-semibold mt-8 mb-4 text-gray-800">
        <i class="fas fa-user-times mr-2"></i> Employees Without "Entry" Today
    </h2>
    <div class="bg-white p-6 rounded shadow-md mt-4 border border-gray-200">
        <div class="overflow-x-auto">
            <table class="w-full table-auto mt-4 border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Full Name</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Username</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">First Type</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">First Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($missing_entry_data)): ?>
                        <?php foreach ($missing_entry_data as $row): ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-200">
                                <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($row['agent_name']) ?></td>
                                <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($row['username']) ?></td>
                                <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($row['first_type']) ?></td>
                                <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars(date('H:i:s', strtotime($row['first_time']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center p-4 text-gray-500">No employees without "Entry" recorded today.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Employees Without "Exit" in Selected Date -->
    <h2 class="text-2xl font-semibold mt-8 mb-4 text-gray-800">
        <i class="fas fa-sign-out-alt mr-2"></i> Employees Without "Exit" in Selected Date
    </h2>
    <div class="bg-white p-6 rounded shadow-md mt-4 border border-gray-200">
        <div class="overflow-x-auto">
            <table class="w-full table-auto mt-4 border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Full Name</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Username</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">First Type</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">First Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($missing_exit_data)): ?>
                        <?php foreach ($missing_exit_data as $row): ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-200">
                                <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($row['agent_name']) ?></td>
                                <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($row['username']) ?></td>
                                <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($row['first_type']) ?></td>
                                <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars(date('H:i:s', strtotime($row['first_time']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center p-4 text-gray-500">No employees without "Exit" recorded for the selected date.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tardiness Percentage -->
    <h2 class="text-2xl font-semibold mt-8 mb-4 text-gray-800">
        <i class="fas fa-percent mr-2"></i> Tardiness Percentage
    </h2>
    <div class="bg-white p-6 rounded shadow-md border border-gray-200">
        <div class="overflow-x-auto">
            <table class="w-full table-auto mt-4 border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Full Name</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Username</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Date</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Late Entries (%)</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Late Lunches (%)</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Late Breaks (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tardiness_data as $data): ?>
                        <tr class="hover:bg-gray-50 transition-colors duration-200">
                            <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($data['full_name']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($data['username']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($data['record_date']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700" style="background-color: <?= ($data['late_entries'] / $data['total_entries'] > 0.5) ? '#FFDDDD' : '#DDFFDD' ?>;">
                                <?= round(($data['late_entries'] / $data['total_entries']) * 100, 2) ?>%
                            </td>
                            <td class="p-3 border border-gray-300 text-gray-700" style="background-color: <?= ($data['late_lunches'] / $data['total_entries'] > 0.5) ? '#FFDDDD' : '#DDFFDD' ?>;">
                                <?= round(($data['late_lunches'] / $data['total_entries']) * 100, 2) ?>%
                            </td>
                            <td class="p-3 border border-gray-300 text-gray-700" style="background-color: <?= ($data['late_breaks'] / $data['total_entries'] > 0.5) ? '#FFDDDD' : '#DDFFDD' ?>;">
                                <?= round(($data['late_breaks'] / $data['total_entries']) * 100, 2) ?>%
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tardiness_data)): ?>
                        <tr>
                            <td colspan="6" class="text-center p-4 text-gray-500">No tardiness data found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Existing JavaScript with added animations
$(document).ready(function() {
    // Initialize DataTables with custom styling
    const tableConfig = {
        dom: '<"flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4"Bf>rtip',
        buttons: [
            {
                extend: 'excel',
                className: 'hidden' // Hide default buttons
            },
            {
                extend: 'pdf',
                className: 'hidden'
            }
        ],
        pageLength: 10,
        responsive: true,
        order: [[0, 'desc']],
        language: {
            search: "",
            searchPlaceholder: "Search records..."
        },
        drawCallback: function() {
            // Add fade-in animation to new rows
            $(this).find('tbody tr').addClass('fade-in');
        }
    };

    const tables = $('table').DataTable(tableConfig);

    // Enhanced export functionality
    $('#exportExcel').click(function() {
        tables.button('.buttons-excel').trigger();
    });

    $('#exportPDF').click(function() {
        tables.button('.buttons-pdf').trigger();
    });

    // Enhanced notification system
    function showNotification(message, type) {
        const notification = $('<div>')
            .addClass(`fixed top-4 right-4 p-4 rounded-lg text-white ${type === 'success' ? 'bg-green-500' : 'bg-red-500'} fade-in`)
            .text(message)
            .appendTo('body');

        setTimeout(() => {
            notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }

    // Enhanced reload functionality
    $('#reload-button').click(function() {
        const button = $(this);
        button.prop('disabled', true)
              .html('<i class="fas fa-spinner fa-spin mr-2"></i> Reloading...');

        setTimeout(() => {
            location.reload();
        }, 500);
    });

    // Initialize DateRangePicker with enhanced styling
    $('#daterange').daterangepicker({
        opens: 'left',
        locale: {
            format: 'YYYY-MM-DD'
        },
        ranges: {
           'Today': [moment(), moment()],
           'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
           'Last 7 Days': [moment().subtract(6, 'days'), moment()],
           'Last 30 Days': [moment().subtract(29, 'days'), moment()],
           'This Month': [moment().startOf('month'), moment().endOf('month')],
           'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
        }
    });
});
</script>

<?php include 'footer.php'; ?>


<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php'); // Redirige a la página de login si no hay sesión activa
    exit;
}

include 'db.php';

$search = $_GET['search'] ?? '';
$user_filter = $_GET['user'] ?? '';
$date_filter = $_GET['dates'] ?? '';
$type_filter = $_GET['type'] ?? '';

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
if ($date_filter) {
    $dates = explode(',', $date_filter);
    $placeholders = implode(',', array_fill(0, count($dates), '?'));
    $query .= " AND DATE(attendance.timestamp) IN ($placeholders)";
    $params = array_merge($params, $dates);
}
if ($type_filter) {
    $query .= " AND attendance.type = ?";
    $params[] = $type_filter;
}

$query .= " ORDER BY attendance.timestamp DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Usuarios únicos para filtro
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
if ($date_filter) {
    $placeholders = implode(',', array_fill(0, count($dates), '?'));
    $summary_query .= " AND DATE(attendance.timestamp) IN ($placeholders)";
    $summary_params = array_merge($summary_params, $dates);
}

$summary_query .= " GROUP BY users.full_name, users.username, record_date ORDER BY record_date DESC";
$summary_stmt = $pdo->prepare($summary_query);
$summary_stmt->execute($summary_params);
$work_summary = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);

// Lista de salarios por usuario
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

// Agregar columna del monto a pagar
foreach ($work_summary as &$summary) {
    $username = $summary['username'];
    $hourly_rate = isset($hourly_rates[$username]) ? $hourly_rates[$username] : 0;

    // Calcular horas trabajadas (restando el tiempo de lunch y break)
    $work_hours = ($summary['work_time'] / 3600);

    // Calcular el monto a pagar
    $summary['total_payment'] = round($work_hours * $hourly_rate, 2);
}


// Cálculo de Porcentaje de Tardanza Diario
$tardiness_query = "
    SELECT 
        users.full_name,
        users.username, 
        DATE(attendance.timestamp) AS record_date,
        COUNT(CASE WHEN attendance.type = 'Entry' AND TIME(attendance.timestamp) > '10:05:00' THEN 1 END) AS late_entries,
        COUNT(CASE WHEN attendance.type = 'Lunch' AND TIME(attendance.timestamp) > '14:00:00' THEN 1 END) AS late_lunches,
        COUNT(CASE WHEN attendance.type = 'Break' AND TIME(attendance.timestamp) > '17:00:00' THEN 1 END) AS late_breaks,
        COUNT(*) AS total_entries
    FROM attendance 
    JOIN users ON attendance.user_id = users.id 
    WHERE 1=1
";

$tardiness_params = [];
if ($user_filter) {
    $tardiness_query .= " AND users.username = ?";
    $tardiness_params[] = $user_filter;
}
if ($date_filter) {
    $tardiness_query .= " AND DATE(attendance.timestamp) IN ($placeholders)";
    $tardiness_params = array_merge($tardiness_params, $dates);
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

<div class="container mx-auto px-4 md:px-0 mt-8">
    <!-- Title -->
    <h2 class="text-3xl font-semibold text-gray-800 mb-6">
        <i class="fas fa-clipboard-list mr-2"></i> Attendance Records
    </h2>

    <!-- Filters -->
    <div class="bg-white p-6 rounded-lg shadow-md mb-6 border border-gray-200">
        <form method="GET" class="flex flex-col gap-4 md:flex-row md:items-end">
            <!-- Search Input -->
            <div class="flex flex-col flex-1">
                <label for="search" class="block text-sm font-medium text-gray-700">Search</label>
                <input type="text" name="search" id="search" placeholder="Search..." 
                    class="mt-1 p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm"
                    value="<?= htmlspecialchars($search) ?>">
            </div>
            <!-- User Filter -->
            <div class="flex flex-col flex-1">
                <label for="user" class="block text-sm font-medium text-gray-700">User</label>
                <select name="user" id="user" class="mt-1 p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm">
                    <option value="">All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= htmlspecialchars($user) ?>" <?= $user_filter === $user ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Dates Filter -->
            <div class="flex flex-col flex-1">
                <label for="dates" class="block text-sm font-medium text-gray-700">Dates (YYYY-MM-DD,YYYY-MM-DD)</label>
                <input type="text" name="dates" id="dates" placeholder="YYYY-MM-DD,YYYY-MM-DD"
                    class="mt-1 p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm" 
                    value="<?= htmlspecialchars($date_filter) ?>">
            </div>
            <!-- Type Filter -->
            <div class="flex flex-col flex-1">
                <label for="type" class="block text-sm font-medium text-gray-700">Type</label>
                <select name="type" id="type" class="mt-1 p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm">
                    <option value="">All Types</option>
                    <option value="Entry" <?= $type_filter === 'Entry' ? 'selected' : '' ?>>Entry</option>
                    <option value="Break" <?= $type_filter === 'Break' ? 'selected' : '' ?>>Break</option>
                    <option value="Coaching" <?= $type_filter === 'Coaching' ? 'selected' : '' ?>>Coaching</option>
                    <option value="Exit" <?= $type_filter === 'Exit' ? 'selected' : '' ?>>Exit</option>
                    <option value="Lunch" <?= $type_filter === 'Lunch' ? 'selected' : '' ?>>Lunch</option>
                    <option value="Meeting" <?= $type_filter === 'Meeting' ? 'selected' : '' ?>>Meeting</option>
                    <option value="Follow Up" <?= $type_filter === 'Follow Up' ? 'selected' : '' ?>>Follow Up</option>
                    <option value="Ready" <?= $type_filter === 'Ready' ? 'selected' : '' ?>>Ready</option>
                </select>
            </div>
            <!-- Submit Button -->
            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white font-medium py-2 px-4 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-opacity-50 transition duration-150 ease-in-out">
                <i class="fas fa-filter mr-2"></i> Filter
            </button>
        </form>

        <div class="mt-4">
            <!-- Reload Button -->
            <button id="reload-button" class="bg-blue-500 hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-50 transition duration-150 ease-in-out">
                <i class="fas fa-sync-alt mr-2"></i> Reload Data
            </button>
        </div>
    </div>

    <!-- Records Table -->
    <div class="bg-white p-6 rounded-lg shadow-md border border-gray-200">
        <h3 class="text-xl font-semibold text-gray-800 mb-4"><i class="fas fa-table mr-2"></i> Record Details</h3>
        <div class="overflow-x-auto">
            <table class="w-full mt-4 table-auto border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="p-3 text-left border border-gray-300 font-semibold text-gray-700">ID</th>
                        <th class="p-3 text-left border border-gray-300 font-semibold text-gray-700">Full Name</th>
                        <th class="p-3 text-left border border-gray-300 font-semibold text-gray-700">Username</th>
                        <th class="p-3 text-left border border-gray-300 font-semibold text-gray-700">Type</th>
                        <th class="p-3 text-left border border-gray-300 font-semibold text-gray-700">Date</th>
                        <th class="p-3 text-left border border-gray-300 font-semibold text-gray-700">Time</th>
                        <th class="p-3 text-left border border-gray-300 font-semibold text-gray-700">IP</th>
                        <th class="p-3 text-center border border-gray-300 font-semibold text-gray-700">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr class="hover:bg-gray-50 transition-colors duration-200">
                            <td class="p-3 border border-gray-300 text-gray-700"><?= $record['id'] ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($record['full_name']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($record['username']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($record['type']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($record['record_date']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($record['record_time']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($record['ip_address']) ?></td>
                            <td class="p-3 border border-gray-300 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="edit_record.php?id=<?= $record['id'] ?>"
                                        class="bg-blue-500 hover:bg-blue-600 text-white py-1.5 px-3 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-50 transition duration-150 ease-in-out">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="delete_record.php" method="POST" class="inline">
                                        <input type="hidden" name="id" value="<?= $record['id'] ?>">
                                        <button type="submit" 
                                            class="bg-red-500 hover:bg-red-600 text-white py-1.5 px-3 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-opacity-50 transition duration-150 ease-in-out"
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
                            <td colspan="8" class="text-center p-4 text-gray-500">No records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Work Time Summary -->
    <h2 class="text-2xl font-semibold mt-8 mb-4 text-gray-800">
        <i class="fas fa-clock mr-2"></i> Work Time Summary (Daily)
    </h2>
    <div class="bg-white p-6 rounded-lg shadow-md border border-gray-200">
        <div class="overflow-x-auto">
            <table class="w-full table-auto mt-4 border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Full Name</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Username</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Date</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Break Time</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Lunch Time</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Follow Up Time</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Ready Time</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Work Time</th>
                        <th class="p-3 border border-gray-300 text-left font-semibold text-gray-700">Total Payment ($)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($work_summary as $summary): ?>
                        <tr class="hover:bg-gray-50 transition-colors duration-200">
                            <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($summary['full_name']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($summary['username']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= htmlspecialchars($summary['record_date']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= gmdate("H:i:s", $summary['break_time']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= gmdate("H:i:s", $summary['lunch_time']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= gmdate("H:i:s", $summary['follow_up_time']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= gmdate("H:i:s", $summary['ready_time']) ?: '00:00:00' ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700"><?= gmdate("H:i:s", $summary['work_time']) ?></td>
                            <td class="p-3 border border-gray-300 text-gray-700">$<?= number_format($summary['total_payment'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($work_summary)): ?>
                        <tr>
                            <td colspan="9" class="text-center p-4 text-gray-500">No summary data found.</td>
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
    <div class="bg-white p-6 rounded-lg shadow-md mt-4 border border-gray-200">
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
    <div class="bg-white p-6 rounded-lg shadow-md mt-4 border border-gray-200">
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
    <div class="bg-white p-6 rounded-lg shadow-md border border-gray-200">
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
    document.getElementById('reload-button').addEventListener('click', function() {
        location.reload();
    });
</script>
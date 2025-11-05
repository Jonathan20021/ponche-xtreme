<?php
session_start();
include 'db.php';

date_default_timezone_set('America/Santo_Domingo');

ensurePermission('view_admin_hours');

// Pagination settings
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';
$username = isset($_GET['username']) ? $_GET['username'] : '';

// Base query
$query = "
    SELECT 
        ah.timestamp, 
        u.full_name, 
        u.username, 
        ah.type, 
        ah.ip_address 
    FROM administrative_hours ah
    JOIN users u ON ah.user_id = u.id
    WHERE 1=1
";

$count_query = "
    SELECT COUNT(*) as total 
    FROM administrative_hours ah
    JOIN users u ON ah.user_id = u.id
    WHERE 1=1
";

$params = [];

// Add filters to query
if ($search) {
    $query .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR ah.ip_address LIKE ?)";
    $count_query .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR ah.ip_address LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

if ($start_date) {
    $query .= " AND DATE(ah.timestamp) >= ?";
    $count_query .= " AND DATE(ah.timestamp) >= ?";
    $params[] = $start_date;
}

if ($end_date) {
    $query .= " AND DATE(ah.timestamp) <= ?";
    $count_query .= " AND DATE(ah.timestamp) <= ?";
    $params[] = $end_date;
}

if ($type) {
    $query .= " AND ah.type = ?";
    $count_query .= " AND ah.type = ?";
    $params[] = $type;
}

if ($username) {
    $query .= " AND u.username = ?";
    $count_query .= " AND u.username = ?";
    $params[] = $username;
}

// Get total records for pagination
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Add pagination to main query
$query .= " ORDER BY ah.timestamp DESC LIMIT $records_per_page OFFSET $offset";

// Execute main query - Note that we're not adding LIMIT/OFFSET to params anymore
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique types for filter dropdown
$types_query = "SELECT DISTINCT type FROM administrative_hours ORDER BY type";
$types_stmt = $pdo->prepare($types_query);
$types_stmt->execute();
$types = $types_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get unique usernames for filter dropdown
$usernames_query = "SELECT DISTINCT username FROM users ORDER BY username";
$usernames_stmt = $pdo->prepare($usernames_query);
$usernames_stmt->execute();
$usernames = $usernames_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<?php include 'header.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/pagination-styles.css">
    <title>Administrative Hours</title>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-6 text-center">Administrative Hours</h1>

        <!-- Filters Section -->
        <div class="bg-white p-4 rounded-lg shadow mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <!-- Search Bar -->
                <div class="col-span-full">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Search by name, username or IP..." 
                           class="w-full p-2 border rounded">
                </div>

                <!-- Date Filters -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Start Date</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" 
                           class="w-full p-2 border rounded">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">End Date</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" 
                           class="w-full p-2 border rounded">
                </div>

                <!-- Type Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Type</label>
                    <select name="type" class="w-full p-2 border rounded">
                        <option value="">All Types</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= $type === $t ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Username Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Username</label>
                    <select name="username" class="w-full p-2 border rounded">
                        <option value="">All Users</option>
                        <?php foreach ($usernames as $u): ?>
                            <option value="<?= htmlspecialchars($u) ?>" <?= $username === $u ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filter Buttons -->
                <div class="flex space-x-2">
                    <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                        Apply Filters
                    </button>
                    <a href="?" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">
                        Clear Filters
                    </a>
                </div>
            </form>
        </div>

        <!-- Results Table -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="p-3 text-left border-b">Timestamp</th>
                        <th class="p-3 text-left border-b">Employee</th>
                        <th class="p-3 text-left border-b">Username</th>
                        <th class="p-3 text-left border-b">Type</th>
                        <th class="p-3 text-left border-b">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="5" class="p-3 text-center border-b">No records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="p-3 border-b"><?= date('m/d/Y h:i:s A', strtotime($record['timestamp'])) ?></td>
                                <td class="p-3 border-b"><?= htmlspecialchars($record['full_name']) ?></td>
                                <td class="p-3 border-b"><?= htmlspecialchars($record['username']) ?></td>
                                <td class="p-3 border-b"><?= htmlspecialchars($record['type']) ?></td>
                                <td class="p-3 border-b"><?= htmlspecialchars($record['ip_address']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): 
            // Build filter query string
            $filter_params = '';
            if ($search) $filter_params .= '&search=' . urlencode($search);
            if ($start_date) $filter_params .= '&start_date=' . urlencode($start_date);
            if ($end_date) $filter_params .= '&end_date=' . urlencode($end_date);
            if ($type) $filter_params .= '&type=' . urlencode($type);
            if ($username) $filter_params .= '&username=' . urlencode($username);
            
            $start_record = ($page - 1) * $records_per_page + 1;
            $end_record = min($page * $records_per_page, $total_records);
        ?>
            <div class="pagination-wrapper mt-6">
                <div class="pagination-info">
                    Mostrando <strong><?= number_format($start_record) ?></strong> a
                    <strong><?= number_format($end_record) ?></strong> de
                    <strong><?= number_format($total_records) ?></strong> registros
                </div>
                <div class="pagination-controls">
                    <?php if ($page > 1): ?>
                        <a class="pagination-btn" href="?page=<?= $page - 1 ?><?= $filter_params ?>">
                            <i class="fas fa-chevron-left"></i>
                            <span>Anterior</span>
                        </a>
                    <?php endif; ?>
                    
                    <div class="pagination-pages">
                        <?php
                        // Calculate page range to display
                        $range = 2;
                        $startPage = max(1, $page - $range);
                        $endPage = min($total_pages, $page + $range);
                        
                        // First page
                        if ($startPage > 1): ?>
                            <a class="page-btn" href="?page=1<?= $filter_params ?>">1</a>
                            <?php if ($startPage > 2): ?>
                                <span class="page-ellipsis">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="page-btn active"><?= $i ?></span>
                            <?php else: ?>
                                <a class="page-btn" href="?page=<?= $i ?><?= $filter_params ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php // Last page
                        if ($endPage < $total_pages): ?>
                            <?php if ($endPage < $total_pages - 1): ?>
                                <span class="page-ellipsis">...</span>
                            <?php endif; ?>
                            <a class="page-btn" href="?page=<?= $total_pages ?><?= $filter_params ?>"><?= $total_pages ?></a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($page < $total_pages): ?>
                        <a class="pagination-btn primary" href="?page=<?= $page + 1 ?><?= $filter_params ?>">
                            <span>Siguiente</span>
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

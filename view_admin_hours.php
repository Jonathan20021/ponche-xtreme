<?php
session_start();
include 'db.php';

date_default_timezone_set('America/Santo_Domingo');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Admin', 'HR', 'IT'])) {
    header('Location: index.php');
    exit;
}

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
        <?php if ($total_pages > 1): ?>
            <div class="mt-6 flex justify-center">
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <!-- Previous Page -->
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&type=<?= urlencode($type) ?>&username=<?= urlencode($username) ?>" 
                           class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            Previous
                        </a>
                    <?php endif; ?>

                    <!-- Page Numbers -->
                    <?php 
                    // Show maximum 5 page numbers with current page in the middle when possible
                    $start_page = max(1, min($page - 2, $total_pages - 4));
                    $end_page = min($total_pages, $start_page + 4);
                    
                    for ($i = $start_page; $i <= $end_page; $i++): 
                    ?>
                        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&type=<?= urlencode($type) ?>&username=<?= urlencode($username) ?>" 
                           class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?= $i === $page ? 'text-blue-600 bg-blue-50' : 'text-gray-700 hover:bg-gray-50' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Next Page -->
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&type=<?= urlencode($type) ?>&username=<?= urlencode($username) ?>" 
                           class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                            Next
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

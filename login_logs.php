<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'IT') {
    header('Location: index.php');
    exit;
}

include 'db.php';

// Consultar los registros de inicio de sesión
$query = "
    SELECT l.id, u.full_name, l.username, l.role, l.ip_address, l.location, l.login_time, public_ip
    FROM admin_login_logs l
    JOIN users u ON l.user_id = u.id
    ORDER BY l.login_time DESC
";
$stmt = $pdo->query($query);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<?php include 'header.php'; ?>
<body class="bg-gray-100">
    <div class="container mx-auto mt-6">
        <h2 class="text-2xl font-bold mb-4">Administrative Login Logs</h2>
        <div class="bg-white p-6 rounded shadow-md">
            <table class="w-full mt-4 border-collapse">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="p-2 border">ID</th>
                        <th class="p-2 border">Full Name</th>
                        <th class="p-2 border">Username</th>
                        <th class="p-2 border">Role</th>
                        <th class="p-2 border">IP Address Local</th>
                        <th class="p-2 border">IP Address Public</th>
                        <th class="p-2 border">Location</th>
                        <th class="p-2 border">Login Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="p-2 border"><?= $log['id'] ?></td>
                            <td class="p-2 border"><?= htmlspecialchars($log['full_name']) ?></td>
                            <td class="p-2 border"><?= htmlspecialchars($log['username']) ?></td>
                            <td class="p-2 border"><?= htmlspecialchars($log['role']) ?></td>
                            <td class="p-2 border"><?= htmlspecialchars($log['ip_address']) ?></td>
                            <td class="p-2 border"><?= htmlspecialchars($log['public_ip']) ?></td>
                            <td class="p-2 border"><?= htmlspecialchars($log['location'] ?? 'N/A') ?></td>
                            <td class="p-2 border"><?= htmlspecialchars($log['login_time']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" class="text-center p-4">No login logs found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

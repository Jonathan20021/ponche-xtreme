<?php
session_start();
if (!isset($_SESSION['employee_id'])) {
    header('Location: login_agent.php'); // Redirige si no hay sesión activa
    exit;
}

include '../db.php';

$employee_id = $_SESSION['employee_id'];
$username = $_SESSION['username'];

// Métricas personales
$query = "
    SELECT 
        COUNT(*) AS total_punches,
        COUNT(CASE WHEN type = 'Entry' AND TIME(timestamp) > '10:05:00' THEN 1 END) AS late_entries
    FROM attendance 
    WHERE user_id = ?
";
$stmt = $pdo->prepare($query);
$stmt->execute([$employee_id]);
$metrics = $stmt->fetch(PDO::FETCH_ASSOC);

$total_punches = $metrics['total_punches'];
$late_entries = $metrics['late_entries'];
?>

<?php include 'header_agent.php'; ?>
<div class="container mx-auto mt-6">
    <h2 class="text-2xl font-bold mb-4">Welcome, <?= htmlspecialchars($username) ?>!</h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-6 rounded shadow-md">
            <h3 class="text-lg font-semibold">Total Punches</h3>
            <p class="text-4xl font-bold"><?= $total_punches ?></p>
        </div>
        <div class="bg-white p-6 rounded shadow-md">
            <h3 class="text-lg font-semibold">Late Entries</h3>
            <p class="text-4xl font-bold <?= $late_entries > 0 ? 'text-red-500' : 'text-green-500' ?>">
                <?= $late_entries ?>
            </p>
        </div>
    </div>
</div>
</body>
</html>

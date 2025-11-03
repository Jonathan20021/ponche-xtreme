<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['AGENT', 'IT', 'Supervisor'], true)) {
    header('Location: ../login_agent.php');
    exit;
}

include '../db.php';

$user_id = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'Agente';

// Metricas personales
$query = "
    SELECT 
        COUNT(*) AS total_punches,
        COUNT(CASE WHEN UPPER(type) = 'ENTRY' AND TIME(timestamp) > '10:05:00' THEN 1 END) AS late_entries
    FROM attendance 
    WHERE user_id = ?
";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$metrics = $stmt->fetch(PDO::FETCH_ASSOC);

$total_punches = $metrics['total_punches'] ?? 0;
$late_entries = $metrics['late_entries'] ?? 0;
?>

<?php include '../header_agent.php'; ?>
<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="glass-card mb-6">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-12 h-12 rounded-lg bg-emerald-500/20 flex items-center justify-center">
                <i class="fas fa-chart-line text-emerald-400 text-xl"></i>
            </div>
            <div>
                <h1 class="text-2xl font-bold">Bienvenido, <?= htmlspecialchars($full_name) ?></h1>
                <p class="text-slate-400 text-sm">Resumen de tu actividad</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-slate-800/50 p-6 rounded-lg border border-slate-700">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-medium text-slate-400">Total de Marcaciones</h3>
                    <i class="fas fa-fingerprint text-blue-400"></i>
                </div>
                <p class="text-3xl font-bold text-white"><?= number_format($total_punches) ?></p>
                <p class="text-xs text-slate-500 mt-1">Registros totales</p>
            </div>
            <div class="bg-slate-800/50 p-6 rounded-lg border border-slate-700">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-sm font-medium text-slate-400">Entradas Tardías</h3>
                    <i class="fas fa-clock text-<?= $late_entries > 0 ? 'red' : 'green' ?>-400"></i>
                </div>
                <p class="text-3xl font-bold text-<?= $late_entries > 0 ? 'red' : 'green' ?>-400">
                    <?= number_format($late_entries) ?>
                </p>
                <p class="text-xs text-slate-500 mt-1">Después de las 10:05 AM</p>
            </div>
        </div>

        <div class="mt-6 pt-6 border-t border-slate-700">
            <h3 class="text-sm font-semibold mb-3 text-slate-300">Acciones Rápidas</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <a href="../agent_dashboard.php" class="flex items-center gap-3 p-4 bg-blue-500/10 hover:bg-blue-500/20 border border-blue-500/30 rounded-lg transition-colors">
                    <i class="fas fa-tachometer-alt text-blue-400"></i>
                    <div>
                        <p class="font-medium text-white">Panel de Control</p>
                        <p class="text-xs text-slate-400">Ver métricas detalladas</p>
                    </div>
                </a>
                <a href="../agent.php" class="flex items-center gap-3 p-4 bg-purple-500/10 hover:bg-purple-500/20 border border-purple-500/30 rounded-lg transition-colors">
                    <i class="fas fa-clock text-purple-400"></i>
                    <div>
                        <p class="font-medium text-white">Mis Registros</p>
                        <p class="text-xs text-slate-400">Historial de asistencia</p>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>
<?php include '../footer.php'; ?>

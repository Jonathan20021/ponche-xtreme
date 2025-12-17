<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['AGENT', 'IT', 'Supervisor'], true)) {
    header('Location: ../login_agent.php');
    exit;
}

require_once '../db.php';

$user_id = (int) $_SESSION['user_id'];

// Get employee data
$employeeStmt = $pdo->prepare("SELECT id, first_name, last_name FROM employees WHERE user_id = ?");
$employeeStmt->execute([$user_id]);
$employeeData = $employeeStmt->fetch(PDO::FETCH_ASSOC);
$employeeId = $employeeData['id'] ?? null;

if (!$employeeId) {
    die('No tienes un perfil de empleado asociado. Contacta a Recursos Humanos.');
}

// Handle permission request submission
$successMsg = null;
$errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_permission'])) {
    $permissionType = $_POST['permission_type'];
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $reason = trim($_POST['reason']);
    
    if (empty($reason)) {
        $errorMsg = "El motivo es obligatorio.";
    } else {
        try {
            $insertStmt = $pdo->prepare("
                INSERT INTO permission_requests (employee_id, user_id, request_type, start_date, end_date, total_days, reason, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
            ");

            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            $totalDays = $start->diff($end)->days + 1;

            $insertStmt->execute([$employeeId, $user_id, strtoupper($permissionType), $startDate, $endDate, $totalDays, $reason]);
            $successMsg = "Solicitud de permiso enviada correctamente. Será revisada por Recursos Humanos.";
        } catch (Exception $e) {
            $errorMsg = "Error al enviar la solicitud: " . $e->getMessage();
        }
    }
}

// Get pending requests
$requestsStmt = $pdo->prepare("
    SELECT * FROM permission_requests 
    WHERE employee_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$requestsStmt->execute([$employeeId]);
$requests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

include '../header_agent.php';
?>

<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="glass-card mb-6">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-12 h-12 rounded-lg bg-blue-500/20 flex items-center justify-center">
                <i class="fas fa-calendar-check text-blue-400 text-xl"></i>
            </div>
            <div>
                <h1 class="text-2xl font-bold">Solicitar Permiso</h1>
                <p class="text-slate-400 text-sm">Envía una solicitud de permiso a Recursos Humanos</p>
            </div>
        </div>

        <?php if ($successMsg): ?>
            <div class="bg-green-500/10 border border-green-500/30 rounded-lg p-4 mb-6">
                <p class="text-green-300"><i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($successMsg) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($errorMsg): ?>
            <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-6">
                <p class="text-red-300"><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($errorMsg) ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <div>
                <label class="block text-sm font-medium mb-2">Tipo de Permiso *</label>
                <select name="permission_type" required class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="MEDICAL">Médico</option>
                    <option value="PERSONAL">Personal</option>
                    <option value="STUDY">Estudio</option>
                    <option value="FAMILY">Familiar</option>
                    <option value="OTHER">Otro</option>
                </select>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Fecha Inicio *</label>
                    <input type="date" name="start_date" required class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Fecha Fin *</label>
                    <input type="date" name="end_date" required class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-2">Motivo *</label>
                <textarea name="reason" rows="4" required class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Describe el motivo de tu solicitud..."></textarea>
            </div>

            <div class="flex gap-3">
                <button type="submit" name="submit_permission" class="flex-1 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors">
                    <i class="fas fa-paper-plane mr-2"></i>
                    Enviar Solicitud
                </button>
                <a href="../agent_dashboard.php" class="px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white font-semibold rounded-lg transition-colors">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Volver
                </a>
            </div>
        </form>
    </div>

    <?php if (!empty($requests)): ?>
    <div class="glass-card">
        <h2 class="text-xl font-bold mb-4">Mis Solicitudes de Permisos</h2>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-slate-700">
                        <th class="text-left py-3 px-4">Tipo</th>
                        <th class="text-left py-3 px-4">Fechas</th>
                        <th class="text-left py-3 px-4">Motivo</th>
                        <th class="text-center py-3 px-4">Estado</th>
                        <th class="text-left py-3 px-4">Solicitado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req): ?>
                        <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                            <td class="py-3 px-4">
                                <span class="px-2 py-1 rounded text-xs bg-blue-500/20 text-blue-300">
                                    <?= htmlspecialchars($req['request_type'] ?? '') ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 text-sm">
                                <?= htmlspecialchars(date('d/m/Y', strtotime($req['start_date']))) ?> - 
                                <?= htmlspecialchars(date('d/m/Y', strtotime($req['end_date']))) ?>
                            </td>
                            <td class="py-3 px-4 text-sm text-slate-400">
                                <?= htmlspecialchars(substr($req['reason'], 0, 50)) ?><?= strlen($req['reason']) > 50 ? '...' : '' ?>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <?php
                                $statusColors = [
                                    'PENDING' => 'bg-yellow-500/20 text-yellow-300',
                                    'APPROVED' => 'bg-green-500/20 text-green-300',
                                    'REJECTED' => 'bg-red-500/20 text-red-300'
                                ];
                                $statusColor = $statusColors[$req['status']] ?? 'bg-gray-500/20 text-gray-300';
                                ?>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $statusColor ?>">
                                    <?= htmlspecialchars($req['status']) ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 text-sm text-slate-400">
                                <?= htmlspecialchars(date('d/m/Y H:i', strtotime($req['created_at']))) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include '../footer.php'; ?>

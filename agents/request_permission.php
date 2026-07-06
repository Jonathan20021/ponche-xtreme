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
    } elseif (empty($startDate) || empty($endDate)) {
        $errorMsg = "Debes indicar la fecha de inicio y de fin.";
    } elseif ($endDate < $startDate) {
        $errorMsg = "La fecha de fin no puede ser anterior a la de inicio.";
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

$permTypeLabels = ['MEDICAL' => 'Médico', 'PERSONAL' => 'Personal', 'STUDY' => 'Estudio', 'FAMILY' => 'Familiar', 'OTHER' => 'Otro'];
$statusLabels   = ['PENDING' => 'Pendiente', 'APPROVED' => 'Aprobada', 'REJECTED' => 'Rechazada'];
?>

<div class="agent-dashboard">
    <div class="ag-pagehead">
        <div>
            <h1><i class="fas fa-calendar-check" style="color:var(--ag-brand);"></i> Solicitar Permiso</h1>
            <p>Envía una solicitud de permiso a Recursos Humanos y da seguimiento a su estado.</p>
        </div>
        <div class="ag-head-actions">
            <a href="../agent_dashboard.php" class="ag-chip" style="text-decoration:none;"><i class="fas fa-arrow-left"></i> Volver al panel</a>
        </div>
    </div>

    <?php if ($successMsg): ?>
        <div class="ag-alert ok"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="ag-alert err"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="ag-req-layout">
        <!-- Formulario -->
        <div class="ag-card ag-sec">
            <div class="ag-sec-head"><div class="ttl"><i class="fas fa-paper-plane"></i> Nueva solicitud</div></div>
            <form method="POST" autocomplete="off">
                <div class="ag-field">
                    <label for="permission_type">Tipo de permiso <span class="req">*</span></label>
                    <select name="permission_type" id="permission_type" required>
                        <?php foreach ($permTypeLabels as $val => $lbl): ?>
                            <option value="<?= $val ?>"><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ag-row2">
                    <div class="ag-field">
                        <label for="start_date">Fecha inicio <span class="req">*</span></label>
                        <input type="date" name="start_date" id="start_date" required>
                    </div>
                    <div class="ag-field">
                        <label for="end_date">Fecha fin <span class="req">*</span></label>
                        <input type="date" name="end_date" id="end_date" required>
                    </div>
                </div>
                <div class="ag-field">
                    <label for="reason">Motivo <span class="req">*</span></label>
                    <textarea name="reason" id="reason" rows="4" required placeholder="Describe el motivo de tu solicitud…"></textarea>
                    <div class="hint">Recursos Humanos revisará tu solicitud y verás el resultado en el historial.</div>
                </div>
                <div class="ag-form-actions">
                    <button type="submit" name="submit_permission" class="ag-btn ag-btn-primary"><i class="fas fa-paper-plane"></i> Enviar solicitud</button>
                    <a href="../agent_dashboard.php" class="ag-btn ag-btn-ghost"><i class="fas fa-xmark"></i> Cancelar</a>
                </div>
            </form>
        </div>

        <!-- Historial -->
        <div class="ag-card ag-sec">
            <div class="ag-sec-head">
                <div class="ttl"><i class="fas fa-clock-rotate-left"></i> Mis solicitudes</div>
                <?php if (!empty($requests)): ?><span class="ag-chip"><?= count($requests) ?> recientes</span><?php endif; ?>
            </div>
            <?php if (empty($requests)): ?>
                <div class="ag-empty-state"><i class="fas fa-inbox"></i><p>Aún no has enviado solicitudes de permiso.</p></div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="ag-table">
                        <thead>
                            <tr><th>Tipo</th><th>Fechas</th><th>Motivo</th><th style="text-align:center;">Estado</th><th style="text-align:right;">Solicitado</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($requests as $req): ?>
                            <?php $reason = (string) ($req['reason'] ?? ''); ?>
                            <tr>
                                <td><span class="ag-tag" style="background:var(--ag-brand-tint); color:var(--ag-brand);"><?= htmlspecialchars($permTypeLabels[$req['request_type']] ?? ($req['request_type'] ?? '—')) ?></span></td>
                                <td class="ag-tsub"><?= htmlspecialchars(date('d/m/Y', strtotime($req['start_date']))) ?> – <?= htmlspecialchars(date('d/m/Y', strtotime($req['end_date']))) ?></td>
                                <td class="ag-tsub" title="<?= htmlspecialchars($reason) ?>"><?= htmlspecialchars(mb_substr($reason, 0, 46)) ?><?= mb_strlen($reason) > 46 ? '…' : '' ?></td>
                                <td style="text-align:center;"><span class="ag-status <?= htmlspecialchars($req['status']) ?>"><?= htmlspecialchars($statusLabels[$req['status']] ?? $req['status']) ?></span></td>
                                <td style="text-align:right;" class="ag-tsub"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($req['created_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../footer.php'; ?>

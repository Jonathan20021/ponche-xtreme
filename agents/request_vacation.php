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

// Handle vacation request submission
$successMsg = null;
$errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vacation'])) {
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $days = (int)$_POST['days_requested'];
    $reason = trim($_POST['reason']);

    if (empty($startDate) || empty($endDate)) {
        $errorMsg = "Debes indicar la fecha de inicio y de fin.";
    } elseif ($endDate < $startDate) {
        $errorMsg = "La fecha de fin no puede ser anterior a la de inicio.";
    } elseif ($days < 1) {
        $errorMsg = "Debes solicitar al menos 1 día de vacaciones.";
    } else {
        try {
            $insertStmt = $pdo->prepare("
                INSERT INTO vacation_requests (employee_id, start_date, end_date, days_requested, reason, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'PENDING', NOW())
            ");
            $insertStmt->execute([$employeeId, $startDate, $endDate, $days, $reason]);
            $successMsg = "Solicitud de vacaciones enviada correctamente. Será revisada por Recursos Humanos.";
        } catch (Exception $e) {
            $errorMsg = "Error al enviar la solicitud: " . $e->getMessage();
        }
    }
}

// Get pending requests
$requestsStmt = $pdo->prepare("
    SELECT * FROM vacation_requests
    WHERE employee_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$requestsStmt->execute([$employeeId]);
$requests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

include '../header_agent.php';

$statusLabels = ['PENDING' => 'Pendiente', 'APPROVED' => 'Aprobada', 'REJECTED' => 'Rechazada'];
?>

<div class="agent-dashboard">
    <div class="ag-pagehead">
        <div>
            <h1><i class="fas fa-umbrella-beach" style="color:var(--ag-brand);"></i> Solicitar Vacaciones</h1>
            <p>Envía una solicitud de vacaciones a Recursos Humanos y da seguimiento a su estado.</p>
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
                    <label for="days_requested">Días solicitados <span class="req">*</span></label>
                    <input type="number" name="days_requested" id="days_requested" min="1" required placeholder="Número de días">
                    <div class="hint">Se calcula automáticamente al elegir las fechas; puedes ajustarlo si excluyes fines de semana.</div>
                </div>
                <div class="ag-field">
                    <label for="reason">Motivo <span style="color:var(--ag-faint); font-weight:600;">(opcional)</span></label>
                    <textarea name="reason" id="reason" rows="4" placeholder="Describe el motivo de tus vacaciones…"></textarea>
                </div>
                <div class="ag-form-actions">
                    <button type="submit" name="submit_vacation" class="ag-btn ag-btn-primary"><i class="fas fa-paper-plane"></i> Enviar solicitud</button>
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
                <div class="ag-empty-state"><i class="fas fa-umbrella-beach"></i><p>Aún no has enviado solicitudes de vacaciones.</p></div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="ag-table">
                        <thead>
                            <tr><th>Días</th><th>Fechas</th><th>Motivo</th><th style="text-align:center;">Estado</th><th style="text-align:right;">Solicitado</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($requests as $req): ?>
                            <?php $reason = (string) ($req['reason'] ?? ''); ?>
                            <tr>
                                <td><span class="ag-tag" style="background:var(--ag-brand-tint); color:var(--ag-brand);"><?= (int) $req['days_requested'] ?> días</span></td>
                                <td class="ag-tsub"><?= htmlspecialchars(date('d/m/Y', strtotime($req['start_date']))) ?> – <?= htmlspecialchars(date('d/m/Y', strtotime($req['end_date']))) ?></td>
                                <td class="ag-tsub" title="<?= htmlspecialchars($reason) ?>"><?= $reason !== '' ? htmlspecialchars(mb_substr($reason, 0, 46)) . (mb_strlen($reason) > 46 ? '…' : '') : '<span style="opacity:.6;">Sin motivo</span>' ?></td>
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

<script>
// Auto-calcular días al elegir fechas (el usuario puede sobreescribir).
(function () {
    var s = document.getElementById('start_date'),
        e = document.getElementById('end_date'),
        d = document.getElementById('days_requested');
    if (!s || !e || !d) { return; }
    var userTouched = false;
    d.addEventListener('input', function () { userTouched = true; });
    function calc() {
        if (!s.value || !e.value) { return; }
        var a = new Date(s.value + 'T00:00:00'), b = new Date(e.value + 'T00:00:00');
        if (isNaN(a) || isNaN(b) || b < a) { return; }
        var days = Math.round((b - a) / 86400000) + 1;
        if (!userTouched || d.value === '' || parseInt(d.value, 10) < 1) { d.value = days; }
    }
    s.addEventListener('change', calc);
    e.addEventListener('change', calc);
})();
</script>

<?php include '../footer.php'; ?>

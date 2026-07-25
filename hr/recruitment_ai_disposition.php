<?php
/**
 * hr/recruitment_ai_disposition.php
 *
 * Aprueba o descarta la disposición que la IA sugirió para un candidato.
 * La IA nunca mueve al candidato sola cuando la revisión está activa: aquí es
 * donde Reclutamiento decide, después de ver la evaluación y la justificación
 * que le llegó por la campana de notificaciones.
 */

session_start();
require_once '../db.php';
require_once '../lib/recruitment_ai.php';
require_once '../lib/notifications.php';

ensurePermission('hr_recruitment', '../unauthorized.php');

$isAjax = !empty($_POST['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

function dispositionRespond(bool $ok, string $message, int $applicationId, bool $isAjax): void
{
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $ok, 'message' => $message]);
        exit;
    }
    $_SESSION[$ok ? 'success_message' : 'error_message'] = $message;
    header('Location: view_application.php?id=' . $applicationId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: recruitment.php');
    exit;
}

$applicationId = (int) ($_POST['application_id'] ?? 0);
$decision      = (string) ($_POST['decision'] ?? '');

if ($applicationId <= 0 || !in_array($decision, ['approve', 'discard'], true)) {
    dispositionRespond(false, 'Parámetros inválidos.', $applicationId, $isAjax);
}

$result = recruitmentAIResolveDisposition(
    $pdo,
    $applicationId,
    $decision === 'approve',
    (int) $_SESSION['user_id']
);

if (!$result['success']) {
    dispositionRespond(false, $result['error'] ?? 'No se pudo procesar la decisión.', $applicationId, $isAjax);
}

// Cierra la notificación pendiente de esa postulación: ya fue atendida y no
// tiene por qué seguir contando como acción pendiente para nadie.
try {
    $stmt = $pdo->prepare("
        SELECT id FROM system_notifications
        WHERE notif_type = 'RECRUITMENT_AI_DISPOSITION'
          AND requires_action = 1
          AND resolved_at IS NULL
          AND dedupe_key LIKE ?
    ");
    $stmt->execute(['recruitment_ai_disp:' . $applicationId . ':%']);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $notifId) {
        notifyResolve($pdo, (int) $notifId, (int) $_SESSION['user_id']);
    }
} catch (Throwable $e) {
    error_log('recruitment_ai_disposition (cerrar notificación): ' . $e->getMessage());
}

$message = $decision === 'approve'
    ? 'Disposición aplicada: ' . recruitmentDispositionLabel((string) $result['applied_status'])
    : 'Sugerencia descartada. El candidato queda como estaba.';

dispositionRespond(true, $message, $applicationId, $isAjax);

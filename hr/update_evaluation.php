<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

// Permissions
try {
    ensurePermission('hr_recruitment', '../unauthorized.php');
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo no permitido']);
    exit;
}

$application_id = (int)($_POST['application_id'] ?? 0);
$result = $_POST['evaluation_result'] ?? '';
$datetime = $_POST['evaluation_datetime'] ?? '';
$comments = trim($_POST['evaluation_comments'] ?? '');
$interviewer = trim($_POST['evaluation_interviewer'] ?? '');
$interview_date = $_POST['evaluation_interview_date'] ?? '';

$allowedResults = ['acceptable', 'rejected', 'consideration', 'interview'];

if ($application_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Solicitud invalida']);
    exit;
}
if (!in_array($result, $allowedResults, true)) {
    echo json_encode(['success' => false, 'message' => 'Resultado invalido']);
    exit;
}
if ($datetime === '') {
    echo json_encode(['success' => false, 'message' => 'La fecha/hora de evaluacion es requerida']);
    exit;
}

// Normalize datetime
$dt = date('Y-m-d H:i:s', strtotime($datetime));
$interview_date_clean = $interview_date ? date('Y-m-d', strtotime($interview_date)) : null;

$stmt = $pdo->prepare("
    UPDATE job_applications
    SET evaluation_result = :result,
        evaluation_datetime = :eval_dt,
        evaluation_comments = :comments,
        evaluation_interviewer = :interviewer,
        evaluation_interview_date = :interview_date,
        last_updated = NOW()
    WHERE id = :id
");

$stmt->execute([
    'result' => $result,
    'eval_dt' => $dt,
    'comments' => $comments !== '' ? $comments : null,
    'interviewer' => $interviewer !== '' ? $interviewer : null,
    'interview_date' => $interview_date_clean,
    'id' => $application_id
]);

echo json_encode(['success' => true, 'message' => 'Evaluacion guardada']);

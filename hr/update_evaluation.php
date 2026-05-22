<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !userHasPermission('hr_recruitment')) {
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
$datetimeTs = strtotime($datetime);
if ($datetimeTs === false) {
    echo json_encode(['success' => false, 'message' => 'Fecha/hora de evaluacion invalida']);
    exit;
}
$dt = date('Y-m-d H:i:s', $datetimeTs);

$interview_date_clean = null;
if ($interview_date !== '') {
    $interviewTs = strtotime($interview_date);
    if ($interviewTs === false) {
        echo json_encode(['success' => false, 'message' => 'Fecha de entrevista invalida']);
        exit;
    }
    $interview_date_clean = date('Y-m-d', $interviewTs);
}

try {
    $exists = $pdo->prepare("SELECT id FROM job_applications WHERE id = ?");
    $exists->execute([$application_id]);
    if (!$exists->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Solicitud no encontrada']);
        exit;
    }

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
} catch (PDOException $e) {
    error_log('update_evaluation error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'No se pudo guardar la evaluacion']);
}

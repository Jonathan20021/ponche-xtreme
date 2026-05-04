<?php
/**
 * Admin-side AJAX endpoints for the recruitment AI features.
 * Actions:
 *   - generate_job_description : POST {title, department?, notes?}
 *   - process_application      : POST {application_id}    (parse CV + screen)
 *   - screen_application       : POST {application_id}    (re-screen against current job)
 *   - summarize_application    : POST {application_id}    (one-paragraph summary)
 */

session_start();
require_once '../db.php';
require_once '../lib/recruitment_ai.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado.']);
    exit;
}

if (!function_exists('userHasPermission') || (!userHasPermission('hr_recruitment') && !userHasPermission('hr_recruitment_ai') && !userHasPermission('hr_job_postings'))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sin permisos para usar IA de reclutamiento.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'generate_job_description': {
            $title      = trim($_POST['title']      ?? '');
            $department = trim($_POST['department'] ?? '');
            $notes      = trim($_POST['notes']      ?? '');
            if ($title === '') {
                echo json_encode(['success' => false, 'error' => 'Falta el título del puesto.']);
                exit;
            }
            $r = generateJobDescriptionWithAI($pdo, $title, $department, $notes);
            echo json_encode($r);
            break;
        }

        case 'process_application': {
            $id = (int) ($_POST['application_id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'application_id inválido.']);
                exit;
            }
            $r = processApplicationAI($pdo, $id);
            echo json_encode($r);
            break;
        }

        case 'screen_application': {
            $id = (int) ($_POST['application_id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'application_id inválido.']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT * FROM job_applications WHERE id = ?");
            $stmt->execute([$id]);
            $app = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$app) {
                echo json_encode(['success' => false, 'error' => 'Solicitud no encontrada.']);
                exit;
            }
            $job = null;
            if (!empty($app['job_posting_id'])) {
                $j = $pdo->prepare("SELECT * FROM job_postings WHERE id = ?");
                $j->execute([$app['job_posting_id']]);
                $job = $j->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$job) {
                echo json_encode(['success' => false, 'error' => 'No hay vacante asociada para evaluar.']);
                exit;
            }
            $extracted = !empty($app['ai_extracted_data']) ? json_decode($app['ai_extracted_data'], true) : null;
            $r = screenCandidateWithAI($pdo, $app, $job, $extracted);
            if ($r['success']) {
                $u = $pdo->prepare("UPDATE job_applications
                    SET ai_summary = ?, ai_score = ?, ai_strengths = ?, ai_concerns = ?, ai_recommendation = ?, ai_processed_at = NOW(), ai_model_used = ?
                    WHERE id = ?");
                $u->execute([
                    $r['summary'],
                    $r['score'],
                    json_encode($r['strengths'] ?: [], JSON_UNESCAPED_UNICODE),
                    json_encode($r['concerns']  ?: [], JSON_UNESCAPED_UNICODE),
                    $r['recommendation'],
                    $r['model'],
                    $id,
                ]);
            }
            echo json_encode($r);
            break;
        }

        case 'summarize_application': {
            // Lightweight: re-use processApplicationAI which combines parse + screen
            $id = (int) ($_POST['application_id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'application_id inválido.']);
                exit;
            }
            $r = processApplicationAI($pdo, $id);
            echo json_encode($r);
            break;
        }

        case 'bulk_process_unscored': {
            // Cap to a small batch to avoid long-running requests
            $cap = max(1, min(20, (int) ($_REQUEST['limit'] ?? 10)));
            $ids = $pdo->query("SELECT id FROM job_applications WHERE ai_processed_at IS NULL ORDER BY applied_date DESC LIMIT $cap")->fetchAll(PDO::FETCH_COLUMN);
            $processed = 0;
            $errors = [];
            foreach ($ids as $id) {
                $r = processApplicationAI($pdo, (int) $id);
                if ($r['success']) {
                    $processed++;
                } else {
                    $errors[] = ['id' => $id, 'error' => $r['error']];
                }
            }
            echo json_encode(['success' => true, 'processed' => $processed, 'attempted' => count($ids), 'errors' => $errors]);
            break;
        }

        default:
            echo json_encode(['success' => false, 'error' => 'Acción desconocida.']);
            break;
    }
} catch (Throwable $e) {
    error_log('recruitment_ai_endpoints error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
}

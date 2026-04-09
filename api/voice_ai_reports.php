<?php
ini_set('display_errors', '0');
ini_set('html_errors', '0');
if (function_exists('set_time_limit')) {
    @set_time_limit(300);
}

ob_start();
session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';
require_once __DIR__ . '/../lib/voice_ai_client.php';
require_once __DIR__ . '/../lib/voice_ai_extended_reports.php';

$voiceAiJsonResponseSent = false;

function voiceAiSendJsonResponse(array $payload, int $statusCode = 200): void
{
    global $voiceAiJsonResponseSent;

    $voiceAiJsonResponseSent = true;

    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo voiceAiJsonEncode($payload);
    exit;
}

register_shutdown_function(static function () use (&$voiceAiJsonResponseSent): void {
    if ($voiceAiJsonResponseSent) {
        return;
    }

    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    error_log('Voice AI reports shutdown error: ' . ($error['message'] ?? 'unknown'));

    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo voiceAiJsonEncode([
        'success' => false,
        'message' => 'Se produjo un error interno al consultar la reporteria de comunicaciones.',
    ]);
});

function voiceAiReadInput(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function voiceAiBuildFiltersFromRequest(): array
{
    return [
        'integration_id' => trim((string) ($_GET['integration_id'] ?? '')),
        'start_date' => trim((string) ($_GET['start_date'] ?? '')),
        'end_date' => trim((string) ($_GET['end_date'] ?? '')),
        'interaction_channel' => trim((string) ($_GET['interaction_channel'] ?? '')),
        'direction' => trim((string) ($_GET['direction'] ?? '')),
        'status' => trim((string) ($_GET['status'] ?? '')),
        'disposition' => trim((string) ($_GET['disposition'] ?? '')),
        'source' => trim((string) ($_GET['source'] ?? '')),
        'user_id' => trim((string) ($_GET['user_id'] ?? '')),
        'call_type' => trim((string) ($_GET['call_type'] ?? '')),
        'action_type' => trim((string) ($_GET['action_type'] ?? '')),
        'search' => trim((string) ($_GET['search'] ?? '')),
        'transcript_only' => ($_GET['transcript_only'] ?? '0') === '1',
        'fast_mode' => ($_GET['fast_mode'] ?? '1') !== '0',
        'with_comparison' => ($_GET['with_comparison'] ?? '0') === '1',
        'sort_order' => strtolower((string) ($_GET['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
        'max_pages' => (int) ($_GET['max_pages'] ?? 10),
        'page_size' => (int) ($_GET['page_size'] ?? 50),
        'interaction_max_pages' => (int) ($_GET['interaction_max_pages'] ?? 50),
        'interaction_page_size' => (int) ($_GET['interaction_page_size'] ?? 100),
    ];
}

function voiceAiIsTransientDbError(Throwable $e): bool
{
    $message = strtolower($e->getMessage());
    if (strpos($message, 'server has gone away') !== false) {
        return true;
    }
    if (strpos($message, 'lost connection') !== false) {
        return true;
    }

    if ($e instanceof PDOException) {
        $code = (string) $e->getCode();
        if ($code === '2006' || $code === '2013') {
            return true;
        }
    }

    return false;
}

function voiceAiReconnectPdo(): ?PDO
{
    global $host, $dbname, $username, $password, $pdo;

    try {
        $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $username, $password, [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec("SET time_zone = '-04:00'");
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

if (!isset($_SESSION['user_id']) || !userHasPermission('voice_ai_reports')) {
    voiceAiSendJsonResponse([
        'success' => false,
        'message' => 'No tienes permiso para acceder a la reporteria de comunicaciones.',
    ], 403);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'dashboard';

try {
    switch ($action) {
        case 'config_status':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            voiceAiSendJsonResponse([
                'success' => true,
                'config_status' => voiceAiGetConfigStatus($pdo, $_GET['integration_id'] ?? null),
            ]);
            break;

        case 'save_config':
            if (!userHasPermission('settings')) {
                voiceAiSendJsonResponse([
                    'success' => false,
                    'message' => 'No tienes permiso para actualizar la configuracion.',
                ], 403);
            }

            $payload = voiceAiReadInput();
            voiceAiSetContextIntegrationId($payload['integration_id'] ?? null);
            $result = voiceAiSaveConfig($pdo, $payload, (int) ($_SESSION['user_id'] ?? 0));
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'call_detail':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $callId = trim((string) ($_GET['call_id'] ?? ''));
            if ($callId === '') {
                voiceAiSendJsonResponse([
                    'success' => false,
                    'message' => 'Debes enviar un call_id.',
                ], 400);
            }

            $result = voiceAiGetCallDetail($pdo, $callId);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'disposition_analytics':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $result = voiceAiFetchDispositionAnalytics($pdo, $filters);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'call_quality':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $result = voiceAiFetchCallQualityMetrics($pdo, $filters);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'comprehensive_report':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $result = voiceAiGenerateComprehensiveReport($pdo, $filters);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'dashboard':
        default:
            $filters = voiceAiBuildFiltersFromRequest();
            try {
                $result = voiceAiBuildReportPayload($pdo, $filters, !empty($filters['with_comparison']));
            } catch (Throwable $retryableError) {
                if (!voiceAiIsTransientDbError($retryableError)) {
                    throw $retryableError;
                }

                $reconnected = voiceAiReconnectPdo();
                if (!$reconnected) {
                    throw $retryableError;
                }

                voiceAiSetContextIntegrationId($filters['integration_id'] ?? null);
                $result = voiceAiBuildReportPayload($reconnected, $filters, !empty($filters['with_comparison']));
            }
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;
    }
} catch (Throwable $e) {
    error_log('Voice AI reports API error: ' . $e->getMessage());
    voiceAiSendJsonResponse([
        'success' => false,
        'message' => 'Se produjo un error interno al consultar la reporteria de comunicaciones.',
    ], 500);
}


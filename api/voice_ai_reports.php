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
require_once __DIR__ . '/../lib/voice_ai_mega_reports.php';
require_once __DIR__ . '/../lib/voice_ai_dispositions.php';
require_once __DIR__ . '/../lib/voice_ai_ai_insights.php';

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

        case 'dispositions_full':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $result = voiceAiFetchUnifiedDispositions($pdo, $filters);
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

        case 'pipelines':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $result = voiceAiMegaFetchPipelines($pdo);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'opportunities_report':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $result = voiceAiMegaFetchOpportunities($pdo, $filters);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'calendars':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $result = voiceAiMegaFetchCalendars($pdo);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'appointments_report':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $result = voiceAiMegaFetchAppointments($pdo, $filters);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'forms_report':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $result = voiceAiMegaFetchForms($pdo, $filters);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'surveys_report':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $result = voiceAiMegaFetchSurveys($pdo, $filters);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'workflows_report':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $result = voiceAiMegaFetchWorkflows($pdo);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'campaigns_report':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $result = voiceAiMegaFetchCampaigns($pdo);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'tags_report':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $result = voiceAiMegaFetchTags($pdo);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'custom_fields_report':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $result = voiceAiMegaFetchCustomFields($pdo);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'contacts_growth':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $result = voiceAiMegaFetchContactsGrowth($pdo, $filters);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'location_info':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $result = voiceAiMegaFetchLocationInfo($pdo);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'mega_report':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $result = voiceAiMegaFetchMegaReport($pdo, $filters);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'ai_executive_summary':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $bundle = voiceAiInsightSharedContext($pdo, $filters, [
                'include_pipeline' => true,
                'include_appointments' => true,
                'include_contacts_growth' => true,
            ]);
            if (!voiceAiInsightHasAnyData($bundle)) {
                voiceAiSendJsonResponse([
                    'success' => false,
                    'enabled' => voiceAiInsightIsEnabled($pdo),
                    'content' => '',
                    'error' => 'Sin datos en el rango seleccionado — abre primero el dashboard o amplia el rango antes de pedir el resumen.',
                    'model' => '',
                    'usage' => null,
                    'cached' => false,
                ], 200);
            }
            $result = voiceAiInsightExecutiveSummary($pdo, $bundle);
            $result['data_shape'] = ['has_pipeline' => !empty($bundle['pipeline']), 'has_appointments' => !empty($bundle['appointments']), 'has_contacts_growth' => !empty($bundle['contacts_growth'])];
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'ai_agent_coaching':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $bundle = voiceAiInsightSharedContext($pdo, $filters, [
                'include_voice_ai_quality' => true,
            ]);
            if (!voiceAiInsightHasAnyData($bundle)) {
                voiceAiSendJsonResponse([
                    'success' => false,
                    'enabled' => voiceAiInsightIsEnabled($pdo),
                    'content' => '',
                    'error' => 'No hay actividad de agentes en el rango. Carga el dashboard o selecciona un rango con llamadas/mensajes antes de pedir coaching.',
                    'model' => '',
                    'usage' => null,
                    'cached' => false,
                ], 200);
            }
            $result = voiceAiInsightAgentCoaching($pdo, $bundle);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'ai_risk_opportunity':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $bundle = voiceAiInsightSharedContext($pdo, $filters, [
                'include_pipeline' => true,
                'include_contacts_growth' => true,
            ]);
            $dashboard = $bundle['dashboard'] ?? [];
            $payload = [
                'period' => $bundle['filters'] ?? [],
                'call_dispositions' => $dashboard['call_dispositions'] ?? [],
                'timeline' => $dashboard['timeline'] ?? [],
                'distributions' => $dashboard['distributions'] ?? [],
                'contacts' => $dashboard['contacts'] ?? [],
                'agents_activity' => array_slice($dashboard['agents'] ?? [], 0, 15),
                'opportunities_summary' => $bundle['pipeline']['summary'] ?? null,
                'top_opportunities' => $bundle['pipeline']['top'] ?? [],
                'contacts_growth' => $bundle['contacts_growth'] ?? null,
            ];
            $result = voiceAiInsightChurnAndOpportunity($pdo, $payload);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'ai_anomalies':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $bundle = voiceAiInsightSharedContext($pdo, $filters);
            $dashboard = $bundle['dashboard'] ?? [];
            $result = voiceAiInsightAnomalies(
                $pdo,
                $dashboard['timeline'] ?? [],
                $dashboard['distributions'] ?? []
            );
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'ai_forecast':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $bundle = voiceAiInsightSharedContext($pdo, $filters);
            $timelineByDay = $bundle['dashboard']['timeline']['by_day'] ?? [];
            $result = voiceAiInsightForecast($pdo, $timelineByDay);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'ai_natural_query':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $input = voiceAiReadInput();
            $question = trim((string) ($input['question'] ?? ($_GET['question'] ?? '')));
            $bundle = voiceAiInsightSharedContext($pdo, $filters, [
                'include_pipeline' => true,
                'include_appointments' => true,
                'include_contacts_growth' => true,
                'include_forms_surveys' => true,
            ]);
            $dashboard = $bundle['dashboard'] ?? [];
            $dataContext = [
                'period' => $bundle['filters'] ?? [],
                'kpis' => $dashboard['kpis'] ?? [],
                'distributions' => $dashboard['distributions'] ?? [],
                'timeline' => $dashboard['timeline'] ?? [],
                'call_dispositions' => $dashboard['call_dispositions'] ?? [],
                'disposition_by_user' => $dashboard['disposition_by_user'] ?? [],
                'queue_by_user' => $dashboard['queue_by_user'] ?? [],
                'agents_activity' => array_slice($dashboard['agents'] ?? [], 0, 20),
                'contacts' => array_slice($dashboard['contacts'] ?? [], 0, 20),
                'summary' => $dashboard['summary'] ?? [],
                'pipeline' => $bundle['pipeline']['summary'] ?? null,
                'appointments' => $bundle['appointments'] ?? null,
                'contacts_growth' => $bundle['contacts_growth'] ?? null,
                'forms' => $bundle['forms'] ?? null,
                'surveys' => $bundle['surveys'] ?? null,
            ];
            $result = voiceAiInsightNaturalQuery($pdo, $question, $dataContext);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'ai_call_analysis':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $callId = trim((string) ($_GET['call_id'] ?? ''));
            if ($callId === '') {
                voiceAiSendJsonResponse(['success' => false, 'message' => 'Debes enviar un call_id.'], 400);
            }
            $detail = voiceAiGetCallDetail($pdo, $callId);
            if (empty($detail['success'])) {
                voiceAiSendJsonResponse($detail, 400);
            }
            $result = voiceAiInsightCallAnalysis($pdo, $detail['call'] ?? []);
            $result['call_id'] = $callId;
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'ai_opportunities':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $opportunities = voiceAiMegaFetchOpportunities($pdo, $filters);
            if (empty($opportunities['success'])) {
                voiceAiSendJsonResponse($opportunities, 400);
            }
            $result = voiceAiInsightOpportunities($pdo, $opportunities);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'ai_health':
            $result = voiceAiInsightHealthCheck($pdo);
            voiceAiSendJsonResponse($result, !empty($result['success']) ? 200 : 400);
            break;

        case 'date_debug':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $config = voiceAiGetConfig($pdo);
            $range = voiceAiMegaResolveDateRange($filters);
            $messageRange = voiceAiNormalizeInclusiveDateRange($filters);

            $voiceAiCallLogsQ = voiceAiBuildListQuery($config, $filters, 1);
            $conversationsQ = voiceAiBuildMessageExportQuery($config, $filters, 'Call');

            $oppsQ = [
                'location_id' => $config['location_id'],
                'limit' => 100,
                '__note' => 'GHL /opportunities/search no filtra por fecha server-side; se filtra localmente por createdAt (dentro del rango ['.$range['start_date'].' 00:00:00 → '.$range['end_date'].' 23:59:59]).',
            ];
            $apptsQ = [
                'locationId' => $config['location_id'],
                'calendarId' => '<cada calendarId>',
                'startTime' => $range['start_ms'],
                'endTime' => $range['end_ms'],
                '__note' => 'epoch ms, inclusive.',
            ];
            $formsQ = [
                'locationId' => $config['location_id'],
                'startAt' => $range['start_date'],
                'endAt' => $range['end_date'],
                '__note' => 'YYYY-MM-DD.',
            ];
            $contactsBody = [
                'locationId' => $config['location_id'],
                'filters' => [[
                    'field' => 'dateAdded',
                    'operator' => 'between',
                    'value' => [$range['start_iso'], $range['end_iso']],
                ]],
                '__note' => 'POST /contacts/search con filtro ISO + filtro local de respaldo.',
            ];

            voiceAiSendJsonResponse([
                'success' => true,
                'integration' => [
                    'location_id' => $config['location_id'],
                    'timezone' => $config['timezone'],
                    'version' => $config['version'],
                ],
                'input_filters' => [
                    'start_date' => $filters['start_date'],
                    'end_date' => $filters['end_date'],
                ],
                'resolved_range' => $range,
                'endpoints' => [
                    '/voice-ai/dashboard/call-logs' => [
                        'method' => 'GET',
                        'query' => $voiceAiCallLogsQ,
                        'format_used' => 'startDate=YYYY-MM-DD, endDate=YYYY-MM-DD',
                    ],
                    '/conversations/messages/export' => [
                        'method' => 'GET',
                        'query' => $conversationsQ,
                        'inclusive_date_range' => $messageRange,
                        'format_used' => 'startDate=YYYY-MM-DD, endDate=YYYY-MM-DD (endDate exclusive → +1 día)',
                    ],
                    '/opportunities/search' => [
                        'method' => 'GET',
                        'query' => $oppsQ,
                        'format_used' => 'sin filtro server-side; filtro local por createdAt en el rango',
                    ],
                    '/calendars/events' => [
                        'method' => 'GET',
                        'query' => $apptsQ,
                        'format_used' => 'startTime/endTime en epoch ms',
                    ],
                    '/forms/submissions' => [
                        'method' => 'GET',
                        'query' => $formsQ,
                        'format_used' => 'startAt/endAt en YYYY-MM-DD',
                    ],
                    '/surveys/submissions' => [
                        'method' => 'GET',
                        'query' => $formsQ,
                        'format_used' => 'startAt/endAt en YYYY-MM-DD',
                    ],
                    '/contacts/search' => [
                        'method' => 'POST',
                        'body' => $contactsBody,
                        'format_used' => 'filter ISO8601 + filtro local por dateAdded',
                    ],
                ],
            ]);
            break;

        case 'date_probe':
            voiceAiSetContextIntegrationId($_GET['integration_id'] ?? null);
            $filters = voiceAiBuildFiltersFromRequest();
            $config = voiceAiGetConfig($pdo);
            $probes = [];

            // Real calls with timing — confirms the date filter actually
            // returns data from GHL with the current range.
            $t = microtime(true);
            $r = voiceAiHttpRequest($config, 'GET', '/voice-ai/dashboard/call-logs', voiceAiBuildListQuery($config, $filters, 1));
            $probes['/voice-ai/dashboard/call-logs'] = [
                'ok' => !empty($r['success']),
                'status' => $r['status_code'] ?? 0,
                'elapsed_ms' => (int) round((microtime(true) - $t) * 1000),
                'records_returned' => count(voiceAiExtractCallItems($r['data'] ?? [])),
                'message' => $r['message'] ?? null,
            ];

            $t = microtime(true);
            $r = voiceAiHttpRequest($config, 'GET', '/conversations/messages/export', voiceAiBuildMessageExportQuery($config, $filters, 'Call'));
            $probes['/conversations/messages?channel=Call'] = [
                'ok' => !empty($r['success']),
                'status' => $r['status_code'] ?? 0,
                'elapsed_ms' => (int) round((microtime(true) - $t) * 1000),
                'records_returned' => is_array($r['data']['messages'] ?? null) ? count($r['data']['messages']) : 0,
                'message' => $r['message'] ?? null,
            ];

            $t = microtime(true);
            $r = voiceAiHttpRequest($config, 'GET', '/opportunities/search', [
                'location_id' => $config['location_id'],
                'limit' => 100,
            ]);
            $probes['/opportunities/search'] = [
                'ok' => !empty($r['success']),
                'status' => $r['status_code'] ?? 0,
                'elapsed_ms' => (int) round((microtime(true) - $t) * 1000),
                'records_returned' => is_array($r['data']['opportunities'] ?? null) ? count($r['data']['opportunities']) : 0,
                'message' => $r['message'] ?? null,
            ];

            voiceAiSendJsonResponse([
                'success' => true,
                'input_filters' => [
                    'start_date' => $filters['start_date'],
                    'end_date' => $filters['end_date'],
                ],
                'probes' => $probes,
                'hint' => 'Si algún endpoint devuelve records_returned=0, verifica que exista data en ese rango dentro de GHL directamente.',
            ]);
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


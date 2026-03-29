<?php
ini_set('display_errors', '0');
ini_set('html_errors', '0');
if (function_exists('set_time_limit')) {
    @set_time_limit(300);
}

session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';
require_once __DIR__ . '/../lib/voice_ai_client.php';

if (!isset($_SESSION['user_id']) || !userHasPermission('voice_ai_reports')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'No tienes permiso para exportar este reporte.';
    exit;
}

$filters = [
    'integration_id' => trim((string) ($_GET['integration_id'] ?? '')),
    'start_date' => trim((string) ($_GET['start_date'] ?? '')),
    'end_date' => trim((string) ($_GET['end_date'] ?? '')),
    'interaction_channel' => trim((string) ($_GET['interaction_channel'] ?? '')),
    'direction' => trim((string) ($_GET['direction'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'source' => trim((string) ($_GET['source'] ?? '')),
    'user_id' => trim((string) ($_GET['user_id'] ?? '')),
    'call_type' => trim((string) ($_GET['call_type'] ?? '')),
    'action_type' => trim((string) ($_GET['action_type'] ?? '')),
    'search' => trim((string) ($_GET['search'] ?? '')),
    'transcript_only' => ($_GET['transcript_only'] ?? '0') === '1',
    'sort_order' => strtolower((string) ($_GET['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
];

voiceAiSetContextIntegrationId($filters['integration_id'] ?? null);

$result = voiceAiFetchInteractions($pdo, $filters);
if (!$result['success']) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $result['message'] ?? 'No se pudo generar la exportacion.';
    exit;
}

$filename = sprintf(
    'communications_report_%s_to_%s.csv',
    preg_replace('/[^0-9-]/', '', $filters['start_date'] ?: 'all'),
    preg_replace('/[^0-9-]/', '', $filters['end_date'] ?: 'all')
);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'interaction_id',
    'conversation_id',
    'date_added',
    'channel',
    'direction',
    'status',
    'source',
    'duration_seconds',
    'user_id',
    'user_name',
    'contact_id',
    'contact_name',
    'contact_phone',
    'contact_email',
    'contact_company',
    'business_number',
    'counterparty_phone',
    'has_recording',
    'recording_urls',
    'body',
    'error',
]);

foreach ($result['items'] as $item) {
    fputcsv($output, [
        $item['id'],
        $item['conversation_id'],
        $item['date_added'],
        $item['channel'],
        $item['direction'],
        $item['status'],
        $item['source'],
        $item['duration_seconds'],
        $item['user_id'],
        $item['user_name'],
        $item['contact_id'],
        $item['contact_name'],
        $item['contact_phone'],
        $item['contact_email'],
        $item['contact_company'],
        $item['business_number'],
        $item['counterparty_phone'],
        !empty($item['has_recording']) ? '1' : '0',
        implode(', ', $item['recording_urls'] ?? []),
        $item['body'],
        $item['error'],
    ]);
}

fclose($output);

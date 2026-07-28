<?php
/**
 * Webhook público de verificación de empleo.
 *
 * Pensado para que el agente de IA de voz de GoHighLevel (Custom Action)
 * consulte en vivo si una persona es colaborador activo de la empresa,
 * para el caso típico de un banco llamando a verificar referencias laborales.
 *
 * Autenticación: header X-Api-Key (o ?api_key=), comparado contra
 * system_settings.employment_verification_api_key. Configurable 100% desde
 * settings.php -> pestaña "Verificación de Empleo".
 */

ini_set('display_errors', '0');
ini_set('html_errors', '0');

ob_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/rate_limiter.php';

$evJsonResponseSent = false;

function evSendJsonResponse(array $payload, int $statusCode = 200): void
{
    global $evJsonResponseSent;
    $evJsonResponseSent = true;

    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

register_shutdown_function(static function () use (&$evJsonResponseSent): void {
    if ($evJsonResponseSent) {
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

    error_log('Employment verification webhook shutdown error: ' . ($error['message'] ?? 'unknown'));

    if (ob_get_length() !== false && ob_get_length() > 0) {
        ob_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'Error interno al verificar el empleo.',
    ], JSON_UNESCAPED_UNICODE);
});

function evReadInput(): array
{
    $params = $_GET;

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $params = array_merge($params, $decoded);
        }
    } else {
        $params = array_merge($params, $_POST);
    }

    return $params;
}

function evNormalizeIdNumber(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function evClientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            return $ip;
        }
    }
    return '0.0.0.0';
}

// Máximo 20 consultas por minuto por IP: suficiente para uso legítimo de un
// agente de voz, bajo para frenar abuso/enumeración de cédulas.
enforceRateLimit(20, 60);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    evSendJsonResponse(['success' => true]);
}

$enabled = (bool) getSystemSetting($pdo, 'employment_verification_enabled', false);
if (!$enabled) {
    evSendJsonResponse([
        'success' => false,
        'message' => 'La verificación de empleo no está habilitada.',
    ], 503);
}

$configuredKey = (string) getSystemSetting($pdo, 'employment_verification_api_key', '');
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? ($_POST['api_key'] ?? ''));

if ($configuredKey === '' || !hash_equals($configuredKey, (string) $providedKey)) {
    evSendJsonResponse([
        'success' => false,
        'message' => 'API key inválida o ausente.',
    ], 401);
}

$input = evReadInput();

$idNumberRaw = trim((string) ($input['cedula'] ?? $input['id_number'] ?? $input['identification_number'] ?? $input['document_number'] ?? ''));
$fullNameRaw = trim((string) ($input['nombre'] ?? $input['full_name'] ?? $input['employee_name'] ?? ''));

if ($idNumberRaw === '' && $fullNameRaw === '') {
    evSendJsonResponse([
        'success' => false,
        'message' => 'Debes enviar "cedula" o "nombre" para poder verificar.',
    ], 400);
}

$employee = null;

try {
    if ($idNumberRaw !== '') {
        $idNumberNormalized = evNormalizeIdNumber($idNumberRaw);

        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, employee_code, position, hire_date,
                   termination_date, employment_status
            FROM employees
            WHERE REPLACE(REPLACE(REPLACE(id_card_number, '-', ''), ' ', ''), '.', '') = :id_a
               OR REPLACE(REPLACE(REPLACE(identification_number, '-', ''), ' ', ''), '.', '') = :id_b
            LIMIT 1
        ");
        $stmt->execute(['id_a' => $idNumberNormalized, 'id_b' => $idNumberNormalized]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif ($fullNameRaw !== '') {
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, employee_code, position, hire_date,
                   termination_date, employment_status
            FROM employees
            WHERE LOWER(CONCAT(first_name, ' ', last_name)) = LOWER(:full_name)
            LIMIT 2
        ");
        $stmt->execute(['full_name' => $fullNameRaw]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Nombre ambiguo o sin coincidencia exacta -> no se revela nada, se
        // le pide al que llama que confirme la cédula en su lugar.
        $employee = (count($matches) === 1) ? $matches[0] : null;
    }
} catch (PDOException $e) {
    error_log('Employment verification query error: ' . $e->getMessage());
    evSendJsonResponse([
        'success' => false,
        'message' => 'Error interno al consultar los registros.',
    ], 500);
}

$includePosition = (bool) getSystemSetting($pdo, 'employment_verification_include_position', true);

$found = $employee !== null;
$isActive = null;
$employeeName = null;
$position = null;
$hireDate = null;

if ($found) {
    $isActive = in_array(strtoupper((string) $employee['employment_status']), ['ACTIVE', 'TRIAL'], true);
    $employeeName = trim($employee['first_name'] . ' ' . $employee['last_name']);

    if ($includePosition) {
        $position = $employee['position'] ?: null;
        $hireDate = $employee['hire_date'] ?: null;
    }
}

if (!$found) {
    $message = 'No encontramos ningún colaborador con esos datos en nuestros registros.';
} elseif ($isActive) {
    $message = "Sí, {$employeeName} es colaborador activo de la empresa.";
    if ($position) {
        $message .= " Actualmente ocupa el cargo de {$position}.";
    }
    if ($hireDate) {
        $message .= ' Trabaja con nosotros desde ' . date('d/m/Y', strtotime($hireDate)) . '.';
    }
} else {
    $message = "No, {$employeeName} no es un colaborador activo de la empresa en este momento.";
}

try {
    $logStmt = $pdo->prepare("
        INSERT INTO employment_verification_log
            (queried_id_number, employee_id, found, is_active, caller_ip, source, created_at)
        VALUES (:queried_id_number, :employee_id, :found, :is_active, :caller_ip, 'ghl_voice_ai', NOW())
    ");
    $logStmt->execute([
        'queried_id_number' => $idNumberRaw !== '' ? $idNumberRaw : $fullNameRaw,
        'employee_id'       => $found ? $employee['id'] : null,
        'found'             => $found ? 1 : 0,
        'is_active'         => $found ? ($isActive ? 1 : 0) : null,
        'caller_ip'         => evClientIp(),
    ]);
} catch (PDOException $e) {
    error_log('Employment verification log insert error: ' . $e->getMessage());
}

$response = [
    'success'       => true,
    'found'         => $found,
    'active'        => $isActive,
    'employee_name' => $employeeName,
    'message'       => $message,
];

if ($includePosition) {
    $response['position'] = $position;
    $response['hire_date'] = $hireDate;
}

evSendJsonResponse($response);

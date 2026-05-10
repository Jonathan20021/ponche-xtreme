<?php
/**
 * Cliente HTTP para comunicarse con la app de Finanzas (Next.js).
 *
 * Configuración:
 *   - LOANS_API_BASE_URL  : URL base de la app de Finanzas (default: localhost:3000)
 *   - LOANS_API_KEY       : API key compartida (matches loan_settings.external_api_key)
 *
 * Estas constantes se pueden sobreescribir vía variables de entorno o
 * editar directamente abajo. Cualquier despliegue debe ajustarlas según
 * el dominio donde está corriendo la app de Finanzas.
 */

if (!defined('LOANS_API_BASE_URL')) {
    define('LOANS_API_BASE_URL', getenv('LOANS_API_BASE_URL') ?: 'http://localhost:3000');
}
if (!defined('LOANS_API_KEY')) {
    define('LOANS_API_KEY', getenv('LOANS_API_KEY') ?: 'f094f4dd3f0e7cfeca859e1edac7450c19c1eab6596b6180ff098d7fea0a62f8');
}

/**
 * Hace una llamada HTTP a la app de Finanzas.
 *
 * @param string      $method 'GET' | 'POST'
 * @param string      $path   ruta relativa, ej: '/api/loans/external-request'
 * @param array|null  $body   datos JSON (solo POST)
 * @param array       $query  query string params (solo GET)
 * @return array{ok:bool, status:int, data:array|null, error:string|null}
 */
function loansApiCall(string $method, string $path, ?array $body = null, array $query = []): array {
    $url = rtrim(LOANS_API_BASE_URL, '/') . $path;
    if (!empty($query)) {
        $url .= (strpos($path, '?') === false ? '?' : '&') . http_build_query($query);
    }

    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-API-Key: ' . LOANS_API_KEY,
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false, // desactivar para localhost sin TLS
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? new stdClass()));
    }

    $rawResponse = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false) {
        return [
            'ok' => false,
            'status' => 0,
            'data' => null,
            'error' => 'No se pudo conectar con la app de Finanzas: ' . $curlError,
        ];
    }

    $data = json_decode($rawResponse, true);
    $ok = $status >= 200 && $status < 300;

    return [
        'ok' => $ok,
        'status' => $status,
        'data' => $data,
        'error' => $ok ? null : ($data['error'] ?? "HTTP $status"),
    ];
}

/**
 * Obtiene los tipos de préstamo disponibles para empleados.
 */
function getLoanTypesFromFinance(): array {
    $r = loansApiCall('GET', '/api/loans/external-types');
    if ($r['ok'] && isset($r['data']['loan_types'])) {
        return $r['data']['loan_types'];
    }
    return [];
}

/**
 * Crea una solicitud de préstamo en la app de Finanzas.
 */
function createLoanRequestInFinance(array $payload): array {
    return loansApiCall('POST', '/api/loans/external-request', $payload);
}

/**
 * Lista los préstamos de un empleado por su employee_external_id.
 */
function getEmployeeLoansFromFinance(int $employeeId): array {
    $r = loansApiCall('GET', '/api/loans/external-request', null, [
        'employee_external_id' => $employeeId,
    ]);
    if ($r['ok'] && isset($r['data']['loans'])) {
        return $r['data']['loans'];
    }
    return [];
}

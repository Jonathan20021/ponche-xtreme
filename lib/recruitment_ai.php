<?php
/**
 * Recruitment AI helpers (Claude / Anthropic Messages API).
 *
 * All prompts, models, and toggles are read from system_settings so they remain
 * configurable from settings.php (per project policy).
 *
 * Public functions:
 *   - getRecruitmentAIConfig(PDO): array
 *   - parseCVWithAI(PDO, string $cv_path): array
 *   - screenCandidateWithAI(PDO, array $application, array $jobPosting, ?array $extracted = null): array
 *   - generateJobDescriptionWithAI(PDO, string $title, string $department = '', string $notes = ''): array
 *   - processApplicationAI(PDO, int $application_id): array (full pipeline: parse + screen + persist)
 */

require_once __DIR__ . '/claude_api_client.php';

if (!function_exists('getRecruitmentAIConfig')) {
    function getRecruitmentAIConfig(PDO $pdo): array
    {
        $defaults = [
            'recruitment_ai_enabled'              => '1',
            'recruitment_ai_model'                => '',
            'recruitment_ai_min_score_shortlist'  => '75',
            'recruitment_ai_extract_prompt'       => '',
            'recruitment_ai_screen_prompt'        => '',
            'recruitment_ai_jobdesc_prompt'       => '',
        ];
        $keys = array_keys($defaults);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        $out = $defaults;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['setting_key']] = (string) $row['setting_value'];
        }
        if (trim($out['recruitment_ai_model']) === '') {
            $out['recruitment_ai_model'] = resolveAnthropicDefaultModel($pdo);
        }
        return $out;
    }
}

if (!function_exists('extractJSONFromAIResponse')) {
    function extractJSONFromAIResponse(string $raw): ?array
    {
        $raw = trim($raw);
        // Strip markdown fences
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/```\s*$/', '', $raw);
        $raw = trim($raw);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Try to find first { ... last } substring
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($raw, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }
}

if (!function_exists('parseCVWithAI')) {
    /**
     * Send a CV (PDF preferred) to Claude and return structured candidate data.
     * Falls back to plain text extraction for non-PDF if PDF processing is unavailable.
     *
     * @return array{success: bool, data: ?array, error: ?string, model: ?string}
     */
    function parseCVWithAI(PDO $pdo, string $cv_relative_path): array
    {
        $cfg = getRecruitmentAIConfig($pdo);
        $apiKey = resolveAnthropicApiKey($pdo);
        if ($apiKey === '') {
            return ['success' => false, 'data' => null, 'error' => 'Anthropic API key no configurada.', 'model' => null];
        }
        if (empty($cfg['recruitment_ai_enabled']) || $cfg['recruitment_ai_enabled'] === '0') {
            return ['success' => false, 'data' => null, 'error' => 'Recruitment AI deshabilitado en settings.', 'model' => null];
        }
        $absPath = realpath(__DIR__ . '/../' . ltrim($cv_relative_path, '/\\'));
        if (!$absPath || !is_file($absPath) || !is_readable($absPath)) {
            return ['success' => false, 'data' => null, 'error' => 'Archivo CV no encontrado: ' . $cv_relative_path, 'model' => null];
        }

        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        $model = $cfg['recruitment_ai_model'];
        $systemPrompt = trim($cfg['recruitment_ai_extract_prompt']);

        $schemaHint = "\n\nDevuelve un JSON exactamente con esta estructura:\n"
            . "{\n"
            . "  \"full_name\": \"string\",\n"
            . "  \"email\": \"string|null\",\n"
            . "  \"phone\": \"string|null\",\n"
            . "  \"address\": \"string|null\",\n"
            . "  \"date_of_birth\": \"YYYY-MM-DD|null\",\n"
            . "  \"summary\": \"string (resumen profesional 1-2 lineas)\",\n"
            . "  \"years_of_experience\": \"number|null\",\n"
            . "  \"current_position\": \"string|null\",\n"
            . "  \"current_company\": \"string|null\",\n"
            . "  \"education\": [{\"degree\":\"string\",\"institution\":\"string\",\"year\":\"string\"}],\n"
            . "  \"experience\": [{\"company\":\"string\",\"role\":\"string\",\"period\":\"string\",\"description\":\"string\"}],\n"
            . "  \"skills\": [\"string\"],\n"
            . "  \"languages\": [{\"language\":\"string\",\"level\":\"string\"}],\n"
            . "  \"certifications\": [\"string\"],\n"
            . "  \"linkedin_url\": \"string|null\"\n"
            . "}";

        $userText = "Analiza el siguiente CV y devuelve la informacion estructurada." . $schemaHint;

        // Build content blocks
        $content = [];

        if ($ext === 'pdf') {
            // Use Anthropic native PDF support
            $base64 = base64_encode(file_get_contents($absPath));
            $content[] = [
                'type'   => 'document',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => 'application/pdf',
                    'data'       => $base64,
                ],
            ];
            $content[] = ['type' => 'text', 'text' => $userText];
        } else {
            // Best-effort text extraction (DOC/DOCX/TXT)
            $extracted = extractTextFromDocument($absPath, $ext);
            if ($extracted === '') {
                return ['success' => false, 'data' => null, 'error' => 'No se pudo leer texto del CV (' . $ext . '). Sube un PDF para mejor extraccion.', 'model' => $model];
            }
            $content[] = ['type' => 'text', 'text' => $userText . "\n\nCV:\n\n" . substr($extracted, 0, 16000)];
        }

        $payload = [
            'model'       => $model,
            'max_tokens'  => 2048,
            'temperature' => 0.1,
            'system'      => $systemPrompt,
            'messages'    => [
                ['role' => 'user', 'content' => $content],
            ],
        ];

        $apiResp = anthropicRawCall($apiKey, $payload, 90);

        if (!$apiResp['success']) {
            return ['success' => false, 'data' => null, 'error' => $apiResp['error'], 'model' => $model];
        }

        $text = '';
        foreach (($apiResp['raw']['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        $parsed = extractJSONFromAIResponse($text);
        if (!$parsed) {
            return ['success' => false, 'data' => null, 'error' => 'No se pudo parsear JSON del CV. Respuesta: ' . substr($text, 0, 200), 'model' => $model];
        }

        return ['success' => true, 'data' => $parsed, 'error' => null, 'model' => $model];
    }
}

if (!function_exists('extractTextFromDocument')) {
    function extractTextFromDocument(string $absPath, string $ext): string
    {
        if ($ext === 'txt') {
            return (string) file_get_contents($absPath);
        }
        if ($ext === 'docx') {
            // DOCX is a ZIP with word/document.xml
            if (!class_exists('ZipArchive')) {
                return '';
            }
            $zip = new ZipArchive();
            if ($zip->open($absPath) !== true) {
                return '';
            }
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml === false) {
                return '';
            }
            $clean = preg_replace('/<w:p[^>]*>/', "\n", $xml);
            $clean = strip_tags($clean);
            return html_entity_decode(preg_replace('/[ \t]+/', ' ', $clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($ext === 'doc') {
            // Legacy .doc not parseable without libraries — return empty so caller errors out cleanly.
            return '';
        }
        return '';
    }
}

if (!function_exists('anthropicRawCall')) {
    /**
     * Lower-level Anthropic Messages call that supports rich content blocks
     * (the wrapper in claude_api_client.php only supports plain user_prompt).
     */
    function anthropicRawCall(string $apiKey, array $payload, int $timeout = 60): array
    {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['success' => false, 'raw' => null, 'error' => 'cURL: ' . $err, 'http_code' => $code];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'raw' => null, 'error' => 'Invalid JSON. HTTP ' . $code . ' - ' . substr($body, 0, 200), 'http_code' => $code];
        }
        if ($code < 200 || $code >= 300) {
            $msg = $decoded['error']['message'] ?? ('HTTP ' . $code);
            return ['success' => false, 'raw' => $decoded, 'error' => 'Anthropic API: ' . $msg, 'http_code' => $code];
        }
        return ['success' => true, 'raw' => $decoded, 'error' => null, 'http_code' => $code];
    }
}

if (!function_exists('screenCandidateWithAI')) {
    /**
     * Score a candidate against a job posting.
     * @return array{success: bool, score: ?int, summary: ?string, strengths: ?array, concerns: ?array, recommendation: ?string, error: ?string, model: ?string}
     */
    function screenCandidateWithAI(PDO $pdo, array $application, array $jobPosting, ?array $extracted = null): array
    {
        $cfg = getRecruitmentAIConfig($pdo);
        $apiKey = resolveAnthropicApiKey($pdo);
        if ($apiKey === '') {
            return ['success' => false, 'score' => null, 'summary' => null, 'strengths' => null, 'concerns' => null, 'recommendation' => null, 'error' => 'Anthropic API key no configurada.', 'model' => null];
        }

        $systemPrompt = trim($cfg['recruitment_ai_screen_prompt']);
        $model = $cfg['recruitment_ai_model'];

        $candidateBlock = [
            'name'                 => trim(($application['first_name'] ?? '') . ' ' . ($application['last_name'] ?? '')),
            'email'                => $application['email'] ?? '',
            'phone'                => $application['phone'] ?? '',
            'expected_salary'      => $application['expected_salary'] ?? '',
            'years_of_experience'  => $application['years_of_experience'] ?? '',
            'current_position'     => $application['current_position'] ?? '',
            'current_company'      => $application['current_company'] ?? '',
            'education_level'      => $application['education_level'] ?? '',
            'cover_letter_excerpt' => substr((string) ($application['cover_letter'] ?? ''), 0, 500),
            'extracted_from_cv'    => $extracted ?: null,
        ];

        $jobBlock = [
            'title'            => $jobPosting['title']            ?? '',
            'department'       => $jobPosting['department']       ?? '',
            'location'         => $jobPosting['location']         ?? '',
            'employment_type'  => $jobPosting['employment_type']  ?? '',
            'salary_range'     => $jobPosting['salary_range']     ?? '',
            'description'      => $jobPosting['description']      ?? '',
            'requirements'     => $jobPosting['requirements']     ?? '',
            'responsibilities' => $jobPosting['responsibilities'] ?? '',
        ];

        $userPrompt = "Vacante:\n```json\n" . json_encode($jobBlock, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n```\n\nCandidato:\n```json\n" . json_encode($candidateBlock, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n```\n\nResponde SOLO con JSON: {\"score\": int 0-100, \"summary\": \"string\", \"strengths\": [string,...], \"concerns\": [string,...], \"recommendation\": \"shortlist|review|reject\"}";

        $payload = [
            'model'       => $model,
            'max_tokens'  => 1024,
            'temperature' => 0.2,
            'system'      => $systemPrompt,
            'messages'    => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => $userPrompt]]],
            ],
        ];

        $apiResp = anthropicRawCall($apiKey, $payload, 60);
        if (!$apiResp['success']) {
            return ['success' => false, 'score' => null, 'summary' => null, 'strengths' => null, 'concerns' => null, 'recommendation' => null, 'error' => $apiResp['error'], 'model' => $model];
        }

        $text = '';
        foreach (($apiResp['raw']['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        $parsed = extractJSONFromAIResponse($text);
        if (!$parsed) {
            return ['success' => false, 'score' => null, 'summary' => null, 'strengths' => null, 'concerns' => null, 'recommendation' => null, 'error' => 'No se pudo parsear JSON del screening.', 'model' => $model];
        }

        $score = isset($parsed['score']) ? (int) $parsed['score'] : null;
        if ($score !== null) {
            $score = max(0, min(100, $score));
        }

        return [
            'success'        => true,
            'score'          => $score,
            'summary'        => isset($parsed['summary']) ? (string) $parsed['summary'] : null,
            'strengths'      => isset($parsed['strengths']) && is_array($parsed['strengths']) ? array_values($parsed['strengths']) : [],
            'concerns'       => isset($parsed['concerns']) && is_array($parsed['concerns']) ? array_values($parsed['concerns']) : [],
            'recommendation' => isset($parsed['recommendation']) ? strtolower((string) $parsed['recommendation']) : null,
            'error'          => null,
            'model'          => $model,
        ];
    }
}

if (!function_exists('generateJobDescriptionWithAI')) {
    /**
     * Generate description / responsibilities / requirements for a job posting.
     * @return array{success: bool, description: ?string, responsibilities: ?string, requirements: ?string, error: ?string, model: ?string}
     */
    function generateJobDescriptionWithAI(PDO $pdo, string $title, string $department = '', string $notes = ''): array
    {
        $cfg = getRecruitmentAIConfig($pdo);
        $apiKey = resolveAnthropicApiKey($pdo);
        if ($apiKey === '') {
            return ['success' => false, 'description' => null, 'responsibilities' => null, 'requirements' => null, 'error' => 'Anthropic API key no configurada.', 'model' => null];
        }
        $title = trim($title);
        if ($title === '') {
            return ['success' => false, 'description' => null, 'responsibilities' => null, 'requirements' => null, 'error' => 'Falta el titulo del puesto.', 'model' => null];
        }

        $systemPrompt = trim($cfg['recruitment_ai_jobdesc_prompt']);
        $model = $cfg['recruitment_ai_model'];

        $userPrompt = "Genera la informacion del puesto:\n"
            . "- Titulo: {$title}\n"
            . "- Departamento: " . ($department !== '' ? $department : 'No especificado') . "\n"
            . "- Notas adicionales: " . ($notes !== '' ? $notes : 'Ninguna') . "\n\n"
            . "Responde SOLO con JSON: {\"description\": \"string\", \"responsibilities\": \"string (lista con vinetas)\", \"requirements\": \"string (lista con vinetas)\"}. "
            . "Las listas deben usar el caracter '-' al inicio de cada linea.";

        $payload = [
            'model'       => $model,
            'max_tokens'  => 1500,
            'temperature' => 0.5,
            'system'      => $systemPrompt,
            'messages'    => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => $userPrompt]]],
            ],
        ];

        $apiResp = anthropicRawCall($apiKey, $payload, 60);
        if (!$apiResp['success']) {
            return ['success' => false, 'description' => null, 'responsibilities' => null, 'requirements' => null, 'error' => $apiResp['error'], 'model' => $model];
        }
        $text = '';
        foreach (($apiResp['raw']['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        $parsed = extractJSONFromAIResponse($text);
        if (!$parsed) {
            return ['success' => false, 'description' => null, 'responsibilities' => null, 'requirements' => null, 'error' => 'No se pudo parsear JSON. Respuesta cruda: ' . substr($text, 0, 300), 'model' => $model];
        }

        return [
            'success'          => true,
            'description'      => isset($parsed['description']) ? (string) $parsed['description'] : null,
            'responsibilities' => isset($parsed['responsibilities']) ? (string) $parsed['responsibilities'] : null,
            'requirements'     => isset($parsed['requirements']) ? (string) $parsed['requirements'] : null,
            'error'            => null,
            'model'            => $model,
        ];
    }
}

if (!function_exists('processApplicationAI')) {
    /**
     * Full pipeline: parse CV (if present) + screen against job + persist results.
     * @return array{success: bool, score: ?int, summary: ?string, recommendation: ?string, error: ?string}
     */
    function processApplicationAI(PDO $pdo, int $application_id): array
    {
        $stmt = $pdo->prepare("SELECT * FROM job_applications WHERE id = ?");
        $stmt->execute([$application_id]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$app) {
            return ['success' => false, 'score' => null, 'summary' => null, 'recommendation' => null, 'error' => 'Aplicacion no encontrada.'];
        }

        $job = null;
        if (!empty($app['job_posting_id'])) {
            $jstmt = $pdo->prepare("SELECT * FROM job_postings WHERE id = ?");
            $jstmt->execute([$app['job_posting_id']]);
            $job = $jstmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $extracted = null;
        if (!empty($app['cv_path'])) {
            $cvResult = parseCVWithAI($pdo, $app['cv_path']);
            if ($cvResult['success']) {
                $extracted = $cvResult['data'];
            } else {
                error_log('Recruitment AI CV parse failed for app ' . $application_id . ': ' . $cvResult['error']);
            }
        }

        if (!$job) {
            // No job to screen against — just persist extracted data and return
            if ($extracted) {
                $u = $pdo->prepare("UPDATE job_applications SET ai_extracted_data = ?, ai_processed_at = NOW(), ai_model_used = ? WHERE id = ?");
                $u->execute([json_encode($extracted, JSON_UNESCAPED_UNICODE), $cvResult['model'] ?? null, $application_id]);
                return ['success' => true, 'score' => null, 'summary' => $extracted['summary'] ?? null, 'recommendation' => null, 'error' => null];
            }
            return ['success' => false, 'score' => null, 'summary' => null, 'recommendation' => null, 'error' => 'No hay vacante asociada ni CV procesable.'];
        }

        $screen = screenCandidateWithAI($pdo, $app, $job, $extracted);
        if (!$screen['success']) {
            // Persist whatever we have
            if ($extracted) {
                $u = $pdo->prepare("UPDATE job_applications SET ai_extracted_data = ?, ai_processed_at = NOW(), ai_model_used = ? WHERE id = ?");
                $u->execute([json_encode($extracted, JSON_UNESCAPED_UNICODE), $screen['model'] ?? null, $application_id]);
            }
            return ['success' => false, 'score' => null, 'summary' => null, 'recommendation' => null, 'error' => $screen['error']];
        }

        $u = $pdo->prepare("UPDATE job_applications
            SET ai_summary = ?, ai_score = ?, ai_strengths = ?, ai_concerns = ?, ai_recommendation = ?, ai_extracted_data = ?, ai_processed_at = NOW(), ai_model_used = ?
            WHERE id = ?");
        $u->execute([
            $screen['summary'],
            $screen['score'],
            json_encode($screen['strengths'] ?: [], JSON_UNESCAPED_UNICODE),
            json_encode($screen['concerns']  ?: [], JSON_UNESCAPED_UNICODE),
            $screen['recommendation'],
            $extracted ? json_encode($extracted, JSON_UNESCAPED_UNICODE) : null,
            $screen['model'],
            $application_id,
        ]);

        return [
            'success'        => true,
            'score'          => $screen['score'],
            'summary'        => $screen['summary'],
            'recommendation' => $screen['recommendation'],
            'error'          => null,
        ];
    }
}

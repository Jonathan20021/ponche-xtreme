<?php
/**
 * Anthropic Claude API Client
 * Minimal cURL wrapper around the Messages API.
 *
 * Docs: https://docs.anthropic.com/en/api/messages
 *
 * Usage:
 *   require_once __DIR__ . '/lib/claude_api_client.php';
 *   $result = callClaudeAPI([
 *       'api_key'       => getenv('ANTHROPIC_API_KEY'),
 *       'model'         => 'claude-sonnet-4-6',
 *       'system_prompt' => 'You are a helpful assistant.',
 *       'user_prompt'   => 'Say hi in one line.',
 *       'max_tokens'    => 256,
 *   ]);
 *   if ($result['success']) echo $result['content'];
 */

if (!function_exists('resolveAnthropicApiKey')) {
    /**
     * Resolve the Anthropic API key using this priority:
     *   1. Explicit `$explicit` argument (per-call override).
     *   2. Global `anthropic_api_key` setting in system_settings.
     *   3. ANTHROPIC_API_KEY environment variable.
     *
     * @param PDO|null $pdo Optional PDO connection to look up the global setting.
     * @param string   $explicit Optional explicit key provided by the caller.
     * @return string The resolved key, or '' if none is available.
     */
    function resolveAnthropicApiKey(?PDO $pdo = null, string $explicit = ''): string
    {
        $explicit = trim($explicit);
        if ($explicit !== '') {
            return $explicit;
        }

        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'anthropic_api_key'");
                $stmt->execute();
                $global = (string) ($stmt->fetchColumn() ?: '');
                if (trim($global) !== '') {
                    return trim($global);
                }
            } catch (PDOException $e) {
                error_log('resolveAnthropicApiKey: ' . $e->getMessage());
            }
        } else {
            // Try global $pdo if present
            global $pdo;
            if ($pdo instanceof PDO) {
                try {
                    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'anthropic_api_key'");
                    $stmt->execute();
                    $global = (string) ($stmt->fetchColumn() ?: '');
                    if (trim($global) !== '') {
                        return trim($global);
                    }
                } catch (PDOException $e) {
                    error_log('resolveAnthropicApiKey: ' . $e->getMessage());
                }
            }
        }

        $env = (string) (getenv('ANTHROPIC_API_KEY') ?: '');
        return trim($env);
    }
}

if (!function_exists('resolveAnthropicDefaultModel')) {
    /**
     * Resolve the default Claude model (global setting, with fallback).
     */
    function resolveAnthropicDefaultModel(?PDO $pdo = null, string $fallback = 'claude-sonnet-4-6'): string
    {
        if (!$pdo instanceof PDO) {
            global $pdo;
        }
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'anthropic_default_model'");
                $stmt->execute();
                $val = trim((string) ($stmt->fetchColumn() ?: ''));
                if ($val !== '') {
                    return $val;
                }
            } catch (PDOException $e) {
                error_log('resolveAnthropicDefaultModel: ' . $e->getMessage());
            }
        }
        return $fallback;
    }
}

if (!function_exists('callClaudeAPI')) {
    /**
     * Call the Anthropic Messages API with a single user turn.
     *
     * @param array $opts {
     *     @var string   $api_key        Anthropic API key. Optional — if empty, resolveAnthropicApiKey() is used.
     *     @var string   $model          Model id (default: claude-sonnet-4-6).
     *     @var string   $system_prompt  System prompt (optional).
     *     @var string   $user_prompt    User message (required).
     *     @var int      $max_tokens     Max output tokens (default: 1024).
     *     @var float    $temperature    Sampling temperature (default: 0.5).
     *     @var int      $timeout        cURL timeout in seconds (default: 60).
     *     @var string   $api_version    Anthropic API version header (default: 2023-06-01).
     *     @var PDO|null $pdo            Optional PDO for global-setting resolution when api_key is empty.
     * }
     * @return array{success: bool, content: string, error: ?string, usage: ?array, raw: ?array, http_code: int}
     */
    function callClaudeAPI(array $opts): array
    {
        $explicitKey = trim((string) ($opts['api_key'] ?? ''));
        $pdoArg = $opts['pdo'] ?? null;
        if (!($pdoArg instanceof PDO)) {
            $pdoArg = null;
        }
        $apiKey = resolveAnthropicApiKey($pdoArg, $explicitKey);
        $model       = trim((string) ($opts['model']        ?? 'claude-sonnet-4-6'));
        $systemPrompt = (string) ($opts['system_prompt']    ?? '');
        $userPrompt  = (string) ($opts['user_prompt']       ?? '');
        $maxTokens   = (int)    ($opts['max_tokens']        ?? 1024);
        $temperature = (float)  ($opts['temperature']       ?? 0.5);
        $timeout     = (int)    ($opts['timeout']           ?? 60);
        $apiVersion  = (string) ($opts['api_version']       ?? '2023-06-01');

        if ($apiKey === '') {
            return [
                'success' => false,
                'content' => '',
                'error'   => 'Anthropic API key is not configured. Set it in system_settings or ANTHROPIC_API_KEY env var.',
                'usage'   => null,
                'raw'     => null,
                'http_code' => 0,
            ];
        }

        if ($userPrompt === '') {
            return [
                'success' => false,
                'content' => '',
                'error'   => 'user_prompt is required.',
                'usage'   => null,
                'raw'     => null,
                'http_code' => 0,
            ];
        }

        $payload = [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'messages'    => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . $apiVersion,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            return [
                'success' => false,
                'content' => '',
                'error'   => 'cURL error: ' . $curlError,
                'usage'   => null,
                'raw'     => null,
                'http_code' => $httpCode,
            ];
        }

        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded)) {
            return [
                'success' => false,
                'content' => '',
                'error'   => 'Invalid JSON response from Anthropic API. HTTP ' . $httpCode,
                'usage'   => null,
                'raw'     => null,
                'http_code' => $httpCode,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $apiErr = $decoded['error']['message'] ?? ('HTTP ' . $httpCode);
            return [
                'success' => false,
                'content' => '',
                'error'   => 'Anthropic API error: ' . $apiErr,
                'usage'   => $decoded['usage'] ?? null,
                'raw'     => $decoded,
                'http_code' => $httpCode,
            ];
        }

        // Extract text from content blocks
        $text = '';
        foreach (($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        return [
            'success' => true,
            'content' => trim($text),
            'error'   => null,
            'usage'   => $decoded['usage'] ?? null,
            'raw'     => $decoded,
            'http_code' => $httpCode,
        ];
    }
}

if (!function_exists('testClaudeAPIConnection')) {
    /**
     * Quick connectivity / auth test. Sends a trivial prompt and returns
     * whether the credentials and network path are working.
     *
     * If $apiKey is empty, the global setting or ANTHROPIC_API_KEY env var is used.
     */
    function testClaudeAPIConnection(string $apiKey = '', string $model = '', ?PDO $pdo = null): array
    {
        if ($model === '') {
            $model = resolveAnthropicDefaultModel($pdo);
        }
        return callClaudeAPI([
            'api_key'     => $apiKey,
            'model'       => $model,
            'user_prompt' => 'Respond with the single word OK.',
            'max_tokens'  => 10,
            'temperature' => 0.0,
            'timeout'     => 20,
            'pdo'         => $pdo,
        ]);
    }
}

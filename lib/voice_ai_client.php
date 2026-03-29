<?php

if (!function_exists('voiceAiMaskToken')) {
    function voiceAiMaskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        $length = strlen($token);
        if ($length <= 10) {
            return str_repeat('*', $length);
        }

        return substr($token, 0, 4) . str_repeat('*', max(4, $length - 8)) . substr($token, -4);
    }
}

if (!function_exists('voiceAiJsonFlags')) {
    function voiceAiJsonFlags(): int
    {
        return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    }
}

if (!function_exists('voiceAiJsonEncode')) {
    function voiceAiJsonEncode($value): string
    {
        $encoded = json_encode($value, voiceAiJsonFlags());
        return is_string($encoded) ? $encoded : 'null';
    }
}

if (!function_exists('voiceAiUpsertSetting')) {
    function voiceAiUpsertSetting(PDO $pdo, string $key, string $value, string $type = 'string', ?string $description = null, ?int $userId = null): bool
    {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_type, description, updated_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                setting_type = VALUES(setting_type),
                description = COALESCE(VALUES(description), description),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");

        return $stmt->execute([$key, $value, $type, $description, $userId]);
    }
}

if (!function_exists('voiceAiNormalizeBoundedInt')) {
    function voiceAiNormalizeBoundedInt($value, int $default, int $min, int $max): int
    {
        $normalized = (int) $value;
        if ($normalized < $min || $normalized > $max) {
            return $default;
        }

        return $normalized;
    }
}

if (!function_exists('voiceAiGetLegacyConfig')) {
    function voiceAiGetLegacyConfig(PDO $pdo): array
    {
        $timezone = trim((string) getSystemSetting($pdo, 'voice_ai_timezone', date_default_timezone_get() ?: 'UTC'));
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        return [
            'integration_id' => null,
            'integration_name' => 'Cuenta principal legacy',
            'api_key' => trim((string) getSystemSetting($pdo, 'voice_ai_api_key', '')),
            'location_id' => trim((string) getSystemSetting($pdo, 'voice_ai_location_id', '')),
            'timezone' => $timezone,
            'page_size' => voiceAiNormalizeBoundedInt(getSystemSetting($pdo, 'voice_ai_page_size', 50), 50, 10, 50),
            'max_pages' => voiceAiNormalizeBoundedInt(getSystemSetting($pdo, 'voice_ai_max_pages', 10), 10, 1, 50),
            'interaction_page_size' => voiceAiNormalizeBoundedInt(getSystemSetting($pdo, 'voice_ai_interaction_page_size', 100), 100, 10, 100),
            'interaction_max_pages' => voiceAiNormalizeBoundedInt(getSystemSetting($pdo, 'voice_ai_interaction_max_pages', 200), 200, 1, 250),
            'base_url' => 'https://services.leadconnectorhq.com',
            'version' => '2021-07-28',
            'is_enabled' => true,
        ];
    }
}

if (!function_exists('voiceAiSetContextIntegrationId')) {
    function voiceAiSetContextIntegrationId($integrationId): void
    {
        if ($integrationId === null || $integrationId === '') {
            unset($GLOBALS['voice_ai_context_integration_id']);
            return;
        }

        $GLOBALS['voice_ai_context_integration_id'] = (int) $integrationId;
    }
}

if (!function_exists('voiceAiGetContextIntegrationId')) {
    function voiceAiGetContextIntegrationId(): ?int
    {
        if (!isset($GLOBALS['voice_ai_context_integration_id'])) {
            return null;
        }

        return (int) $GLOBALS['voice_ai_context_integration_id'];
    }
}

if (!function_exists('voiceAiResolveIntegrationId')) {
    function voiceAiResolveIntegrationId($integrationId = null): ?int
    {
        if ($integrationId === null || $integrationId === '') {
            $integrationId = voiceAiGetContextIntegrationId();
        }

        if ($integrationId === null || $integrationId === '') {
            return null;
        }

        if (!is_numeric($integrationId)) {
            return null;
        }

        $resolved = (int) $integrationId;
        return $resolved > 0 ? $resolved : null;
    }
}

if (!function_exists('voiceAiEnsureIntegrationSchema')) {
    function voiceAiEnsureIntegrationSchema(PDO $pdo): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS voice_ai_integrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                integration_name VARCHAR(191) NOT NULL,
                api_key VARCHAR(255) NOT NULL DEFAULT '',
                location_id VARCHAR(100) NOT NULL DEFAULT '',
                timezone VARCHAR(64) NOT NULL DEFAULT 'America/La_Paz',
                page_size INT NOT NULL DEFAULT 50,
                max_pages INT NOT NULL DEFAULT 10,
                interaction_page_size INT NOT NULL DEFAULT 100,
                interaction_max_pages INT NOT NULL DEFAULT 200,
                display_order INT NOT NULL DEFAULT 0,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL DEFAULT NULL,
                updated_by INT NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_voice_ai_integrations_enabled (is_enabled, display_order, integration_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $count = (int) $pdo->query("SELECT COUNT(*) FROM voice_ai_integrations")->fetchColumn();
        if ($count === 0) {
            $legacy = voiceAiGetLegacyConfig($pdo);
            if ($legacy['api_key'] !== '' || $legacy['location_id'] !== '') {
                $stmt = $pdo->prepare("
                    INSERT INTO voice_ai_integrations (
                        integration_name, api_key, location_id, timezone, page_size, max_pages,
                        interaction_page_size, interaction_max_pages, display_order, is_enabled
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $legacy['integration_name'],
                    $legacy['api_key'],
                    $legacy['location_id'],
                    $legacy['timezone'],
                    $legacy['page_size'],
                    $legacy['max_pages'],
                    $legacy['interaction_page_size'],
                    $legacy['interaction_max_pages'],
                    1,
                ]);

                $legacyId = (int) $pdo->lastInsertId();
                if ($legacyId > 0) {
                    voiceAiUpsertSetting($pdo, 'voice_ai_default_integration_id', (string) $legacyId, 'number', 'Integracion GHL seleccionada por defecto para reporteria multicuenta');
                }
            }
        }

        $initialized = true;
    }
}

if (!function_exists('voiceAiNormalizeIntegrationRecord')) {
    function voiceAiNormalizeIntegrationRecord(array $row): array
    {
        $timezone = trim((string) ($row['timezone'] ?? 'UTC'));
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        $apiKey = trim((string) ($row['api_key'] ?? ''));
        $locationId = trim((string) ($row['location_id'] ?? ''));

        return [
            'integration_id' => (int) ($row['id'] ?? 0),
            'integration_name' => trim((string) ($row['integration_name'] ?? 'Sin nombre')),
            'api_key' => $apiKey,
            'location_id' => $locationId,
            'timezone' => $timezone,
            'page_size' => voiceAiNormalizeBoundedInt($row['page_size'] ?? 50, 50, 10, 50),
            'max_pages' => voiceAiNormalizeBoundedInt($row['max_pages'] ?? 10, 10, 1, 50),
            'interaction_page_size' => voiceAiNormalizeBoundedInt($row['interaction_page_size'] ?? 100, 100, 10, 100),
            'interaction_max_pages' => voiceAiNormalizeBoundedInt($row['interaction_max_pages'] ?? 200, 200, 1, 250),
            'display_order' => (int) ($row['display_order'] ?? 0),
            'is_enabled' => !empty($row['is_enabled']),
            'token_masked' => voiceAiMaskToken($apiKey),
            'has_api_key' => $apiKey !== '',
            'has_location_id' => $locationId !== '',
            'is_ready' => $apiKey !== '' && $locationId !== '',
            'base_url' => 'https://services.leadconnectorhq.com',
            'version' => '2021-07-28',
        ];
    }
}

if (!function_exists('voiceAiGetIntegrations')) {
    function voiceAiGetIntegrations(PDO $pdo): array
    {
        voiceAiEnsureIntegrationSchema($pdo);

        $stmt = $pdo->query("
            SELECT id, integration_name, api_key, location_id, timezone, page_size, max_pages,
                   interaction_page_size, interaction_max_pages, display_order, is_enabled
            FROM voice_ai_integrations
            WHERE is_enabled = 1
            ORDER BY display_order ASC, integration_name ASC, id ASC
        ");

        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return array_map('voiceAiNormalizeIntegrationRecord', array_values(array_filter($rows, 'is_array')));
    }
}

if (!function_exists('voiceAiGetDefaultIntegrationId')) {
    function voiceAiGetDefaultIntegrationId(PDO $pdo, array $integrations = []): ?int
    {
        $defaultId = voiceAiResolveIntegrationId(getSystemSetting($pdo, 'voice_ai_default_integration_id', ''));
        if ($defaultId === null) {
            return !empty($integrations[0]['integration_id']) ? (int) $integrations[0]['integration_id'] : null;
        }

        foreach ($integrations as $integration) {
            if ((int) ($integration['integration_id'] ?? 0) === $defaultId) {
                return $defaultId;
            }
        }

        return !empty($integrations[0]['integration_id']) ? (int) $integrations[0]['integration_id'] : null;
    }
}

if (!function_exists('voiceAiSetDefaultIntegrationId')) {
    function voiceAiSetDefaultIntegrationId(PDO $pdo, ?int $integrationId, ?int $userId = null): void
    {
        voiceAiUpsertSetting(
            $pdo,
            'voice_ai_default_integration_id',
            $integrationId !== null ? (string) $integrationId : '',
            'number',
            'Integracion GHL seleccionada por defecto para reporteria multicuenta',
            $userId
        );
    }
}

if (!function_exists('voiceAiSyncLegacySettingsFromConfig')) {
    function voiceAiSyncLegacySettingsFromConfig(PDO $pdo, array $config, ?int $userId = null): void
    {
        if (($config['api_key'] ?? '') !== '') {
            voiceAiUpsertSetting($pdo, 'voice_ai_api_key', (string) $config['api_key'], 'string', 'Private Integration Token para compatibilidad legacy', $userId);
        }

        voiceAiUpsertSetting($pdo, 'voice_ai_location_id', (string) ($config['location_id'] ?? ''), 'string', 'Location ID legacy sincronizado desde la integracion por defecto', $userId);
        voiceAiUpsertSetting($pdo, 'voice_ai_timezone', (string) ($config['timezone'] ?? 'UTC'), 'string', 'Zona horaria legacy sincronizada desde la integracion por defecto', $userId);
        voiceAiUpsertSetting($pdo, 'voice_ai_page_size', (string) ($config['page_size'] ?? 50), 'number', 'Tamano de pagina legacy sincronizado desde la integracion por defecto', $userId);
        voiceAiUpsertSetting($pdo, 'voice_ai_max_pages', (string) ($config['max_pages'] ?? 10), 'number', 'Maximo de paginas legacy sincronizado desde la integracion por defecto', $userId);
        voiceAiUpsertSetting($pdo, 'voice_ai_interaction_page_size', (string) ($config['interaction_page_size'] ?? 100), 'number', 'Tamano de pagina legacy de interacciones sincronizado desde la integracion por defecto', $userId);
        voiceAiUpsertSetting($pdo, 'voice_ai_interaction_max_pages', (string) ($config['interaction_max_pages'] ?? 200), 'number', 'Maximo de paginas legacy de interacciones sincronizado desde la integracion por defecto', $userId);
    }
}

if (!function_exists('voiceAiGetConfig')) {
    function voiceAiGetConfig(PDO $pdo, $integrationId = null): array
    {
        $legacy = voiceAiGetLegacyConfig($pdo);
        $integrations = voiceAiGetIntegrations($pdo);

        $selectedId = voiceAiResolveIntegrationId($integrationId);
        if ($selectedId === null) {
            $selectedId = voiceAiGetDefaultIntegrationId($pdo, $integrations);
        }

        foreach ($integrations as $integration) {
            if ((int) ($integration['integration_id'] ?? 0) === (int) $selectedId) {
                return $integration;
            }
        }

        if (!empty($integrations[0])) {
            return $integrations[0];
        }

        return $legacy;
    }
}

if (!function_exists('voiceAiGetConfigStatus')) {
    function voiceAiGetConfigStatus(PDO $pdo, $integrationId = null): array
    {
        $config = voiceAiGetConfig($pdo, $integrationId);
        $integrations = voiceAiGetIntegrations($pdo);

        return [
            'integration_id' => $config['integration_id'] ?? null,
            'integration_name' => $config['integration_name'] ?? 'Sin cuenta',
            'selected_integration_id' => $config['integration_id'] ?? null,
            'selected_integration_name' => $config['integration_name'] ?? 'Sin cuenta',
            'default_integration_id' => voiceAiGetDefaultIntegrationId($pdo, $integrations),
            'integrations' => array_map(static function (array $integration): array {
                unset($integration['api_key'], $integration['base_url'], $integration['version']);
                return $integration;
            }, $integrations),
            'has_api_key' => ($config['api_key'] ?? '') !== '',
            'has_location_id' => ($config['location_id'] ?? '') !== '',
            'token_masked' => voiceAiMaskToken((string) ($config['api_key'] ?? '')),
            'location_id' => $config['location_id'] ?? '',
            'timezone' => $config['timezone'] ?? 'UTC',
            'page_size' => $config['page_size'] ?? 50,
            'max_pages' => $config['max_pages'] ?? 10,
            'interaction_page_size' => $config['interaction_page_size'] ?? 100,
            'interaction_max_pages' => $config['interaction_max_pages'] ?? 200,
            'is_ready' => (($config['api_key'] ?? '') !== '' && ($config['location_id'] ?? '') !== ''),
            'missing' => array_values(array_filter([
                ($config['api_key'] ?? '') === '' ? 'api_key' : null,
                ($config['location_id'] ?? '') === '' ? 'location_id' : null,
            ])),
            'has_multiple_integrations' => count($integrations) > 1,
        ];
    }
}

if (!function_exists('voiceAiSaveConfig')) {
    function voiceAiSaveConfig(PDO $pdo, array $input, ?int $userId = null): array
    {
        voiceAiEnsureIntegrationSchema($pdo);

        $integrationId = voiceAiResolveIntegrationId($input['integration_id'] ?? null);
        $isUpdate = $integrationId !== null;
        $current = voiceAiGetConfig($pdo, $integrationId);
        $integrationName = trim((string) ($input['integration_name'] ?? ($current['integration_name'] ?? '')));
        $apiKey = trim((string) ($input['api_key'] ?? ''));
        $locationId = trim((string) ($input['location_id'] ?? ($current['location_id'] ?? '')));
        $timezone = trim((string) ($input['timezone'] ?? ($current['timezone'] ?? 'UTC')));
        $pageSize = isset($input['page_size']) ? (int) $input['page_size'] : (int) ($current['page_size'] ?? 50);
        $maxPages = isset($input['max_pages']) ? (int) $input['max_pages'] : (int) ($current['max_pages'] ?? 10);
        $interactionPageSize = isset($input['interaction_page_size']) ? (int) $input['interaction_page_size'] : (int) ($current['interaction_page_size'] ?? 100);
        $interactionMaxPages = isset($input['interaction_max_pages']) ? (int) $input['interaction_max_pages'] : (int) ($current['interaction_max_pages'] ?? 200);
        $setAsDefault = !empty($input['set_as_default']);

        if ($apiKey === '' && !empty($current['api_key']) && !empty($current['integration_id']) && (int) $current['integration_id'] === (int) $integrationId) {
            $apiKey = (string) $current['api_key'];
        }

        if ($integrationName === '') {
            return ['success' => false, 'message' => 'Debes indicar un nombre para la cuenta o campana.'];
        }

        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            return ['success' => false, 'message' => 'La zona horaria no es valida.'];
        }

        if ($locationId === '') {
            return ['success' => false, 'message' => 'El Location ID es obligatorio para la cuenta.'];
        }

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $locationId)) {
            return ['success' => false, 'message' => 'El Location ID contiene caracteres no validos.'];
        }

        if ($apiKey === '') {
            return ['success' => false, 'message' => 'La API key es obligatoria para la cuenta.'];
        }

        if (stripos($apiKey, 'pit-') !== 0) {
            return ['success' => false, 'message' => 'La API key no tiene el formato esperado de Private Integration Token.'];
        }

        if ($pageSize < 10 || $pageSize > 50) {
            return ['success' => false, 'message' => 'El tamano de pagina debe estar entre 10 y 50.'];
        }

        if ($maxPages < 1 || $maxPages > 50) {
            return ['success' => false, 'message' => 'El maximo de paginas debe estar entre 1 y 50.'];
        }

        if ($interactionPageSize < 10 || $interactionPageSize > 100) {
            return ['success' => false, 'message' => 'El tamano de pagina de interacciones debe estar entre 10 y 100.'];
        }

        if ($interactionMaxPages < 1 || $interactionMaxPages > 250) {
            return ['success' => false, 'message' => 'El maximo de paginas de interacciones debe estar entre 1 y 250.'];
        }

        try {
            $pdo->beginTransaction();

            $duplicateCheck = $pdo->prepare("
                SELECT id
                FROM voice_ai_integrations
                WHERE location_id = ?
                  AND (? IS NULL OR id <> ?)
                LIMIT 1
            ");
            $duplicateCheck->execute([$locationId, $integrationId, $integrationId]);
            if ($duplicateCheck->fetchColumn()) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Ya existe otra integracion con ese Location ID.'];
            }

            if ($integrationId !== null) {
                $stmt = $pdo->prepare("
                    UPDATE voice_ai_integrations
                    SET integration_name = ?, api_key = ?, location_id = ?, timezone = ?, page_size = ?, max_pages = ?,
                        interaction_page_size = ?, interaction_max_pages = ?, updated_by = ?, is_enabled = 1
                    WHERE id = ?
                ");
                $stmt->execute([
                    $integrationName,
                    $apiKey,
                    $locationId,
                    $timezone,
                    $pageSize,
                    $maxPages,
                    $interactionPageSize,
                    $interactionMaxPages,
                    $userId,
                    $integrationId,
                ]);
            } else {
                $maxOrder = (int) $pdo->query("SELECT COALESCE(MAX(display_order), 0) FROM voice_ai_integrations")->fetchColumn();
                $stmt = $pdo->prepare("
                    INSERT INTO voice_ai_integrations (
                        integration_name, api_key, location_id, timezone, page_size, max_pages,
                        interaction_page_size, interaction_max_pages, display_order, is_enabled, created_by, updated_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                ");
                $stmt->execute([
                    $integrationName,
                    $apiKey,
                    $locationId,
                    $timezone,
                    $pageSize,
                    $maxPages,
                    $interactionPageSize,
                    $interactionMaxPages,
                    $maxOrder + 1,
                    $userId,
                    $userId,
                ]);
                $integrationId = (int) $pdo->lastInsertId();
            }

            $integrations = voiceAiGetIntegrations($pdo);
            if ($setAsDefault || voiceAiGetDefaultIntegrationId($pdo, $integrations) === null) {
                voiceAiSetDefaultIntegrationId($pdo, $integrationId, $userId);
            }

            $selectedConfig = voiceAiGetConfig($pdo, $integrationId);
            voiceAiSyncLegacySettingsFromConfig($pdo, $selectedConfig, $userId);

            $pdo->commit();
            voiceAiSetContextIntegrationId($integrationId);
            voiceAiClearReportsCache();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['success' => false, 'message' => 'No se pudo guardar la configuracion multicuenta de GoHighLevel.'];
        }

        return [
            'success' => true,
            'message' => $isUpdate ? 'Cuenta GoHighLevel actualizada.' : 'Cuenta GoHighLevel agregada.',
            'config_status' => voiceAiGetConfigStatus($pdo, $integrationId),
        ];
    }
}

if (!function_exists('voiceAiHttpRequest')) {
    function voiceAiHttpRequest(array $config, string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = rtrim($config['base_url'], '/') . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $config['api_key'],
            'Version: ' . $config['version'],
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, voiceAiJsonEncode($body));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'status_code' => 0,
                'message' => 'No se pudo conectar con GoHighLevel Voice AI.',
                'error_detail' => $error,
            ];
        }

        $decoded = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            if (is_array($decoded)) {
                $rawMessage = $decoded['message'] ?? $decoded['error'] ?? 'Error al consultar Voice AI.';
                if (is_array($rawMessage)) {
                    $message = implode('; ', array_map(static function ($item): string {
                        return is_scalar($item) ? (string) $item : voiceAiJsonEncode($item);
                    }, $rawMessage));
                } else {
                    $message = (string) $rawMessage;
                }
            } else {
                $message = 'Error al consultar Voice AI.';
            }

            return [
                'success' => false,
                'status_code' => $httpCode,
                'message' => $message,
                'raw_body' => $response,
                'data' => is_array($decoded) ? $decoded : null,
            ];
        }

        return [
            'success' => true,
            'status_code' => $httpCode,
            'data' => is_array($decoded) ? $decoded : [],
            'raw_body' => $response,
        ];
    }
}

if (!function_exists('voiceAiIsList')) {
    function voiceAiIsList($value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}

if (!function_exists('voiceAiGetNestedValue')) {
    function voiceAiGetNestedValue(array $source, string $path, $default = null)
    {
        $segments = explode('.', $path);
        $value = $source;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('voiceAiPickValue')) {
    function voiceAiPickValue(array $source, array $paths, $default = null)
    {
        foreach ($paths as $path) {
            $value = strpos($path, '.') !== false
                ? voiceAiGetNestedValue($source, $path, null)
                : ($source[$path] ?? null);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }
}

if (!function_exists('voiceAiLower')) {
    function voiceAiLower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}

if (!function_exists('voiceAiNormalizeSummary')) {
    function voiceAiNormalizeSummary($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_array($value)) {
            foreach (['summary', 'text', 'content', 'value'] as $key) {
                if (!empty($value[$key]) && is_string($value[$key])) {
                    return trim($value[$key]);
                }
            }

            $parts = [];
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $parts[] = trim($item);
                }
            }

            if (!empty($parts)) {
                return implode("\n", $parts);
            }

            return trim(voiceAiJsonEncode($value));
        }

        return trim((string) $value);
    }
}

if (!function_exists('voiceAiNormalizeTranscript')) {
    function voiceAiNormalizeTranscript($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (!is_array($value)) {
            return trim((string) $value);
        }

        $lines = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $lines[] = trim($item);
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $speaker = trim((string) voiceAiPickValue($item, ['speaker', 'role', 'name'], ''));
            $text = trim((string) voiceAiPickValue($item, ['text', 'content', 'message', 'transcript', 'utterance'], ''));

            if ($text === '' && !empty($item['utterances']) && is_array($item['utterances'])) {
                $nested = voiceAiNormalizeTranscript($item['utterances']);
                if ($nested !== '') {
                    $lines[] = $nested;
                }
                continue;
            }

            if ($text === '') {
                continue;
            }

            $lines[] = $speaker !== '' ? ($speaker . ': ' . $text) : $text;
        }

        return trim(implode("\n", $lines));
    }
}

if (!function_exists('voiceAiNormalizeActionTypes')) {
    function voiceAiNormalizeActionTypes($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $parts = preg_split('/\s*,\s*/', trim($value));
            return array_values(array_unique(array_filter(array_map('trim', $parts))));
        }

        if (!is_array($value)) {
            return [trim((string) $value)];
        }

        $actions = [];
        foreach ($value as $key => $item) {
            if (is_string($item) && trim($item) !== '') {
                $actions[] = trim($item);
                continue;
            }

            if (is_array($item)) {
                $actions[] = trim((string) voiceAiPickValue($item, ['actionType', 'type', 'name', 'action'], ''));
                continue;
            }

            if (!is_numeric($key) && trim((string) $key) !== '') {
                $actions[] = trim((string) $key);
            }
        }

        return array_values(array_unique(array_filter($actions)));
    }
}

if (!function_exists('voiceAiToTimestamp')) {
    function voiceAiToTimestamp($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $number = (int) $value;
            if ($number > 9999999999) {
                $number = (int) round($number / 1000);
            }
            return $number > 0 ? $number : null;
        }

        $timestamp = strtotime((string) $value);
        return $timestamp !== false ? $timestamp : null;
    }
}

if (!function_exists('voiceAiNormalizeDate')) {
    function voiceAiNormalizeDate($value): string
    {
        $timestamp = voiceAiToTimestamp($value);
        return $timestamp ? date('c', $timestamp) : '';
    }
}

if (!function_exists('voiceAiNormalizeDuration')) {
    function voiceAiNormalizeDuration($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return max(0, (int) round((float) $value));
        }

        if (is_string($value) && preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', trim($value), $matches)) {
            if (isset($matches[3])) {
                return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
            }

            return ((int) $matches[1] * 60) + (int) $matches[2];
        }

        return 0;
    }
}

if (!function_exists('voiceAiNormalizeCall')) {
    function voiceAiNormalizeCall(array $raw): array
    {
        $summary = voiceAiNormalizeSummary(voiceAiPickValue($raw, [
            'summary',
            'callSummary',
            'aiSummary',
            'analysis.summary',
        ]));

        $transcript = voiceAiNormalizeTranscript(voiceAiPickValue($raw, [
            'transcript',
            'callTranscript',
            'conversation.transcript',
            'analysis.transcript',
            'messages',
            'utterances',
        ]));

        $actionTypes = voiceAiNormalizeActionTypes(voiceAiPickValue($raw, [
            'actionTypes',
            'actions',
            'triggeredActions',
            'actionHistory',
            'analysis.actionTypes',
        ], []));

        $startedAtValue = voiceAiPickValue($raw, [
            'startedAt',
            'startTime',
            'callStartTime',
            'createdAt',
            'created_at',
            'timestamps.startedAt',
            'timestamps.start',
        ]);

        $endedAtValue = voiceAiPickValue($raw, [
            'endedAt',
            'endTime',
            'callEndTime',
            'updatedAt',
            'updated_at',
            'timestamps.endedAt',
            'timestamps.end',
        ]);

        $status = trim((string) voiceAiPickValue($raw, [
            'status',
            'callStatus',
            'outcome',
            'result',
            'disposition',
        ], 'Unknown'));

        $callType = trim((string) voiceAiPickValue($raw, [
            'callType',
            'direction',
            'type',
        ], 'Unknown'));

        return [
            'call_id' => (string) voiceAiPickValue($raw, ['callId', 'call_id', 'id'], ''),
            'started_at' => voiceAiNormalizeDate($startedAtValue),
            'started_at_ts' => voiceAiToTimestamp($startedAtValue),
            'ended_at' => voiceAiNormalizeDate($endedAtValue),
            'ended_at_ts' => voiceAiToTimestamp($endedAtValue),
            'call_type' => $callType !== '' ? $callType : 'Unknown',
            'status' => $status !== '' ? $status : 'Unknown',
            'duration_seconds' => voiceAiNormalizeDuration(voiceAiPickValue($raw, [
                'durationSeconds',
                'duration',
                'callDuration',
                'metrics.durationSeconds',
                'metrics.duration',
                'durationInSeconds',
            ], 0)),
            'agent_id' => (string) voiceAiPickValue($raw, ['agent.id', 'agentId', 'agent_id', 'voiceAgentId'], ''),
            'agent_name' => trim((string) voiceAiPickValue($raw, ['agent.name', 'agentName', 'agent_name', 'voiceAgentName'], 'Sin agente')),
            'contact_id' => (string) voiceAiPickValue($raw, ['contact.id', 'contactId', 'contact_id'], ''),
            'contact_name' => trim((string) voiceAiPickValue($raw, ['contact.name', 'contact.fullName', 'contactName', 'contact_name', 'leadName'], 'Sin contacto')),
            'contact_phone' => trim((string) voiceAiPickValue($raw, ['contact.phone', 'phone', 'contactPhone', 'from', 'to', 'customerPhone'], '')),
            'summary' => $summary,
            'transcript' => $transcript,
            'recording_url' => trim((string) voiceAiPickValue($raw, [
                'recordingUrl',
                'recording.url',
                'audioUrl',
                'callRecordingUrl',
                'recordingLink',
            ], '')),
            'sentiment' => trim((string) voiceAiPickValue($raw, [
                'sentiment',
                'analysis.sentiment',
                'callSentiment',
            ], '')),
            'action_types' => $actionTypes,
            'has_summary' => $summary !== '',
            'has_transcript' => $transcript !== '',
        ];
    }
}

if (!function_exists('voiceAiExtractCallItems')) {
    function voiceAiExtractCallItems($payload): array
    {
        if (voiceAiIsList($payload)) {
            return $payload;
        }

        if (!is_array($payload)) {
            return [];
        }

        foreach (['data', 'items', 'results', 'callLogs', 'calls', 'logs'] as $key) {
            if (isset($payload[$key]) && voiceAiIsList($payload[$key])) {
                return $payload[$key];
            }

            if (isset($payload[$key]) && is_array($payload[$key])) {
                foreach (['items', 'results', 'callLogs', 'calls', 'logs'] as $nestedKey) {
                    if (isset($payload[$key][$nestedKey]) && voiceAiIsList($payload[$key][$nestedKey])) {
                        return $payload[$key][$nestedKey];
                    }
                }
            }
        }

        return [];
    }
}

if (!function_exists('voiceAiExtractPaginationMeta')) {
    function voiceAiExtractPaginationMeta($payload): array
    {
        $meta = [
            'total' => null,
            'total_pages' => null,
            'current_page' => null,
            'has_more' => null,
            'page_size' => null,
        ];

        if (!is_array($payload)) {
            return $meta;
        }

        if (isset($payload['totalRecords'])) {
            $meta['total'] = (int) $payload['totalRecords'];
        } elseif (isset($payload['total'])) {
            $meta['total'] = (int) $payload['total'];
        }

        if (isset($payload['page'])) {
            $meta['current_page'] = (int) $payload['page'];
        }

        if (isset($payload['pageSize'])) {
            $meta['page_size'] = (int) $payload['pageSize'];
        }

        foreach (['meta', 'pagination', 'pageInfo'] as $containerKey) {
            if (empty($payload[$containerKey]) || !is_array($payload[$containerKey])) {
                continue;
            }

            $container = $payload[$containerKey];
            $meta['total'] = isset($container['total']) ? (int) $container['total'] : (isset($container['totalCount']) ? (int) $container['totalCount'] : $meta['total']);
            $meta['total_pages'] = isset($container['totalPages']) ? (int) $container['totalPages'] : (isset($container['pages']) ? (int) $container['pages'] : $meta['total_pages']);
            $meta['current_page'] = isset($container['page']) ? (int) $container['page'] : (isset($container['currentPage']) ? (int) $container['currentPage'] : $meta['current_page']);
            $meta['page_size'] = isset($container['pageSize']) ? (int) $container['pageSize'] : $meta['page_size'];

            if (isset($container['hasNextPage'])) {
                $meta['has_more'] = (bool) $container['hasNextPage'];
            } elseif (isset($container['hasMore'])) {
                $meta['has_more'] = (bool) $container['hasMore'];
            } elseif (isset($container['nextPage'])) {
                $meta['has_more'] = !empty($container['nextPage']);
            }
        }

        if ($meta['has_more'] === null && $meta['total'] !== null && $meta['current_page'] !== null && $meta['page_size'] !== null && $meta['page_size'] > 0) {
            $meta['total_pages'] = (int) ceil($meta['total'] / $meta['page_size']);
            $meta['has_more'] = $meta['current_page'] < $meta['total_pages'];
        }

        return $meta;
    }
}

if (!function_exists('voiceAiBuildListQuery')) {
    function voiceAiBuildListQuery(array $config, array $filters = [], int $page = 1): array
    {
        $query = [
            'locationId' => $config['location_id'],
            'page' => max(1, $page),
            'pageSize' => isset($filters['page_size']) ? max(10, min(50, (int) $filters['page_size'])) : $config['page_size'],
            'timezone' => $filters['timezone'] ?? $config['timezone'],
        ];

        if (!empty($filters['start_date'])) {
            $query['startDate'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $query['endDate'] = $filters['end_date'];
        }
        if (!empty($filters['call_type'])) {
            $query['callType'] = strtoupper((string) $filters['call_type']);
        }
        if (!empty($filters['action_type'])) {
            $query['actionTypes'] = $filters['action_type'];
        }
        if (!empty($filters['agent_id'])) {
            $query['agentId'] = $filters['agent_id'];
        }
        if (!empty($filters['contact_id'])) {
            $query['contactId'] = $filters['contact_id'];
        }

        return $query;
    }
}

if (!function_exists('voiceAiApplyLocalFilters')) {
    function voiceAiApplyLocalFilters(array $calls, array $filters = []): array
    {
        $startTs = null;
        $endTs = null;

        if (!empty($filters['start_date'])) {
            $startTs = strtotime($filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $endTs = strtotime($filters['end_date'] . ' 23:59:59');
        }

        $callType = trim((string) ($filters['call_type'] ?? ''));
        $actionType = trim((string) ($filters['action_type'] ?? ''));
        $search = voiceAiLower(trim((string) ($filters['search'] ?? '')));
        $transcriptOnly = !empty($filters['transcript_only']);

        return array_values(array_filter($calls, static function (array $call) use ($startTs, $endTs, $callType, $actionType, $search, $transcriptOnly): bool {
            $timestamp = $call['started_at_ts'] ?? null;

            if ($startTs !== null && $timestamp !== null && $timestamp < $startTs) {
                return false;
            }
            if ($endTs !== null && $timestamp !== null && $timestamp > $endTs) {
                return false;
            }
            if ($callType !== '' && strcasecmp((string) $call['call_type'], $callType) !== 0) {
                return false;
            }
            if ($actionType !== '' && !in_array($actionType, $call['action_types'] ?? [], true)) {
                return false;
            }
            if ($transcriptOnly && empty($call['has_transcript'])) {
                return false;
            }
            if ($search !== '') {
                $haystack = voiceAiLower(implode(' ', [
                    (string) ($call['call_id'] ?? ''),
                    (string) ($call['agent_name'] ?? ''),
                    (string) ($call['contact_name'] ?? ''),
                    (string) ($call['contact_phone'] ?? ''),
                    (string) ($call['status'] ?? ''),
                    (string) ($call['call_type'] ?? ''),
                    (string) ($call['summary'] ?? ''),
                ]));

                if (strpos($haystack, $search) === false) {
                    return false;
                }
            }

            return true;
        }));
    }
}

if (!function_exists('voiceAiSortCalls')) {
    function voiceAiSortCalls(array $calls, string $sortOrder = 'desc'): array
    {
        usort($calls, static function (array $a, array $b) use ($sortOrder): int {
            $left = (int) ($a['started_at_ts'] ?? 0);
            $right = (int) ($b['started_at_ts'] ?? 0);

            if ($left === $right) {
                return strcmp((string) ($a['call_id'] ?? ''), (string) ($b['call_id'] ?? ''));
            }

            if (strtolower($sortOrder) === 'asc') {
                return $left <=> $right;
            }

            return $right <=> $left;
        });

        return $calls;
    }
}

if (!function_exists('voiceAiBuildAvailableFilters')) {
    function voiceAiBuildAvailableFilters(array $calls): array
    {
        $callTypes = [];
        $actionTypes = [];
        $agents = [];

        foreach ($calls as $call) {
            if (!empty($call['call_type'])) {
                $callTypes[$call['call_type']] = true;
            }
            foreach ($call['action_types'] ?? [] as $actionType) {
                if ($actionType !== '') {
                    $actionTypes[$actionType] = true;
                }
            }
            if (!empty($call['agent_name'])) {
                $agentKey = $call['agent_id'] !== '' ? $call['agent_id'] : $call['agent_name'];
                $agents[$agentKey] = [
                    'id' => $call['agent_id'],
                    'name' => $call['agent_name'],
                ];
            }
        }

        ksort($callTypes);
        ksort($actionTypes);
        uasort($agents, static function (array $left, array $right): int {
            return strcasecmp($left['name'], $right['name']);
        });

        return [
            'call_types' => array_keys($callTypes),
            'action_types' => array_keys($actionTypes),
            'agents' => array_values($agents),
        ];
    }
}

if (!function_exists('voiceAiFetchCalls')) {
    function voiceAiFetchCalls(PDO $pdo, array $filters = []): array
    {
        $config = voiceAiGetConfig($pdo);
        $configStatus = voiceAiGetConfigStatus($pdo);

        if (!$configStatus['is_ready']) {
            return [
                'success' => false,
                'message' => 'La integracion de Voice AI requiere API key y Location ID.',
                'config_status' => $configStatus,
            ];
        }

        $maxPages = isset($filters['max_pages']) ? (int) $filters['max_pages'] : $config['max_pages'];
        if ($maxPages < 1) {
            $maxPages = 1;
        }
        if ($maxPages > 50) {
            $maxPages = 50;
        }

        $allRawCalls = [];
        $pagesFetched = 0;
        $lastPageCount = 0;
        $paginationMeta = [
            'total' => null,
            'total_pages' => null,
            'current_page' => null,
            'has_more' => null,
        ];

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = voiceAiHttpRequest(
                $config,
                'GET',
                '/voice-ai/dashboard/call-logs',
                voiceAiBuildListQuery($config, $filters, $page)
            );

            if (!$response['success']) {
                return [
                    'success' => false,
                    'message' => $response['message'],
                    'status_code' => $response['status_code'] ?? 0,
                    'config_status' => $configStatus,
                ];
            }

            $items = voiceAiExtractCallItems($response['data']);
            $paginationMeta = voiceAiExtractPaginationMeta($response['data']);
            $pagesFetched++;
            $lastPageCount = count($items);

            if ($lastPageCount === 0) {
                break;
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    $allRawCalls[] = $item;
                }
            }

            if ($paginationMeta['total_pages'] !== null && $page >= $paginationMeta['total_pages']) {
                break;
            }
            if ($paginationMeta['has_more'] === false) {
                break;
            }
            if ($lastPageCount < ($filters['page_size'] ?? $config['page_size'])) {
                break;
            }
        }

        $normalized = array_map('voiceAiNormalizeCall', $allRawCalls);
        $filtered = voiceAiApplyLocalFilters($normalized, $filters);
        $sorted = voiceAiSortCalls($filtered, (string) ($filters['sort_order'] ?? 'desc'));

        return [
            'success' => true,
            'calls' => $sorted,
            'all_calls' => $normalized,
            'available_filters' => voiceAiBuildAvailableFilters($normalized),
            'config_status' => $configStatus,
            'meta' => [
                'pages_fetched' => $pagesFetched,
                'page_size' => $filters['page_size'] ?? $config['page_size'],
                'max_pages' => $maxPages,
                'fetched_count' => count($normalized),
                'filtered_count' => count($sorted),
                'truncated' => $pagesFetched >= $maxPages && $lastPageCount >= ($filters['page_size'] ?? $config['page_size']),
                'api_total' => $paginationMeta['total'],
                'api_total_pages' => $paginationMeta['total_pages'],
            ],
        ];
    }
}

if (!function_exists('voiceAiFormatDuration')) {
    function voiceAiFormatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }
        if ($remainingSeconds > 0 || empty($parts)) {
            $parts[] = $remainingSeconds . 's';
        }

        return implode(' ', $parts);
    }
}

if (!function_exists('voiceAiBuildMetricDelta')) {
    function voiceAiBuildMetricDelta(float $current, float $previous): array
    {
        $delta = $current - $previous;
        $percent = $previous > 0 ? round(($delta / $previous) * 100, 1) : null;

        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => round($delta, 2),
            'delta_pct' => $percent,
        ];
    }
}

if (!function_exists('voiceAiBuildDashboardMetricsOnly')) {
    function voiceAiBuildDashboardMetricsOnly(array $calls): array
    {
        $uniqueAgents = [];
        $uniqueContacts = [];
        $totalDuration = 0;
        $totalActions = 0;
        $transcriptCount = 0;

        foreach ($calls as $call) {
            $agentKey = $call['agent_id'] !== '' ? $call['agent_id'] : $call['agent_name'];
            $contactKey = $call['contact_id'] !== '' ? $call['contact_id'] : ($call['contact_phone'] !== '' ? $call['contact_phone'] : $call['contact_name']);

            if ($agentKey !== '') {
                $uniqueAgents[$agentKey] = true;
            }
            if ($contactKey !== '') {
                $uniqueContacts[$contactKey] = true;
            }

            $totalDuration += (int) ($call['duration_seconds'] ?? 0);
            $totalActions += count($call['action_types'] ?? []);
            $transcriptCount += !empty($call['has_transcript']) ? 1 : 0;
        }

        return [
            'total_calls' => count($calls),
            'unique_agents' => count($uniqueAgents),
            'unique_contacts' => count($uniqueContacts),
            'total_actions' => $totalActions,
            'avg_duration' => count($calls) > 0 ? (int) round($totalDuration / count($calls)) : 0,
            'transcript_rate' => count($calls) > 0 ? round(($transcriptCount / count($calls)) * 100, 1) : 0.0,
        ];
    }
}

if (!function_exists('voiceAiBuildDashboard')) {
    function voiceAiBuildDashboard(array $calls, array $previousCalls = []): array
    {
        $uniqueAgents = [];
        $uniqueContacts = [];
        $callTypes = [];
        $statuses = [];
        $actions = [];
        $sentiments = [];
        $timelineDays = [];
        $timelineHours = array_fill(0, 24, 0);
        $agents = [];
        $contacts = [];

        $totalDuration = 0;
        $totalActions = 0;
        $transcriptCount = 0;
        $summaryCount = 0;
        $recordingCount = 0;

        foreach ($calls as $call) {
            $agentKey = $call['agent_id'] !== '' ? $call['agent_id'] : $call['agent_name'];
            $contactKey = $call['contact_id'] !== '' ? $call['contact_id'] : ($call['contact_phone'] !== '' ? $call['contact_phone'] : $call['contact_name']);

            if ($agentKey !== '') {
                $uniqueAgents[$agentKey] = true;
            }
            if ($contactKey !== '') {
                $uniqueContacts[$contactKey] = true;
            }

            $type = $call['call_type'] ?: 'Unknown';
            $status = $call['status'] ?: 'Unknown';
            $sentiment = $call['sentiment'] ?: 'Unknown';
            $duration = (int) ($call['duration_seconds'] ?? 0);

            $callTypes[$type] = ($callTypes[$type] ?? 0) + 1;
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            if ($call['sentiment'] !== '') {
                $sentiments[$sentiment] = ($sentiments[$sentiment] ?? 0) + 1;
            }

            foreach ($call['action_types'] ?? [] as $actionType) {
                $actions[$actionType] = ($actions[$actionType] ?? 0) + 1;
                $totalActions++;
            }

            if (!empty($call['has_transcript'])) {
                $transcriptCount++;
            }
            if (!empty($call['has_summary'])) {
                $summaryCount++;
            }
            if (!empty($call['recording_url'])) {
                $recordingCount++;
            }

            $totalDuration += $duration;

            if (!empty($call['started_at_ts'])) {
                $dayKey = date('Y-m-d', $call['started_at_ts']);
                $timelineDays[$dayKey] = ($timelineDays[$dayKey] ?? 0) + 1;
                $timelineHours[(int) date('G', $call['started_at_ts'])]++;
            }

            $agentLabel = $call['agent_name'] ?: 'Sin agente';
            if (!isset($agents[$agentKey])) {
                $agents[$agentKey] = [
                    'agent_id' => $call['agent_id'],
                    'agent_name' => $agentLabel,
                    'calls' => 0,
                    'duration_seconds' => 0,
                    'actions' => 0,
                    'transcripts' => 0,
                    'summaries' => 0,
                    'statuses' => [],
                    'last_call_at' => $call['started_at'],
                ];
            }
            $agents[$agentKey]['calls']++;
            $agents[$agentKey]['duration_seconds'] += $duration;
            $agents[$agentKey]['actions'] += count($call['action_types'] ?? []);
            $agents[$agentKey]['transcripts'] += !empty($call['has_transcript']) ? 1 : 0;
            $agents[$agentKey]['summaries'] += !empty($call['has_summary']) ? 1 : 0;
            $agents[$agentKey]['statuses'][$status] = ($agents[$agentKey]['statuses'][$status] ?? 0) + 1;
            if (($call['started_at_ts'] ?? 0) > voiceAiToTimestamp($agents[$agentKey]['last_call_at'])) {
                $agents[$agentKey]['last_call_at'] = $call['started_at'];
            }

            $contactLabel = $call['contact_name'] ?: ($call['contact_phone'] ?: 'Sin contacto');
            if (!isset($contacts[$contactKey])) {
                $contacts[$contactKey] = [
                    'contact_id' => $call['contact_id'],
                    'contact_name' => $contactLabel,
                    'contact_phone' => $call['contact_phone'],
                    'calls' => 0,
                    'duration_seconds' => 0,
                    'last_call_at' => $call['started_at'],
                ];
            }
            $contacts[$contactKey]['calls']++;
            $contacts[$contactKey]['duration_seconds'] += $duration;
            if (($call['started_at_ts'] ?? 0) > voiceAiToTimestamp($contacts[$contactKey]['last_call_at'])) {
                $contacts[$contactKey]['last_call_at'] = $call['started_at'];
            }
        }

        ksort($timelineDays);
        arsort($callTypes);
        arsort($statuses);
        arsort($actions);
        arsort($sentiments);

        foreach ($agents as &$agent) {
            $agent['avg_duration_seconds'] = $agent['calls'] > 0 ? (int) round($agent['duration_seconds'] / $agent['calls']) : 0;
        }
        unset($agent);

        foreach ($contacts as &$contact) {
            $contact['avg_duration_seconds'] = $contact['calls'] > 0 ? (int) round($contact['duration_seconds'] / $contact['calls']) : 0;
        }
        unset($contact);

        usort($agents, static function (array $left, array $right): int {
            if ($left['calls'] === $right['calls']) {
                return $right['duration_seconds'] <=> $left['duration_seconds'];
            }
            return $right['calls'] <=> $left['calls'];
        });

        usort($contacts, static function (array $left, array $right): int {
            if ($left['calls'] === $right['calls']) {
                return $right['duration_seconds'] <=> $left['duration_seconds'];
            }
            return $right['calls'] <=> $left['calls'];
        });

        $currentMetrics = [
            'total_calls' => count($calls),
            'unique_agents' => count($uniqueAgents),
            'unique_contacts' => count($uniqueContacts),
            'total_duration' => $totalDuration,
            'avg_duration' => count($calls) > 0 ? (int) round($totalDuration / count($calls)) : 0,
            'total_actions' => $totalActions,
            'transcript_rate' => count($calls) > 0 ? round(($transcriptCount / count($calls)) * 100, 1) : 0.0,
            'summary_rate' => count($calls) > 0 ? round(($summaryCount / count($calls)) * 100, 1) : 0.0,
            'recording_rate' => count($calls) > 0 ? round(($recordingCount / count($calls)) * 100, 1) : 0.0,
        ];

        $previousDashboard = voiceAiBuildDashboardMetricsOnly($previousCalls);

        return [
            'kpis' => [
                'total_calls' => [
                    'value' => $currentMetrics['total_calls'],
                    'formatted' => number_format($currentMetrics['total_calls']),
                    'label' => 'Llamadas',
                    'icon' => 'fa-phone-volume',
                    'color' => 'cyan',
                    'comparison' => voiceAiBuildMetricDelta((float) $currentMetrics['total_calls'], (float) $previousDashboard['total_calls']),
                ],
                'unique_agents' => [
                    'value' => $currentMetrics['unique_agents'],
                    'formatted' => number_format($currentMetrics['unique_agents']),
                    'label' => 'Agentes',
                    'icon' => 'fa-users',
                    'color' => 'emerald',
                    'comparison' => voiceAiBuildMetricDelta((float) $currentMetrics['unique_agents'], (float) $previousDashboard['unique_agents']),
                ],
                'unique_contacts' => [
                    'value' => $currentMetrics['unique_contacts'],
                    'formatted' => number_format($currentMetrics['unique_contacts']),
                    'label' => 'Contactos',
                    'icon' => 'fa-address-book',
                    'color' => 'amber',
                    'comparison' => voiceAiBuildMetricDelta((float) $currentMetrics['unique_contacts'], (float) $previousDashboard['unique_contacts']),
                ],
                'avg_duration' => [
                    'value' => $currentMetrics['avg_duration'],
                    'formatted' => voiceAiFormatDuration($currentMetrics['avg_duration']),
                    'label' => 'Duracion promedio',
                    'icon' => 'fa-stopwatch',
                    'color' => 'blue',
                    'comparison' => voiceAiBuildMetricDelta((float) $currentMetrics['avg_duration'], (float) $previousDashboard['avg_duration']),
                ],
                'total_actions' => [
                    'value' => $currentMetrics['total_actions'],
                    'formatted' => number_format($currentMetrics['total_actions']),
                    'label' => 'Acciones disparadas',
                    'icon' => 'fa-bolt',
                    'color' => 'orange',
                    'comparison' => voiceAiBuildMetricDelta((float) $currentMetrics['total_actions'], (float) $previousDashboard['total_actions']),
                ],
                'transcript_rate' => [
                    'value' => $currentMetrics['transcript_rate'],
                    'formatted' => $currentMetrics['transcript_rate'] . '%',
                    'label' => 'Cobertura transcript',
                    'icon' => 'fa-file-lines',
                    'color' => 'indigo',
                    'comparison' => voiceAiBuildMetricDelta((float) $currentMetrics['transcript_rate'], (float) $previousDashboard['transcript_rate']),
                ],
            ],
            'distributions' => [
                'call_types' => $callTypes,
                'statuses' => $statuses,
                'actions' => $actions,
                'sentiments' => $sentiments,
            ],
            'timeline' => [
                'by_day' => $timelineDays,
                'by_hour' => array_combine(
                    array_map(static function (int $hour): string {
                        return sprintf('%02d:00', $hour);
                    }, range(0, 23)),
                    $timelineHours
                ),
            ],
            'agents' => array_slice($agents, 0, 15),
            'contacts' => array_slice($contacts, 0, 15),
            'recent_calls' => array_slice($calls, 0, 100),
            'coverage' => [
                'transcripts' => $transcriptCount,
                'summaries' => $summaryCount,
                'recordings' => $recordingCount,
            ],
        ];
    }
}

if (!function_exists('voiceAiShiftDateWindow')) {
    function voiceAiShiftDateWindow(array $filters): ?array
    {
        if (empty($filters['start_date']) || empty($filters['end_date'])) {
            return null;
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d', $filters['start_date']);
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $filters['end_date']);

        if (!$start || !$end) {
            return null;
        }

        $days = (int) $start->diff($end)->format('%a') + 1;
        $previousEnd = $start->modify('-1 day');
        $previousStart = $previousEnd->modify('-' . ($days - 1) . ' days');

        $shifted = $filters;
        $shifted['start_date'] = $previousStart->format('Y-m-d');
        $shifted['end_date'] = $previousEnd->format('Y-m-d');

        return $shifted;
    }
}

if (!function_exists('voiceAiBuildReportPayload')) {
    function voiceAiBuildReportPayload(PDO $pdo, array $filters = [], bool $withComparison = true): array
    {
        $filters = voiceAiNormalizeReportFilters($filters);
        voiceAiSetContextIntegrationId($filters['integration_id'] ?? null);
        $config = voiceAiGetConfig($pdo, $filters['integration_id'] ?? null);
        $cacheKey = voiceAiBuildReportsCacheKey($config, $filters, $withComparison);
        $cached = voiceAiReadReportsCache($cacheKey, 600);
        if (is_array($cached) && !empty($cached['success'])) {
            return $cached;
        }

        $interactions = voiceAiFetchInteractions($pdo, $filters);
        if (!$interactions['success']) {
            return $interactions;
        }

        $interactionTotalsResult = voiceAiFetchInteractionTotals($pdo, $filters, $interactions['items'] ?? [], $interactions['meta'] ?? []);
        $interactionTotals = $interactionTotalsResult['success'] ? ($interactionTotalsResult['totals'] ?? []) : [];

        $activeUsers = array_values(array_filter($interactions['users'] ?? [], static function (array $user) use ($interactions): bool {
            $userId = (string) ($user['id'] ?? '');
            if ($userId === '') {
                return false;
            }

            foreach ($interactions['items'] ?? [] as $item) {
                if ((string) ($item['user_id'] ?? '') === $userId) {
                    return true;
                }
            }

            return false;
        }));

        $assignmentTotalsResult = voiceAiFetchAssignedConversationTotals($pdo, $activeUsers);
        $assignmentTotals = $assignmentTotalsResult['success'] ? ($assignmentTotalsResult['totals'] ?? []) : [];

        $agentsCatalogResult = voiceAiFetchAgents($pdo, $filters);
        $agentsCatalog = $agentsCatalogResult['success'] ? ($agentsCatalogResult['agents'] ?? []) : [];
        $conversationsResult = voiceAiFetchConversationsSnapshot($pdo, 25);
        $conversationsSnapshot = $conversationsResult['success'] ? ($conversationsResult['conversations'] ?? []) : [];
        $conversationsTotal = $conversationsResult['success'] ? (int) ($conversationsResult['total'] ?? count($conversationsSnapshot)) : 0;

        $current = voiceAiFetchCalls($pdo, $filters);
        $previousCalls = [];
        $previousRange = null;
        $voiceAiWarnings = [];

        if ($current['success'] && $withComparison) {
            $previousFilters = voiceAiShiftDateWindow($filters);
            if ($previousFilters !== null) {
                $previousRange = [
                    'start_date' => $previousFilters['start_date'],
                    'end_date' => $previousFilters['end_date'],
                ];

                $previous = voiceAiFetchCalls($pdo, $previousFilters);
                if ($previous['success']) {
                    $previousCalls = $previous['calls'];
                }
            }
        }

        if (!$current['success']) {
            $voiceAiWarnings[] = $current['message'] ?? 'No se pudo consultar Voice AI.';
        }

        $voiceAiDashboard = $current['success'] ? voiceAiBuildDashboard($current['calls'], $previousCalls) : [
            'kpis' => [],
            'distributions' => [
                'call_types' => [],
                'statuses' => [],
                'actions' => [],
                'sentiments' => [],
            ],
            'timeline' => [
                'by_day' => [],
                'by_hour' => [],
            ],
            'agents' => [],
            'contacts' => [],
            'recent_calls' => [],
            'coverage' => [
                'transcripts' => 0,
                'summaries' => 0,
                'recordings' => 0,
            ],
        ];

        $interactionDashboard = voiceAiBuildInteractionsDashboard(
            $interactions['items'],
            $interactions['users'] ?? [],
            $assignmentTotals,
            $interactions['numbers'] ?? [],
            $interactionTotals
        );

        $dashboard = [
            'kpis' => $interactionDashboard['kpis'],
            'distributions' => $interactionDashboard['distributions'],
            'timeline' => $interactionDashboard['timeline'],
            'agents' => $interactionDashboard['users'],
            'contacts' => $interactionDashboard['contacts'],
            'recent_calls' => $interactionDashboard['recent_calls'],
            'recent_messages' => $interactionDashboard['recent_messages'],
            'recent_interactions' => $interactionDashboard['recent_interactions'],
            'queue_by_user' => $interactionDashboard['queue_by_user'],
            'numbers' => $interactionDashboard['numbers'],
            'summary' => $interactionDashboard['summary'],
            'users_catalog' => $interactions['users'] ?? [],
            'numbers_catalog' => $interactions['numbers'] ?? [],
        ];
        $dashboard['agents_catalog'] = $agentsCatalog;
        $dashboard['conversations_snapshot'] = $conversationsSnapshot;
        $dashboard['kpis']['configured_agents'] = [
            'value' => count($agentsCatalog),
            'formatted' => number_format(count($agentsCatalog)),
            'label' => 'Agentes configurados',
            'icon' => 'fa-robot',
            'color' => 'emerald',
            'comparison' => [
                'current' => count($agentsCatalog),
                'previous' => count($agentsCatalog),
                'delta' => 0,
                'delta_pct' => 0,
            ],
        ];
        $dashboard['kpis']['total_conversations'] = [
            'value' => $conversationsTotal,
            'formatted' => number_format($conversationsTotal),
            'label' => 'Conversaciones',
            'icon' => 'fa-comments',
            'color' => 'cyan',
            'comparison' => [
                'current' => $conversationsTotal,
                'previous' => $conversationsTotal,
                'delta' => 0,
                'delta_pct' => 0,
            ],
        ];
        $dashboard['kpis']['voice_ai_call_logs'] = [
            'value' => $current['success'] ? count($current['calls']) : 0,
            'formatted' => number_format($current['success'] ? count($current['calls']) : 0),
            'label' => 'Voice AI call logs',
            'icon' => 'fa-robot',
            'color' => 'emerald',
            'comparison' => voiceAiBuildStaticComparison($current['success'] ? count($current['calls']) : 0),
        ];

        $payload = [
            'success' => true,
            'config_status' => $interactions['config_status'],
            'meta' => array_merge($interactions['meta'], [
                'comparison_previous_range' => $previousRange,
                'agents_total' => count($agentsCatalog),
                'conversations_total' => $conversationsTotal,
                'users_total' => count($interactions['users'] ?? []),
                'numbers_total' => count($interactions['numbers'] ?? []),
                'interaction_totals' => $interactionTotals,
                'voice_ai_total' => $current['success'] ? (int) (($current['meta']['api_total'] ?? count($current['calls']))) : 0,
                'voice_ai_warnings' => $voiceAiWarnings,
                'warnings' => array_merge($interactions['meta']['warnings'] ?? [], $voiceAiWarnings, $interactionTotalsResult['warnings'] ?? []),
                'generated_at' => date('c'),
            ]),
            'available_filters' => array_merge(
                $current['success'] ? ($current['available_filters'] ?? []) : [],
                $interactions['available_filters'] ?? []
            ),
            'dashboard' => $dashboard,
        ];

        voiceAiWriteReportsCache($cacheKey, $payload);

        return $payload;
    }
}

if (!function_exists('voiceAiGetCallDetail')) {
    function voiceAiGetCallDetail(PDO $pdo, string $callId): array
    {
        $configStatus = voiceAiGetConfigStatus($pdo);
        if (!$configStatus['is_ready']) {
            return [
                'success' => false,
                'message' => 'La integracion de Voice AI requiere API key y Location ID.',
                'config_status' => $configStatus,
            ];
        }

        $config = voiceAiGetConfig($pdo);
        $response = voiceAiHttpRequest(
            $config,
            'GET',
            '/voice-ai/dashboard/call-logs/' . rawurlencode($callId),
            ['locationId' => $config['location_id']]
        );

        if (!$response['success']) {
            return [
                'success' => false,
                'message' => $response['message'],
                'status_code' => $response['status_code'] ?? 0,
            ];
        }

        $payload = $response['data'];
        if (isset($payload['data']) && is_array($payload['data']) && !voiceAiIsList($payload['data'])) {
            $payload = $payload['data'];
        }

        if (!is_array($payload) || voiceAiIsList($payload)) {
            return [
                'success' => false,
                'message' => 'La respuesta del detalle de llamada no tiene el formato esperado.',
            ];
        }

        return [
            'success' => true,
            'call' => voiceAiNormalizeCall($payload),
        ];
    }
}

if (!function_exists('voiceAiNormalizeAgent')) {
    function voiceAiNormalizeAgent(array $raw): array
    {
        return [
            'id' => (string) ($raw['id'] ?? ''),
            'location_id' => (string) ($raw['locationId'] ?? ''),
            'agent_name' => (string) ($raw['agentName'] ?? 'Sin nombre'),
            'business_name' => (string) ($raw['businessName'] ?? ''),
            'voice_id' => (string) ($raw['voiceId'] ?? ''),
            'language' => (string) ($raw['language'] ?? ''),
            'timezone' => (string) ($raw['timezone'] ?? ''),
            'max_call_duration' => (int) ($raw['maxCallDuration'] ?? 0),
            'responsiveness' => isset($raw['responsiveness']) ? (float) $raw['responsiveness'] : null,
            'actions_count' => !empty($raw['actions']) && is_array($raw['actions']) ? count($raw['actions']) : 0,
            'working_hours_count' => !empty($raw['agentWorkingHours']) && is_array($raw['agentWorkingHours']) ? count($raw['agentWorkingHours']) : 0,
            'welcome_message' => (string) ($raw['welcomeMessage'] ?? ''),
        ];
    }
}

if (!function_exists('voiceAiFetchAgents')) {
    function voiceAiFetchAgents(PDO $pdo, array $filters = []): array
    {
        $configStatus = voiceAiGetConfigStatus($pdo);
        if (!$configStatus['is_ready']) {
            return [
                'success' => false,
                'message' => 'La integracion de Voice AI requiere API key y Location ID.',
                'config_status' => $configStatus,
            ];
        }

        $config = voiceAiGetConfig($pdo);
        $pageSize = isset($filters['page_size']) ? max(10, min(50, (int) $filters['page_size'])) : $config['page_size'];
        $maxPages = min(10, $config['max_pages']);
        $allAgents = [];
        $total = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = voiceAiHttpRequest($config, 'GET', '/voice-ai/agents', [
                'locationId' => $config['location_id'],
                'page' => $page,
                'pageSize' => $pageSize,
            ]);

            if (!$response['success']) {
                return [
                    'success' => false,
                    'message' => $response['message'],
                    'status_code' => $response['status_code'] ?? 0,
                ];
            }

            $items = [];
            if (!empty($response['data']['agents']) && is_array($response['data']['agents'])) {
                $items = $response['data']['agents'];
            }

            $total = isset($response['data']['total']) ? (int) $response['data']['total'] : $total;

            foreach ($items as $item) {
                if (is_array($item)) {
                    $allAgents[] = voiceAiNormalizeAgent($item);
                }
            }

            if (count($items) < $pageSize) {
                break;
            }
            if ($total !== null && count($allAgents) >= $total) {
                break;
            }
        }

        return [
            'success' => true,
            'agents' => $allAgents,
            'meta' => [
                'total' => $total ?? count($allAgents),
                'page_size' => $pageSize,
            ],
        ];
    }
}

if (!function_exists('voiceAiFetchConversationsSnapshot')) {
    function voiceAiFetchConversationsSnapshot(PDO $pdo, int $limit = 25): array
    {
        $configStatus = voiceAiGetConfigStatus($pdo);
        if (!$configStatus['is_ready']) {
            return [
                'success' => false,
                'message' => 'La integracion requiere API key y Location ID.',
            ];
        }

        $config = voiceAiGetConfig($pdo);
        $response = voiceAiHttpRequest($config, 'GET', '/conversations/search', [
            'locationId' => $config['location_id'],
            'limit' => max(1, min(100, $limit)),
        ]);

        if (!$response['success']) {
            return [
                'success' => false,
                'message' => $response['message'],
                'status_code' => $response['status_code'] ?? 0,
            ];
        }

        $items = [];
        if (!empty($response['data']['conversations']) && is_array($response['data']['conversations'])) {
            $items = $response['data']['conversations'];
        }

        $normalized = array_map(static function (array $item): array {
            return [
                'id' => (string) ($item['id'] ?? ''),
                'location_id' => (string) ($item['locationId'] ?? ''),
                'contact_id' => (string) ($item['contactId'] ?? ''),
                'contact_name' => (string) ($item['contactName'] ?? $item['fullName'] ?? 'Sin contacto'),
                'full_name' => (string) ($item['fullName'] ?? ''),
                'company_name' => (string) ($item['companyName'] ?? ''),
                'email' => (string) ($item['email'] ?? ''),
                'phone' => (string) ($item['phone'] ?? ''),
                'type' => (string) ($item['type'] ?? ''),
                'last_message_type' => (string) ($item['lastMessageType'] ?? ''),
                'last_message_body' => (string) ($item['lastMessageBody'] ?? ''),
                'last_message_direction' => (string) ($item['lastMessageDirection'] ?? ''),
                'last_outbound_action' => (string) ($item['lastOutboundMessageAction'] ?? ''),
                'unread_count' => (int) ($item['unreadCount'] ?? 0),
                'inbox' => !empty($item['inbox']),
                'assigned_to' => (string) ($item['assignedTo'] ?? ''),
                'last_message_date' => !empty($item['lastMessageDate']) ? date('c', ((int) $item['lastMessageDate']) / 1000) : '',
                'tags' => !empty($item['tags']) && is_array($item['tags']) ? array_values($item['tags']) : [],
            ];
        }, $items);

        return [
            'success' => true,
            'total' => (int) ($response['data']['total'] ?? count($normalized)),
            'conversations' => $normalized,
        ];
    }
}

if (!function_exists('voiceAiBuildStaticComparison')) {
    function voiceAiBuildStaticComparison($current): array
    {
        return [
            'current' => $current,
            'previous' => null,
            'delta' => 0,
            'delta_pct' => null,
        ];
    }
}

if (!function_exists('voiceAiGetReportsCacheDir')) {
    function voiceAiGetReportsCacheDir(): string
    {
        return dirname(__DIR__) . '/cache/voice_ai_reports';
    }
}

if (!function_exists('voiceAiNormalizeReportFilters')) {
    function voiceAiNormalizeReportFilters(array $filters = []): array
    {
        $normalized = [];
        $integrationId = voiceAiResolveIntegrationId($filters['integration_id'] ?? null);
        if ($integrationId !== null) {
            $normalized['integration_id'] = $integrationId;
        }

        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $start = $startDate !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $startDate) : false;
        $end = $endDate !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $endDate) : false;

        if ($start && $end && $end < $start) {
            [$start, $end] = [$end, $start];
        }

        if ($start) {
            $normalized['start_date'] = $start->format('Y-m-d');
        } elseif ($startDate !== '') {
            $normalized['start_date'] = $startDate;
        }

        if ($end) {
            $normalized['end_date'] = $end->format('Y-m-d');
        } elseif ($endDate !== '') {
            $normalized['end_date'] = $endDate;
        }

        $interactionChannel = voiceAiNormalizeInteractionChannel((string) ($filters['interaction_channel'] ?? ''));
        if ($interactionChannel !== '') {
            $normalized['interaction_channel'] = $interactionChannel;
        }

        foreach (['direction', 'status', 'source'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $normalized[$key] = strtolower($value);
            }
        }

        foreach (['user_id', 'call_type', 'action_type'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $normalized['search'] = voiceAiLower($search);
        }

        if (!empty($filters['transcript_only'])) {
            $normalized['transcript_only'] = true;
        }

        $normalized['sort_order'] = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $normalized;
    }
}

if (!function_exists('voiceAiBuildReportsCacheKey')) {
    function voiceAiBuildReportsCacheKey(array $config, array $filters = [], bool $withComparison = true): string
    {
        $payload = [
            'payload_version' => '2026-03-29-multi-account-v1',
            'integration_id' => $config['integration_id'] ?? '',
            'location_id' => $config['location_id'] ?? '',
            'timezone' => $config['timezone'] ?? '',
            'page_size' => $config['page_size'] ?? '',
            'max_pages' => $config['max_pages'] ?? '',
            'interaction_page_size' => $config['interaction_page_size'] ?? '',
            'interaction_max_pages' => $config['interaction_max_pages'] ?? '',
            'with_comparison' => $withComparison,
            'filters' => voiceAiNormalizeReportFilters($filters),
        ];

        return sha1(voiceAiJsonEncode($payload));
    }
}

if (!function_exists('voiceAiReadReportsCache')) {
    function voiceAiReadReportsCache(string $cacheKey, int $ttlSeconds = 600): ?array
    {
        $cacheFile = voiceAiGetReportsCacheDir() . '/' . $cacheKey . '.json';
        if (!is_file($cacheFile)) {
            return null;
        }

        $modifiedAt = @filemtime($cacheFile);
        if ($modifiedAt === false || (time() - $modifiedAt) > $ttlSeconds) {
            return null;
        }

        $raw = @file_get_contents($cacheFile);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('voiceAiWriteReportsCache')) {
    function voiceAiWriteReportsCache(string $cacheKey, array $payload): void
    {
        $cacheDir = voiceAiGetReportsCacheDir();
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }

        if (!is_dir($cacheDir)) {
            return;
        }

        $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
        @file_put_contents($cacheFile, voiceAiJsonEncode($payload));
    }
}

if (!function_exists('voiceAiClearReportsCache')) {
    function voiceAiClearReportsCache(): void
    {
        $cacheDir = voiceAiGetReportsCacheDir();
        if (!is_dir($cacheDir)) {
            return;
        }

        $files = glob($cacheDir . '/*.json');
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}

if (!function_exists('voiceAiNormalizeInclusiveDateRange')) {
    function voiceAiNormalizeInclusiveDateRange(array $filters): array
    {
        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));

        if ($startDate === '' && $endDate === '') {
            return [];
        }

        if ($startDate === '') {
            $startDate = $endDate;
        }
        if ($endDate === '') {
            $endDate = $startDate;
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);

        if (!$start || !$end) {
            return [];
        }

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        return [
            'start_date' => $start->format('Y-m-d'),
            // The export API treats endDate as exclusive.
            'end_date' => $end->modify('+1 day')->format('Y-m-d'),
        ];
    }
}

if (!function_exists('voiceAiNormalizeInteractionChannel')) {
    function voiceAiNormalizeInteractionChannel(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        $map = [
            'sms' => 'SMS',
            'call' => 'Call',
            'calls' => 'Call',
            'whatsapp' => 'WhatsApp',
            'email' => 'Email',
        ];

        return $map[$normalized] ?? '';
    }
}

if (!function_exists('voiceAiBuildMessageExportQuery')) {
    function voiceAiBuildMessageExportQuery(array $config, array $filters = [], string $channel = '', ?string $cursor = null, ?int $limit = null): array
    {
        $query = [
            'locationId' => $config['location_id'],
            'limit' => max(10, min(100, $limit ?? (int) ($filters['interaction_page_size'] ?? $config['interaction_page_size']))),
        ];

        $dateRange = voiceAiNormalizeInclusiveDateRange($filters);
        if ($dateRange !== []) {
            $query['startDate'] = $dateRange['start_date'];
            $query['endDate'] = $dateRange['end_date'];
        }

        $channel = voiceAiNormalizeInteractionChannel($channel !== '' ? $channel : (string) ($filters['interaction_channel'] ?? ''));
        if ($channel !== '') {
            $query['channel'] = $channel;
        }

        if ($cursor !== null && trim($cursor) !== '') {
            $query['cursor'] = trim($cursor);
        }

        return $query;
    }
}

if (!function_exists('voiceAiNormalizeUser')) {
    function voiceAiNormalizeUser(array $raw): array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) (($raw['firstName'] ?? '') . ' ' . ($raw['lastName'] ?? '')));
        }
        if ($name === '') {
            $name = 'Sin usuario';
        }

        return [
            'id' => (string) ($raw['id'] ?? ''),
            'name' => $name,
            'email' => (string) ($raw['email'] ?? ''),
            'phone' => (string) ($raw['phone'] ?? ''),
            'role' => (string) ($raw['roles']['role'] ?? ''),
            'role_type' => (string) ($raw['roles']['type'] ?? ''),
            'deleted' => !empty($raw['deleted']),
            'scopes_count' => !empty($raw['scopes']) && is_array($raw['scopes']) ? count($raw['scopes']) : 0,
        ];
    }
}

if (!function_exists('voiceAiFetchUsers')) {
    function voiceAiFetchUsers(PDO $pdo): array
    {
        $configStatus = voiceAiGetConfigStatus($pdo);
        if (!$configStatus['is_ready']) {
            return [
                'success' => false,
                'message' => 'La integracion requiere API key y Location ID.',
            ];
        }

        $config = voiceAiGetConfig($pdo);
        $response = voiceAiHttpRequest($config, 'GET', '/users/', [
            'locationId' => $config['location_id'],
        ]);

        if (!$response['success']) {
            return [
                'success' => false,
                'message' => $response['message'],
                'status_code' => $response['status_code'] ?? 0,
            ];
        }

        $items = [];
        if (!empty($response['data']['users']) && is_array($response['data']['users'])) {
            $items = $response['data']['users'];
        }

        $users = array_map('voiceAiNormalizeUser', array_values(array_filter($items, 'is_array')));
        $userMap = [];
        foreach ($users as $user) {
            if ($user['id'] !== '') {
                $userMap[$user['id']] = $user;
            }
        }

        uasort($userMap, static function (array $left, array $right): int {
            return strcasecmp($left['name'], $right['name']);
        });

        return [
            'success' => true,
            'users' => array_values($userMap),
            'user_map' => $userMap,
        ];
    }
}

if (!function_exists('voiceAiNormalizePhoneNumberRecord')) {
    function voiceAiNormalizePhoneNumberRecord(array $raw): array
    {
        return [
            'phone_number' => (string) ($raw['phoneNumber'] ?? ''),
            'friendly_name' => trim((string) ($raw['friendlyName'] ?? '')),
            'sid' => (string) ($raw['sid'] ?? ''),
            'country_code' => (string) ($raw['countryCode'] ?? ''),
            'type' => (string) ($raw['type'] ?? ''),
            'is_default' => !empty($raw['isDefaultNumber']),
            'sms_enabled' => !empty($raw['capabilities']['sms']),
            'mms_enabled' => !empty($raw['capabilities']['mms']),
            'voice_enabled' => !empty($raw['capabilities']['voice']),
            'forwarding_number' => (string) ($raw['forwardingNumber'] ?? ''),
        ];
    }
}

if (!function_exists('voiceAiFetchPhoneNumbers')) {
    function voiceAiFetchPhoneNumbers(PDO $pdo): array
    {
        $configStatus = voiceAiGetConfigStatus($pdo);
        if (!$configStatus['is_ready']) {
            return [
                'success' => false,
                'message' => 'La integracion requiere API key y Location ID.',
            ];
        }

        $config = voiceAiGetConfig($pdo);
        $response = voiceAiHttpRequest(
            $config,
            'GET',
            '/phone-system/numbers/location/' . rawurlencode($config['location_id']),
            [
                'page' => 1,
                'pageSize' => 100,
            ]
        );

        if (!$response['success']) {
            return [
                'success' => false,
                'message' => $response['message'],
                'status_code' => $response['status_code'] ?? 0,
            ];
        }

        $items = [];
        if (!empty($response['data']['numbers']) && is_array($response['data']['numbers'])) {
            $items = $response['data']['numbers'];
        }

        $numbers = array_map('voiceAiNormalizePhoneNumberRecord', array_values(array_filter($items, 'is_array')));
        $numberMap = [];
        foreach ($numbers as $number) {
            if ($number['phone_number'] !== '') {
                $numberMap[$number['phone_number']] = $number;
            }
        }

        return [
            'success' => true,
            'numbers' => $numbers,
            'number_map' => $numberMap,
            'meta' => [
                'total' => count($numbers),
                'account_status' => (string) ($response['data']['accountStatus'] ?? ''),
                'is_under_ghl' => !empty($response['data']['isUnderGhl']),
            ],
        ];
    }
}

if (!function_exists('voiceAiNormalizeContactRecord')) {
    function voiceAiNormalizeContactRecord(array $raw): array
    {
        $firstName = trim((string) ($raw['firstName'] ?? ''));
        $lastName = trim((string) ($raw['lastName'] ?? ''));
        $fullName = trim((string) ($raw['name'] ?? ''));
        if ($fullName === '') {
            $fullName = trim($firstName . ' ' . $lastName);
        }
        if ($fullName === '') {
            $fullName = 'Sin contacto';
        }

        return [
            'id' => (string) ($raw['id'] ?? ''),
            'name' => $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => (string) ($raw['email'] ?? ''),
            'phone' => (string) ($raw['phone'] ?? ''),
            'company_name' => (string) ($raw['companyName'] ?? ''),
            'assigned_to' => (string) ($raw['assignedTo'] ?? ''),
            'tags' => !empty($raw['tags']) && is_array($raw['tags']) ? array_values($raw['tags']) : [],
        ];
    }
}

if (!function_exists('voiceAiFetchContactsByIds')) {
    function voiceAiFetchContactsByIds(PDO $pdo, array $contactIds): array
    {
        $configStatus = voiceAiGetConfigStatus($pdo);
        if (!$configStatus['is_ready']) {
            return [
                'success' => false,
                'message' => 'La integracion requiere API key y Location ID.',
            ];
        }

        $config = voiceAiGetConfig($pdo);
        $contactIds = array_values(array_unique(array_filter(array_map(static function ($id): string {
            return trim((string) $id);
        }, $contactIds))));

        $contactMap = [];
        foreach ($contactIds as $contactId) {
            $response = voiceAiHttpRequest($config, 'GET', '/contacts/' . rawurlencode($contactId));
            if (!$response['success']) {
                continue;
            }

            $payload = $response['data']['contact'] ?? null;
            if (!is_array($payload)) {
                continue;
            }

            $contact = voiceAiNormalizeContactRecord($payload);
            if ($contact['id'] !== '') {
                $contactMap[$contact['id']] = $contact;
            }
        }

        return [
            'success' => true,
            'contact_map' => $contactMap,
        ];
    }
}

if (!function_exists('voiceAiFetchAssignedConversationTotals')) {
    function voiceAiFetchAssignedConversationTotals(PDO $pdo, array $users): array
    {
        $configStatus = voiceAiGetConfigStatus($pdo);
        if (!$configStatus['is_ready']) {
            return [
                'success' => false,
                'message' => 'La integracion requiere API key y Location ID.',
            ];
        }

        $config = voiceAiGetConfig($pdo);
        $totals = [];

        foreach ($users as $user) {
            $userId = trim((string) ($user['id'] ?? ''));
            if ($userId === '') {
                continue;
            }

            $response = voiceAiHttpRequest($config, 'GET', '/conversations/search', [
                'locationId' => $config['location_id'],
                'limit' => 1,
                'assignedTo' => $userId,
            ]);

            if (!$response['success']) {
                continue;
            }

            $totals[$userId] = (int) ($response['data']['total'] ?? 0);
        }

        return [
            'success' => true,
            'totals' => $totals,
        ];
    }
}

if (!function_exists('voiceAiNormalizeInteractionBody')) {
    function voiceAiNormalizeInteractionBody(array $raw): string
    {
        $body = $raw['body'] ?? '';
        if (is_array($body)) {
            $body = implode(', ', array_map('strval', $body));
        }

        $body = trim(strip_tags((string) $body));
        if ($body !== '') {
            return preg_replace('/\s+/', ' ', $body) ?? $body;
        }

        if (!empty($raw['meta']['call']['status'])) {
            return 'Llamada ' . (string) $raw['meta']['call']['status'];
        }

        return '';
    }
}

if (!function_exists('voiceAiInferInteractionChannel')) {
    function voiceAiInferInteractionChannel(array $raw): string
    {
        $messageType = strtoupper((string) ($raw['messageType'] ?? ''));
        $type = (int) ($raw['type'] ?? 0);

        if ($messageType === 'TYPE_CALL' || $type === 1) {
            return 'Call';
        }
        if ($messageType === 'TYPE_SMS' || $type === 2) {
            return 'SMS';
        }
        if ($messageType === 'TYPE_EMAIL' || $type === 3) {
            return 'Email';
        }
        if (strpos($messageType, 'WHATSAPP') !== false) {
            return 'WhatsApp';
        }

        return $messageType !== '' ? $messageType : 'Unknown';
    }
}

if (!function_exists('voiceAiNormalizeRecipientValue')) {
    function voiceAiNormalizeRecipientValue($value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(static function ($item): string {
                return trim((string) $item);
            }, $value));
        }

        return trim((string) $value);
    }
}

if (!function_exists('voiceAiNormalizeInteractionMessage')) {
    function voiceAiNormalizeInteractionMessage(array $raw, array $userMap = [], array $contactMap = [], array $numberMap = []): array
    {
        $channel = voiceAiInferInteractionChannel($raw);
        $contactId = trim((string) ($raw['contactId'] ?? ''));
        $userId = trim((string) ($raw['userId'] ?? ''));
        $contact = $contactMap[$contactId] ?? null;
        $user = $userMap[$userId] ?? null;
        $direction = strtolower(trim((string) ($raw['direction'] ?? '')));
        $status = trim((string) ($raw['status'] ?? ($raw['meta']['call']['status'] ?? '')));
        $duration = 0;
        if (isset($raw['meta']['call']['duration']) && $raw['meta']['call']['duration'] !== null) {
            $duration = (int) $raw['meta']['call']['duration'];
        }

        $fromValue = voiceAiNormalizeRecipientValue($raw['from'] ?? '');
        $toValue = voiceAiNormalizeRecipientValue($raw['to'] ?? '');
        $counterpartyPhone = $direction === 'outbound' ? $toValue : $fromValue;
        $businessNumber = $direction === 'outbound' ? $fromValue : $toValue;
        if ($businessNumber !== '' && !isset($numberMap[$businessNumber]) && isset($numberMap[$counterpartyPhone])) {
            $businessNumber = $counterpartyPhone;
            $counterpartyPhone = $direction === 'outbound' ? $toValue : $fromValue;
        }

        $body = voiceAiNormalizeInteractionBody($raw);
        $timestamp = voiceAiToTimestamp($raw['dateAdded'] ?? null);
        $recordings = !empty($raw['attachments']) && is_array($raw['attachments']) ? array_values($raw['attachments']) : [];

        return [
            'id' => trim((string) ($raw['id'] ?? '')),
            'alt_id' => trim((string) ($raw['altId'] ?? '')),
            'conversation_id' => trim((string) ($raw['conversationId'] ?? '')),
            'contact_id' => $contactId,
            'contact_name' => $contact['name'] ?? ($counterpartyPhone !== '' ? $counterpartyPhone : ($contactId !== '' ? $contactId : 'Sin contacto')),
            'contact_phone' => $contact['phone'] ?? $counterpartyPhone,
            'contact_email' => $contact['email'] ?? '',
            'contact_company' => $contact['company_name'] ?? '',
            'assigned_to' => $contact['assigned_to'] ?? '',
            'user_id' => $userId,
            'user_name' => $user['name'] ?? ($userId !== '' ? $userId : 'Sin usuario'),
            'user_email' => $user['email'] ?? '',
            'channel' => $channel,
            'direction' => $direction !== '' ? $direction : 'unknown',
            'status' => $status !== '' ? $status : 'unknown',
            'source' => trim((string) ($raw['source'] ?? '')),
            'message_type' => trim((string) ($raw['messageType'] ?? '')),
            'type' => (int) ($raw['type'] ?? 0),
            'body' => $body,
            'from' => $fromValue,
            'to' => $toValue,
            'business_number' => $businessNumber,
            'counterparty_phone' => $counterpartyPhone,
            'date_added' => !empty($raw['dateAdded']) ? (string) $raw['dateAdded'] : '',
            'date_updated' => !empty($raw['dateUpdated']) ? (string) $raw['dateUpdated'] : '',
            'timestamp' => $timestamp,
            'duration_seconds' => $duration,
            'is_call' => $channel === 'Call',
            'is_email' => $channel === 'Email',
            'recording_urls' => $recordings,
            'has_recording' => !empty($recordings),
            'error' => trim((string) ($raw['error'] ?? '')),
        ];
    }
}

if (!function_exists('voiceAiApplyInteractionFilters')) {
    function voiceAiApplyInteractionFilters(array $items, array $filters = []): array
    {
        $search = voiceAiLower(trim((string) ($filters['search'] ?? '')));
        $direction = strtolower(trim((string) ($filters['direction'] ?? '')));
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $source = strtolower(trim((string) ($filters['source'] ?? '')));
        $userId = trim((string) ($filters['user_id'] ?? ''));
        $channel = voiceAiNormalizeInteractionChannel((string) ($filters['interaction_channel'] ?? ''));

        $startTs = null;
        $endTs = null;
        if (!empty($filters['start_date'])) {
            $startTs = strtotime((string) $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $endTs = strtotime((string) $filters['end_date'] . ' 23:59:59');
        }

        return array_values(array_filter($items, static function (array $item) use ($search, $direction, $status, $source, $userId, $channel, $startTs, $endTs): bool {
            $timestamp = (int) ($item['timestamp'] ?? 0);

            if ($startTs !== null && $timestamp > 0 && $timestamp < $startTs) {
                return false;
            }
            if ($endTs !== null && $timestamp > 0 && $timestamp > $endTs) {
                return false;
            }
            if ($direction !== '' && strtolower((string) ($item['direction'] ?? '')) !== $direction) {
                return false;
            }
            if ($status !== '' && strtolower((string) ($item['status'] ?? '')) !== $status) {
                return false;
            }
            if ($source !== '' && strtolower((string) ($item['source'] ?? '')) !== $source) {
                return false;
            }
            if ($userId !== '' && (string) ($item['user_id'] ?? '') !== $userId) {
                return false;
            }
            if ($channel !== '' && (string) ($item['channel'] ?? '') !== $channel) {
                return false;
            }

            if ($search !== '') {
                $haystack = voiceAiLower(implode(' ', [
                    (string) ($item['id'] ?? ''),
                    (string) ($item['contact_name'] ?? ''),
                    (string) ($item['contact_phone'] ?? ''),
                    (string) ($item['user_name'] ?? ''),
                    (string) ($item['body'] ?? ''),
                    (string) ($item['status'] ?? ''),
                    (string) ($item['source'] ?? ''),
                    (string) ($item['channel'] ?? ''),
                ]));

                if (strpos($haystack, $search) === false) {
                    return false;
                }
            }

            return true;
        }));
    }
}

if (!function_exists('voiceAiSortInteractions')) {
    function voiceAiSortInteractions(array $items, string $sortOrder = 'desc'): array
    {
        usort($items, static function (array $left, array $right) use ($sortOrder): int {
            $leftTs = (int) ($left['timestamp'] ?? 0);
            $rightTs = (int) ($right['timestamp'] ?? 0);

            if ($leftTs === $rightTs) {
                return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
            }

            if (strtolower($sortOrder) === 'asc') {
                return $leftTs <=> $rightTs;
            }

            return $rightTs <=> $leftTs;
        });

        return $items;
    }
}

if (!function_exists('voiceAiBuildInteractionAvailableFilters')) {
    function voiceAiBuildInteractionAvailableFilters(array $items, array $userMap = []): array
    {
        $channels = [];
        $directions = [];
        $statuses = [];
        $sources = [];
        $users = [];

        foreach ($items as $item) {
            $channel = (string) ($item['channel'] ?? '');
            $direction = (string) ($item['direction'] ?? '');
            $status = (string) ($item['status'] ?? '');
            $source = (string) ($item['source'] ?? '');
            $userId = (string) ($item['user_id'] ?? '');

            if ($channel !== '') {
                $channels[$channel] = true;
            }
            if ($direction !== '') {
                $directions[$direction] = true;
            }
            if ($status !== '') {
                $statuses[$status] = true;
            }
            if ($source !== '') {
                $sources[$source] = true;
            }
            if ($userId !== '') {
                $users[$userId] = [
                    'id' => $userId,
                    'name' => $userMap[$userId]['name'] ?? ((string) ($item['user_name'] ?? $userId)),
                ];
            }
        }

        ksort($channels);
        ksort($directions);
        ksort($statuses);
        ksort($sources);
        uasort($users, static function (array $left, array $right): int {
            return strcasecmp($left['name'], $right['name']);
        });

        return [
            'interaction_channels' => array_keys($channels),
            'interaction_directions' => array_keys($directions),
            'interaction_statuses' => array_keys($statuses),
            'interaction_sources' => array_keys($sources),
            'interaction_users' => array_values($users),
        ];
    }
}

if (!function_exists('voiceAiFetchInteractions')) {
    function voiceAiFetchInteractions(PDO $pdo, array $filters = []): array
    {
        $configStatus = voiceAiGetConfigStatus($pdo);
        if (!$configStatus['is_ready']) {
            return [
                'success' => false,
                'message' => 'La integracion requiere API key y Location ID.',
                'config_status' => $configStatus,
            ];
        }

        $config = voiceAiGetConfig($pdo);
        $usersResult = voiceAiFetchUsers($pdo);
        $userMap = $usersResult['success'] ? ($usersResult['user_map'] ?? []) : [];

        $numbersResult = voiceAiFetchPhoneNumbers($pdo);
        $numberMap = $numbersResult['success'] ? ($numbersResult['number_map'] ?? []) : [];

        $limit = isset($filters['interaction_page_size']) ? (int) $filters['interaction_page_size'] : $config['interaction_page_size'];
        $limit = max(10, min(100, $limit));
        $maxPages = isset($filters['interaction_max_pages']) ? (int) $filters['interaction_max_pages'] : $config['interaction_max_pages'];
        $maxPages = max(1, min(250, $maxPages));
        $sortOrder = (string) ($filters['sort_order'] ?? 'desc');
        $channel = voiceAiNormalizeInteractionChannel((string) ($filters['interaction_channel'] ?? ''));

        $allRawItems = [];
        $pagesFetched = 0;
        $apiTotal = null;
        $cursor = null;
        $signatures = [];
        $warnings = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = voiceAiHttpRequest(
                $config,
                'GET',
                '/conversations/messages/export',
                voiceAiBuildMessageExportQuery($config, $filters, $channel, $cursor, $limit)
            );

            if (!$response['success']) {
                if ($page === 1) {
                    return [
                        'success' => false,
                        'message' => $response['message'],
                        'status_code' => $response['status_code'] ?? 0,
                        'config_status' => $configStatus,
                    ];
                }

                $warnings[] = $response['message'];
                break;
            }

            $items = [];
            if (!empty($response['data']['messages']) && is_array($response['data']['messages'])) {
                $items = array_values(array_filter($response['data']['messages'], 'is_array'));
            }

            $pagesFetched++;
            $apiTotal = isset($response['data']['total']) ? (int) $response['data']['total'] : $apiTotal;
            $cursor = isset($response['data']['nextCursor']) ? trim((string) $response['data']['nextCursor']) : $cursor;

            if (empty($items)) {
                break;
            }

            $firstId = (string) ($items[0]['id'] ?? '');
            $lastId = (string) ($items[count($items) - 1]['id'] ?? '');
            $signature = $firstId . '|' . $lastId . '|' . count($items);
            if (isset($signatures[$signature])) {
                break;
            }
            $signatures[$signature] = true;

            foreach ($items as $item) {
                $allRawItems[] = $item;
            }

            if ($apiTotal !== null && count($allRawItems) >= $apiTotal) {
                break;
            }
            if (count($items) < $limit) {
                break;
            }
            if ($cursor === '') {
                break;
            }
        }

        $contactFrequency = [];
        foreach ($allRawItems as $rawItem) {
            $contactId = trim((string) ($rawItem['contactId'] ?? ''));
            if ($contactId !== '') {
                $contactFrequency[$contactId] = ($contactFrequency[$contactId] ?? 0) + 1;
            }
        }

        arsort($contactFrequency);
        $priorityContacts = array_slice(array_keys($contactFrequency), 0, 12);
        $contactsResult = voiceAiFetchContactsByIds($pdo, $priorityContacts);
        $contactMap = $contactsResult['success'] ? ($contactsResult['contact_map'] ?? []) : [];

        $normalized = array_map(static function (array $raw) use ($userMap, $contactMap, $numberMap): array {
            return voiceAiNormalizeInteractionMessage($raw, $userMap, $contactMap, $numberMap);
        }, $allRawItems);

        $filtered = voiceAiApplyInteractionFilters($normalized, $filters);
        $sorted = voiceAiSortInteractions($filtered, $sortOrder);

        return [
            'success' => true,
            'items' => $sorted,
            'all_items' => $normalized,
            'user_map' => $userMap,
            'contact_map' => $contactMap,
            'number_map' => $numberMap,
            'users' => $usersResult['users'] ?? [],
            'numbers' => $numbersResult['numbers'] ?? [],
            'available_filters' => voiceAiBuildInteractionAvailableFilters($normalized, $userMap),
            'meta' => [
                'pages_fetched' => $pagesFetched,
                'page_size' => $limit,
                'max_pages' => $maxPages,
                'fetched_count' => count($normalized),
                'filtered_count' => count($sorted),
                'api_total' => $apiTotal ?? count($normalized),
                'truncated' => $apiTotal !== null && count($normalized) < $apiTotal,
                'warnings' => $warnings,
            ],
            'config_status' => $configStatus,
        ];
    }
}

if (!function_exists('voiceAiFetchInteractionTotals')) {
    function voiceAiFetchInteractionTotals(PDO $pdo, array $filters = [], array $items = [], array $meta = []): array
    {
        $configStatus = voiceAiGetConfigStatus($pdo);
        if (!$configStatus['is_ready']) {
            return [
                'success' => false,
                'message' => 'La integracion requiere API key y Location ID.',
            ];
        }

        $config = voiceAiGetConfig($pdo);
        $selectedChannel = voiceAiNormalizeInteractionChannel((string) ($filters['interaction_channel'] ?? ''));
        $warnings = [];
        $totals = [
            'non_email' => 0,
            'call' => 0,
            'sms' => 0,
            'whatsapp' => 0,
            'email' => 0,
        ];

        foreach ($items as $item) {
            $channel = (string) ($item['channel'] ?? '');
            if ($channel === 'Call') {
                $totals['call']++;
            } elseif ($channel === 'SMS') {
                $totals['sms']++;
            } elseif ($channel === 'WhatsApp') {
                $totals['whatsapp']++;
            } elseif ($channel === 'Email') {
                $totals['email']++;
            }
        }

        $isComplete = empty($meta['truncated']);
        if ($selectedChannel === '') {
            $totals['non_email'] = $isComplete ? (int) ($meta['api_total'] ?? count($items)) : 0;
        } elseif ($selectedChannel === 'Email') {
            $totals['email'] = $isComplete ? (int) ($meta['api_total'] ?? count($items)) : $totals['email'];
        } else {
            $channelKey = strtolower($selectedChannel);
            if (isset($totals[$channelKey])) {
                $totals[$channelKey] = $isComplete ? (int) ($meta['api_total'] ?? count($items)) : $totals[$channelKey];
            }
        }

        $channelsToQuery = [];
        if ($selectedChannel === '') {
            if (!$isComplete) {
                $channelsToQuery = [
                    'non_email' => '',
                    'call' => 'Call',
                    'sms' => 'SMS',
                    'whatsapp' => 'WhatsApp',
                ];
            }
            $channelsToQuery['email'] = 'Email';
        } elseif (!$isComplete) {
            $channelsToQuery = [
                strtolower($selectedChannel) => $selectedChannel,
            ];
        }

        foreach ($channelsToQuery as $key => $channel) {
            $response = voiceAiHttpRequest(
                $config,
                'GET',
                '/conversations/messages/export',
                voiceAiBuildMessageExportQuery($config, $filters, $channel, null, 10)
            );

            if (!$response['success']) {
                $warnings[$key] = $response['message'];
                continue;
            }

            $totals[$key] = (int) ($response['data']['total'] ?? 0);
        }

        if ($selectedChannel !== '' && $selectedChannel !== 'Email') {
            $totals['non_email'] = $selectedChannel === '' ? $totals['non_email'] : max($totals['non_email'], (int) ($meta['api_total'] ?? count($items)));
        }

        $totals['tracked_total'] = $selectedChannel === 'Email'
            ? (int) ($totals['email'] ?? 0)
            : (($selectedChannel !== '' && $selectedChannel !== 'Email')
                ? (int) ($totals[strtolower($selectedChannel)] ?? count($items))
                : (int) (($totals['non_email'] ?? 0) + ($totals['email'] ?? 0)));

        return [
            'success' => true,
            'totals' => $totals,
            'warnings' => $warnings,
        ];
    }
}

if (!function_exists('voiceAiBuildInteractionsDashboard')) {
    function voiceAiBuildInteractionsDashboard(array $items, array $users, array $assignmentTotals, array $numbers, array $interactionTotals): array
    {
        $channels = [];
        $directions = [];
        $statuses = [];
        $sources = [];
        $timelineDays = [];
        $timelineHours = array_fill(0, 24, 0);
        $userRows = [];
        $contactRows = [];

        $callDurationTotal = 0;
        $recordedCallCount = 0;
        $callsFetched = 0;
        $messagesFetched = 0;
        $emailsFetched = 0;
        $usersWithActivity = [];

        $usersById = [];
        foreach ($users as $user) {
            $userId = (string) ($user['id'] ?? '');
            if ($userId === '') {
                continue;
            }
            $usersById[$userId] = $user;
        }

        foreach ($items as $item) {
            $channel = (string) ($item['channel'] ?? 'Unknown');
            $direction = (string) ($item['direction'] ?? 'unknown');
            $status = (string) ($item['status'] ?? 'unknown');
            $source = (string) ($item['source'] ?? '');
            $timestamp = (int) ($item['timestamp'] ?? 0);
            $userId = (string) ($item['user_id'] ?? '');
            $duration = (int) ($item['duration_seconds'] ?? 0);
            $contactKey = (string) ($item['contact_id'] ?? '') !== '' ? (string) $item['contact_id'] : ((string) ($item['counterparty_phone'] ?? '') !== '' ? (string) $item['counterparty_phone'] : (string) ($item['conversation_id'] ?? ''));

            $channels[$channel] = ($channels[$channel] ?? 0) + 1;
            $directions[$direction] = ($directions[$direction] ?? 0) + 1;
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            if ($source !== '') {
                $sources[$source] = ($sources[$source] ?? 0) + 1;
            }

            if ($timestamp > 0) {
                $dayKey = date('Y-m-d', $timestamp);
                $timelineDays[$dayKey] = ($timelineDays[$dayKey] ?? 0) + 1;
                $timelineHours[(int) date('G', $timestamp)]++;
            }

            if (!empty($item['is_call'])) {
                $callsFetched++;
                $callDurationTotal += $duration;
                $recordedCallCount += !empty($item['has_recording']) ? 1 : 0;
            } else {
                $messagesFetched++;
                if (!empty($item['is_email'])) {
                    $emailsFetched++;
                }
            }

            if ($userId !== '') {
                $usersWithActivity[$userId] = true;

                if (!isset($userRows[$userId])) {
                    $userRows[$userId] = [
                        'user_id' => $userId,
                        'user_name' => $item['user_name'] ?? ($usersById[$userId]['name'] ?? $userId),
                        'user_email' => $usersById[$userId]['email'] ?? ($item['user_email'] ?? ''),
                        'role' => $usersById[$userId]['role'] ?? '',
                        'assigned_conversations' => (int) ($assignmentTotals[$userId] ?? 0),
                        'interactions' => 0,
                        'calls' => 0,
                        'messages' => 0,
                        'emails' => 0,
                        'outbound' => 0,
                        'inbound' => 0,
                        'total_call_duration' => 0,
                        'recorded_calls' => 0,
                        'active_days' => [],
                        'first_activity_at' => '',
                        'last_activity_at' => '',
                    ];
                }

                $userRows[$userId]['interactions']++;
                if ($direction === 'outbound') {
                    $userRows[$userId]['outbound']++;
                } elseif ($direction === 'inbound') {
                    $userRows[$userId]['inbound']++;
                }
                if (!empty($item['is_call'])) {
                    $userRows[$userId]['calls']++;
                    $userRows[$userId]['total_call_duration'] += $duration;
                    $userRows[$userId]['recorded_calls'] += !empty($item['has_recording']) ? 1 : 0;
                } else {
                    $userRows[$userId]['messages']++;
                    if (!empty($item['is_email'])) {
                        $userRows[$userId]['emails']++;
                    }
                }

                if ($timestamp > 0) {
                    $dayKey = date('Y-m-d', $timestamp);
                    $hourKey = date('Y-m-d H', $timestamp);
                    if (!isset($userRows[$userId]['active_days'][$dayKey])) {
                        $userRows[$userId]['active_days'][$dayKey] = [
                            'min' => $timestamp,
                            'max' => $timestamp,
                            'hours' => [],
                        ];
                    }
                    $userRows[$userId]['active_days'][$dayKey]['min'] = min($userRows[$userId]['active_days'][$dayKey]['min'], $timestamp);
                    $userRows[$userId]['active_days'][$dayKey]['max'] = max($userRows[$userId]['active_days'][$dayKey]['max'], $timestamp);
                    $userRows[$userId]['active_days'][$dayKey]['hours'][$hourKey] = true;
                }

                if ($userRows[$userId]['first_activity_at'] === '' || $timestamp < voiceAiToTimestamp($userRows[$userId]['first_activity_at'])) {
                    $userRows[$userId]['first_activity_at'] = (string) ($item['date_added'] ?? '');
                }
                if ($userRows[$userId]['last_activity_at'] === '' || $timestamp > voiceAiToTimestamp($userRows[$userId]['last_activity_at'])) {
                    $userRows[$userId]['last_activity_at'] = (string) ($item['date_added'] ?? '');
                }
            }

            if ($contactKey !== '') {
                if (!isset($contactRows[$contactKey])) {
                    $contactRows[$contactKey] = [
                        'contact_id' => (string) ($item['contact_id'] ?? ''),
                        'contact_name' => (string) ($item['contact_name'] ?? 'Sin contacto'),
                        'contact_phone' => (string) ($item['contact_phone'] ?? $item['counterparty_phone'] ?? ''),
                        'contact_email' => (string) ($item['contact_email'] ?? ''),
                        'contact_company' => (string) ($item['contact_company'] ?? ''),
                        'assigned_to' => (string) ($item['assigned_to'] ?? ''),
                        'interactions' => 0,
                        'calls' => 0,
                        'messages' => 0,
                        'emails' => 0,
                        'last_activity_at' => '',
                    ];
                }

                $contactRows[$contactKey]['interactions']++;
                if (!empty($item['is_call'])) {
                    $contactRows[$contactKey]['calls']++;
                } else {
                    $contactRows[$contactKey]['messages']++;
                    if (!empty($item['is_email'])) {
                        $contactRows[$contactKey]['emails']++;
                    }
                }

                if ($contactRows[$contactKey]['last_activity_at'] === '' || $timestamp > voiceAiToTimestamp($contactRows[$contactKey]['last_activity_at'])) {
                    $contactRows[$contactKey]['last_activity_at'] = (string) ($item['date_added'] ?? '');
                }
            }
        }

        foreach ($usersById as $userId => $user) {
            if (!isset($userRows[$userId]) && !empty($assignmentTotals[$userId])) {
                $userRows[$userId] = [
                    'user_id' => $userId,
                    'user_name' => $user['name'],
                    'user_email' => $user['email'],
                    'role' => $user['role'],
                    'assigned_conversations' => (int) ($assignmentTotals[$userId] ?? 0),
                    'interactions' => 0,
                    'calls' => 0,
                    'messages' => 0,
                    'emails' => 0,
                    'outbound' => 0,
                    'inbound' => 0,
                    'total_call_duration' => 0,
                    'recorded_calls' => 0,
                    'active_days' => [],
                    'first_activity_at' => '',
                    'last_activity_at' => '',
                ];
            }
        }

        foreach ($userRows as &$userRow) {
            $activeHourBuckets = 0;
            $spanSeconds = 0;

            foreach ($userRow['active_days'] as $activeDay) {
                $activeHourBuckets += count($activeDay['hours']);
                $spanSeconds += max(0, (int) $activeDay['max'] - (int) $activeDay['min']);
            }

            $daysActive = count($userRow['active_days']);
            $userRow['days_active'] = $daysActive;
            $userRow['active_hours_estimated'] = $activeHourBuckets;
            $userRow['avg_daily_window_seconds'] = $daysActive > 0 ? (int) round($spanSeconds / $daysActive) : 0;
            $userRow['avg_call_duration_seconds'] = $userRow['calls'] > 0 ? (int) round($userRow['total_call_duration'] / $userRow['calls']) : 0;
            unset($userRow['active_days']);
        }
        unset($userRow);

        usort($userRows, static function (array $left, array $right): int {
            if ($left['interactions'] === $right['interactions']) {
                return $right['calls'] <=> $left['calls'];
            }
            return $right['interactions'] <=> $left['interactions'];
        });

        usort($contactRows, static function (array $left, array $right): int {
            if ($left['interactions'] === $right['interactions']) {
                return $right['calls'] <=> $left['calls'];
            }
            return $right['interactions'] <=> $left['interactions'];
        });

        $queueByUser = [];
        foreach ($assignmentTotals as $userId => $total) {
            $queueByUser[] = [
                'user_id' => $userId,
                'user_name' => $usersById[$userId]['name'] ?? $userId,
                'assigned_conversations' => (int) $total,
                'email' => $usersById[$userId]['email'] ?? '',
                'role' => $usersById[$userId]['role'] ?? '',
            ];
        }
        usort($queueByUser, static function (array $left, array $right): int {
            return $right['assigned_conversations'] <=> $left['assigned_conversations'];
        });

        ksort($timelineDays);
        arsort($channels);
        arsort($directions);
        arsort($statuses);
        arsort($sources);

        $exactCallTotal = (int) ($interactionTotals['call'] ?? $callsFetched);
        $exactSmsTotal = (int) ($interactionTotals['sms'] ?? 0);
        $exactWhatsappTotal = (int) ($interactionTotals['whatsapp'] ?? 0);
        $exactEmailTotal = (int) ($interactionTotals['email'] ?? $emailsFetched);
        $trackedTotal = (int) ($interactionTotals['tracked_total'] ?? count($items));

        return [
            'kpis' => [
                'total_interactions' => [
                    'value' => $trackedTotal,
                    'formatted' => number_format($trackedTotal),
                    'label' => 'Interacciones',
                    'icon' => 'fa-comments',
                    'color' => 'cyan',
                    'comparison' => voiceAiBuildStaticComparison($trackedTotal),
                ],
                'total_calls_inbox' => [
                    'value' => $exactCallTotal,
                    'formatted' => number_format($exactCallTotal),
                    'label' => 'Llamadas inbox',
                    'icon' => 'fa-phone',
                    'color' => 'emerald',
                    'comparison' => voiceAiBuildStaticComparison($exactCallTotal),
                ],
                'total_sms_inbox' => [
                    'value' => $exactSmsTotal,
                    'formatted' => number_format($exactSmsTotal),
                    'label' => 'SMS inbox',
                    'icon' => 'fa-message',
                    'color' => 'amber',
                    'comparison' => voiceAiBuildStaticComparison($exactSmsTotal),
                ],
                'total_email_inbox' => [
                    'value' => $exactEmailTotal,
                    'formatted' => number_format($exactEmailTotal),
                    'label' => 'Emails',
                    'icon' => 'fa-envelope',
                    'color' => 'blue',
                    'comparison' => voiceAiBuildStaticComparison($exactEmailTotal),
                ],
                'users_with_activity' => [
                    'value' => count($usersWithActivity),
                    'formatted' => number_format(count($usersWithActivity)),
                    'label' => 'Usuarios activos',
                    'icon' => 'fa-user-clock',
                    'color' => 'orange',
                    'comparison' => voiceAiBuildStaticComparison(count($usersWithActivity)),
                ],
                'active_numbers' => [
                    'value' => count($numbers),
                    'formatted' => number_format(count($numbers)),
                    'label' => 'Numeros activos',
                    'icon' => 'fa-sim-card',
                    'color' => 'indigo',
                    'comparison' => voiceAiBuildStaticComparison(count($numbers)),
                ],
                'call_duration_total' => [
                    'value' => $callDurationTotal,
                    'formatted' => voiceAiFormatDuration($callDurationTotal),
                    'label' => 'Duracion llamadas',
                    'icon' => 'fa-clock',
                    'color' => 'emerald',
                    'comparison' => voiceAiBuildStaticComparison($callDurationTotal),
                ],
                'avg_call_duration' => [
                    'value' => $callsFetched > 0 ? (int) round($callDurationTotal / $callsFetched) : 0,
                    'formatted' => voiceAiFormatDuration($callsFetched > 0 ? (int) round($callDurationTotal / $callsFetched) : 0),
                    'label' => 'Promedio llamada',
                    'icon' => 'fa-stopwatch',
                    'color' => 'blue',
                    'comparison' => voiceAiBuildStaticComparison($callsFetched > 0 ? (int) round($callDurationTotal / $callsFetched) : 0),
                ],
            ],
            'distributions' => [
                'channels' => $channels,
                'directions' => $directions,
                'statuses' => $statuses,
                'sources' => $sources,
            ],
            'timeline' => [
                'by_day' => $timelineDays,
                'by_hour' => array_combine(
                    array_map(static function (int $hour): string {
                        return sprintf('%02d:00', $hour);
                    }, range(0, 23)),
                    $timelineHours
                ),
            ],
            'summary' => [
                'tracked_total' => $trackedTotal,
                'non_email_total' => (int) ($interactionTotals['non_email'] ?? count($items)),
                'call_total' => $exactCallTotal,
                'sms_total' => $exactSmsTotal,
                'whatsapp_total' => $exactWhatsappTotal,
                'email_total' => $exactEmailTotal,
                'calls_fetched' => $callsFetched,
                'messages_fetched' => $messagesFetched,
                'emails_fetched' => $emailsFetched,
                'recorded_call_count' => $recordedCallCount,
                'assigned_conversations_total' => array_sum($assignmentTotals),
            ],
            'users' => array_slice($userRows, 0, 20),
            'contacts' => array_slice($contactRows, 0, 20),
            'queue_by_user' => array_slice($queueByUser, 0, 20),
            'numbers' => array_slice($numbers, 0, 20),
            'recent_interactions' => array_slice($items, 0, 100),
            'recent_calls' => array_slice(array_values(array_filter($items, static function (array $item): bool {
                return !empty($item['is_call']);
            })), 0, 100),
            'recent_messages' => array_slice(array_values(array_filter($items, static function (array $item): bool {
                return empty($item['is_call']);
            })), 0, 100),
        ];
    }
}

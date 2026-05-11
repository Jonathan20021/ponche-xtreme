<?php
/**
 * Inventory AI helpers — Claude (Anthropic) integration.
 *
 * Everything (prompts, model, toggle, thresholds) is read from system_settings,
 * per project policy. See settings.php > "Inventario IA" section.
 *
 * Public API:
 *   - inv_ai_config(PDO): array
 *   - inv_ai_extract_json(string): ?array
 *   - inv_ai_categorize_item(PDO, string $itemName): array
 *   - inv_ai_predict_consumption(PDO, int $itemTypeId): array
 *   - inv_ai_detect_anomalies(PDO, int $itemTypeId): array
 *   - inv_ai_chat(PDO, int $userId, string $userMessage): array
 *   - inv_ai_build_inventory_snapshot(PDO, int $maxItems = 40): string
 */

require_once __DIR__ . '/claude_api_client.php';
require_once __DIR__ . '/inventory_functions.php';

if (!function_exists('inv_ai_config')) {
    function inv_ai_config(PDO $pdo): array
    {
        $defaults = [
            'inventory_ai_enabled'                 => '1',
            'inventory_ai_model'                   => '',
            'inventory_ai_chat_system_prompt'      => '',
            'inventory_ai_categorize_prompt'       => '',
            'inventory_ai_predict_prompt'          => '',
            'inventory_ai_anomaly_prompt'          => '',
            'inventory_ai_low_stock_threshold_pct' => '20',
            'inventory_ai_max_chat_history'        => '20',
            'inventory_ai_predict_days'            => '90',
            'inventory_ai_auto_categorize_on_create' => '1',
        ];
        $keys = array_keys($defaults);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        $out = $defaults;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['setting_key']] = (string) $row['setting_value'];
        }
        if (trim($out['inventory_ai_model']) === '') {
            $out['inventory_ai_model'] = resolveAnthropicDefaultModel($pdo);
        }
        return $out;
    }
}

if (!function_exists('inv_ai_extract_json')) {
    /** Strip ``` fences and try to find a JSON object/array in the response. */
    function inv_ai_extract_json(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/```\s*$/', '', $raw);
        $raw = trim($raw);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($raw, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) return $decoded;
        }
        return null;
    }
}

if (!function_exists('inv_ai_categorize_item')) {
    /**
     * Ask Claude to categorize a new item based on its name.
     * @return array{success:bool, suggestion:?array, error:?string}
     */
    function inv_ai_categorize_item(PDO $pdo, string $itemName): array
    {
        $cfg = inv_ai_config($pdo);
        if ($cfg['inventory_ai_enabled'] !== '1') {
            return ['success' => false, 'suggestion' => null, 'error' => 'IA de inventario deshabilitada'];
        }
        $itemName = trim($itemName);
        if ($itemName === '') {
            return ['success' => false, 'suggestion' => null, 'error' => 'Nombre vacio'];
        }

        $cats = $pdo->query("SELECT id, name, description FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $catList = array_map(static function ($c) {
            $desc = trim((string) ($c['description'] ?? ''));
            $line = "- id={$c['id']} | {$c['name']}";
            if ($desc !== '') $line .= " ($desc)";
            return $line;
        }, $cats);

        $user = "Articulo nuevo: \"$itemName\"\n\nCategorias disponibles:\n" . implode("\n", $catList);
        $resp = callClaudeAPI([
            'model'         => $cfg['inventory_ai_model'],
            'system_prompt' => $cfg['inventory_ai_categorize_prompt'],
            'user_prompt'   => $user,
            'max_tokens'    => 400,
            'temperature'   => 0.2,
            'pdo'           => $pdo,
        ]);
        if (!$resp['success']) {
            return ['success' => false, 'suggestion' => null, 'error' => $resp['error']];
        }
        $json = inv_ai_extract_json($resp['content']);
        if (!$json) {
            return ['success' => false, 'suggestion' => null, 'error' => 'Respuesta IA no es JSON valido'];
        }

        $catId = (int) ($json['category_id'] ?? 0);
        $valid = false;
        foreach ($cats as $c) if ((int) $c['id'] === $catId) { $valid = true; break; }
        if (!$valid) {
            return ['success' => false, 'suggestion' => null, 'error' => 'category_id sugerido no existe'];
        }

        return [
            'success'    => true,
            'suggestion' => [
                'category_id'   => $catId,
                'description'   => trim((string) ($json['description'] ?? '')),
                'unit'          => trim((string) ($json['unit'] ?? 'unidad')) ?: 'unidad',
                'is_consumable' => isset($json['is_consumable']) ? (int) (bool) $json['is_consumable'] : 1,
                'track_lots'    => isset($json['track_lots'])    ? (int) (bool) $json['track_lots']    : 0,
                'min_stock'     => (float) ($json['min_stock']   ?? 0),
            ],
            'error'      => null,
        ];
    }
}

if (!function_exists('inv_ai_predict_consumption')) {
    /**
     * Predict days-until-stockout and reorder qty for an item.
     * @return array{success:bool, prediction:?array, error:?string}
     */
    function inv_ai_predict_consumption(PDO $pdo, int $itemTypeId): array
    {
        $cfg = inv_ai_config($pdo);
        if ($cfg['inventory_ai_enabled'] !== '1') {
            return ['success' => false, 'prediction' => null, 'error' => 'IA deshabilitada'];
        }
        $days = max(7, (int) $cfg['inventory_ai_predict_days']);

        $itemStmt = $pdo->prepare("SELECT name, unit, current_stock, min_stock, max_stock FROM inventory_item_types WHERE id = ?");
        $itemStmt->execute([$itemTypeId]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) return ['success' => false, 'prediction' => null, 'error' => 'Item no existe'];

        $history = inv_get_consumption_history($pdo, $itemTypeId, $days);
        if (count($history) < 3) {
            return [
                'success'    => false,
                'prediction' => null,
                'error'      => 'Datos insuficientes (menos de 3 dias de historial)',
            ];
        }

        $lines = [];
        foreach ($history as $row) {
            $lines[] = "{$row['day']}: " . inv_format_qty((float) $row['units_out'], $item['unit']);
        }

        $user = "Articulo: \"{$item['name']}\"\n"
              . "Unidad: {$item['unit']}\n"
              . "Stock actual: " . inv_format_qty((float) $item['current_stock'], $item['unit']) . "\n"
              . "Stock minimo: " . inv_format_qty((float) $item['min_stock'], $item['unit']) . "\n"
              . "Historial de salidas (ultimos $days dias):\n" . implode("\n", $lines);

        $resp = callClaudeAPI([
            'model'         => $cfg['inventory_ai_model'],
            'system_prompt' => $cfg['inventory_ai_predict_prompt'],
            'user_prompt'   => $user,
            'max_tokens'    => 500,
            'temperature'   => 0.2,
            'pdo'           => $pdo,
        ]);
        if (!$resp['success']) return ['success' => false, 'prediction' => null, 'error' => $resp['error']];

        $json = inv_ai_extract_json($resp['content']);
        if (!$json) return ['success' => false, 'prediction' => null, 'error' => 'Respuesta IA no es JSON valido'];

        return ['success' => true, 'prediction' => $json, 'error' => null];
    }
}

if (!function_exists('inv_ai_detect_anomalies')) {
    /**
     * Detect anomalies in weekly consumption.
     * @return array{success:bool, anomaly:?array, error:?string}
     */
    function inv_ai_detect_anomalies(PDO $pdo, int $itemTypeId): array
    {
        $cfg = inv_ai_config($pdo);
        if ($cfg['inventory_ai_enabled'] !== '1') {
            return ['success' => false, 'anomaly' => null, 'error' => 'IA deshabilitada'];
        }

        $itemStmt = $pdo->prepare("SELECT name, unit, current_stock FROM inventory_item_types WHERE id = ?");
        $itemStmt->execute([$itemTypeId]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) return ['success' => false, 'anomaly' => null, 'error' => 'Item no existe'];

        $weeks = inv_get_weekly_consumption($pdo, $itemTypeId, 12);
        if (count($weeks) < 4) {
            return ['success' => false, 'anomaly' => null, 'error' => 'Historial semanal insuficiente'];
        }

        $lines = [];
        foreach ($weeks as $w) {
            $lines[] = "Semana de {$w['week_start']}: " . inv_format_qty((float) $w['units_out'], $item['unit']);
        }

        $user = "Articulo: \"{$item['name']}\"\n"
              . "Unidad: {$item['unit']}\n"
              . "Stock actual: " . inv_format_qty((float) $item['current_stock'], $item['unit']) . "\n"
              . "Consumo semanal (ultimas 12 semanas o menos):\n" . implode("\n", $lines);

        $resp = callClaudeAPI([
            'model'         => $cfg['inventory_ai_model'],
            'system_prompt' => $cfg['inventory_ai_anomaly_prompt'],
            'user_prompt'   => $user,
            'max_tokens'    => 500,
            'temperature'   => 0.2,
            'pdo'           => $pdo,
        ]);
        if (!$resp['success']) return ['success' => false, 'anomaly' => null, 'error' => $resp['error']];

        $json = inv_ai_extract_json($resp['content']);
        if (!$json) return ['success' => false, 'anomaly' => null, 'error' => 'Respuesta IA no es JSON valido'];

        return ['success' => true, 'anomaly' => $json, 'error' => null];
    }
}

if (!function_exists('inv_ai_build_inventory_snapshot')) {
    /**
     * Build a compact textual snapshot of inventory state for the chat assistant.
     * Limited to top-$maxItems items by stock value so it fits in context.
     */
    function inv_ai_build_inventory_snapshot(PDO $pdo, int $maxItems = 40): string
    {
        $summary = inv_get_stock_summary($pdo);

        $stmt = $pdo->prepare("
            SELECT it.id, it.name, it.unit, it.current_stock, it.min_stock, it.max_stock,
                   it.unit_cost, it.is_consumable, it.track_lots, c.name AS category_name
            FROM inventory_item_types it
            JOIN inventory_categories c ON c.id = it.category_id
            WHERE it.is_active = 1
            ORDER BY (it.current_stock * COALESCE(it.unit_cost, 0)) DESC, it.name ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $maxItems, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $low = inv_get_low_stock_items($pdo, 20);
        $expiring = inv_get_expiring_lots($pdo, 30, 20);

        $out = [];
        $out[] = "## Resumen general";
        $out[] = "- Items activos: {$summary['total_items']}";
        $out[] = "- Unidades en stock: " . number_format($summary['total_units'], 2);
        $out[] = "- Items con stock bajo: {$summary['low_stock']}";
        $out[] = "- Items agotados: {$summary['out_of_stock']}";
        $out[] = "- Lotes proximos a vencer (30d): {$summary['expiring_soon']}";
        $out[] = "- Valor total inventario: \$" . number_format($summary['total_value'], 2);

        $out[] = "\n## Stock por item (top $maxItems)";
        $out[] = "| Item | Categoria | Stock | Unidad | Min | Costo |";
        $out[] = "|---|---|---|---|---|---|";
        foreach ($items as $it) {
            $cost = $it['unit_cost'] !== null ? '$' . number_format((float) $it['unit_cost'], 2) : '-';
            $out[] = sprintf(
                "| %s | %s | %s | %s | %s | %s |",
                $it['name'],
                $it['category_name'],
                inv_format_qty((float) $it['current_stock']),
                $it['unit'],
                inv_format_qty((float) $it['min_stock']),
                $cost
            );
        }

        if (!empty($low)) {
            $out[] = "\n## Items con stock bajo (urgente)";
            foreach ($low as $l) {
                $out[] = "- {$l['name']} ({$l['category_name']}): "
                       . inv_format_qty((float) $l['current_stock'], $l['unit'])
                       . " / minimo "
                       . inv_format_qty((float) $l['min_stock'], $l['unit']);
            }
        }

        if (!empty($expiring)) {
            $out[] = "\n## Lotes proximos a vencer";
            foreach ($expiring as $e) {
                $out[] = "- {$e['item_name']} lote " . ($e['lot_code'] ?: '(sin codigo)')
                       . ": " . inv_format_qty((float) $e['quantity_remaining'], $e['unit'])
                       . " - vence {$e['expiration_date']} ({$e['days_to_expire']}d)";
            }
        }

        // Last 20 movements for context
        $recent = inv_get_recent_movements($pdo, 20);
        if (!empty($recent)) {
            $out[] = "\n## Movimientos recientes (20 ultimos)";
            foreach ($recent as $m) {
                $who = $m['employee_name'] ?: ($m['performed_by_name'] ?: 'N/A');
                $qty = inv_format_qty((float) $m['quantity'], $m['unit']);
                $out[] = "- {$m['performed_at']} | {$m['movement_type']} | {$m['item_name']} | $qty | {$who}";
            }
        }

        return implode("\n", $out);
    }
}

if (!function_exists('inv_ai_chat')) {
    /**
     * Multi-turn chat with full inventory snapshot in context.
     * @return array{success:bool, reply:?string, error:?string, tokens:?array}
     */
    function inv_ai_chat(PDO $pdo, ?int $userId, string $userMessage): array
    {
        $cfg = inv_ai_config($pdo);
        if ($cfg['inventory_ai_enabled'] !== '1') {
            return ['success' => false, 'reply' => null, 'error' => 'IA deshabilitada', 'tokens' => null];
        }
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            return ['success' => false, 'reply' => null, 'error' => 'Mensaje vacio', 'tokens' => null];
        }

        $snapshot = inv_ai_build_inventory_snapshot($pdo);

        // Pull prior turns (last N) for this user
        $history = [];
        if ($userId) {
            $maxHistory = max(2, (int) $cfg['inventory_ai_max_chat_history']);
            $hist = $pdo->prepare("SELECT role, message FROM inventory_ai_chats
                WHERE user_id = ? AND role IN ('user','assistant')
                ORDER BY id DESC LIMIT ?");
            $hist->bindValue(1, $userId,      PDO::PARAM_INT);
            $hist->bindValue(2, $maxHistory,  PDO::PARAM_INT);
            $hist->execute();
            $rows = array_reverse($hist->fetchAll(PDO::FETCH_ASSOC));
            foreach ($rows as $r) {
                $history[] = $r['role'] . ': ' . $r['message'];
            }
        }
        $historyBlock = $history ? "\n\n## Conversacion previa\n" . implode("\n\n", $history) : '';

        $userPrompt = "## Contexto actual del inventario\n\n$snapshot$historyBlock\n\n## Pregunta del usuario\n\n$userMessage";

        $resp = callClaudeAPI([
            'model'         => $cfg['inventory_ai_model'],
            'system_prompt' => $cfg['inventory_ai_chat_system_prompt'],
            'user_prompt'   => $userPrompt,
            'max_tokens'    => 1500,
            'temperature'   => 0.4,
            'pdo'           => $pdo,
        ]);
        if (!$resp['success']) {
            return ['success' => false, 'reply' => null, 'error' => $resp['error'], 'tokens' => $resp['usage'] ?? null];
        }

        // Persist both turns
        $insert = $pdo->prepare("INSERT INTO inventory_ai_chats (user_id, role, message, tokens_used) VALUES (?, ?, ?, ?)");
        try {
            $insert->execute([$userId, 'user', $userMessage, null]);
            $tokensUsed = $resp['usage']['output_tokens'] ?? null;
            $insert->execute([$userId, 'assistant', $resp['content'], $tokensUsed]);
        } catch (Throwable $e) {
            error_log('inv_ai_chat persist: ' . $e->getMessage());
        }

        return ['success' => true, 'reply' => $resp['content'], 'error' => null, 'tokens' => $resp['usage'] ?? null];
    }
}

if (!function_exists('inv_ai_save_insight')) {
    function inv_ai_save_insight(PDO $pdo, array $data): int
    {
        $stmt = $pdo->prepare("INSERT INTO inventory_ai_insights
            (item_type_id, insight_type, severity, title, description, payload_json)
            VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            !empty($data['item_type_id']) ? (int) $data['item_type_id'] : null,
            $data['insight_type'] ?? 'SUGGESTION',
            $data['severity'] ?? 'LOW',
            $data['title'] ?? '',
            $data['description'] ?? '',
            isset($data['payload']) ? json_encode($data['payload'], JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int) $pdo->lastInsertId();
    }
}

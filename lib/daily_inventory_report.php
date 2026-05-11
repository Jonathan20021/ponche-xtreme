<?php
/**
 * Daily Inventory Report
 *
 * Snapshot of the inventory module:
 *   - Global totals (items, units, value, low stock, out of stock, expiring soon)
 *   - Per-category breakdown (items, units, value)
 *   - Low / out-of-stock items (most urgent first)
 *   - Lots near expiration (within configurable window)
 *   - Top consumed items in the last N days
 *   - Last N movements (latest ledger entries)
 *   - Active employee assignments grouped by item type
 *   - Optional Claude executive summary
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/claude_api_client.php';
require_once __DIR__ . '/inventory_functions.php';

if (!function_exists('getInventoryReportSettings')) {
    function getInventoryReportSettings(PDO $pdo): array
    {
        $defaults = [
            'inventory_report_enabled'              => '0',
            'inventory_report_time'                 => '08:00',
            'inventory_report_recipients'           => '',
            'inventory_report_exclude_weekends'     => '1',
            'inventory_report_only_with_alerts'     => '0',
            'inventory_report_expiring_days'        => '30',
            'inventory_report_consumption_days'     => '30',
            'inventory_report_movements_limit'      => '20',
            'inventory_report_claude_enabled'       => '0',
            'inventory_report_claude_model'         => 'claude-sonnet-4-6',
            'inventory_report_claude_max_tokens'    => '900',
            'inventory_report_claude_prompt'        => '',
        ];
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'inventory_report_%'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $defaults[$row['setting_key']] = $row['setting_value'] ?? '';
            }
        } catch (PDOException $e) {
            error_log('getInventoryReportSettings: ' . $e->getMessage());
        }
        return $defaults;
    }
}

if (!function_exists('getInventoryReportRecipients')) {
    function getInventoryReportRecipients(PDO $pdo): array
    {
        $raw = (string) (getInventoryReportSettings($pdo)['inventory_report_recipients'] ?? '');
        if ($raw === '') return [];
        $emails = array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []);
        return array_values(array_filter($emails, static fn($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

if (!function_exists('inventoryReportSpanishDate')) {
    function inventoryReportSpanishDate(string $date): string
    {
        $days = ['Sunday' => 'Domingo', 'Monday' => 'Lunes', 'Tuesday' => 'Martes',
                 'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes',
                 'Saturday' => 'Sábado'];
        $months = ['January' => 'enero', 'February' => 'febrero', 'March' => 'marzo',
                   'April' => 'abril', 'May' => 'mayo', 'June' => 'junio', 'July' => 'julio',
                   'August' => 'agosto', 'September' => 'septiembre', 'October' => 'octubre',
                   'November' => 'noviembre', 'December' => 'diciembre'];
        $ts = strtotime($date);
        if ($ts === false) return $date;
        return sprintf('%s, %d de %s de %s',
            $days[date('l', $ts)] ?? date('l', $ts),
            (int) date('j', $ts),
            $months[date('F', $ts)] ?? date('F', $ts),
            date('Y', $ts));
    }
}

if (!function_exists('generateDailyInventoryReport')) {
    /**
     * Builds the inventory snapshot for the target date.
     */
    function generateDailyInventoryReport(PDO $pdo, ?string $date = null, array $overrides = []): array
    {
        $date = $date ?: date('Y-m-d');
        $settings = getInventoryReportSettings($pdo);

        $expiringDays    = (int) ($overrides['expiring_days']    ?? $settings['inventory_report_expiring_days']    ?? 30);
        $consumptionDays = (int) ($overrides['consumption_days'] ?? $settings['inventory_report_consumption_days'] ?? 30);
        $movementsLimit  = (int) ($overrides['movements_limit']  ?? $settings['inventory_report_movements_limit']  ?? 20);

        if ($expiringDays    < 1)  $expiringDays    = 30;
        if ($consumptionDays < 1)  $consumptionDays = 30;
        if ($movementsLimit  < 1)  $movementsLimit  = 20;

        // --- Global totals
        $summary = inv_get_stock_summary($pdo, $expiringDays);

        // --- Per-category breakdown
        $catStmt = $pdo->query("
            SELECT c.id, c.name, c.icon, c.color,
                   COUNT(it.id)                                   AS items_count,
                   COALESCE(SUM(it.current_stock), 0)             AS units_total,
                   COALESCE(SUM(it.current_stock * COALESCE(it.unit_cost, 0)), 0) AS value_total,
                   COALESCE(SUM(CASE WHEN it.current_stock <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
                   COALESCE(SUM(CASE WHEN it.current_stock > 0 AND it.min_stock > 0 AND it.current_stock <= it.min_stock THEN 1 ELSE 0 END), 0) AS low_stock
            FROM inventory_categories c
            LEFT JOIN inventory_item_types it ON it.category_id = c.id AND it.is_active = 1
            GROUP BY c.id, c.name, c.icon, c.color
            ORDER BY value_total DESC, items_count DESC, c.name ASC
        ");
        $byCategory = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($byCategory as &$cRow) {
            $cRow['items_count']  = (int)   $cRow['items_count'];
            $cRow['units_total']  = (float) $cRow['units_total'];
            $cRow['value_total']  = (float) $cRow['value_total'];
            $cRow['out_of_stock'] = (int)   $cRow['out_of_stock'];
            $cRow['low_stock']    = (int)   $cRow['low_stock'];
        }
        unset($cRow);

        // --- Low / out of stock items (urgent first: out of stock then largest deficit)
        $lowStmt = $pdo->query("
            SELECT it.id, it.name, it.unit, it.current_stock, it.min_stock, it.max_stock,
                   it.reorder_qty, it.unit_cost, it.is_consumable,
                   c.name AS category_name, c.color AS category_color
            FROM inventory_item_types it
            JOIN inventory_categories c ON c.id = it.category_id
            WHERE it.is_active = 1
              AND (
                    (it.min_stock > 0 AND it.current_stock <= it.min_stock)
                 OR it.current_stock <= 0
              )
            ORDER BY
              (it.current_stock <= 0) DESC,
              (it.min_stock - it.current_stock) DESC
            LIMIT 50
        ");
        $lowStock = $lowStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // --- Lots near expiration
        $expStmt = $pdo->prepare("
            SELECT l.id, l.lot_code, l.quantity_remaining, l.received_date, l.expiration_date,
                   DATEDIFF(l.expiration_date, CURDATE()) AS days_to_expire,
                   it.id AS item_type_id, it.name AS item_name, it.unit,
                   c.name AS category_name, c.color AS category_color,
                   s.name AS supplier_name
            FROM inventory_lots l
            JOIN inventory_item_types it ON it.id = l.item_type_id
            JOIN inventory_categories  c  ON c.id = it.category_id
            LEFT JOIN inventory_suppliers s ON s.id = l.supplier_id
            WHERE l.quantity_remaining > 0
              AND l.expiration_date IS NOT NULL
              AND l.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY l.expiration_date ASC
            LIMIT 50
        ");
        $expStmt->bindValue(1, $expiringDays, PDO::PARAM_INT);
        $expStmt->execute();
        $expiringLots = $expStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // --- Top consumed items in last N days
        $topConsStmt = $pdo->prepare("
            SELECT it.id, it.name, it.unit, it.current_stock, it.min_stock,
                   c.name AS category_name, c.color AS category_color,
                   COALESCE(ABS(SUM(m.quantity)), 0) AS units_out,
                   COUNT(m.id) AS movements_count
            FROM inventory_movements m
            JOIN inventory_item_types it ON it.id = m.item_type_id
            JOIN inventory_categories  c  ON c.id = it.category_id
            WHERE m.quantity < 0
              AND m.performed_at >= DATE_SUB(?, INTERVAL ? DAY)
              AND m.performed_at <= DATE_ADD(?, INTERVAL 1 DAY)
            GROUP BY it.id, it.name, it.unit, it.current_stock, it.min_stock, c.name, c.color
            ORDER BY units_out DESC
            LIMIT 15
        ");
        $topConsStmt->execute([$date, $consumptionDays, $date]);
        $topConsumed = $topConsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($topConsumed as &$tc) {
            $tc['units_out']       = (float) $tc['units_out'];
            $tc['movements_count'] = (int)   $tc['movements_count'];
            $tc['current_stock']   = (float) $tc['current_stock'];
            $tc['min_stock']       = (float) $tc['min_stock'];
            $tc['days_window']     = $consumptionDays;
            // Estimate days of cover at current rate
            $perDay = $consumptionDays > 0 ? ($tc['units_out'] / $consumptionDays) : 0;
            $tc['avg_daily_consumption'] = $perDay;
            $tc['days_of_cover'] = $perDay > 0 ? (int) floor($tc['current_stock'] / $perDay) : null;
        }
        unset($tc);

        // --- Movements summary for the day (in/out)
        $dayMovStmt = $pdo->prepare("
            SELECT movement_type, COUNT(*) AS cnt, COALESCE(SUM(quantity), 0) AS signed_qty
            FROM inventory_movements
            WHERE DATE(performed_at) = ?
            GROUP BY movement_type
        ");
        $dayMovStmt->execute([$date]);
        $dayMovRows = $dayMovStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $todayMovements = [
            'total'        => 0,
            'units_in'     => 0.0,
            'units_out'    => 0.0,
            'by_type'      => [],
        ];
        foreach ($dayMovRows as $r) {
            $cnt = (int) $r['cnt'];
            $qty = (float) $r['signed_qty'];
            $todayMovements['total']     += $cnt;
            $todayMovements['by_type'][] = [
                'type'       => $r['movement_type'],
                'count'      => $cnt,
                'signed_qty' => $qty,
            ];
            if ($qty > 0) $todayMovements['units_in']  += $qty;
            if ($qty < 0) $todayMovements['units_out'] += abs($qty);
        }

        // --- Recent movements ledger
        $recentStmt = $pdo->prepare("
            SELECT m.id, m.movement_type, m.quantity, m.unit_cost, m.reason, m.reference,
                   m.notes, m.performed_at,
                   it.name AS item_name, it.unit,
                   c.name AS category_name, c.color AS category_color,
                   CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                   u.full_name AS performed_by_name
            FROM inventory_movements m
            JOIN inventory_item_types it ON it.id = m.item_type_id
            JOIN inventory_categories  c  ON c.id = it.category_id
            LEFT JOIN employees e ON e.id = m.employee_id
            LEFT JOIN users     u ON u.id = m.performed_by
            ORDER BY m.performed_at DESC
            LIMIT ?
        ");
        $recentStmt->bindValue(1, $movementsLimit, PDO::PARAM_INT);
        $recentStmt->execute();
        $recentMovements = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // --- Active assignments grouped by item type
        $assignStmt = $pdo->query("
            SELECT it.id, it.name AS item_name, it.unit,
                   c.name AS category_name, c.color AS category_color,
                   COUNT(ei.id) AS total_assigned
            FROM employee_inventory ei
            JOIN inventory_item_types it ON it.id = ei.item_type_id
            JOIN inventory_categories  c  ON c.id = it.category_id
            WHERE ei.status = 'ASSIGNED'
            GROUP BY it.id, it.name, it.unit, c.name, c.color
            ORDER BY total_assigned DESC
            LIMIT 25
        ");
        $activeAssignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $assignmentsTotal = 0;
        foreach ($activeAssignments as $a) {
            $assignmentsTotal += (int) $a['total_assigned'];
        }

        // --- Suppliers count
        $suppliersCount = (int) $pdo->query("SELECT COUNT(*) FROM inventory_suppliers WHERE is_active = 1")->fetchColumn();

        return [
            'date'           => $date,
            'date_formatted' => inventoryReportSpanishDate($date),
            'config'         => [
                'expiring_days'    => $expiringDays,
                'consumption_days' => $consumptionDays,
                'movements_limit'  => $movementsLimit,
            ],
            'totals' => [
                'total_items'   => (int)   $summary['total_items'],
                'total_units'   => (float) $summary['total_units'],
                'total_value'   => (float) $summary['total_value'],
                'low_stock'     => (int)   $summary['low_stock'],
                'out_of_stock'  => (int)   $summary['out_of_stock'],
                'expiring_soon' => (int)   $summary['expiring_soon'],
                'active_assignments' => $assignmentsTotal,
                'active_suppliers'   => $suppliersCount,
                'alerts_count'       => (int) $summary['low_stock'] + (int) $summary['out_of_stock'] + (int) $summary['expiring_soon'],
            ],
            'by_category'        => $byCategory,
            'low_stock'          => $lowStock,
            'expiring_lots'      => $expiringLots,
            'top_consumed'       => $topConsumed,
            'today_movements'    => $todayMovements,
            'recent_movements'   => $recentMovements,
            'active_assignments' => $activeAssignments,
            'generated_at'   => date('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('generateAIInventorySummary')) {
    function generateAIInventorySummary(PDO $pdo, array $reportData): string
    {
        $settings = getInventoryReportSettings($pdo);
        if (($settings['inventory_report_claude_enabled'] ?? '0') !== '1') {
            return '';
        }

        $model = trim((string) ($settings['inventory_report_claude_model'] ?? '')) ?: resolveAnthropicDefaultModel($pdo);
        $maxTokens = max(100, (int) ($settings['inventory_report_claude_max_tokens'] ?? 900));
        $systemPrompt = (string) ($settings['inventory_report_claude_prompt'] ?? '');

        $payload = [
            'fecha'   => $reportData['date'],
            'config'  => $reportData['config'],
            'totales' => $reportData['totals'],
            'por_categoria' => array_map(static fn($c) => [
                'categoria'    => $c['name'],
                'items'        => (int) $c['items_count'],
                'unidades'     => (float) $c['units_total'],
                'valor'        => (float) $c['value_total'],
                'bajo_stock'   => (int) $c['low_stock'],
                'sin_stock'    => (int) $c['out_of_stock'],
            ], $reportData['by_category']),
            'items_criticos' => array_map(static fn($i) => [
                'item'           => $i['name'],
                'categoria'      => $i['category_name'],
                'stock_actual'   => (float) $i['current_stock'],
                'stock_minimo'   => (float) $i['min_stock'],
                'reorden_sugerida' => isset($i['reorder_qty']) ? (float) $i['reorder_qty'] : null,
                'unidad'         => $i['unit'],
            ], array_slice($reportData['low_stock'], 0, 30)),
            'lotes_proximos_vencer' => array_map(static fn($l) => [
                'item'        => $l['item_name'],
                'lote'        => $l['lot_code'],
                'cantidad'    => (float) $l['quantity_remaining'],
                'vence'       => $l['expiration_date'],
                'dias_para_vencer' => (int) $l['days_to_expire'],
            ], array_slice($reportData['expiring_lots'], 0, 30)),
            'top_consumidos' => array_map(static fn($t) => [
                'item'          => $t['name'],
                'categoria'     => $t['category_name'],
                'unidades_consumidas' => (float) $t['units_out'],
                'dias_ventana'  => (int) $t['days_window'],
                'consumo_diario_avg'  => (float) $t['avg_daily_consumption'],
                'dias_de_cobertura'   => $t['days_of_cover'],
                'stock_actual'  => (float) $t['current_stock'],
            ], array_slice($reportData['top_consumed'], 0, 15)),
            'movimientos_hoy' => $reportData['today_movements'],
        ];

        $userPrompt = "Aquí está el snapshot diario del inventario en JSON. Genera el resumen ejecutivo según las instrucciones:\n\n"
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $result = callClaudeAPI([
            'api_key'       => '',
            'model'         => $model,
            'system_prompt' => $systemPrompt,
            'user_prompt'   => $userPrompt,
            'max_tokens'    => $maxTokens,
            'temperature'   => 0.3,
            'pdo'           => $pdo,
        ]);

        if (!$result['success']) {
            error_log('[inventory_report] Claude API error: ' . ($result['error'] ?? 'unknown'));
            return '';
        }
        return (string) $result['content'];
    }
}

if (!function_exists('inv_report_fmt_money')) {
    function inv_report_fmt_money(float $v): string
    {
        return '$' . number_format($v, 2);
    }
}

if (!function_exists('generateInventoryReportHTML')) {
    function generateInventoryReportHTML(array $reportData, string $aiSummary = ''): string
    {
        $date    = htmlspecialchars($reportData['date_formatted']);
        $totals  = $reportData['totals'];
        $byCat   = $reportData['by_category'];
        $low     = $reportData['low_stock'];
        $expLots = $reportData['expiring_lots'];
        $topCons = $reportData['top_consumed'];
        $todayMv = $reportData['today_movements'];
        $recent  = $reportData['recent_movements'];
        $assigns = $reportData['active_assignments'];
        $cfg     = $reportData['config'];

        $aiBlock = '';
        if (trim($aiSummary) !== '') {
            $safe = nl2br(htmlspecialchars($aiSummary), false);
            $aiBlock = "<div class='ai-summary'>"
                . "<div class='ai-badge'>Resumen ejecutivo generado por IA</div>"
                . "<div class='ai-body'>{$safe}</div>"
                . "</div>";
        }

        // Per-category rows
        $catRows = '';
        foreach ($byCat as $c) {
            $name  = htmlspecialchars($c['name'] ?? '—');
            $items = (int) $c['items_count'];
            $units = inv_format_qty((float) $c['units_total']);
            $value = inv_report_fmt_money((float) $c['value_total']);
            $low_  = (int) $c['low_stock'];
            $out_  = (int) $c['out_of_stock'];
            $alertCell = '';
            if ($out_ > 0) $alertCell .= "<span class='badge badge-danger'>{$out_} sin stock</span> ";
            if ($low_ > 0) $alertCell .= "<span class='badge badge-warn'>{$low_} bajo</span>";
            if ($alertCell === '') $alertCell = "<span class='muted'>OK</span>";
            $catRows .= "<tr>"
                . "<td><strong>{$name}</strong></td>"
                . "<td class='num'>{$items}</td>"
                . "<td class='num'>{$units}</td>"
                . "<td class='num'>{$value}</td>"
                . "<td>{$alertCell}</td>"
                . "</tr>";
        }

        // Low stock rows
        $lowRows = '';
        foreach ($low as $i) {
            $name   = htmlspecialchars($i['name']);
            $cat    = htmlspecialchars($i['category_name'] ?? '—');
            $unit   = htmlspecialchars($i['unit'] ?? '');
            $stock  = inv_format_qty((float) $i['current_stock'], $unit);
            $min_   = inv_format_qty((float) $i['min_stock'], $unit);
            $reord  = $i['reorder_qty'] !== null && (float)$i['reorder_qty'] > 0
                ? inv_format_qty((float) $i['reorder_qty'], $unit)
                : '<span class="muted">—</span>';
            $statusBadge = ((float)$i['current_stock'] <= 0)
                ? "<span class='badge badge-danger'>Sin stock</span>"
                : "<span class='badge badge-warn'>Bajo</span>";
            $lowRows .= "<tr>"
                . "<td><strong>{$name}</strong><br><span class='muted'>{$cat}</span></td>"
                . "<td>{$statusBadge}</td>"
                . "<td class='num'>{$stock}</td>"
                . "<td class='num'>{$min_}</td>"
                . "<td class='num'>{$reord}</td>"
                . "</tr>";
        }
        $lowBlock = !empty($low)
            ? "<div class='section'><h2>⚠️ Items con stock bajo o agotado ({$totals['low_stock']} bajos · {$totals['out_of_stock']} sin stock)</h2>"
              . "<table><thead><tr><th>Item</th><th>Estado</th><th style='text-align:right;'>Stock</th><th style='text-align:right;'>Mínimo</th><th style='text-align:right;'>Reorden sug.</th></tr></thead><tbody>{$lowRows}</tbody></table></div>"
            : "";

        // Expiring lot rows
        $expRows = '';
        foreach ($expLots as $l) {
            $item  = htmlspecialchars($l['item_name']);
            $lotCode = htmlspecialchars($l['lot_code'] ?? '—');
            $qty   = inv_format_qty((float) $l['quantity_remaining'], (string) $l['unit']);
            $exp   = htmlspecialchars((string) $l['expiration_date']);
            $days  = (int) $l['days_to_expire'];
            $cat   = htmlspecialchars($l['category_name'] ?? '—');
            $daysBadge = $days < 0
                ? "<span class='badge badge-critical'>Vencido hace " . abs($days) . " d</span>"
                : ($days <= 7
                    ? "<span class='badge badge-danger'>{$days} d</span>"
                    : ($days <= 15
                        ? "<span class='badge badge-warn'>{$days} d</span>"
                        : "<span class='badge badge-info'>{$days} d</span>"));
            $expRows .= "<tr>"
                . "<td><strong>{$item}</strong><br><span class='muted'>{$cat}</span></td>"
                . "<td>{$lotCode}</td>"
                . "<td class='num'>{$qty}</td>"
                . "<td>{$exp}</td>"
                . "<td>{$daysBadge}</td>"
                . "</tr>";
        }
        $expBlock = !empty($expLots)
            ? "<div class='section'><h2>📅 Lotes próximos a vencer (ventana: {$cfg['expiring_days']} días)</h2>"
              . "<table><thead><tr><th>Item</th><th>Lote</th><th style='text-align:right;'>Cantidad</th><th>Vence</th><th>Faltan</th></tr></thead><tbody>{$expRows}</tbody></table></div>"
            : "";

        // Top consumed rows
        $topRows = '';
        foreach ($topCons as $t) {
            $name = htmlspecialchars($t['name']);
            $cat  = htmlspecialchars($t['category_name'] ?? '—');
            $unit = (string) $t['unit'];
            $out  = inv_format_qty((float) $t['units_out'], $unit);
            $avg  = inv_format_qty((float) $t['avg_daily_consumption'], $unit . '/día');
            $stock = inv_format_qty((float) $t['current_stock'], $unit);
            $cover = $t['days_of_cover'] === null
                ? '<span class="muted">—</span>'
                : ($t['days_of_cover'] <= 7
                    ? "<span class='badge badge-danger'>{$t['days_of_cover']} d</span>"
                    : ($t['days_of_cover'] <= 30
                        ? "<span class='badge badge-warn'>{$t['days_of_cover']} d</span>"
                        : "{$t['days_of_cover']} d"));
            $topRows .= "<tr>"
                . "<td><strong>{$name}</strong><br><span class='muted'>{$cat}</span></td>"
                . "<td class='num'>{$out}</td>"
                . "<td class='num'>{$avg}</td>"
                . "<td class='num'>{$stock}</td>"
                . "<td>{$cover}</td>"
                . "</tr>";
        }
        $topBlock = !empty($topCons)
            ? "<div class='section'><h2>🔥 Top items consumidos (últimos {$cfg['consumption_days']} días)</h2>"
              . "<table><thead><tr><th>Item</th><th style='text-align:right;'>Consumido</th><th style='text-align:right;'>Prom. diario</th><th style='text-align:right;'>Stock actual</th><th>Cobertura</th></tr></thead><tbody>{$topRows}</tbody></table></div>"
            : "";

        // Today movements summary
        $dayTypeRows = '';
        foreach ($todayMv['by_type'] as $r) {
            $type = htmlspecialchars($r['type']);
            $cnt  = (int) $r['count'];
            $qty  = inv_format_qty((float) $r['signed_qty']);
            $sign = (float) $r['signed_qty'] >= 0 ? '+' : '';
            $dayTypeRows .= "<tr><td><strong>{$type}</strong></td><td class='num'>{$cnt}</td><td class='num'>{$sign}{$qty}</td></tr>";
        }
        $todayBlock = "<div class='section'><h2>📥📤 Movimientos del día</h2>"
            . "<div class='mini-stats'>"
            . "<div class='mini-stat'><div class='mini-stat-label'>Total movimientos</div><div class='mini-stat-num'>{$todayMv['total']}</div></div>"
            . "<div class='mini-stat success'><div class='mini-stat-label'>Unidades entrantes</div><div class='mini-stat-num'>" . inv_format_qty((float)$todayMv['units_in']) . "</div></div>"
            . "<div class='mini-stat danger'><div class='mini-stat-label'>Unidades salientes</div><div class='mini-stat-num'>" . inv_format_qty((float)$todayMv['units_out']) . "</div></div>"
            . "</div>"
            . (!empty($dayTypeRows)
                ? "<table><thead><tr><th>Tipo</th><th style='text-align:right;'># Mov.</th><th style='text-align:right;'>Δ Unidades</th></tr></thead><tbody>{$dayTypeRows}</tbody></table>"
                : "<p class='muted-small'>Sin movimientos registrados hoy.</p>")
            . "</div>";

        // Active assignments
        $assignRows = '';
        foreach ($assigns as $a) {
            $item = htmlspecialchars($a['item_name']);
            $cat  = htmlspecialchars($a['category_name'] ?? '—');
            $tot  = (int) $a['total_assigned'];
            $assignRows .= "<tr><td><strong>{$item}</strong><br><span class='muted'>{$cat}</span></td><td class='num'>{$tot}</td></tr>";
        }
        $assignBlock = !empty($assigns)
            ? "<div class='section'><h2>👥 Asignaciones activas a empleados ({$totals['active_assignments']})</h2>"
              . "<table><thead><tr><th>Item</th><th style='text-align:right;'># Asignados</th></tr></thead><tbody>{$assignRows}</tbody></table></div>"
            : "";

        // Recent movements
        $recentRows = '';
        foreach ($recent as $m) {
            $when = htmlspecialchars(date('d/m H:i', strtotime((string) $m['performed_at'])));
            $type = htmlspecialchars($m['movement_type']);
            $item = htmlspecialchars($m['item_name']);
            $qty  = (float) $m['quantity'];
            $sign = $qty >= 0 ? '+' : '';
            $unit = (string) $m['unit'];
            $qStr = $sign . inv_format_qty($qty, $unit);
            $by   = htmlspecialchars((string) ($m['performed_by_name'] ?? '—'));
            $emp  = trim((string) ($m['employee_name'] ?? ''));
            $extra = $emp !== '' ? "<br><span class='muted'>Empleado: " . htmlspecialchars($emp) . "</span>" : '';
            $reason = htmlspecialchars((string) ($m['reason'] ?? ''));
            $qClass = $qty >= 0 ? 'qty-in' : 'qty-out';
            $recentRows .= "<tr>"
                . "<td>{$when}</td>"
                . "<td><span class='badge badge-info'>{$type}</span></td>"
                . "<td><strong>{$item}</strong>{$extra}</td>"
                . "<td class='num {$qClass}'>{$qStr}</td>"
                . "<td>{$reason}</td>"
                . "<td>{$by}</td>"
                . "</tr>";
        }
        $recentBlock = !empty($recent)
            ? "<div class='section'><h2>📜 Últimos {$cfg['movements_limit']} movimientos</h2>"
              . "<table><thead><tr><th>Fecha</th><th>Tipo</th><th>Item</th><th style='text-align:right;'>Cantidad</th><th>Motivo</th><th>Operador</th></tr></thead><tbody>{$recentRows}</tbody></table></div>"
            : "";

        $allClear = '';
        if ($totals['alerts_count'] === 0) {
            $allClear = "<div class='success-card'><h3>✅ Inventario en buen estado</h3><p>No hay alertas de stock bajo, agotamiento o vencimientos próximos.</p></div>";
        }

        $totalUnitsFmt = inv_format_qty((float) $totals['total_units']);
        $totalValueFmt = inv_report_fmt_money((float) $totals['total_value']);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; padding: 20px; }
  .header { background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%); color: #fff; padding: 28px; text-align: center; border-radius: 10px; }
  .header h1 { margin: 0; font-size: 26px; font-weight: 600; }
  .header p { margin: 8px 0 0 0; font-size: 15px; opacity: 0.95; }
  .stats-grid { display: table; width: 100%; margin: 18px 0; border-spacing: 10px; }
  .stat-card { display: table-cell; background: #fff; padding: 16px; border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
  .stat-card.primary  { border-top: 4px solid #0ea5e9; }
  .stat-card.success  { border-top: 4px solid #10b981; }
  .stat-card.warn     { border-top: 4px solid #f59e0b; }
  .stat-card.danger   { border-top: 4px solid #ef4444; }
  .stat-card.info     { border-top: 4px solid #6366f1; }
  .stat-card.muted    { border-top: 4px solid #64748b; }
  .stat-number { font-size: 26px; font-weight: 700; margin: 8px 0 0 0; }
  .stat-sub { font-size: 12px; color: #666; margin-top: 4px; }
  .stat-label { color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
  .ai-summary { background: #fffbeb; border: 1px solid #fcd34d; border-left: 4px solid #f59e0b; padding: 16px 20px; border-radius: 8px; margin: 18px 0; }
  .ai-badge { display: inline-block; background: #f59e0b; color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; margin-bottom: 10px; }
  .ai-body { color: #333; font-size: 14px; white-space: pre-wrap; }
  .section { background: #fff; margin: 18px 0; padding: 22px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
  .section h2 { margin: 0 0 14px 0; font-size: 18px; border-bottom: 2px solid #0ea5e9; padding-bottom: 8px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead { background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%); }
  th { color: #fff; padding: 10px 8px; text-align: left; font-weight: 600; font-size: 12px; }
  td { padding: 10px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  tbody tr:nth-child(even) { background-color: #fafafa; }
  td.num { font-family: 'Courier New', monospace; white-space: nowrap; text-align: right; }
  .qty-in  { color: #047857; font-weight: 600; }
  .qty-out { color: #b91c1c; font-weight: 600; }
  .muted { color: #888; font-size: 11px; }
  .muted-small { color: #666; font-size: 12px; margin-bottom: 8px; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; color: #fff; }
  .badge-info     { background: #6366f1; }
  .badge-warn     { background: #f59e0b; }
  .badge-danger   { background: #ef4444; }
  .badge-critical { background: #7f1d1d; }
  .success-card { background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 8px; padding: 24px; text-align: center; color: #065f46; }
  .mini-stats { display: table; width: 100%; border-spacing: 8px; margin-bottom: 12px; }
  .mini-stat { display: table-cell; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center; }
  .mini-stat.success { background: #ecfdf5; border-color: #6ee7b7; }
  .mini-stat.danger  { background: #fef2f2; border-color: #fca5a5; }
  .mini-stat-label { font-size: 11px; color: #666; text-transform: uppercase; }
  .mini-stat-num { font-size: 18px; font-weight: 700; }
  .footer { text-align: center; padding: 18px; color: #777; font-size: 12px; margin-top: 20px; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>📦 Reporte Diario de Inventario</h1>
    <p>{$date}</p>
  </div>

  <div class="stats-grid">
    <div class="stat-card primary">
      <div class="stat-label">Items activos</div>
      <div class="stat-number">{$totals['total_items']}</div>
      <div class="stat-sub">Tipos en catálogo</div>
    </div>
    <div class="stat-card info">
      <div class="stat-label">Unidades totales</div>
      <div class="stat-number">{$totalUnitsFmt}</div>
    </div>
    <div class="stat-card success">
      <div class="stat-label">Valor estimado</div>
      <div class="stat-number">{$totalValueFmt}</div>
    </div>
    <div class="stat-card warn">
      <div class="stat-label">Alertas activas</div>
      <div class="stat-number">{$totals['alerts_count']}</div>
      <div class="stat-sub">bajo + agotado + por vencer</div>
    </div>
  </div>

  <div class="stats-grid">
    <div class="stat-card warn">
      <div class="stat-label">Stock bajo</div>
      <div class="stat-number">{$totals['low_stock']}</div>
    </div>
    <div class="stat-card danger">
      <div class="stat-label">Sin stock</div>
      <div class="stat-number">{$totals['out_of_stock']}</div>
    </div>
    <div class="stat-card warn">
      <div class="stat-label">Por vencer ({$cfg['expiring_days']}d)</div>
      <div class="stat-number">{$totals['expiring_soon']}</div>
    </div>
    <div class="stat-card muted">
      <div class="stat-label">Asignaciones activas</div>
      <div class="stat-number">{$totals['active_assignments']}</div>
      <div class="stat-sub">{$totals['active_suppliers']} proveedores</div>
    </div>
  </div>

  {$aiBlock}
  {$allClear}

  <div class="section">
    <h2>📊 Desglose por categoría</h2>
    <table><thead><tr><th>Categoría</th><th style='text-align:right;'># Items</th><th style='text-align:right;'>Unidades</th><th style='text-align:right;'>Valor</th><th>Estado</th></tr></thead><tbody>{$catRows}</tbody></table>
  </div>

  {$lowBlock}
  {$expBlock}
  {$topBlock}
  {$todayBlock}
  {$assignBlock}
  {$recentBlock}

  <div class='footer'>
    <p><strong>Reporte generado automáticamente</strong></p>
    <p>{$reportData['generated_at']} — Módulo de Inventario</p>
  </div>
</div>
</body>
</html>
HTML;
    }
}

if (!function_exists('sendInventoryReportByEmail')) {
    function sendInventoryReportByEmail(PDO $pdo, array $reportData, array $recipients, string $aiSummary = ''): bool
    {
        if (empty($recipients)) {
            error_log('[inventory_report] No recipients configured');
            return false;
        }

        $html = generateInventoryReportHTML($reportData, $aiSummary);
        require_once __DIR__ . '/email_functions.php';

        $result = sendDailyInventoryReport($html, $recipients, $reportData);

        if ($result['success']) {
            error_log('[inventory_report] Sent: ' . $result['message']);
            return true;
        }
        error_log('[inventory_report] Failed: ' . $result['message']);
        return false;
    }
}

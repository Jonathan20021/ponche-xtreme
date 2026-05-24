<?php
// Helper compartido: carga todos los datos del reporte de rentabilidad por campaña.
// Lo usan tanto la vista principal como los exports PDF/Excel.
// READ-ONLY: solo SELECT, no escribe nada.

if (!function_exists('loadCampaignProfitabilityData')) {
    /**
     * @param PDO         $pdo          Conexión principal
     * @param string      $startDate    YYYY-MM-DD
     * @param string      $endDate      YYYY-MM-DD
     * @param int|null    $campaignOnly Si se pasa, filtra el drill-down a esa campaña
     * @return array {
     *   rows: array,           // una fila por campaña
     *   totals: array,         // globales
     *   qaAvailable: bool,
     *   qaError: ?string,
     *   drilldownCampaign: ?array,
     *   drilldownByDept: array,
     *   drilldownEmployees: array,
     * }
     */
    function loadCampaignProfitabilityData(PDO $pdo, string $startDate, string $endDate, ?int $campaignOnly = null): array
    {
        // --- Costos por campaña ---
        $costStmt = $pdo->prepare("
            SELECT
                COALESCE(c.id, 0) AS campaign_id,
                COALESCE(c.name, 'Sin Campaña') AS campaign_name,
                COALESCE(c.color, '#64748b') AS campaign_color,
                SUM(pr.gross_salary) AS total_gross,
                SUM(pr.total_employer_contributions) AS total_employer,
                SUM(pr.gross_salary + pr.total_employer_contributions) AS total_cost,
                SUM(pr.total_hours) AS total_hours,
                SUM(pr.overtime_hours) AS total_overtime_hours,
                COUNT(DISTINCT pr.employee_id) AS payroll_employees
            FROM payroll_records pr
            JOIN payroll_periods pp ON pp.id = pr.payroll_period_id
            JOIN employees e        ON e.id  = pr.employee_id
            LEFT JOIN campaigns c   ON c.id  = e.campaign_id
            WHERE pp.payment_date BETWEEN ? AND ?
            GROUP BY COALESCE(c.id, 0), COALESCE(c.name, 'Sin Campaña'), COALESCE(c.color, '#64748b')
        ");
        $costStmt->execute([$startDate, $endDate]);
        $costsByCampaign = [];
        foreach ($costStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $costsByCampaign[(int)$row['campaign_id']] = $row;
        }

        // --- Ingresos por campaña ---
        $revStmt = $pdo->prepare("
            SELECT
                campaign_id,
                SUM(revenue_amount) AS total_revenue,
                SUM(sales_amount)   AS total_sales,
                SUM(volume)         AS total_volume,
                COUNT(*)            AS report_days,
                GROUP_CONCAT(DISTINCT currency ORDER BY currency) AS currencies
            FROM campaign_sales_reports
            WHERE report_date BETWEEN ? AND ?
            GROUP BY campaign_id
        ");
        $revStmt->execute([$startDate, $endDate]);
        $revenueByCampaign = [];
        foreach ($revStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $revenueByCampaign[(int)$row['campaign_id']] = $row;
        }

        // --- Roles por campaña (snapshot actual) ---
        $roleStmt = $pdo->query("
            SELECT
                COALESCE(e.campaign_id, 0) AS campaign_id,
                UPPER(TRIM(COALESCE(u.role, ''))) AS role_norm,
                COUNT(*) AS cnt
            FROM employees e
            JOIN users u ON u.id = e.user_id
            WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
            GROUP BY COALESCE(e.campaign_id, 0), UPPER(TRIM(COALESCE(u.role, '')))
        ");
        $rolesByCampaign = [];
        foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['campaign_id'];
            $role = $row['role_norm'];
            $cnt = (int)$row['cnt'];
            if (!isset($rolesByCampaign[$cid])) {
                $rolesByCampaign[$cid] = ['AGENT' => 0, 'SUPERVISOR' => 0, 'OTROS' => 0, 'TOTAL' => 0];
            }
            if ($role === 'AGENT')           $rolesByCampaign[$cid]['AGENT'] += $cnt;
            elseif ($role === 'SUPERVISOR')  $rolesByCampaign[$cid]['SUPERVISOR'] += $cnt;
            else                             $rolesByCampaign[$cid]['OTROS'] += $cnt;
            $rolesByCampaign[$cid]['TOTAL'] += $cnt;
        }

        // --- Supervisores asignados a campañas ---
        $supStmt = $pdo->query("
            SELECT sc.campaign_id, u.id AS user_id, u.full_name, u.username
            FROM supervisor_campaigns sc
            JOIN users u ON u.id = sc.supervisor_id
            ORDER BY u.full_name
        ");
        $supervisorsByCampaign = [];
        foreach ($supStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['campaign_id'];
            if (!isset($supervisorsByCampaign[$cid])) $supervisorsByCampaign[$cid] = [];
            $supervisorsByCampaign[$cid][] = $row['full_name'] ?: $row['username'];
        }

        // --- QA (BD externa, opcional) ---
        $qaByCampaign = [];
        $qaAvailable = false;
        $qaError = null;
        try {
            $qaDb = function_exists('getQualityDbConnection') ? getQualityDbConnection() : null;
            if ($qaDb) {
                $qaStmt = $qaDb->prepare("
                    SELECT campaign_id,
                           AVG(percentage) AS avg_score,
                           COUNT(*)        AS eval_count
                    FROM evaluations
                    WHERE call_date BETWEEN ? AND ?
                      AND percentage IS NOT NULL
                    GROUP BY campaign_id
                ");
                $qaStmt->execute([$startDate, $endDate]);
                foreach ($qaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $qaByCampaign[(int)$row['campaign_id']] = [
                        'avg_score'  => (float)$row['avg_score'],
                        'eval_count' => (int)$row['eval_count'],
                    ];
                }
                $qaAvailable = true;
            } else {
                $qaError = 'No se pudo conectar a la base de datos de QA.';
            }
        } catch (Throwable $e) {
            $qaError = 'Error consultando QA: ' . $e->getMessage();
            error_log('[campaign_profitability] ' . $qaError);
        }

        // --- Catálogo de campañas activas ---
        $allCampaigns = $pdo->query("SELECT id, name, color FROM campaigns WHERE is_active = 1 ORDER BY name")
                            ->fetchAll(PDO::FETCH_ASSOC);

        // --- Unificar filas ---
        $rows = [];
        $seen = [];
        $build = function (int $cid, string $name, string $color) use (
            $costsByCampaign, $revenueByCampaign, $rolesByCampaign, $supervisorsByCampaign, $qaByCampaign
        ) {
            $cost = $costsByCampaign[$cid] ?? null;
            $rev  = $revenueByCampaign[$cid] ?? null;
            $hasRevenue = $rev !== null && (float)$rev['total_revenue'] > 0;
            $revenue = $hasRevenue ? (float)$rev['total_revenue'] : null;
            $totalCost = $cost ? (float)$cost['total_cost'] : 0.0;
            $profit = $hasRevenue ? $revenue - $totalCost : null;
            $margin = ($hasRevenue && $revenue > 0) ? ($profit / $revenue) * 100 : null;
            return [
                'campaign_id'        => $cid,
                'campaign_name'      => $name,
                'campaign_color'     => $color ?: '#64748b',
                'revenue'            => $revenue,
                'sales'              => $hasRevenue ? (float)$rev['total_sales'] : null,
                'volume'             => $rev ? (int)$rev['total_volume'] : 0,
                'currencies'         => $rev['currencies'] ?? null,
                'report_days'        => $rev ? (int)$rev['report_days'] : 0,
                'total_cost'         => $totalCost,
                'total_gross'        => $cost ? (float)$cost['total_gross'] : 0.0,
                'total_employer'     => $cost ? (float)$cost['total_employer'] : 0.0,
                'total_hours'        => $cost ? (float)$cost['total_hours'] : 0.0,
                'total_overtime'     => $cost ? (float)$cost['total_overtime_hours'] : 0.0,
                'payroll_employees'  => $cost ? (int)$cost['payroll_employees'] : 0,
                'profit'             => $profit,
                'margin'             => $margin,
                'roles'              => $rolesByCampaign[$cid] ?? ['AGENT' => 0, 'SUPERVISOR' => 0, 'OTROS' => 0, 'TOTAL' => 0],
                'supervisors'        => $supervisorsByCampaign[$cid] ?? [],
                'qa'                 => $qaByCampaign[$cid] ?? null,
            ];
        };

        foreach ($allCampaigns as $c) {
            $cid = (int)$c['id'];
            $seen[$cid] = true;
            $rows[] = $build($cid, $c['name'], $c['color']);
        }
        foreach ($costsByCampaign as $cid => $cost) {
            if (!isset($seen[$cid])) {
                $rows[] = $build((int)$cid, $cost['campaign_name'], $cost['campaign_color']);
            }
        }

        usort($rows, function ($a, $b) {
            if ($a['margin'] === null && $b['margin'] === null) {
                return $b['total_cost'] <=> $a['total_cost'];
            }
            if ($a['margin'] === null) return 1;
            if ($b['margin'] === null) return -1;
            return $b['margin'] <=> $a['margin'];
        });

        // --- Totales globales ---
        $totals = [
            'revenue' => 0.0, 'cost' => 0.0, 'sales' => 0.0, 'volume' => 0,
            'campaigns_with_revenue' => 0, 'campaigns_profitable' => 0,
        ];
        foreach ($rows as $r) {
            if ($r['revenue'] !== null) {
                $totals['revenue'] += $r['revenue'];
                $totals['sales']   += $r['sales'] ?? 0;
                $totals['volume']  += $r['volume'];
                $totals['campaigns_with_revenue']++;
                if ($r['profit'] !== null && $r['profit'] > 0) $totals['campaigns_profitable']++;
            }
            $totals['cost'] += $r['total_cost'];
        }
        $totals['profit'] = $totals['revenue'] - $totals['cost'];
        $totals['margin'] = $totals['revenue'] > 0 ? ($totals['profit'] / $totals['revenue']) * 100 : null;

        // --- Drill-down (opcional) ---
        $drilldownCampaign = null;
        $drilldownByDept = [];
        $drilldownEmployees = [];
        if ($campaignOnly !== null) {
            if ($campaignOnly > 0) {
                $cs = $pdo->prepare("SELECT id, name, color FROM campaigns WHERE id = ?");
                $cs->execute([$campaignOnly]);
                $drilldownCampaign = $cs->fetch(PDO::FETCH_ASSOC);
            } else {
                $drilldownCampaign = ['id' => 0, 'name' => 'Sin Campaña', 'color' => '#64748b'];
            }

            if ($drilldownCampaign) {
                $deptFilter = $campaignOnly > 0 ? 'e.campaign_id = ?' : 'e.campaign_id IS NULL';

                $deptCostStmt = $pdo->prepare("
                    SELECT
                        COALESCE(d.id, 0) AS dept_id,
                        COALESCE(d.name, 'Sin Departamento') AS dept_name,
                        COUNT(DISTINCT pr.employee_id) AS emp_count,
                        SUM(pr.gross_salary + pr.total_employer_contributions) AS total_cost,
                        SUM(pr.gross_salary) AS gross,
                        SUM(pr.total_employer_contributions) AS employer,
                        SUM(pr.total_hours) AS hours
                    FROM payroll_records pr
                    JOIN payroll_periods pp ON pp.id = pr.payroll_period_id
                    JOIN employees e ON e.id = pr.employee_id
                    LEFT JOIN departments d ON d.id = e.department_id
                    WHERE pp.payment_date BETWEEN ? AND ?
                      AND $deptFilter
                    GROUP BY COALESCE(d.id, 0), COALESCE(d.name, 'Sin Departamento')
                    ORDER BY total_cost DESC
                ");
                $bindings = [$startDate, $endDate];
                if ($campaignOnly > 0) $bindings[] = $campaignOnly;
                $deptCostStmt->execute($bindings);
                $drilldownByDept = $deptCostStmt->fetchAll(PDO::FETCH_ASSOC);

                $empStmt = $pdo->prepare("
                    SELECT
                        e.id AS employee_id,
                        e.first_name, e.last_name, e.employee_code, e.position,
                        COALESCE(d.name, 'Sin Departamento') AS dept_name,
                        COALESCE(u.role, '') AS role,
                        SUM(pr.gross_salary) AS gross,
                        SUM(pr.total_employer_contributions) AS employer,
                        SUM(pr.gross_salary + pr.total_employer_contributions) AS total_cost,
                        SUM(pr.total_hours) AS hours,
                        SUM(pr.overtime_hours) AS overtime
                    FROM payroll_records pr
                    JOIN payroll_periods pp ON pp.id = pr.payroll_period_id
                    JOIN employees e ON e.id = pr.employee_id
                    JOIN users u     ON u.id = e.user_id
                    LEFT JOIN departments d ON d.id = e.department_id
                    WHERE pp.payment_date BETWEEN ? AND ?
                      AND $deptFilter
                    GROUP BY e.id, e.first_name, e.last_name, e.employee_code, e.position,
                             COALESCE(d.name, 'Sin Departamento'), u.role
                    ORDER BY total_cost DESC
                ");
                $empStmt->execute($bindings);
                $drilldownEmployees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'qaAvailable' => $qaAvailable,
            'qaError' => $qaError,
            'drilldownCampaign' => $drilldownCampaign,
            'drilldownByDept' => $drilldownByDept,
            'drilldownEmployees' => $drilldownEmployees,
        ];
    }
}

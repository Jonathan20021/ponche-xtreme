<?php
/**
 * lib/delivery_restaurants.php
 *
 * Reparto de costos de Delivery por restaurante.
 *
 * El área de Delivery atiende llamadas de varios restaurantes, pero para la
 * NÓMINA sigue siendo UNA sola campaña. Lo que hacía falta era saber a qué
 * restaurante atiende cada colaborador para poder dividir su costo en el sistema
 * contable, sin fragmentar la campaña.
 *
 * Por eso el restaurante vive en su propia tabla (`employee_restaurants`) y NO
 * toca `employees.campaign_id`: la nómina, los monitores y los reportes siguen
 * viendo la campaña Delivery igual que siempre.
 *
 * Un colaborador puede atender varios restaurantes con un porcentaje de reparto
 * (allocation_pct); si atiende uno solo, va al 100%.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/agent_hours.php';

if (!function_exists('deliveryGetRestaurants')) {
    /**
     * @return array<int,array<string,mixed>>
     */
    function deliveryGetRestaurants(PDO $pdo, bool $onlyActive = true): array
    {
        try {
            $sql = "
                SELECT r.*, c.name AS campaign_name,
                       (SELECT COUNT(*) FROM employee_restaurants er
                        WHERE er.restaurant_id = r.id AND er.end_date IS NULL) AS active_employees
                FROM restaurants r
                LEFT JOIN campaigns c ON c.id = r.campaign_id
            ";
            if ($onlyActive) {
                $sql .= " WHERE r.is_active = 1";
            }
            $sql .= " ORDER BY r.name";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('deliveryGetRestaurants: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('deliveryGetEmployeeRestaurants')) {
    /**
     * Restaurantes de un colaborador (vigentes e históricos).
     *
     * @return array<int,array<string,mixed>>
     */
    function deliveryGetEmployeeRestaurants(PDO $pdo, int $employeeId, bool $onlyActive = false): array
    {
        try {
            $sql = "
                SELECT er.*, r.name AS restaurant_name, r.code AS restaurant_code, r.color,
                       u.full_name AS assigned_by_name
                FROM employee_restaurants er
                INNER JOIN restaurants r ON r.id = er.restaurant_id
                LEFT JOIN users u ON u.id = er.assigned_by
                WHERE er.employee_id = ?
            ";
            if ($onlyActive) {
                $sql .= " AND er.end_date IS NULL";
            }
            $sql .= " ORDER BY er.end_date IS NULL DESC, er.start_date DESC, er.id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$employeeId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('deliveryGetEmployeeRestaurants: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('deliveryAllocationWarnings')) {
    /**
     * Colaboradores cuyo reparto vigente NO suma 100%: el costo quedaría mal
     * repartido en contabilidad y conviene avisarlo antes de cerrar el mes.
     *
     * @return array<int,array{employee_id:int,name:string,total_pct:float,restaurants:int}>
     */
    function deliveryAllocationWarnings(PDO $pdo): array
    {
        try {
            $rows = $pdo->query("
                SELECT er.employee_id,
                       CONCAT(e.first_name, ' ', e.last_name) AS name,
                       SUM(er.allocation_pct) AS total_pct,
                       COUNT(*) AS restaurants
                FROM employee_restaurants er
                INNER JOIN employees e ON e.id = er.employee_id
                WHERE er.end_date IS NULL
                  AND e.employment_status <> 'TERMINATED'
                GROUP BY er.employee_id, e.first_name, e.last_name
                HAVING ABS(SUM(er.allocation_pct) - 100) > 0.01
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('deliveryAllocationWarnings: ' . $e->getMessage());
            return [];
        }

        foreach ($rows as &$r) {
            $r['employee_id'] = (int) $r['employee_id'];
            $r['total_pct']   = (float) $r['total_pct'];
            $r['restaurants'] = (int) $r['restaurants'];
        }
        unset($r);

        return $rows;
    }
}

if (!function_exists('deliveryCostByRestaurant')) {
    /**
     * Reparte el costo de los colaboradores de Delivery entre los restaurantes
     * que atienden, para el sistema contable.
     *
     * El costo se calcula con las horas que YA paga la nómina
     * (computePeriodHoursForUser), no con un cálculo propio.
     *
     * @return array{
     *   from:string, to:string,
     *   restaurants:array<int,array<string,mixed>>,
     *   employees:array<int,array<string,mixed>>,
     *   unassigned:array<int,array<string,mixed>>,
     *   totals:array{hours:float,cost:float,employees:int}
     * }
     */
    function deliveryCostByRestaurant(PDO $pdo, string $from, string $to): array
    {
        $out = [
            'from' => $from, 'to' => $to,
            'restaurants' => [], 'employees' => [], 'unassigned' => [],
            'totals' => ['hours' => 0.0, 'cost' => 0.0, 'employees' => 0],
        ];

        $paidTypes = getPaidAttendanceTypeSlugs($pdo);

        // Colaboradores que tienen restaurante asignado (vigente en el período)
        // más los que están en una campaña de Delivery aunque no lo tengan aún.
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT e.id AS employee_id, e.first_name, e.last_name, e.employee_code,
                       u.id AS user_id, u.full_name,
                       COALESCE(u.payroll_source, 'manual') AS payroll_source,
                       u.hourly_rate, u.hourly_rate_dop, u.monthly_salary, u.monthly_salary_dop,
                       u.preferred_currency, u.compensation_type, u.role,
                       c.name AS campaign_name
                FROM employees e
                INNER JOIN users u ON u.id = e.user_id
                LEFT JOIN campaigns c ON c.id = e.campaign_id
                LEFT JOIN employee_restaurants er ON er.employee_id = e.id AND er.end_date IS NULL
                WHERE e.employment_status <> 'TERMINATED'
                  AND (er.id IS NOT NULL OR c.name LIKE '%elivery%')
                ORDER BY e.first_name, e.last_name
            ");
            $stmt->execute();
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('deliveryCostByRestaurant: ' . $e->getMessage());
            return $out;
        }

        $byRestaurant = [];

        foreach ($employees as $emp) {
            $hours = computePeriodHoursForUser(
                $pdo,
                (int) $emp['user_id'],
                $from,
                $to,
                $paidTypes,
                44.0,
                false,
                (string) $emp['payroll_source']
            );

            $totalHours = $hours['total_seconds'] / 3600;
            if ($totalHours <= 0) {
                continue;
            }

            // Tarifa efectiva (misma regla que el resto del sistema)
            $prefersDop = strtoupper((string) ($emp['preferred_currency'] ?? 'DOP')) === 'DOP';
            $paymentType = resolvePaymentType(
                $emp['compensation_type'] ?? '',
                $emp['role'] ?? '',
                max((float) $emp['monthly_salary'], (float) $emp['monthly_salary_dop'])
            );

            if ($paymentType === 'fixed') {
                $monthly = $prefersDop ? (float) $emp['monthly_salary_dop'] : (float) $emp['monthly_salary'];
                if ($monthly <= 0) { $monthly = $prefersDop ? (float) $emp['monthly_salary'] : (float) $emp['monthly_salary_dop']; }
                $rate = $monthly > 0 ? round($monthly / 23.83 / 8, 2) : 0.0;
            } else {
                $rate = $prefersDop ? (float) $emp['hourly_rate_dop'] : (float) $emp['hourly_rate'];
                if ($rate <= 0) { $rate = $prefersDop ? (float) $emp['hourly_rate'] : (float) $emp['hourly_rate_dop']; }
            }

            $cost = round($totalHours * $rate, 2);
            $name = trim($emp['first_name'] . ' ' . $emp['last_name']) ?: $emp['full_name'];

            $assignments = deliveryGetEmployeeRestaurants($pdo, (int) $emp['employee_id'], true);

            $out['totals']['hours'] += $totalHours;
            $out['totals']['cost']  += $cost;
            $out['totals']['employees']++;

            if (empty($assignments)) {
                // Está en Delivery pero nadie le asignó restaurante: su costo no
                // se puede repartir y hay que verlo, no esconderlo.
                $out['unassigned'][] = [
                    'employee_id' => (int) $emp['employee_id'],
                    'name'        => $name,
                    'hours'       => round($totalHours, 2),
                    'cost'        => $cost,
                ];
                continue;
            }

            $empRow = [
                'employee_id' => (int) $emp['employee_id'],
                'name'        => $name,
                'code'        => $emp['employee_code'],
                'hours'       => round($totalHours, 2),
                'rate'        => $rate,
                'cost'        => $cost,
                'source'      => $hours['source_used'],
                'splits'      => [],
            ];

            foreach ($assignments as $a) {
                $pct = (float) $a['allocation_pct'];
                $share = round($cost * $pct / 100, 2);
                $shareHours = round($totalHours * $pct / 100, 2);

                $rid = (int) $a['restaurant_id'];
                if (!isset($byRestaurant[$rid])) {
                    $byRestaurant[$rid] = [
                        'restaurant_id' => $rid,
                        'name'          => $a['restaurant_name'],
                        'code'          => $a['restaurant_code'],
                        'color'         => $a['color'],
                        'employees'     => 0,
                        'hours'         => 0.0,
                        'cost'          => 0.0,
                    ];
                }
                $byRestaurant[$rid]['employees']++;
                $byRestaurant[$rid]['hours'] += $shareHours;
                $byRestaurant[$rid]['cost']  += $share;

                $empRow['splits'][] = [
                    'restaurant_id' => $rid,
                    'name'          => $a['restaurant_name'],
                    'pct'           => $pct,
                    'hours'         => $shareHours,
                    'cost'          => $share,
                ];
            }

            $out['employees'][] = $empRow;
        }

        // Mayor costo primero
        $out['restaurants'] = array_values($byRestaurant);
        usort($out['restaurants'], static fn($a, $b) => $b['cost'] <=> $a['cost']);
        usort($out['employees'], static fn($a, $b) => $b['cost'] <=> $a['cost']);

        foreach ($out['restaurants'] as &$r) {
            $r['hours'] = round($r['hours'], 2);
            $r['cost']  = round($r['cost'], 2);
        }
        unset($r);

        $out['totals']['hours'] = round($out['totals']['hours'], 2);
        $out['totals']['cost']  = round($out['totals']['cost'], 2);

        return $out;
    }
}

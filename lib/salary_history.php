<?php
/**
 * lib/salary_history.php
 *
 * Historial de cambios salariales: qué cobraba antes, qué cobra ahora, desde
 * cuándo y quién lo cambió.
 *
 * La tabla `salary_history` existía desde el módulo de RRHH pero estaba VACÍA:
 * el formulario de "cambio de tarifa" guardaba en `hourly_rate_history`, pero la
 * edición masiva de usuarios (settings.php → update_users) actualizaba sueldos
 * y tarifas sin dejar rastro. Este archivo cierra ese hueco.
 *
 * Para mostrar el historial se unen las dos fuentes (salary_history y
 * hourly_rate_history) en una sola línea de tiempo.
 */

require_once __DIR__ . '/../db.php';

if (!function_exists('recordSalaryChange')) {
    /**
     * Registra un cambio de compensación. Solo escribe si algo cambió de verdad.
     *
     * @param array{hourly_rate?:float,hourly_rate_dop?:float,monthly_salary?:float,monthly_salary_dop?:float} $old
     * @param array{hourly_rate?:float,hourly_rate_dop?:float,monthly_salary?:float,monthly_salary_dop?:float} $new
     * @return int|null id del registro, o null si no hubo cambio
     */
    function recordSalaryChange(
        PDO $pdo,
        int $userId,
        array $old,
        array $new,
        ?int $changedBy = null,
        ?string $reason = null,
        ?string $effectiveDate = null
    ): ?int {
        if ($userId <= 0) {
            return null;
        }

        $f = static fn($v) => round((float) ($v ?? 0), 2);

        $oldHourUsd  = $f($old['hourly_rate']       ?? 0);
        $newHourUsd  = $f($new['hourly_rate']       ?? 0);
        $oldHourDop  = $f($old['hourly_rate_dop']   ?? 0);
        $newHourDop  = $f($new['hourly_rate_dop']   ?? 0);
        $oldMonthUsd = $f($old['monthly_salary']    ?? 0);
        $newMonthUsd = $f($new['monthly_salary']    ?? 0);
        $oldMonthDop = $f($old['monthly_salary_dop']?? 0);
        $newMonthDop = $f($new['monthly_salary_dop']?? 0);

        $changed = ($oldHourUsd !== $newHourUsd) || ($oldHourDop !== $newHourDop)
                || ($oldMonthUsd !== $newMonthUsd) || ($oldMonthDop !== $newMonthDop);

        if (!$changed) {
            return null;
        }

        // Se guarda el monto en la moneda donde de verdad hubo movimiento, para
        // que el historial se lea sin tener que cruzar cuatro columnas.
        if ($oldMonthDop !== $newMonthDop) {
            $oldSalary = $oldMonthDop; $newSalary = $newMonthDop;
            $currency = 'DOP'; $salaryType = 'MONTHLY';
        } elseif ($oldMonthUsd !== $newMonthUsd) {
            $oldSalary = $oldMonthUsd; $newSalary = $newMonthUsd;
            $currency = 'USD'; $salaryType = 'MONTHLY';
        } elseif ($oldHourDop !== $newHourDop) {
            $oldSalary = $oldHourDop; $newSalary = $newHourDop;
            $currency = 'DOP'; $salaryType = 'HOURLY';
        } else {
            $oldSalary = $oldHourUsd; $newSalary = $newHourUsd;
            $currency = 'USD'; $salaryType = 'HOURLY';
        }

        if ($newSalary > $oldSalary)      { $changeType = 'INCREASE'; }
        elseif ($newSalary < $oldSalary)  { $changeType = 'DECREASE'; }
        else                              { $changeType = 'ADJUSTMENT'; }

        // El id de employee, si lo tiene (la tabla lo pide).
        $employeeId = null;
        try {
            $st = $pdo->prepare("SELECT id FROM employees WHERE user_id = ? LIMIT 1");
            $st->execute([$userId]);
            $employeeId = $st->fetchColumn() ?: null;
        } catch (Throwable $e) { /* puede no tener ficha de empleado */ }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO salary_history
                    (employee_id, user_id, old_salary, new_salary, currency, salary_type,
                     old_hourly_rate, new_hourly_rate, change_type, effective_date, reason, approved_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $employeeId,
                $userId,
                $oldSalary,
                $newSalary,
                $currency,
                $salaryType,
                $currency === 'DOP' ? $oldHourDop : $oldHourUsd,
                $currency === 'DOP' ? $newHourDop : $newHourUsd,
                $changeType,
                $effectiveDate ?: date('Y-m-d'),
                $reason,
                $changedBy ?: ($_SESSION['user_id'] ?? null),
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('recordSalaryChange: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('getSalaryHistoryForEmployee')) {
    /**
     * Línea de tiempo de cambios salariales, uniendo las dos fuentes que existen.
     *
     * @return array<int,array<string,mixed>>
     */
    function getSalaryHistoryForEmployee(PDO $pdo, int $employeeId, int $userId, int $limit = 50): array
    {
        $out = [];

        // 1. salary_history (cambios de sueldo y tarifa)
        try {
            $stmt = $pdo->prepare("
                SELECT s.*, u.full_name AS approved_by_name
                FROM salary_history s
                LEFT JOIN users u ON u.id = s.approved_by
                WHERE s.employee_id = ? OR s.user_id = ?
                ORDER BY s.effective_date DESC, s.id DESC
                LIMIT " . max(1, min(200, $limit)) . "
            ");
            $stmt->execute([$employeeId, $userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $sym = ($r['currency'] ?? 'DOP') === 'DOP' ? 'RD$' : '$';
                $out[] = [
                    'source'        => 'salary_history',
                    'date'          => $r['effective_date'],
                    'created_at'    => $r['created_at'],
                    'change_type'   => $r['change_type'],
                    'salary_type'   => $r['salary_type'] ?? 'MONTHLY',
                    'currency'      => $r['currency'] ?? 'DOP',
                    'old_amount'    => (float) $r['old_salary'],
                    'new_amount'    => (float) $r['new_salary'],
                    'old_label'     => $sym . number_format((float) $r['old_salary'], 2),
                    'new_label'     => $sym . number_format((float) $r['new_salary'], 2),
                    'diff'          => (float) $r['new_salary'] - (float) $r['old_salary'],
                    'reason'        => $r['reason'],
                    'by_name'       => $r['approved_by_name'],
                ];
            }
        } catch (Throwable $e) {
            error_log('getSalaryHistoryForEmployee (salary_history): ' . $e->getMessage());
        }

        // 2. hourly_rate_history (el formulario de cambio de tarifa)
        try {
            $stmt = $pdo->prepare("
                SELECT h.*, u.full_name AS created_by_name
                FROM hourly_rate_history h
                LEFT JOIN users u ON u.id = h.created_by
                WHERE h.user_id = ?
                ORDER BY h.effective_date DESC, h.id DESC
                LIMIT " . max(1, min(200, $limit)) . "
            ");
            $stmt->execute([$userId]);
            $prev = null;
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            // Vienen del más nuevo al más viejo; el "anterior" es la fila siguiente.
            foreach ($rows as $i => $r) {
                $older = $rows[$i + 1] ?? null;
                $dop = (float) $r['hourly_rate_dop'];
                $usd = (float) $r['hourly_rate_usd'];
                $useDop = $dop > 0;
                $sym = $useDop ? 'RD$' : '$';
                $newAmt = $useDop ? $dop : $usd;
                $oldAmt = $older ? ($useDop ? (float) $older['hourly_rate_dop'] : (float) $older['hourly_rate_usd']) : 0.0;

                $out[] = [
                    'source'      => 'hourly_rate_history',
                    'date'        => $r['effective_date'],
                    'created_at'  => $r['created_at'],
                    'change_type' => $older ? ($newAmt > $oldAmt ? 'INCREASE' : ($newAmt < $oldAmt ? 'DECREASE' : 'ADJUSTMENT')) : 'ADJUSTMENT',
                    'salary_type' => 'HOURLY',
                    'currency'    => $useDop ? 'DOP' : 'USD',
                    'old_amount'  => $oldAmt,
                    'new_amount'  => $newAmt,
                    'old_label'   => $older ? $sym . number_format($oldAmt, 2) : '—',
                    'new_label'   => $sym . number_format($newAmt, 2),
                    'diff'        => $older ? $newAmt - $oldAmt : 0.0,
                    'reason'      => $r['notes'],
                    'by_name'     => $r['created_by_name'],
                ];
            }
        } catch (Throwable $e) {
            error_log('getSalaryHistoryForEmployee (hourly_rate_history): ' . $e->getMessage());
        }

        // Una sola línea de tiempo, lo más reciente primero.
        usort($out, static function ($a, $b) {
            $c = strcmp((string) $b['date'], (string) $a['date']);
            return $c !== 0 ? $c : strcmp((string) $b['created_at'], (string) $a['created_at']);
        });

        return array_slice($out, 0, $limit);
    }
}

if (!function_exists('getPaymentHistoryForEmployee')) {
    /**
     * Historial de pagos: lo que se le ha pagado en cada período de nómina.
     *
     * @return array<int,array<string,mixed>>
     */
    function getPaymentHistoryForEmployee(PDO $pdo, int $employeeId, int $limit = 24): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT r.id, r.payroll_period_id, r.base_salary, r.overtime_hours, r.overtime_amount,
                       r.bonuses, r.commissions, r.other_income, r.gross_salary,
                       r.afp_employee, r.sfs_employee, r.isr, r.other_deductions, r.total_deductions,
                       r.net_salary, r.regular_hours, r.total_hours, r.is_paid, r.paid_at, r.notes,
                       p.name AS period_name, p.period_type, p.start_date, p.end_date,
                       p.payment_date, p.status AS period_status
                FROM payroll_records r
                INNER JOIN payroll_periods p ON p.id = r.payroll_period_id
                WHERE r.employee_id = ?
                ORDER BY p.start_date DESC, r.id DESC
                LIMIT " . max(1, min(100, $limit)) . "
            ");
            $stmt->execute([$employeeId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('getPaymentHistoryForEmployee: ' . $e->getMessage());
            return [];
        }

        foreach ($rows as &$r) {
            $r['gross_salary']    = (float) $r['gross_salary'];
            $r['net_salary']      = (float) $r['net_salary'];
            $r['total_deductions']= (float) $r['total_deductions'];
            $r['overtime_hours']  = (float) $r['overtime_hours'];
            $r['total_hours']     = (float) $r['total_hours'];
            $r['is_paid']         = (int) $r['is_paid'] === 1;
        }
        unset($r);

        return $rows;
    }
}

if (!function_exists('getPaymentHistoryTotals')) {
    /**
     * @param array<int,array<string,mixed>> $payments
     * @return array{periods:int,paid:int,gross:float,net:float,deductions:float,overtime_hours:float}
     */
    function getPaymentHistoryTotals(array $payments): array
    {
        $t = ['periods' => 0, 'paid' => 0, 'gross' => 0.0, 'net' => 0.0, 'deductions' => 0.0, 'overtime_hours' => 0.0];
        foreach ($payments as $p) {
            $t['periods']++;
            if (!empty($p['is_paid'])) {
                $t['paid']++;
            }
            $t['gross']          += (float) $p['gross_salary'];
            $t['net']            += (float) $p['net_salary'];
            $t['deductions']     += (float) $p['total_deductions'];
            $t['overtime_hours'] += (float) $p['overtime_hours'];
        }
        return $t;
    }
}

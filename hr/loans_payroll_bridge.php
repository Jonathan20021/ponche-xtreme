<?php
/**
 * Puente Préstamos <-> Nómina (ponche-xtreme <-> hhempeos_financial_system).
 *
 * Cierra el ciclo completo de los préstamos con descuento por nómina:
 *
 *  1. syncLoanDeductionsFromFinanceForPeriod()
 *     Al CALCULAR un período, trae de finanzas las cuotas pendientes de
 *     préstamos activos (vencidas dentro del período o atrasadas) y las
 *     registra como deducciones del empleado (employee_deductions) +
 *     programación en finanzas (loan_payroll_deductions, status 'scheduled').
 *     Es idempotente: recalcular el período no duplica deducciones.
 *
 *  2. applyLoanPaymentsWritebackForPeriod()
 *     Al marcar el período como PAGADO, registra en finanzas el pago real
 *     de cada cuota descontada (loan_payments + loan_installments +
 *     loan_payroll_deductions 'applied'). El trigger de finanzas actualiza
 *     los balances del préstamo y lo marca 'paid' al saldarse.
 *
 * Ambas funciones son best-effort: si la BD de finanzas no responde,
 * devuelven el error sin romper el flujo de nómina.
 */

require_once __DIR__ . '/../db_finanzas.php';

/**
 * Programa en el período las cuotas de préstamo pendientes según finanzas.
 *
 * @param PDO   $ponchePdo Conexión a hhempeos_ponche
 * @param array $period    Fila de payroll_periods (id, name, start_date, end_date)
 * @return array{ok:bool, scheduled:int, skipped:int, errors:array}
 */
function syncLoanDeductionsFromFinanceForPeriod(PDO $ponchePdo, array $period): array
{
    $result = ['ok' => true, 'scheduled' => 0, 'skipped' => 0, 'errors' => []];

    try {
        $fin = getFinanzasPdo();
    } catch (Throwable $e) {
        return ['ok' => false, 'scheduled' => 0, 'skipped' => 0, 'errors' => ['BD finanzas no disponible: ' . $e->getMessage()]];
    }

    $periodId = (int) $period['id'];

    try {
        $stmt = $fin->prepare("
            SELECT li.id, li.loan_id, li.installment_number, li.due_date,
                   li.total_amount, li.amount_paid, li.late_fee_amount, li.late_fee_paid,
                   l.loan_number, l.borrower_external_id
            FROM loan_installments li
            INNER JOIN loans l ON l.id = li.loan_id
            WHERE l.status IN ('active','in_arrears')
              AND l.payment_method = 'payroll_deduction'
              AND l.borrower_type = 'employee'
              AND li.status IN ('pending','partial','overdue')
              AND li.due_date <= ?
            ORDER BY l.id, li.installment_number
        ");
        $stmt->execute([$period['end_date']]);
        $installments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['ok' => false, 'scheduled' => 0, 'skipped' => 0, 'errors' => ['Error consultando cuotas: ' . $e->getMessage()]];
    }

    foreach ($installments as $inst) {
        try {
            $employeeId = (int) $inst['borrower_external_id'];
            if ($employeeId <= 0) {
                $result['skipped']++;
                continue;
            }

            $owed = (float) $inst['total_amount'] - (float) $inst['amount_paid']
                  + ((float) $inst['late_fee_amount'] - (float) $inst['late_fee_paid']);
            if ($owed <= 0.01) {
                $result['skipped']++;
                continue;
            }

            // ¿Ya programada para este período (o cualquier otro no revertido)?
            $check = $fin->prepare("
                SELECT id, payroll_period_id, status FROM loan_payroll_deductions
                WHERE installment_id = ? AND status IN ('scheduled','applied')
                LIMIT 1
            ");
            $check->execute([(int) $inst['id']]);
            if ($check->fetch()) {
                $result['skipped']++;
                continue;
            }

            // Deducción en ponche (la nómina la suma en el cálculo)
            $deductionName = "Préstamo {$inst['loan_number']} - Cuota {$inst['installment_number']}";
            $exists = $ponchePdo->prepare("
                SELECT id FROM employee_deductions
                WHERE employee_id = ? AND name = ? AND start_date = ?
                LIMIT 1
            ");
            $exists->execute([$employeeId, $deductionName, $period['start_date']]);
            $externalId = $exists->fetchColumn();

            if (!$externalId) {
                $ins = $ponchePdo->prepare("
                    INSERT INTO employee_deductions
                        (employee_id, name, description, type, amount, is_active, start_date, end_date)
                    VALUES (?, ?, ?, 'FIXED', ?, 1, ?, ?)
                ");
                $ins->execute([
                    $employeeId,
                    $deductionName,
                    "Cuota {$inst['installment_number']} de préstamo {$inst['loan_number']} ({$period['name']})",
                    round($owed, 2),
                    $period['start_date'],
                    $period['end_date'],
                ]);
                $externalId = (int) $ponchePdo->lastInsertId();
            } else {
                // Reflejar el adeudo vigente (pudo cambiar por pagos manuales)
                $ponchePdo->prepare("UPDATE employee_deductions SET amount = ?, is_active = 1 WHERE id = ?")
                    ->execute([round($owed, 2), (int) $externalId]);
            }

            // Programación en finanzas
            $insFin = $fin->prepare("
                INSERT INTO loan_payroll_deductions
                    (loan_id, installment_id, payroll_period_id, payroll_period_name,
                     employee_external_id, amount, status, external_deduction_id)
                VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?)
                ON DUPLICATE KEY UPDATE
                    amount = VALUES(amount),
                    status = IF(status = 'reversed', 'scheduled', status),
                    external_deduction_id = VALUES(external_deduction_id)
            ");
            $insFin->execute([
                (int) $inst['loan_id'],
                (int) $inst['id'],
                $periodId,
                $period['name'],
                (string) $employeeId,
                round($owed, 2),
                (string) $externalId,
            ]);

            $fin->prepare("
                UPDATE loan_installments
                SET payroll_period_id = ?, payroll_deduction_synced = 1, payroll_synced_at = NOW()
                WHERE id = ?
            ")->execute([$periodId, (int) $inst['id']]);

            $result['scheduled']++;
        } catch (Throwable $e) {
            $result['errors'][] = "Cuota {$inst['loan_number']}#{$inst['installment_number']}: " . $e->getMessage();
        }
    }

    return $result;
}

/**
 * Aplica en finanzas los pagos de las cuotas descontadas en el período.
 * Llamar al marcar el período como PAGADO.
 *
 * @param PDO   $ponchePdo Conexión a hhempeos_ponche
 * @param array $period    Fila de payroll_periods (id, name, start_date, end_date, payment_date)
 * @return array{ok:bool, applied:int, skipped:int, errors:array}
 */
function applyLoanPaymentsWritebackForPeriod(PDO $ponchePdo, array $period): array
{
    $result = ['ok' => true, 'applied' => 0, 'skipped' => 0, 'errors' => []];

    try {
        $fin = getFinanzasPdo();
    } catch (Throwable $e) {
        return ['ok' => false, 'applied' => 0, 'skipped' => 0, 'errors' => ['BD finanzas no disponible: ' . $e->getMessage()]];
    }

    $periodId = (int) $period['id'];
    $paymentDate = $period['payment_date'] ?: date('Y-m-d');

    try {
        $stmt = $fin->prepare("
            SELECT d.id AS deduction_id, d.amount AS scheduled_amount, d.external_deduction_id,
                   d.employee_external_id,
                   li.id AS installment_id, li.loan_id, li.installment_number,
                   li.total_amount, li.amount_paid, li.principal_amount, li.principal_paid,
                   li.interest_amount, li.interest_paid, li.late_fee_amount, li.late_fee_paid,
                   l.loan_number
            FROM loan_payroll_deductions d
            INNER JOIN loan_installments li ON li.id = d.installment_id
            INNER JOIN loans l ON l.id = d.loan_id
            WHERE d.payroll_period_id = ? AND d.status = 'scheduled'
        ");
        $stmt->execute([$periodId]);
        $deductions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['ok' => false, 'applied' => 0, 'skipped' => 0, 'errors' => ['Error consultando deducciones: ' . $e->getMessage()]];
    }

    foreach ($deductions as $ded) {
        try {
            $employeeId = (int) $ded['employee_external_id'];

            // El descuento solo es real si el empleado tuvo nómina en el período
            $rec = $ponchePdo->prepare("
                SELECT id FROM payroll_records
                WHERE payroll_period_id = ? AND employee_id = ?
                LIMIT 1
            ");
            $rec->execute([$periodId, $employeeId]);
            if (!$rec->fetchColumn()) {
                $result['skipped']++;
                $result['errors'][] = "Préstamo {$ded['loan_number']} cuota {$ded['installment_number']}: el empleado #{$employeeId} no tiene registro de nómina en el período — deducción NO aplicada.";
                continue;
            }

            // Lo realmente adeudado hoy (pudo haber pagos manuales después de programar)
            $owedFee       = (float) $ded['late_fee_amount'] - (float) $ded['late_fee_paid'];
            $owedInterest  = (float) $ded['interest_amount'] - (float) $ded['interest_paid'];
            $owedPrincipal = (float) $ded['principal_amount'] - (float) $ded['principal_paid'];
            $owedTotal     = ((float) $ded['total_amount'] - (float) $ded['amount_paid']) + $owedFee;

            $pay = min((float) $ded['scheduled_amount'], max(0.0, $owedTotal));

            $fin->beginTransaction();

            if ($pay <= 0.01) {
                // Cuota ya saldada por otra vía: cerrar la programación sin duplicar pago
                $fin->prepare("UPDATE loan_payroll_deductions SET status='applied', applied_at=NOW() WHERE id = ?")
                    ->execute([(int) $ded['deduction_id']]);
                $fin->commit();
                $result['skipped']++;
            } else {
                // Asignación: mora -> interés -> capital (mismo orden que la app de finanzas)
                $assignFee       = round(min($pay, max(0.0, $owedFee)), 2);
                $assignInterest  = round(min($pay - $assignFee, max(0.0, $owedInterest)), 2);
                $assignPrincipal = round(min($pay - $assignFee - $assignInterest, max(0.0, $owedPrincipal)), 2);

                // INSERT del pago (el trigger de finanzas actualiza balances del préstamo)
                $fin->prepare("
                    INSERT INTO loan_payments
                        (loan_id, installment_id, payment_date, amount,
                         principal_component, interest_component, late_fee_component,
                         payment_method, payroll_period_id, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'payroll_deduction', ?, ?)
                ")->execute([
                    (int) $ded['loan_id'],
                    (int) $ded['installment_id'],
                    $paymentDate,
                    round($pay, 2),
                    $assignPrincipal,
                    $assignInterest,
                    $assignFee,
                    $periodId,
                    "Descuento por nómina período {$period['name']} (aplicado automáticamente desde ponche)",
                ]);

                $appliedToInstallment = $assignPrincipal + $assignInterest;
                $fin->prepare("
                    UPDATE loan_installments
                    SET amount_paid    = amount_paid + ?,
                        principal_paid = principal_paid + ?,
                        interest_paid  = interest_paid + ?,
                        late_fee_paid  = late_fee_paid + ?,
                        status    = CASE WHEN amount_paid + ? >= total_amount - 0.01 THEN 'paid' ELSE 'partial' END,
                        paid_date = CASE WHEN amount_paid + ? >= total_amount - 0.01 THEN ? ELSE paid_date END
                    WHERE id = ?
                ")->execute([
                    $appliedToInstallment,
                    $assignPrincipal,
                    $assignInterest,
                    $assignFee,
                    $appliedToInstallment,
                    $appliedToInstallment,
                    $paymentDate,
                    (int) $ded['installment_id'],
                ]);

                $fin->prepare("UPDATE loan_payroll_deductions SET status='applied', applied_at=NOW() WHERE id = ?")
                    ->execute([(int) $ded['deduction_id']]);

                // Contador de cuotas pagadas del préstamo
                $cnt = $fin->prepare("SELECT COUNT(*) FROM loan_installments WHERE loan_id = ? AND status = 'paid'");
                $cnt->execute([(int) $ded['loan_id']]);
                $fin->prepare("UPDATE loans SET installments_paid = ? WHERE id = ?")
                    ->execute([(int) $cnt->fetchColumn(), (int) $ded['loan_id']]);

                // Auditoría en finanzas
                $fin->prepare("
                    INSERT INTO loan_audit_log (loan_id, action, description, new_value)
                    VALUES (?, 'payment_registered', ?, ?)
                ")->execute([
                    (int) $ded['loan_id'],
                    "Pago por nómina aplicado automáticamente desde ponche — período {$period['name']}",
                    json_encode([
                        'amount' => round($pay, 2),
                        'principal' => $assignPrincipal,
                        'interest' => $assignInterest,
                        'late_fee' => $assignFee,
                        'payroll_period_id' => $periodId,
                        'channel' => 'ponche_writeback',
                    ]),
                ]);

                $fin->commit();
                $result['applied']++;
            }

            // Desactivar la deducción en ponche para que no vuelva a contarse
            if (!empty($ded['external_deduction_id']) && ctype_digit((string) $ded['external_deduction_id'])) {
                try {
                    $ponchePdo->prepare("UPDATE employee_deductions SET is_active = 0 WHERE id = ?")
                        ->execute([(int) $ded['external_deduction_id']]);
                } catch (Throwable $e) {
                    // No crítico: el rango de fechas ya limita la deducción al período
                }
            }
        } catch (Throwable $e) {
            try { if ($fin->inTransaction()) $fin->rollBack(); } catch (Throwable $r) {}
            $result['errors'][] = "Préstamo {$ded['loan_number']} cuota {$ded['installment_number']}: " . $e->getMessage();
        }
    }

    return $result;
}

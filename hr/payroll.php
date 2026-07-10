<?php
session_start();
require_once '../db.php';
require_once 'payroll_functions.php';
require_once 'loans_payroll_bridge.php';
require_once '../lib/logging_functions.php';
require_once '../lib/work_hours_calculator.php';
require_once '../lib/vicidial_api_client.php';

// Check permissions
ensurePermission('hr_payroll', '../unauthorized.php');
ensurePayrollManualIncentivesTable($pdo);
ensurePayrollPeriodsVisibilityColumn($pdo);
ensurePayrollHolidaysTable($pdo);
ensureUserPayrollSourceColumn($pdo);

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle period creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_period'])) {
    $name = trim($_POST['name']);
    $periodType = $_POST['period_type'];
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $paymentDate = $_POST['payment_date'];
    
    $stmt = $pdo->prepare("
        INSERT INTO payroll_periods (name, period_type, start_date, end_date, payment_date, created_by) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$name, $periodType, $startDate, $endDate, $paymentDate, $_SESSION['user_id']]);
    $successMsg = "Período de nómina creado correctamente.";
}

// Handle period edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_period'])) {
    $periodId = (int)$_POST['period_id'];
    $name = trim($_POST['name']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $paymentDate = $_POST['payment_date'];
    
    $existingStmt = $pdo->prepare("SELECT status, start_date, end_date FROM payroll_periods WHERE id = ?");
    $existingStmt->execute([$periodId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        $errorMsg = "Período no encontrado.";
    } elseif (!in_array($existing['status'], ['DRAFT', 'CALCULATED'], true)) {
        $errorMsg = "Solo se pueden editar períodos en estado DRAFT o CALCULATED (actual: " . htmlspecialchars($existing['status']) . ").";
    } else {
        $stmt = $pdo->prepare("
            UPDATE payroll_periods
            SET name = ?, start_date = ?, end_date = ?, payment_date = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $startDate, $endDate, $paymentDate, $periodId]);

        $rangeChanged = ($existing['start_date'] !== $startDate || $existing['end_date'] !== $endDate);
        if ($existing['status'] === 'CALCULATED' && $rangeChanged) {
            $successMsg = "Período actualizado. Cambiaron las fechas del rango: recalcula la nómina para reflejar el nuevo período.";
        } else {
            $successMsg = "Período actualizado correctamente.";
        }
    }
}

// Handle toggling visibility to agents
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_visibility'])) {
    $periodId = (int)$_POST['period_id'];
    $nextState = (int)($_POST['next_state'] ?? 0) === 1 ? 1 : 0;

    $stmt = $pdo->prepare("UPDATE payroll_periods SET visible_to_agents = ? WHERE id = ?");
    $stmt->execute([$nextState, $periodId]);

    $successMsg = $nextState === 1
        ? "Período habilitado para los agentes."
        : "Período oculto para los agentes.";
}

// Handle period deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_period'])) {
    $periodId = (int)$_POST['period_id'];
    
    $pdo->beginTransaction();
    try {
        // Delete payroll records first
        $pdo->prepare("DELETE FROM payroll_records WHERE payroll_period_id = ?")->execute([$periodId]);
        // Delete period (allow DRAFT or CALCULATED)
        $pdo->prepare("DELETE FROM payroll_periods WHERE id = ? AND status IN ('DRAFT', 'CALCULATED')")->execute([$periodId]);
        $pdo->commit();
        $successMsg = "Período eliminado correctamente.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $errorMsg = "Error al eliminar: " . $e->getMessage();
    }
}

// Handle payroll calculation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate_payroll'])) {
    $periodId = (int)$_POST['period_id'];
    
    $pdo->beginTransaction();
    
    try {
        // Get period
        $periodStmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
        $periodStmt->execute([$periodId]);
        $period = $periodStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$period) {
            throw new Exception("Período no encontrado");
        }

        // Traer de finanzas las cuotas de préstamo pendientes y registrarlas
        // como deducciones del período ANTES de calcular, para que la nómina
        // las incluya automáticamente. Best-effort: si finanzas no responde,
        // la nómina se calcula igual (aviso en el mensaje).
        $loanSync = syncLoanDeductionsFromFinanceForPeriod($pdo, $period);

        $manualIncentivesMap = getPayrollManualIncentivesMap($pdo, $periodId);
        $holidaysMap = getPayrollHolidaysMap($pdo, $period['start_date'], $period['end_date']);

        // Rebuild records for this period to ensure recalculation applies new rules
        $pdo->prepare("DELETE FROM payroll_records WHERE payroll_period_id = ?")->execute([$periodId]);

        // Get employees eligible for this period: active/trial, plus terminated
        // whose termination date falls within or after the period start (so their
        // worked hours in the period can still be paid).
        // NOTA: empleados TERMINATED sin termination_date se excluyen — sin fecha
        // no podemos verificar que trabajaron en el período y se han dado casos
        // de doble pago (ej. registros duplicados marcados terminados sin fecha).
        $empStmt = $pdo->prepare("
            SELECT e.id, e.employment_status,
                   u.id as user_id, u.hourly_rate, u.monthly_salary, u.monthly_salary_dop, u.overtime_multiplier,
                   u.compensation_type, u.role,
                   COALESCE(u.payroll_source, 'manual') AS payroll_source
            FROM employees e
            JOIN users u ON u.id = e.user_id
            WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
               OR (
                    e.employment_status = 'TERMINATED'
                    AND e.termination_date IS NOT NULL
                    AND e.termination_date >= ?
                  )
        ");
        $empStmt->execute([$period['start_date']]);
        $employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $config = getScheduleConfig($pdo);
        $scheduledHours = (float)$config['scheduled_hours'];
        $weeklyOvertimeThresholdHours = 44.00;
        
        // Get paid attendance type slugs for payroll calculation
        $paidTypes = getPaidAttendanceTypeSlugs($pdo);

        // Alertas de la fuente Vicidial (días con tope / sin datos) para revisar
        // antes de aprobar. NO bloquean el cálculo; solo se muestran.
        $vicidialPayrollFlags = ['capped' => [], 'no_data' => [], 'backfilled' => []];

        foreach ($employees as $emp) {
            $userId = $emp['user_id'];
            $employeeId = $emp['id'];
            $manualInput = $manualIncentivesMap[$employeeId] ?? null;
            
            // Calculate hours from attendance.
            // NOTE: For precision consistency with /records and the Daily Attendance Report,
            // we must fetch ALL punch types (paid and non-paid) to correctly close paid intervals.
            $paidTypeSlugs = [];
            if (!empty($paidTypes)) {
                $paidTypeSlugs = array_values(array_filter(array_map('sanitizeAttendanceTypeSlug', $paidTypes)));
            }

            // Get all punches needed for weekly overtime context (ALL types).
            // If a payroll period starts mid-week, previous days from that ISO
            // week still count toward the 44-hour overtime threshold.
            $attendanceContextStart = getIsoWeekStartDate($period['start_date']);
            $punchesStmt = $pdo->prepare("
                SELECT 
                    id,
                    timestamp,
                    type,
                    DATE(timestamp) as work_date
                FROM attendance
                WHERE user_id = ?
                AND DATE(timestamp) BETWEEN ? AND ?
                ORDER BY timestamp ASC
            ");

            $punchesStmt->execute([$userId, $attendanceContextStart, $period['end_date']]);
            $punches = $punchesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $totalRegularHours = 0;
            $totalOvertimeHours = 0;
            $daysWorked = 0;

            // Determine if this employee qualifies for holiday double pay
            // (fixed-salary employees are excluded — their salary already covers everything).
            $applyHolidayDouble = shouldApplyHolidayDoublePay(
                $emp['compensation_type'] ?? null,
                $emp['role'] ?? null,
                max((float)($emp['monthly_salary'] ?? 0), (float)($emp['monthly_salary_dop'] ?? 0))
            );

            $dailyWorkSeconds = calculateDailyWorkSecondsFromPunchRows($punches, $paidTypeSlugs);

            // === FUENTE VICIDIAL (Fase 3) ===
            // Para agentes marcados payroll_source='vicidial', las horas pagables
            // vienen del desglose de Vicidial (NONPAUSE + códigos de pausa pagados,
            // con tope de cordura), NO del ponche manual. TODO lo demás — la
            // división semanal 44h, el multiplicador de extra y las deducciones
            // AFP/SFS/ISR — queda EXACTAMENTE igual. Solo cambia la fuente de horas.
            if (($emp['payroll_source'] ?? 'manual') === 'vicidial') {
                $punchDaily = $dailyWorkSeconds; // ponche ya calculado arriba (respaldo por día)
                $vd = vicidialGetPaidSecondsByDate($pdo, (int) $userId, $attendanceContextStart, $period['end_date']);
                // Merge POR DÍA respetando la fecha de corte de la transición: los días
                // ANTES de la fecha efectiva de Vicidial se pagan por el PONCHE (el
                // régimen en que trabajaban entonces), y los días DESDE esa fecha por
                // Vicidial (con respaldo de ponche en los días sin registro Vicidial).
                $vEff  = getVicidialPayrollEffectiveDate($pdo);
                $merge = vicidialMergeDailySeconds($punchDaily, $vd, $vEff);
                $dailyWorkSeconds = $merge['by_date'];

                if (!empty($vd['capped_days'])) {
                    $vicidialPayrollFlags['capped'][] = ['user_id' => (int) $userId, 'days' => $vd['capped_days']];
                }
                if (($vd['days'] ?? 0) === 0) {
                    // Sin NINGÚN dato de Vicidial en el período: se pagó por ponche (nunca 0).
                    $vicidialPayrollFlags['no_data'][] = (int) $userId;
                } else {
                    // Días POST-cambio pagados por ponche por falta de registro en Vicidial
                    // (hueco real / posible agente mal clasificado). Los días PRE-cambio
                    // pagados por ponche son esperados por la transición, no se marcan.
                    $bf = [];
                    foreach ($merge['source'] as $d => $s) {
                        if ($s === 'ponche' && (!$vEff || $d >= $vEff)) {
                            $bf[] = $d;
                        }
                    }
                    if (!empty($bf)) {
                        $vicidialPayrollFlags['backfilled'][] = ['user_id' => (int) $userId, 'days' => $bf];
                    }
                }
            }

            $weeklySplit = splitWeeklyRegularOvertimeSeconds(
                $dailyWorkSeconds,
                (int) round($weeklyOvertimeThresholdHours * 3600)
            );

            foreach ($weeklySplit['by_day'] as $date => $daySplit) {
                if ($date < $period['start_date'] || $date > $period['end_date']) {
                    continue;
                }

                $totalSecondsWorked = (int) ($daySplit['work_seconds'] ?? 0);
                if ($totalSecondsWorked <= 0) {
                    continue;
                }

                $daysWorked++;
                $dayRegular = ((int) ($daySplit['regular_seconds'] ?? 0)) / 3600;
                $dayOvertime = ((int) ($daySplit['overtime_seconds'] ?? 0)) / 3600;

                // Apply holiday multiplier after the weekly regular/overtime split.
                // Fixed-salary employees are excluded — their salary is not hour-based.
                if ($applyHolidayDouble && isset($holidaysMap[$date])) {
                    $multiplier = (float) $holidaysMap[$date]['multiplier'];
                    $dayRegular *= $multiplier;
                    $dayOvertime *= $multiplier;
                }

                $totalRegularHours += $dayRegular;
                $totalOvertimeHours += $dayOvertime;
            }

            $manualRegularHours = max(0, round((float) ($manualInput['manual_regular_hours'] ?? 0), 2));
            $manualOvertimeHours = max(0, round((float) ($manualInput['manual_overtime_hours'] ?? 0), 2));

            // Manual hours ONLY apply when "Corregir Base" is checked AND at least one
            // value is > 0. Otherwise the punched hours are authoritative — never sum
            // (summing was error-prone and caused doubled hours on every recalculation).
            $hasManualOverride = !empty($manualInput['use_manual_hours'])
                && ($manualRegularHours > 0 || $manualOvertimeHours > 0);
            if ($hasManualOverride) {
                $totalRegularHours = $manualRegularHours;
                $totalOvertimeHours = $manualOvertimeHours;
            }

            // Determine effective compensation type (mirrors calculateEmployeePayroll).
            $compTypeForOt = strtolower(trim($emp['compensation_type'] ?? 'hourly'));
            $roleForOt = strtoupper(trim($emp['role'] ?? ''));
            if ($compTypeForOt === '' || $compTypeForOt === 'hourly') {
                if ($roleForOt !== 'AGENT' && max((float)($emp['monthly_salary'] ?? 0), (float)($emp['monthly_salary_dop'] ?? 0)) > 0) {
                    $compTypeForOt = 'fixed';
                }
            }

            // Fixed-salary employees (monthly) don't accrue auto overtime — their salary
            // covers all hours worked. The HR can still pay overtime explicitly via
            // "Corregir Base". Roll the auto-detected overtime into regular hours so the
            // visible total stays correct.
            if ($compTypeForOt === 'fixed' && !$hasManualOverride && $totalOvertimeHours > 0) {
                $totalRegularHours += $totalOvertimeHours;
                $totalOvertimeHours = 0;
            }

            $daysWorked = (int) ceil(max($totalRegularHours + $totalOvertimeHours, 0) / max($scheduledHours, 0.01));
            
            // Calculate payroll
            $hoursData = [
                'regular_hours' => $totalRegularHours,
                'overtime_hours' => $totalOvertimeHours,
                'days_worked' => $daysWorked,
                'bonuses' => (float)($manualIncentivesMap[$employeeId]['night_incentive'] ?? 0),
                'commissions' => (float)($manualIncentivesMap[$employeeId]['sales_incentive'] ?? 0),
                'other_income' => 0,
                'cooperative_deduction' => (float)($manualIncentivesMap[$employeeId]['cooperative_deduction'] ?? 0),
                'additional_deduction' => (float)($manualIncentivesMap[$employeeId]['additional_deduction'] ?? 0),
            ];
            
            $payrollData = calculateEmployeePayroll($pdo, $employeeId, $periodId, $hoursData);
            
            if ($payrollData) {
                // Check if record exists
                $checkStmt = $pdo->prepare("SELECT id FROM payroll_records WHERE payroll_period_id = ? AND employee_id = ?");
                $checkStmt->execute([$periodId, $employeeId]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    // Update
                    $updateStmt = $pdo->prepare("
                        UPDATE payroll_records SET
                            base_salary = ?, regular_hours = ?, overtime_hours = ?, overtime_amount = ?,
                            bonuses = ?, commissions = ?, other_income = ?, gross_salary = ?,
                            afp_employee = ?, sfs_employee = ?, isr = ?, other_deductions = ?, total_deductions = ?,
                            afp_employer = ?, sfs_employer = ?, srl_employer = ?, infotep_employer = ?, total_employer_contributions = ?,
                            net_salary = ?, total_hours = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([
                        $payrollData['base_salary'], $payrollData['regular_hours'], $payrollData['overtime_hours'], $payrollData['overtime_amount'],
                        $payrollData['bonuses'], $payrollData['commissions'], $payrollData['other_income'], $payrollData['gross_salary'],
                        $payrollData['afp_employee'], $payrollData['sfs_employee'], $payrollData['isr'], $payrollData['other_deductions'], $payrollData['total_deductions'],
                        $payrollData['afp_employer'], $payrollData['sfs_employer'], $payrollData['srl_employer'], $payrollData['infotep_employer'], $payrollData['total_employer_contributions'],
                        $payrollData['net_salary'], $payrollData['total_hours'],
                        $existing['id']
                    ]);
                } else {
                    // Insert
                    $insertStmt = $pdo->prepare("
                        INSERT INTO payroll_records (
                            payroll_period_id, employee_id,
                            base_salary, regular_hours, overtime_hours, overtime_amount,
                            bonuses, commissions, other_income, gross_salary,
                            afp_employee, sfs_employee, isr, other_deductions, total_deductions,
                            afp_employer, sfs_employer, srl_employer, infotep_employer, total_employer_contributions,
                            net_salary, total_hours
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insertStmt->execute([
                        $periodId, $employeeId,
                        $payrollData['base_salary'], $payrollData['regular_hours'], $payrollData['overtime_hours'], $payrollData['overtime_amount'],
                        $payrollData['bonuses'], $payrollData['commissions'], $payrollData['other_income'], $payrollData['gross_salary'],
                        $payrollData['afp_employee'], $payrollData['sfs_employee'], $payrollData['isr'], $payrollData['other_deductions'], $payrollData['total_deductions'],
                        $payrollData['afp_employer'], $payrollData['sfs_employer'], $payrollData['srl_employer'], $payrollData['infotep_employer'], $payrollData['total_employer_contributions'],
                        $payrollData['net_salary'], $payrollData['total_hours']
                    ]);
                }
            }
        }
        
        // Update period totals
        $totalsStmt = $pdo->prepare("
            SELECT SUM(gross_salary) as total_gross, SUM(total_deductions) as total_deductions, SUM(net_salary) as total_net
            FROM payroll_records
            WHERE payroll_period_id = ?
        ");
        $totalsStmt->execute([$periodId]);
        $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
        
        $updatePeriodStmt = $pdo->prepare("
            UPDATE payroll_periods SET
                total_gross = ?, total_deductions = ?, total_net = ?, status = 'CALCULATED', updated_at = NOW()
            WHERE id = ?
        ");
        $updatePeriodStmt->execute([
            $totals['total_gross'], $totals['total_deductions'], $totals['total_net'], $periodId
        ]);
        
        // Log payroll generation
        log_payroll_generated($pdo, $_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], $period['start_date'], $period['end_date'], count($employees));
        
        $pdo->commit();
        $successMsg = "Nómina calculada correctamente para " . count($employees) . " empleados.";

        // Alertas de la fuente Vicidial: días con tope (posible sesión abierta) o
        // agentes sin datos de Vicidial (no se loguearon / discador caído). No
        // bloquean, pero conviene revisarlos ANTES de aprobar.
        if (!empty($vicidialPayrollFlags['capped']) || !empty($vicidialPayrollFlags['no_data']) || !empty($vicidialPayrollFlags['backfilled'])) {
            $nCapped = count($vicidialPayrollFlags['capped']);
            $nNoData = count($vicidialPayrollFlags['no_data']);
            $nBackfill = count($vicidialPayrollFlags['backfilled']);
            $successMsg .= " ⚠️ Vicidial:";
            if ($nCapped > 0) {
                $successMsg .= " $nCapped empleado(s) con día(s) sobre el tope (revisar sesiones dejadas abiertas).";
            }
            if ($nBackfill > 0) {
                $successMsg .= " $nBackfill agente(s) Vicidial con día(s) trabajados SIN registro en Vicidial — esos días se pagaron por el PONCHE (no se les descontó); si no deben usar Vicidial, cámbialos a 'Ponche' en Conciliación.";
            }
            if ($nNoData > 0) {
                $successMsg .= " $nNoData agente(s) Vicidial SIN datos en el período (no se loguearon o discador caído) — se les dejó el PONCHE como respaldo (no cobran 0); revísalos.";
            }
            $successMsg .= " Revísalos antes de aprobar.";
        }
        if (!empty($loanSync)) {
            if (!$loanSync['ok']) {
                $successMsg .= " ⚠️ Préstamos: no se pudo sincronizar con finanzas (" . implode('; ', $loanSync['errors']) . ").";
            } elseif ($loanSync['scheduled'] > 0) {
                $successMsg .= " Préstamos: {$loanSync['scheduled']} cuota(s) programada(s) como deducción.";
            }
            if ($loanSync['ok'] && !empty($loanSync['errors'])) {
                $successMsg .= " Avisos préstamos: " . implode('; ', $loanSync['errors']);
            }
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $errorMsg = "Error al calcular nómina: " . $e->getMessage();
    }
}

// Handle period approval (CALCULATED -> APPROVED)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_period'])) {
    $periodId = (int)$_POST['period_id'];

    $stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$periodId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$period) {
        $errorMsg = "Período no encontrado.";
    } elseif ($period['status'] !== 'CALCULATED') {
        $errorMsg = "Solo se pueden aprobar períodos en estado CALCULATED (actual: " . htmlspecialchars($period['status']) . ").";
    } else {
        $pdo->prepare("UPDATE payroll_periods SET status = 'APPROVED', updated_at = NOW() WHERE id = ?")->execute([$periodId]);
        $successMsg = "Período aprobado. Al marcarlo como PAGADO se registrarán los pagos de préstamos en finanzas.";
    }
}

// Handle period payment (CALCULATED/APPROVED -> PAID) + write-back de préstamos a finanzas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_period_paid'])) {
    $periodId = (int)$_POST['period_id'];

    $stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$periodId]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$period) {
        $errorMsg = "Período no encontrado.";
    } elseif (!in_array($period['status'], ['CALCULATED', 'APPROVED'], true)) {
        $errorMsg = "Solo se pueden marcar como pagados períodos CALCULATED o APPROVED (actual: " . htmlspecialchars($period['status']) . ").";
    } else {
        // 1) Registrar en finanzas los pagos de cuotas de préstamo descontadas
        $writeback = applyLoanPaymentsWritebackForPeriod($pdo, $period);

        // 2) Marcar el período como pagado
        $pdo->prepare("UPDATE payroll_periods SET status = 'PAID', updated_at = NOW() WHERE id = ?")->execute([$periodId]);

        $successMsg = "Período marcado como PAGADO.";
        if (!$writeback['ok']) {
            $errorMsg = "El período quedó PAGADO, pero NO se pudieron registrar los pagos de préstamos en finanzas: "
                . implode('; ', $writeback['errors'])
                . " — Puedes aplicarlos manualmente desde la app de finanzas (Préstamos → Nómina).";
        } else {
            if ($writeback['applied'] > 0) {
                $successMsg .= " Préstamos: {$writeback['applied']} pago(s) registrado(s) en finanzas.";
            }
            if (!empty($writeback['errors'])) {
                $successMsg .= " Avisos: " . implode('; ', $writeback['errors']);
            }
        }
    }
}

// Handle manual payroll inputs save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_manual_incentives'])) {
    $periodId = (int)($_POST['period_id'] ?? 0);

    $periodStmt = $pdo->prepare("SELECT id, status FROM payroll_periods WHERE id = ?");
    $periodStmt->execute([$periodId]);
    $period = $periodStmt->fetch(PDO::FETCH_ASSOC);

    if (!$period) {
        $errorMsg = "PerÃ­odo no encontrado.";
    } elseif (!in_array($period['status'], ['DRAFT', 'CALCULATED'], true)) {
        $errorMsg = "Solo puedes editar ajustes manuales en perÃ­odos DRAFT o CALCULATED.";
    } else {
        $rows = $_POST['manual_incentives'] ?? [];

        $pdo->beginTransaction();
        try {
            $deleteStmt = $pdo->prepare("DELETE FROM payroll_manual_incentives WHERE payroll_period_id = ? AND employee_id = ?");
            $upsertStmt = $pdo->prepare("
                INSERT INTO payroll_manual_incentives (
                    payroll_period_id, employee_id, sales_incentive, night_incentive,
                    use_manual_hours, manual_regular_hours, manual_overtime_hours, notes, cooperative_deduction, additional_deduction
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    sales_incentive = VALUES(sales_incentive),
                    night_incentive = VALUES(night_incentive),
                    use_manual_hours = VALUES(use_manual_hours),
                    manual_regular_hours = VALUES(manual_regular_hours),
                    manual_overtime_hours = VALUES(manual_overtime_hours),
                    notes = VALUES(notes),
                    cooperative_deduction = VALUES(cooperative_deduction),
                    additional_deduction = VALUES(additional_deduction)
            ");

            foreach ($rows as $employeeId => $values) {
                $employeeId = (int)$employeeId;
                if ($employeeId <= 0) {
                    continue;
                }

                $sales = isset($values['sales']) ? round((float)$values['sales'], 2) : 0.00;
                $night = isset($values['night']) ? round((float)$values['night'], 2) : 0.00;
                $useManualHours = !empty($values['use_manual_hours']) ? 1 : 0;
                $manualRegularHours = isset($values['manual_regular_hours']) ? round(max((float)$values['manual_regular_hours'], 0), 2) : 0.00;
                $manualOvertimeHours = isset($values['manual_overtime_hours']) ? round(max((float)$values['manual_overtime_hours'], 0), 2) : 0.00;
                // Defensive: a "Corregir Base" flag with both hour inputs at 0 would silently
                // wipe the real punched hours on the next recalculation. Treat it as off.
                if ($useManualHours === 1 && $manualRegularHours == 0.0 && $manualOvertimeHours == 0.0) {
                    $useManualHours = 0;
                }
                $notes = trim((string)($values['notes'] ?? ''));
                $notes = $notes !== '' ? mb_substr($notes, 0, 255) : null;
                $cooperative = isset($values['cooperative']) ? round(max((float)$values['cooperative'], 0), 2) : 0.00;
                $additional = isset($values['additional']) ? round(max((float)$values['additional'], 0), 2) : 0.00;

                if ($sales == 0.0 && $night == 0.0 && $useManualHours === 0 && $manualRegularHours == 0.0 && $manualOvertimeHours == 0.0 && $notes === null && $cooperative == 0.0 && $additional == 0.0) {
                    $deleteStmt->execute([$periodId, $employeeId]);
                    continue;
                }

                $upsertStmt->execute([
                    $periodId,
                    $employeeId,
                    $sales,
                    $night,
                    $useManualHours,
                    $manualRegularHours,
                    $manualOvertimeHours,
                    $notes,
                    $cooperative,
                    $additional
                ]);
            }

            $pdo->commit();
            $successMsg = "Ajustes manuales guardados. Recalcula la nÃ³mina para reflejar horas e incentivos.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $errorMsg = "Error al guardar ajustes manuales: " . $e->getMessage();
        }
    }
}

// Get all periods
$periods = $pdo->query("
    SELECT pp.id, pp.name, pp.period_type, pp.start_date, pp.end_date, pp.payment_date, pp.status,
           pp.visible_to_agents,
           pp.total_gross, pp.total_deductions, pp.total_net,
           COUNT(pr.id) as employee_count,
           u.username as created_by_username
    FROM payroll_periods pp
    LEFT JOIN payroll_records pr ON pr.payroll_period_id = pp.id
    LEFT JOIN users u ON u.id = pp.created_by
    GROUP BY pp.id, pp.name, pp.period_type, pp.start_date, pp.end_date, pp.payment_date, pp.status,
             pp.visible_to_agents,
             pp.total_gross, pp.total_deductions, pp.total_net, u.username
    ORDER BY COALESCE(pp.updated_at, pp.created_at) DESC, pp.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get selected period
$selectedPeriodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : null;
$selectedPeriod = null;
$payrollRecords = [];
$manualIncentives = [];
$editableEmployees = [];

if ($selectedPeriodId) {
    $stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$selectedPeriodId]);
    $selectedPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selectedPeriod) {
        $manualIncentives = getPayrollManualIncentivesMap($pdo, $selectedPeriodId);

        $agentsStmt = $pdo->prepare("
            SELECT e.id, e.employee_code, e.first_name, e.last_name, e.position, u.role,
                   e.employment_status, e.termination_date
            FROM employees e
            JOIN users u ON u.id = e.user_id
            WHERE e.employment_status IN ('ACTIVE', 'TRIAL')
               OR (
                    e.employment_status = 'TERMINATED'
                    AND e.termination_date IS NOT NULL
                    AND e.termination_date >= ?
                  )
            ORDER BY (e.employment_status = 'TERMINATED') ASC, e.last_name, e.first_name
        ");
        $agentsStmt->execute([$selectedPeriod['start_date']]);
        $editableEmployees = $agentsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Hours actually computed for this period, used as defaults in the manual-hours
        // inputs so the user has a reference value instead of seeing 0.00.
        $hoursStmt = $pdo->prepare("
            SELECT employee_id, regular_hours, overtime_hours
            FROM payroll_records
            WHERE payroll_period_id = ?
        ");
        $hoursStmt->execute([$selectedPeriodId]);
        $payrollHoursMap = [];
        foreach ($hoursStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $payrollHoursMap[(int)$r['employee_id']] = [
                'regular_hours' => (float)$r['regular_hours'],
                'overtime_hours' => (float)$r['overtime_hours'],
            ];
        }

        $recordsStmt = $pdo->prepare("
            SELECT pr.*, e.first_name, e.last_name, e.employee_code, e.identification_number, d.name as department_name,
                   COALESCE(pmi.sales_incentive, 0) as sales_incentive,
                   COALESCE(pmi.night_incentive, 0) as night_incentive,
                   COALESCE(pmi.use_manual_hours, 0) as use_manual_hours,
                   COALESCE(pmi.manual_regular_hours, 0) as manual_regular_hours,
                   COALESCE(pmi.manual_overtime_hours, 0) as manual_overtime_hours,
                   COALESCE(pmi.cooperative_deduction, 0) as cooperative_deduction,
                   COALESCE(pmi.additional_deduction, 0) as additional_deduction
            FROM payroll_records pr
            JOIN employees e ON e.id = pr.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN payroll_manual_incentives pmi
                ON pmi.payroll_period_id = pr.payroll_period_id
               AND pmi.employee_id = pr.employee_id
            WHERE pr.payroll_period_id = ?
            ORDER BY e.last_name, e.first_name
        ");
        $recordsStmt->execute([$selectedPeriodId]);
        $payrollRecords = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Cargar cuotas de préstamo por empleado para el período actual.
// Se obtienen en una sola query batched (evita N+1 en la tabla de nómina).
$loanDeductionsByEmployee = [];
if ($selectedPeriod && !empty($payrollRecords)) {
    $employeeIdsForLoans = array_map(static fn($r) => (int) $r['employee_id'], $payrollRecords);
    $loanDeductionsByEmployee = getLoanDeductionsForEmployees(
        $pdo,
        $employeeIdsForLoans,
        $selectedPeriod['start_date'],
        $selectedPeriod['end_date']
    );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nómina RD - HR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold mb-2">
                    <i class="fas fa-money-bill-wave text-green-400 mr-3"></i>
                    Nómina República Dominicana
                </h1>
                <p class="text-slate-400">Sistema completo con AFP, SFS, ISR, TSS y DGII</p>
            </div>
            <div class="flex gap-3 flex-wrap">
                <a href="reports/campaign_profitability.php" class="btn-primary" title="Reporte gerencial: ingreso vs costo por campaña">
                    <i class="fas fa-chart-line"></i>
                    Rentabilidad
                </a>
                <a href="payroll_by_campaign.php?group_by=campaign<?= $selectedPeriodId ? ('&period_id=' . (int)$selectedPeriodId) : '' ?>" class="btn-secondary">
                    <i class="fas fa-bullhorn"></i>
                    Por Campaña
                </a>
                <a href="payroll_by_campaign.php?group_by=department<?= $selectedPeriodId ? ('&period_id=' . (int)$selectedPeriodId) : '' ?>" class="btn-secondary">
                    <i class="fas fa-building"></i>
                    Por Departamento
                </a>
                <a href="employee_deductions.php" class="btn-secondary">
                    <i class="fas fa-hand-holding-usd"></i>
                    Descuentos por Empleado
                </a>
                <?php if (userHasPermission('payroll_hours_adjust')): ?>
                <a href="payroll_hours.php" class="btn-secondary">
                    <i class="fas fa-user-clock"></i>
                    Ajuste de Horas
                </a>
                <?php endif; ?>
                <a href="payroll_settings.php" class="btn-secondary">
                    <i class="fas fa-cog"></i>
                    Configuración
                </a>
                <button onclick="document.getElementById('createPeriodModal').classList.remove('hidden')" class="btn-primary">
                    <i class="fas fa-plus"></i>
                    Nuevo Período
                </button>
                <a href="index.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver
                </a>
            </div>
        </div>

        <?php if (isset($successMsg)): ?>
            <div class="status-banner success mb-6"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        
        <?php if (isset($errorMsg)): ?>
            <div class="status-banner error mb-6"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <!-- Periods List -->
        <div class="glass-card mb-8">
            <h2 class="text-xl font-semibold mb-4">
                <i class="fas fa-calendar-alt text-indigo-400 mr-2"></i>
                Períodos de Nómina
            </h2>
            
            <?php if (empty($periods)): ?>
                <p class="text-slate-400 text-center py-8">No hay períodos creados.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4">Período</th>
                                <th class="text-left py-3 px-4">Fechas</th>
                                <th class="text-left py-3 px-4">Pago</th>
                                <th class="text-center py-3 px-4">Empleados</th>
                                <th class="text-right py-3 px-4">Total Bruto</th>
                                <th class="text-right py-3 px-4">Descuentos</th>
                                <th class="text-right py-3 px-4">Total Neto</th>
                                <th class="text-center py-3 px-4">Estado</th>
                                <th class="text-center py-3 px-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($periods as $period): ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                    <td class="py-3 px-4 font-medium"><?= htmlspecialchars($period['name']) ?></td>
                                    <td class="py-3 px-4 text-sm">
                                        <?= date('d/m/Y', strtotime($period['start_date'])) ?> - 
                                        <?= date('d/m/Y', strtotime($period['end_date'])) ?>
                                    </td>
                                    <td class="py-3 px-4 text-sm">
                                        <?= date('d/m/Y', strtotime($period['payment_date'])) ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="tag-pill"><?= $period['employee_count'] ?></span>
                                    </td>
                                    <td class="py-3 px-4 text-right font-semibold">
                                        <?= formatDOP($period['total_gross'] ?: 0) ?>
                                    </td>
                                    <td class="py-3 px-4 text-right text-red-400">
                                        <?= formatDOP($period['total_deductions'] ?: 0) ?>
                                    </td>
                                    <td class="py-3 px-4 text-right text-green-400 font-semibold">
                                        <?= formatDOP($period['total_net'] ?: 0) ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <?php
                                        $statusColors = [
                                            'DRAFT' => 'bg-gray-500',
                                            'CALCULATED' => 'bg-blue-500',
                                            'APPROVED' => 'bg-purple-500',
                                            'PAID' => 'bg-green-500',
                                            'CLOSED' => 'bg-gray-600'
                                        ];
                                        $statusColor = $statusColors[$period['status']] ?? 'bg-gray-500';
                                        ?>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold text-white <?= $statusColor ?>">
                                            <?= htmlspecialchars($period['status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex justify-center gap-1">
                                            <a href="?period_id=<?= $period['id'] ?>" class="px-2 py-1 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs" title="Ver">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($period['status'] === 'DRAFT' || $period['status'] === 'CALCULATED'): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="calculate_payroll" value="1">
                                                    <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                    <button type="submit" class="px-2 py-1 rounded bg-gray-600 hover:bg-gray-700 text-white text-xs" title="Calcular">
                                                        <i class="fas fa-calculator"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($period['status'] === 'CALCULATED'): ?>
                                                <a href="payroll_export_pdf.php?period_id=<?= $period['id'] ?>" class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs" target="_blank" title="PDF">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                                <a href="payroll_export_excel.php?period_id=<?= $period['id'] ?>" class="px-2 py-1 rounded bg-green-600 hover:bg-green-700 text-white text-xs" title="Excel">
                                                    <i class="fas fa-file-excel"></i>
                                                </a>
                                                <a href="payroll_export_bank.php?period_id=<?= $period['id'] ?>" class="px-2 py-1 rounded bg-blue-600 hover:bg-blue-700 text-white text-xs" title="Excel Bancario (BHD)">
                                                    <i class="fas fa-university"></i>
                                                </a>
                                                <form method="POST" class="inline" onsubmit="return confirm('¿Aprobar este período de nómina?')">
                                                    <input type="hidden" name="approve_period" value="1">
                                                    <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                    <button type="submit" class="px-2 py-1 rounded bg-purple-600 hover:bg-purple-700 text-white text-xs" title="Aprobar período">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($period['status'] === 'CALCULATED' || $period['status'] === 'APPROVED'): ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('¿Marcar este período como PAGADO?\n\nSe registrarán automáticamente en la app de finanzas los pagos de las cuotas de préstamo descontadas en esta nómina. Esta acción cierra el período.')">
                                                    <input type="hidden" name="mark_period_paid" value="1">
                                                    <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                    <button type="submit" class="px-2 py-1 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-xs" title="Marcar como pagada (registra pagos de préstamos en finanzas)">
                                                        <i class="fas fa-money-check-dollar"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php
                                                $isVisibleToAgents = (int)($period['visible_to_agents'] ?? 0) === 1;
                                                $visBtnClass = $isVisibleToAgents
                                                    ? 'bg-emerald-600 hover:bg-emerald-700'
                                                    : 'bg-slate-600 hover:bg-slate-700';
                                                $visBtnIcon = $isVisibleToAgents ? 'fa-eye' : 'fa-eye-slash';
                                                $visBtnTitle = $isVisibleToAgents
                                                    ? 'Visible para agentes (clic para ocultar)'
                                                    : 'Oculto para agentes (clic para mostrar)';
                                            ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="toggle_visibility" value="1">
                                                <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                <input type="hidden" name="next_state" value="<?= $isVisibleToAgents ? 0 : 1 ?>">
                                                <button type="submit" class="px-2 py-1 rounded <?= $visBtnClass ?> text-white text-xs" title="<?= htmlspecialchars($visBtnTitle) ?>">
                                                    <i class="fas <?= $visBtnIcon ?>"></i>
                                                </button>
                                            </form>
                                            <?php if ($period['status'] === 'DRAFT' || $period['status'] === 'CALCULATED'): ?>
                                                <button onclick="editPeriod(<?= $period['id'] ?>, '<?= htmlspecialchars($period['name'], ENT_QUOTES) ?>', '<?= $period['start_date'] ?>', '<?= $period['end_date'] ?>', '<?= $period['payment_date'] ?>')" class="px-2 py-1 rounded bg-yellow-600 hover:bg-yellow-700 text-white text-xs" title="Editar">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este período?')">
                                                    <input type="hidden" name="delete_period" value="1">
                                                    <input type="hidden" name="period_id" value="<?= $period['id'] ?>">
                                                    <button type="submit" class="px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs" title="Eliminar">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($selectedPeriod): ?>
            <div class="glass-card mb-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-6">
                    <div>
                        <h2 class="text-xl font-semibold">
                            <i class="fas fa-moon text-amber-400 mr-2"></i>
                            Ajustes Manuales por Empleado
                        </h2>
                        <p class="text-sm text-slate-400">Las horas reales del ponche son la base. Solo si marcas <strong>"Corregir Base"</strong> y escribes valores, las horas manuales <strong>reemplazan</strong> el cálculo del ponche para ese empleado. Si la casilla está apagada, el valor escrito se ignora.</p>
                    </div>
                    <div class="text-sm text-slate-400">
                        Estado del periodo: <span class="font-semibold text-slate-200"><?= htmlspecialchars($selectedPeriod['status']) ?></span>
                    </div>
                </div>

                <?php if (empty($editableEmployees)): ?>
                    <p class="text-slate-400">No hay empleados activos disponibles para capturar ajustes.</p>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="save_manual_incentives" value="1">
                        <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">

                        <p class="mb-3 text-xs text-slate-400">
                            Las horas de ponche se separan por semana laboral (lunes a domingo): primeras 44h regulares y excedente como extra.
                            El total trabajado es la suma de regular + extra.
                        </p>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-700">
                                        <th class="text-left py-2 px-2">Empleado</th>
                                        <th class="text-left py-2 px-2">Codigo</th>
                                        <th class="text-center py-2 px-2">Corregir Base</th>
                                        <th class="text-right py-2 px-2">Horas Regulares</th>
                                        <th class="text-right py-2 px-2">Horas Extra</th>
                                        <th class="text-right py-2 px-2">Incentivo Ventas</th>
                                        <th class="text-right py-2 px-2">Incentivo Nocturno</th>
                                        <th class="text-right py-2 px-2">Cooperativa</th>
                                        <th class="text-right py-2 px-2">Descuento</th>
                                        <th class="text-left py-2 px-2">Nota</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($editableEmployees as $agent):
                                        $agentIncentive = $manualIncentives[(int)$agent['id']] ?? [
                                            'sales_incentive' => 0,
                                            'night_incentive' => 0,
                                            'use_manual_hours' => 0,
                                            'manual_regular_hours' => 0,
                                            'manual_overtime_hours' => 0,
                                            'notes' => '',
                                            'cooperative_deduction' => 0,
                                            'additional_deduction' => 0,
                                        ];
                                        // Inputs always show the stored manual override value (or 0 if none).
                                        // Pre-filling with punched hours caused doubled-hours bugs because old
                                        // calculate logic summed the input into the punch total.
                                        $punchHours = $payrollHoursMap[(int)$agent['id']] ?? ['regular_hours' => 0.0, 'overtime_hours' => 0.0];
                                        $punchTotalHours = (float)$punchHours['regular_hours'] + (float)$punchHours['overtime_hours'];
                                        $hasPunchOvertime = (float)$punchHours['overtime_hours'] > 0.0001;
                                        $regularDisplay = (float)$agentIncentive['manual_regular_hours'];
                                        $overtimeDisplay = (float)$agentIncentive['manual_overtime_hours'];
                                    ?>
                                        <?php $isTerminated = ($agent['employment_status'] ?? '') === 'TERMINATED'; ?>
                                        <tr class="border-b border-slate-800 hover:bg-slate-800/40<?= $isTerminated ? ' bg-amber-900/10' : '' ?>">
                                            <td class="py-2 px-2">
                                                <div class="font-medium flex items-center gap-2">
                                                    <?= htmlspecialchars($agent['first_name'] . ' ' . $agent['last_name']) ?>
                                                    <?php if ($isTerminated): ?>
                                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-amber-300 bg-amber-900/40 border border-amber-700/60 rounded px-1.5 py-0.5"
                                                              title="Empleado terminado<?= !empty($agent['termination_date']) ? ' el ' . htmlspecialchars(date('d/m/Y', strtotime($agent['termination_date']))) : '' ?>. Se mantiene en nómina para pago de horas pendientes.">
                                                            Terminado
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-xs text-slate-400"><?= htmlspecialchars($agent['position'] ?: ($agent['role'] ?: 'Empleado')) ?></div>
                                                <div class="mt-1 text-[11px] text-slate-500">
                                                    Total ponche: <?= number_format($punchTotalHours, 2) ?>h
                                                    <?php if ($hasPunchOvertime): ?>
                                                        <span class="text-amber-300">(extra semanal: <?= number_format((float)$punchHours['overtime_hours'], 2) ?>h)</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="py-2 px-2 text-slate-300"><?= htmlspecialchars($agent['employee_code']) ?></td>
                                            <td class="py-2 px-2 text-center">
                                                <input
                                                    type="checkbox"
                                                    name="manual_incentives[<?= (int)$agent['id'] ?>][use_manual_hours]"
                                                    value="1"
                                                    <?= !empty($agentIncentive['use_manual_hours']) ? 'checked' : '' ?>
                                                    class="h-4 w-4 rounded border-slate-600 bg-slate-900"
                                                >
                                            </td>
                                            <td class="py-2 px-2">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    name="manual_incentives[<?= (int)$agent['id'] ?>][manual_regular_hours]"
                                                    value="<?= htmlspecialchars(number_format($regularDisplay, 2, '.', '')) ?>"
                                                    placeholder="Ponche: <?= number_format($punchHours['regular_hours'], 2) ?>"
                                                    class="w-full rounded border border-slate-700 bg-slate-900 px-3 py-2 text-right placeholder:text-slate-500"
                                                    title="Horas regulares del ponche segun la regla semanal de 44h: <?= number_format($punchHours['regular_hours'], 2) ?>. Marca 'Corregir Base' y escribe un valor para reemplazar."
                                                >
                                                <div class="text-xs text-slate-500 mt-1 text-right">Ponche: <?= number_format($punchHours['regular_hours'], 2) ?></div>
                                            </td>
                                            <td class="py-2 px-2">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    name="manual_incentives[<?= (int)$agent['id'] ?>][manual_overtime_hours]"
                                                    value="<?= htmlspecialchars(number_format($overtimeDisplay, 2, '.', '')) ?>"
                                                    placeholder="Ponche: <?= number_format($punchHours['overtime_hours'], 2) ?>"
                                                    class="w-full rounded border border-slate-700 bg-slate-900 px-3 py-2 text-right placeholder:text-slate-500"
                                                    title="Horas extra del ponche por excedente semanal sobre 44h: <?= number_format($punchHours['overtime_hours'], 2) ?>. Marca 'Corregir Base' y escribe un valor para reemplazar."
                                                >
                                                <div class="text-xs text-slate-500 mt-1 text-right">Ponche: <?= number_format($punchHours['overtime_hours'], 2) ?></div>
                                            </td>
                                            <td class="py-2 px-2">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    name="manual_incentives[<?= (int)$agent['id'] ?>][sales]"
                                                    value="<?= htmlspecialchars(number_format((float)$agentIncentive['sales_incentive'], 2, '.', '')) ?>"
                                                    class="w-full rounded border border-slate-700 bg-slate-900 px-3 py-2 text-right"
                                                >
                                            </td>
                                            <td class="py-2 px-2">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    name="manual_incentives[<?= (int)$agent['id'] ?>][night]"
                                                    value="<?= htmlspecialchars(number_format((float)$agentIncentive['night_incentive'], 2, '.', '')) ?>"
                                                    class="w-full rounded border border-slate-700 bg-slate-900 px-3 py-2 text-right"
                                                >
                                            </td>
                                            <td class="py-2 px-2">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    name="manual_incentives[<?= (int)$agent['id'] ?>][cooperative]"
                                                    value="<?= htmlspecialchars(number_format((float)($agentIncentive['cooperative_deduction'] ?? 0), 2, '.', '')) ?>"
                                                    class="w-full rounded border border-slate-700 bg-slate-900 px-3 py-2 text-right"
                                                    placeholder="0.00"
                                                >
                                            </td>
                                            <td class="py-2 px-2">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    name="manual_incentives[<?= (int)$agent['id'] ?>][additional]"
                                                    value="<?= htmlspecialchars(number_format((float)($agentIncentive['additional_deduction'] ?? 0), 2, '.', '')) ?>"
                                                    class="w-full rounded border border-slate-700 bg-slate-900 px-3 py-2 text-right"
                                                    placeholder="0.00"
                                                >
                                            </td>
                                            <td class="py-2 px-2">
                                                <input
                                                    type="text"
                                                    maxlength="255"
                                                    name="manual_incentives[<?= (int)$agent['id'] ?>][notes]"
                                                    value="<?= htmlspecialchars((string)($agentIncentive['notes'] ?? '')) ?>"
                                                    placeholder="Opcional"
                                                    class="w-full rounded border border-slate-700 bg-slate-900 px-3 py-2"
                                                >
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-3">
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-save"></i>
                                Guardar Ajustes
                            </button>
                            <span class="text-sm text-slate-400 self-center">Sin marcar la casilla, las horas ingresadas se agregan al ponche. Marcada, reemplazan las horas calculadas para corregir el total.</span>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Payroll Details -->
        <?php if ($selectedPeriod && !empty($payrollRecords)): 
            // Get current deduction rates
            $deductionRates = $pdo->query("SELECT code, employee_percentage FROM payroll_deduction_config WHERE code IN ('AFP', 'SFS')")->fetchAll(PDO::FETCH_KEY_PAIR);
        ?>
            <div class="glass-card mb-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-semibold">
                        <i class="fas fa-list text-indigo-400 mr-2"></i>
                        Detalle: <?= htmlspecialchars($selectedPeriod['name']) ?>
                    </h2>
                    <div class="flex gap-2">
                        <a href="payroll_export_pdf.php?period_id=<?= $selectedPeriod['id'] ?>" class="btn-danger text-sm" target="_blank">
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </a>
                        <a href="payroll_export_excel.php?period_id=<?= $selectedPeriod['id'] ?>" class="btn-secondary text-sm">
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </a>
                        <a href="payroll_export_bank.php?period_id=<?= $selectedPeriod['id'] ?>" class="btn-primary text-sm" title="Archivo Excel para pago bancario (formato BHD)">
                            <i class="fas fa-university"></i>
                            Excel Bancario
                        </a>
                        <a href="payroll_tss.php?period_id=<?= $selectedPeriod['id'] ?>" class="btn-primary text-sm">
                            <i class="fas fa-shield-alt"></i>
                            TSS
                        </a>
                        <a href="payroll_dgii.php?period_id=<?= $selectedPeriod['id'] ?>" class="btn-primary text-sm">
                            <i class="fas fa-landmark"></i>
                            DGII
                        </a>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-2 px-2">Empleado</th>
                                <th class="text-center py-2 px-2">Horas</th>
                                <th class="text-center py-2 px-2">Horas Extra</th>
                                <th class="text-center py-2 px-2">Origen</th>
                                <th class="text-right py-2 px-2">Inc. Ventas</th>
                                <th class="text-right py-2 px-2">Inc. Nocturno</th>
                                <th class="text-right py-2 px-2">Salario Bruto</th>
                                <th class="text-right py-2 px-2">AFP (<?= number_format($deductionRates['AFP'], 2) ?>%)</th>
                                <th class="text-right py-2 px-2">SFS (<?= number_format($deductionRates['SFS'], 2) ?>%)</th>
                                <th class="text-right py-2 px-2">ISR</th>
                                <th class="text-right py-2 px-2">Cooperativa</th>
                                <th class="text-right py-2 px-2">Descuento</th>
                                <th class="text-right py-2 px-2" title="Cuotas de préstamos descontadas por nómina (Finanzas)">
                                    <i class="fas fa-hand-holding-usd text-emerald-400 mr-1"></i>Préstamos
                                </th>
                                <th class="text-right py-2 px-2">Otros Desc.</th>
                                <th class="text-right py-2 px-2">Total Desc.</th>
                                <th class="text-right py-2 px-2">Salario Neto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $totals = ['hours' => 0, 'overtime_hours' => 0, 'sales' => 0, 'night' => 0, 'gross' => 0, 'afp' => 0, 'sfs' => 0, 'isr' => 0, 'cooperative' => 0, 'additional' => 0, 'loans' => 0, 'other' => 0, 'deductions' => 0, 'net' => 0];
                            foreach ($payrollRecords as $record):
                                // pr.other_deductions includes per-employee custom deductions + cooperativa + descuento.
                                // Extraer cuotas de préstamo (descuentos cuyo name comienza con "Préstamo") y
                                // restar manualmente cooperativa y descuento adicional.
                                $coopAmt = (float)$record['cooperative_deduction'];
                                $addAmt = (float)$record['additional_deduction'];
                                $loanAmt = (float)($loanDeductionsByEmployee[(int)$record['employee_id']] ?? 0);
                                $othersOnly = max(0, (float)$record['other_deductions'] - $coopAmt - $addAmt - $loanAmt);

                                $totals['hours'] += $record['total_hours'];
                                $totals['overtime_hours'] += $record['overtime_hours'];
                                $totals['sales'] += $record['sales_incentive'];
                                $totals['night'] += $record['night_incentive'];
                                $totals['gross'] += $record['gross_salary'];
                                $totals['afp'] += $record['afp_employee'];
                                $totals['sfs'] += $record['sfs_employee'];
                                $totals['isr'] += $record['isr'];
                                $totals['cooperative'] += $coopAmt;
                                $totals['additional'] += $addAmt;
                                $totals['loans'] += $loanAmt;
                                $totals['other'] += $othersOnly;
                                $totals['deductions'] += $record['total_deductions'];
                                $totals['net'] += $record['net_salary'];
                            ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/50">
                                    <td class="py-2 px-2">
                                        <div class="font-medium"><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?></div>
                                        <div class="text-xs text-slate-400"><?= htmlspecialchars($record['employee_code']) ?></div>
                                    </td>
                                    <td class="py-2 px-2 text-center"><?= number_format($record['total_hours'], 1) ?></td>
                                    <td class="py-2 px-2 text-center"><?= number_format($record['overtime_hours'], 1) ?></td>
                                    <td class="py-2 px-2 text-center">
                                        <?php
                                        $hasManualExtra = ((float) $record['manual_regular_hours'] > 0 || (float) $record['manual_overtime_hours'] > 0);
                                        $originLabel = 'Asistencia';
                                        $originClass = 'bg-slate-700 text-slate-200';
                                        if (!empty($record['use_manual_hours'])) {
                                            $originLabel = 'Corregida';
                                            $originClass = 'bg-amber-500 text-slate-900';
                                        } elseif ($hasManualExtra) {
                                            $originLabel = 'Asistencia + Manual';
                                            $originClass = 'bg-blue-600 text-white';
                                        }
                                        ?>
                                        <span class="px-2 py-1 rounded-full text-xs font-semibold <?= $originClass ?>">
                                            <?= $originLabel ?>
                                        </span>
                                    </td>
                                    <td class="py-2 px-2 text-right text-emerald-300"><?= formatDOP($record['sales_incentive']) ?></td>
                                    <td class="py-2 px-2 text-right text-amber-300"><?= formatDOP($record['night_incentive']) ?></td>
                                    <td class="py-2 px-2 text-right font-semibold"><?= formatDOP($record['gross_salary']) ?></td>
                                    <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($record['afp_employee']) ?></td>
                                    <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($record['sfs_employee']) ?></td>
                                    <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($record['isr']) ?></td>
                                    <td class="py-2 px-2 text-right text-red-400"><?= $coopAmt > 0 ? formatDOP($coopAmt) : '-' ?></td>
                                    <td class="py-2 px-2 text-right text-red-400"><?= $addAmt > 0 ? formatDOP($addAmt) : '-' ?></td>
                                    <td class="py-2 px-2 text-right text-amber-400 font-semibold" title="Cuotas de préstamos a empleados — sincronizadas desde app Finanzas">
                                        <?= $loanAmt > 0 ? formatDOP($loanAmt) : '-' ?>
                                    </td>
                                    <td class="py-2 px-2 text-right text-red-400"><?= formatDOP($othersOnly) ?></td>
                                    <td class="py-2 px-2 text-right text-red-500 font-semibold"><?= formatDOP($record['total_deductions']) ?></td>
                                    <td class="py-2 px-2 text-right text-green-400 font-bold"><?= formatDOP($record['net_salary']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="bg-slate-800/70 font-bold">
                                <td class="py-3 px-2">TOTALES</td>
                                <td class="py-3 px-2 text-center"><?= number_format($totals['hours'], 1) ?></td>
                                <td class="py-3 px-2 text-center"><?= number_format($totals['overtime_hours'], 1) ?></td>
                                <td class="py-3 px-2 text-center text-slate-400">-</td>
                                <td class="py-3 px-2 text-right text-emerald-300"><?= formatDOP($totals['sales']) ?></td>
                                <td class="py-3 px-2 text-right text-amber-300"><?= formatDOP($totals['night']) ?></td>
                                <td class="py-3 px-2 text-right"><?= formatDOP($totals['gross']) ?></td>
                                <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($totals['afp']) ?></td>
                                <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($totals['sfs']) ?></td>
                                <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($totals['isr']) ?></td>
                                <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($totals['cooperative']) ?></td>
                                <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($totals['additional']) ?></td>
                                <td class="py-3 px-2 text-right text-amber-300"><?= formatDOP($totals['loans']) ?></td>
                                <td class="py-3 px-2 text-right text-red-400"><?= formatDOP($totals['other']) ?></td>
                                <td class="py-3 px-2 text-right text-red-500"><?= formatDOP($totals['deductions']) ?></td>
                                <td class="py-3 px-2 text-right text-green-400"><?= formatDOP($totals['net']) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Create Period Modal -->
    <div id="createPeriodModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="glass-card" style="width: min(600px, 90%);">
            <h3 class="text-xl font-semibold mb-4">Crear Período de Nómina</h3>
            <form method="POST">
                <input type="hidden" name="create_period" value="1">
                
                <div class="form-group mb-4">
                    <label for="name">Nombre del período *</label>
                    <input type="text" id="name" name="name" required placeholder="ej. Quincena 1 - Enero 2025">
                </div>
                
                <div class="form-group mb-4">
                    <label for="period_type">Tipo de período *</label>
                    <select id="period_type" name="period_type" required>
                        <option value="BIWEEKLY">Quincenal</option>
                        <option value="MONTHLY">Mensual</option>
                        <option value="WEEKLY">Semanal</option>
                        <option value="CUSTOM">Personalizado</option>
                    </select>
                </div>
                
                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="form-group">
                        <label for="start_date">Fecha inicio *</label>
                        <input type="date" id="start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label for="end_date">Fecha fin *</label>
                        <input type="date" id="end_date" name="end_date" required>
                    </div>
                    <div class="form-group">
                        <label for="payment_date">Fecha de pago *</label>
                        <input type="date" id="payment_date" name="payment_date" required>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-check"></i>
                        Crear Período
                    </button>
                    <button type="button" onclick="document.getElementById('createPeriodModal').classList.add('hidden')" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Period Modal -->
    <div id="editPeriodModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="glass-card" style="width: min(600px, 90%);">
            <h3 class="text-xl font-semibold mb-4">Editar Período de Nómina</h3>
            <form method="POST">
                <input type="hidden" name="edit_period" value="1">
                <input type="hidden" id="edit_period_id" name="period_id">
                
                <div class="form-group mb-4">
                    <label for="edit_name">Nombre del período *</label>
                    <input type="text" id="edit_name" name="name" required placeholder="ej. Quincena 1 - Enero 2025">
                </div>
                
                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="form-group">
                        <label for="edit_start_date">Fecha inicio *</label>
                        <input type="date" id="edit_start_date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_end_date">Fecha fin *</label>
                        <input type="date" id="edit_end_date" name="end_date" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_payment_date">Fecha de pago *</label>
                        <input type="date" id="edit_payment_date" name="payment_date" required>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-save"></i>
                        Guardar Cambios
                    </button>
                    <button type="button" onclick="document.getElementById('editPeriodModal').classList.add('hidden')" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function editPeriod(id, name, startDate, endDate, paymentDate) {
        document.getElementById('edit_period_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_start_date').value = startDate;
        document.getElementById('edit_end_date').value = endDate;
        document.getElementById('edit_payment_date').value = paymentDate;
        document.getElementById('editPeriodModal').classList.remove('hidden');
    }
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>

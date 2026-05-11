<?php
/**
 * Cliente de préstamos para el portal del agente.
 *
 * Arquitectura: comunicación DIRECTA POR BASE DE DATOS con el esquema de
 * Finanzas (hhempeos_financial_system). NO usa HTTP, así que funciona aún
 * cuando la app de Finanzas (Next.js) no está corriendo.
 *
 * Esto se justifica porque ambos esquemas (hhempeos_ponche y
 * hhempeos_financial_system) viven en el MISMO servidor MySQL de cPanel,
 * y la app de Finanzas en producción puede correr local mientras
 * ponche-xtreme está online en cPanel 24/7.
 *
 * Cuando la app de Finanzas se levante, verá los préstamos creados en
 * estado 'pending' y podrá aprobarlos / desembolsarlos normalmente.
 *
 * La notificación por correo se envía desde aquí mismo (PHP + Resend API)
 * para que el CEO reciba el aviso sin depender de la app local.
 */

require_once __DIR__ . '/../db_finanzas.php';
require_once __DIR__ . '/loans_helpers.php';

/**
 * Obtiene los tipos de préstamo disponibles para empleados.
 *
 * @return array Lista de tipos o [] si falla la conexión.
 */
function getLoanTypesFromFinance(): array {
    try {
        $pdo = getFinanzasPdo();
        $stmt = $pdo->query("
            SELECT id, code, name, description, default_interest_rate, default_term_months,
                   max_amount, min_amount, max_salary_percentage
            FROM loan_types
            WHERE is_active = 1 AND borrower_type = 'employee'
            ORDER BY name
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Préstamos a empleados: exentos de interés por política.
        // Forzamos 0 aquí para que UI y cálculo siempre lo reflejen,
        // independientemente del valor histórico en loan_types.
        foreach ($rows as &$row) {
            $row['default_interest_rate'] = '0.0000';
        }
        unset($row);
        return $rows;
    } catch (Throwable $e) {
        error_log('getLoanTypesFromFinance failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Lista los préstamos del empleado por su employee_external_id.
 */
function getEmployeeLoansFromFinance(int $employeeId): array {
    try {
        $pdo = getFinanzasPdo();
        $stmt = $pdo->prepare("
            SELECT
                l.id, l.loan_number, l.status, l.currency, l.principal_amount, l.total_payable,
                l.total_paid, l.outstanding_balance, l.interest_rate, l.installment_amount,
                l.installment_count, l.installments_paid, l.installment_frequency,
                l.application_date, l.approval_date, l.disbursement_date,
                l.first_due_date, l.last_due_date, l.purpose, l.payment_method,
                lt.name AS loan_type_name, lt.code AS loan_type_code,
                (SELECT MIN(li.due_date) FROM loan_installments li
                   WHERE li.loan_id = l.id AND li.status IN ('pending','partial','overdue')) AS next_due_date,
                (SELECT COUNT(*) FROM loan_installments li
                   WHERE li.loan_id = l.id AND li.status = 'overdue') AS overdue_count
            FROM loans l
            INNER JOIN loan_types lt ON lt.id = l.loan_type_id
            WHERE l.borrower_type = 'employee' AND l.borrower_external_id = ?
            ORDER BY l.created_at DESC
        ");
        $stmt->execute([(string) $employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('getEmployeeLoansFromFinance failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Resuelve los datos del empleado consultando el esquema de ponche local
 * (employees + employment_contracts) para calcular el salario mensual
 * equivalente. Reutiliza la conexión existente $pdo de ponche.
 *
 * @return array|null
 */
function resolveEmployeeForLoan(PDO $ponchePdo, int $employeeId): ?array {
    $stmt = $ponchePdo->prepare("
        SELECT
            e.id,
            TRIM(CONCAT_WS(' ', e.first_name, e.last_name)) AS name,
            COALESCE(e.id_card_number, e.identification_number) AS document,
            e.email,
            COALESCE(e.mobile, e.phone) AS phone,
            e.position,
            d.name AS department,
            c.salary AS contract_amount,
            c.payment_type,
            c.work_schedule
        FROM employees e
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN employment_contracts c ON c.id = (
            SELECT ec.id FROM employment_contracts ec
            WHERE (ec.employee_id = e.id
                   OR ec.employee_name = TRIM(CONCAT_WS(' ', e.first_name, e.last_name)))
              AND ec.salary > 0
            ORDER BY ec.contract_date DESC, ec.id DESC LIMIT 1
        )
        WHERE e.id = ? LIMIT 1
    ");
    $stmt->execute([$employeeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $weeklyHours = 44;
    if (!empty($row['work_schedule']) && preg_match('/(\d{2,3})/', (string) $row['work_schedule'], $m)) {
        $n = (int) $m[1];
        if ($n >= 20 && $n <= 60) $weeklyHours = $n;
    }
    $monthly = null;
    $ct = (float) ($row['contract_amount'] ?? 0);
    if ($ct > 0) {
        if ($row['payment_type'] === 'mensual') {
            $monthly = $ct;
        } elseif ($row['payment_type'] === 'por_hora') {
            $monthly = round($ct * $weeklyHours * (52 / 12), 2);
        }
    }

    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'document' => $row['document'],
        'email' => $row['email'],
        'phone' => $row['phone'],
        'position' => $row['position'],
        'department' => $row['department'],
        'monthly_salary' => $monthly,
        'payment_type' => $row['payment_type'],
    ];
}

/**
 * Crea una solicitud de préstamo escribiendo directamente al esquema de
 * finanzas. Inserta el préstamo + tabla de amortización + audit log y
 * envía el correo de notificación al CEO + extra vía Resend.
 *
 * @return array{ok:bool, data?:array, error?:string}
 */
function createLoanRequestInFinance(array $payload): array {
    global $pdo; // PDO de ponche (hhempeos_ponche), inyectado por db.php

    if (!isset($payload['employee_external_id']) || !isset($payload['loan_type_code'])) {
        return ['ok' => false, 'error' => 'Campos requeridos faltantes'];
    }

    $employeeId = (int) $payload['employee_external_id'];
    $loanTypeCode = (string) $payload['loan_type_code'];
    $principal = (float) ($payload['principal_amount'] ?? 0);
    $installmentCount = (int) ($payload['installment_count'] ?? 0);
    $frequency = $payload['installment_frequency'] ?? 'monthly';
    $interestMethod = $payload['interest_method'] ?? 'french';
    $currency = ($payload['currency'] ?? 'DOP') === 'USD' ? 'USD' : 'DOP';
    $purpose = $payload['purpose'] ?? null;
    $hasGuarantor = !empty($payload['has_guarantor']);
    $guarantorName = $payload['guarantor_name'] ?? null;
    $guarantorDocument = $payload['guarantor_document'] ?? null;
    $guarantorPhone = $payload['guarantor_phone'] ?? null;
    $employeeConsent = !empty($payload['employee_consent']);
    $firstDueDate = !empty($payload['first_due_date']) ? $payload['first_due_date'] : null;
    $source = $payload['source'] ?? 'agent_portal';

    if ($principal <= 0 || $installmentCount <= 0) {
        return ['ok' => false, 'error' => 'principal_amount y installment_count deben ser positivos'];
    }

    try {
        $finanzasPdo = getFinanzasPdo();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo conectar al esquema de finanzas. Intenta más tarde.'];
    }

    // Resolver datos del empleado desde ponche
    $employee = resolveEmployeeForLoan($pdo, $employeeId);
    if (!$employee) {
        return ['ok' => false, 'error' => "Empleado id={$employeeId} no encontrado"];
    }

    // Cargar tipo de préstamo
    $stmt = $finanzasPdo->prepare("SELECT * FROM loan_types WHERE code = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$loanTypeCode]);
    $loanType = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$loanType) {
        return ['ok' => false, 'error' => "Tipo de préstamo '{$loanTypeCode}' no encontrado"];
    }

    // Validaciones de monto
    if (!empty($loanType['max_amount']) && $principal > (float) $loanType['max_amount']) {
        return ['ok' => false, 'error' => "El monto excede el máximo (RD$ " . number_format((float) $loanType['max_amount'], 2) . ")"];
    }
    if (!empty($loanType['min_amount']) && $principal < (float) $loanType['min_amount']) {
        return ['ok' => false, 'error' => "El monto es inferior al mínimo (RD$ " . number_format((float) $loanType['min_amount'], 2) . ")"];
    }

    // Validar préstamos activos
    $maxActive = (int) (getFinanzasConfig($finanzasPdo, 'max_active_loans_per_employee') ?? 2);
    if ($maxActive <= 0) {
        $stmt = $finanzasPdo->prepare("SELECT setting_value FROM loan_settings WHERE setting_key = 'max_active_loans_per_employee'");
        $stmt->execute();
        $maxActive = (int) ($stmt->fetchColumn() ?: 2);
    }
    $stmt = $finanzasPdo->prepare("
        SELECT COUNT(*) FROM loans
        WHERE borrower_type='employee' AND borrower_external_id = ?
          AND status IN ('active','in_arrears','approved','restructured','pending')
    ");
    $stmt->execute([(string) $employeeId]);
    if ((int) $stmt->fetchColumn() >= $maxActive) {
        return ['ok' => false, 'error' => "Excedido el máximo de préstamos activos simultáneos ({$maxActive}). Liquida un préstamo antes de solicitar otro."];
    }

    // Calcular amortización
    $startDate = $firstDueDate ?? date('Y-m-d');
    $amort = calculateAmortizationPHP([
        'principal' => $principal,
        'annualInterestRate' => (float) $loanType['default_interest_rate'],
        'installmentCount' => $installmentCount,
        'frequency' => $frequency,
        'startDate' => $startDate,
        'method' => $interestMethod,
    ]);

    // Validación Art. 201 CT
    $affordabilityWarning = null;
    if ($employee['monthly_salary']) {
        $validation = validateAffordabilityPHP(
            $amort['installmentAmount'],
            (float) $employee['monthly_salary'],
            $frequency,
            (float) ($loanType['max_salary_percentage'] ?? 33.33),
        );
        if (!$validation['ok']) {
            $affordabilityWarning = $validation['message'];
        }
    }

    // Generar número correlativo
    $loanNumber = generateLoanNumberPHP($finanzasPdo);

    $consentTs = $employeeConsent ? date('Y-m-d H:i:s') : null;
    $consentIp = $employeeConsent ? ($_SERVER['REMOTE_ADDR'] ?? null) : null;
    $applicationDate = date('Y-m-d');

    try {
        $finanzasPdo->beginTransaction();

        // INSERT loan
        $insertLoan = $finanzasPdo->prepare("
            INSERT INTO loans (
                loan_number, loan_type_id, borrower_type, borrower_external_id, borrower_name,
                borrower_document, borrower_email, borrower_phone, borrower_position, borrower_department,
                borrower_monthly_salary, currency, principal_amount, interest_rate, interest_method,
                term_months, installment_count, installment_frequency, installment_amount, total_interest,
                total_payable, late_fee_rate, application_date, first_due_date, last_due_date,
                outstanding_balance, status, payment_method, has_guarantor,
                guarantor_name, guarantor_document, guarantor_phone,
                purpose, notes, employee_consent_at, employee_consent_ip, legal_disclaimer_accepted
            ) VALUES (
                ?, ?, 'employee', ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, 'pending', 'payroll_deduction', ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?
            )
        ");
        $insertLoan->execute([
            $loanNumber, (int) $loanType['id'], (string) $employeeId, $employee['name'],
            $employee['document'], $employee['email'], $employee['phone'], $employee['position'], $employee['department'],
            $employee['monthly_salary'], $currency, $principal, (float) $loanType['default_interest_rate'], $interestMethod,
            $installmentCount, $installmentCount, $frequency, $amort['installmentAmount'], $amort['totalInterest'],
            $amort['totalPayable'], 2.0, $applicationDate,
            $amort['installments'][0]['due_date'] ?? null,
            $amort['installments'][count($amort['installments']) - 1]['due_date'] ?? null,
            $amort['totalPayable'],
            $hasGuarantor ? 1 : 0,
            $guarantorName, $guarantorDocument, $guarantorPhone,
            $purpose, "Solicitud originada en {$source}",
            $consentTs, $consentIp, $employeeConsent ? 1 : 0,
        ]);

        $loanId = (int) $finanzasPdo->lastInsertId();

        // INSERT installments
        if (!empty($amort['installments'])) {
            $values = [];
            $placeholders = [];
            foreach ($amort['installments'] as $row) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $values,
                    $loanId,
                    $row['installment_number'],
                    $row['due_date'],
                    $row['principal_amount'],
                    $row['interest_amount'],
                    $row['total_amount'],
                    $row['remaining_balance']
                );
            }
            $sql = "INSERT INTO loan_installments
                    (loan_id, installment_number, due_date, principal_amount, interest_amount, total_amount, remaining_balance)
                    VALUES " . implode(',', $placeholders);
            $stmt = $finanzasPdo->prepare($sql);
            $stmt->execute($values);
        }

        // Audit log
        $audit = $finanzasPdo->prepare("
            INSERT INTO loan_audit_log (loan_id, action, description, new_value, ip_address)
            VALUES (?, 'created', ?, ?, ?)
        ");
        $audit->execute([
            $loanId,
            "Solicitud creada desde {$source} por {$employee['name']}",
            json_encode([
                'loan_number' => $loanNumber,
                'principal_amount' => $principal,
                'source' => $source,
                'channel' => 'php_direct_db',
            ]),
            $consentIp,
        ]);

        $finanzasPdo->commit();
    } catch (Throwable $e) {
        try { $finanzasPdo->rollBack(); } catch (Throwable $r) {}
        error_log('createLoanRequestInFinance INSERT failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Error al guardar el préstamo: ' . $e->getMessage()];
    }

    // Enviar notificación por correo (best-effort, no bloquea)
    $emailResult = ['success' => false, 'skipped' => 'no enviado'];
    try {
        $emailResult = sendLoanCreatedNotificationPHP($finanzasPdo, [
            'loan_number' => $loanNumber,
            'borrower_name' => $employee['name'],
            'borrower_document' => $employee['document'],
            'borrower_position' => $employee['position'],
            'borrower_department' => $employee['department'],
            'borrower_email' => $employee['email'],
            'borrower_phone' => $employee['phone'],
            'borrower_monthly_salary' => $employee['monthly_salary'],
            'loan_type_name' => $loanType['name'],
            'currency' => $currency,
            'principal_amount' => $principal,
            'interest_rate' => (float) $loanType['default_interest_rate'],
            'interest_method' => $interestMethod,
            'installment_count' => $installmentCount,
            'installment_frequency' => $frequency,
            'installment_amount' => $amort['installmentAmount'],
            'total_interest' => $amort['totalInterest'],
            'total_payable' => $amort['totalPayable'],
            'first_due_date' => $amort['installments'][0]['due_date'] ?? null,
            'last_due_date' => $amort['installments'][count($amort['installments']) - 1]['due_date'] ?? null,
            'payment_method' => 'payroll_deduction',
            'has_guarantor' => $hasGuarantor,
            'guarantor_name' => $guarantorName,
            'purpose' => $purpose,
            'status' => 'pending',
            'affordability_warning' => $affordabilityWarning,
            'source' => $source,
        ]);
    } catch (Throwable $e) {
        error_log('Loan email notification failed: ' . $e->getMessage());
    }

    return [
        'ok' => true,
        'data' => [
            'success' => true,
            'id' => $loanId,
            'loan_number' => $loanNumber,
            'status' => 'pending',
            'installment_amount' => $amort['installmentAmount'],
            'total_interest' => $amort['totalInterest'],
            'total_payable' => $amort['totalPayable'],
            'first_due_date' => $amort['installments'][0]['due_date'] ?? null,
            'last_due_date' => $amort['installments'][count($amort['installments']) - 1]['due_date'] ?? null,
            'affordability_warning' => $affordabilityWarning,
            'email_notification' => $emailResult,
            'message' => 'Solicitud guardada en la base de datos de finanzas. Será revisada al iniciar la aplicación.',
        ],
    ];
}

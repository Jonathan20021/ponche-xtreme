<?php
/**
 * Helpers de préstamos en PHP — porte fiel de lib/loan-utils.ts y
 * lib/loan-notifications.ts de la app de Finanzas (Next.js).
 *
 * Permite que ponche-xtreme genere el cuadro de amortización, valide el
 * Art. 201 CT y envíe la notificación por correo SIN depender de que la
 * app de Finanzas local esté corriendo.
 */

/**
 * Calcula el cuadro de amortización.
 *
 * Métodos soportados: 'french', 'german', 'simple', 'zero'.
 *
 * @param array{principal:float,annualInterestRate:float,installmentCount:int,frequency:string,startDate:string,method:string} $input
 * @return array{installments:array,totalInterest:float,totalPayable:float,installmentAmount:float}
 */
function calculateAmortizationPHP(array $input): array {
    $principal = (float) $input['principal'];
    $annualRate = (float) $input['annualInterestRate'];
    $n = max(1, (int) $input['installmentCount']);
    $frequency = $input['frequency'] ?? 'monthly';
    $method = $input['method'] ?? 'french';
    $startDate = $input['startDate'] ?? date('Y-m-d');

    $periodsPerYear = match ($frequency) {
        'weekly' => 52,
        'biweekly' => 26,
        'monthly' => 12,
        default => 12,
    };
    $r = $annualRate > 0 ? ($annualRate / 100) / $periodsPerYear : 0.0;

    $rows = [];
    $balance = $principal;
    $totalInterest = 0.0;
    $installmentAmount = 0.0;

    $round2 = static fn(float $x): float => round($x, 2);

    $addPeriod = static function (string $date, string $freq, int $idx): string {
        $d = new DateTime($date);
        switch ($freq) {
            case 'weekly':   $d->modify('+' . (7 * $idx) . ' days'); break;
            case 'biweekly': $d->modify('+' . (14 * $idx) . ' days'); break;
            case 'monthly':
            default:         $d->modify('+' . $idx . ' months'); break;
        }
        return $d->format('Y-m-d');
    };

    if ($method === 'zero' || $r === 0.0) {
        $installmentAmount = $round2($principal / $n);
        for ($i = 1; $i <= $n; $i++) {
            $principalPart = $i === $n ? $round2($balance) : $installmentAmount;
            $balance = $round2($balance - $principalPart);
            $rows[] = [
                'installment_number' => $i,
                'due_date' => $addPeriod($startDate, $frequency, $i),
                'principal_amount' => $principalPart,
                'interest_amount' => 0.0,
                'total_amount' => $principalPart,
                'remaining_balance' => max(0.0, $balance),
            ];
        }
    } elseif ($method === 'french') {
        $factor = $r / (1 - pow(1 + $r, -$n));
        $installmentAmount = $round2($principal * $factor);
        for ($i = 1; $i <= $n; $i++) {
            $interest = $round2($balance * $r);
            $principalPart = $i === $n ? $round2($balance) : $round2($installmentAmount - $interest);
            $total = $round2($principalPart + $interest);
            $balance = $round2($balance - $principalPart);
            $totalInterest += $interest;
            $rows[] = [
                'installment_number' => $i,
                'due_date' => $addPeriod($startDate, $frequency, $i),
                'principal_amount' => $principalPart,
                'interest_amount' => $interest,
                'total_amount' => $total,
                'remaining_balance' => max(0.0, $balance),
            ];
        }
    } elseif ($method === 'german') {
        $principalFixed = $round2($principal / $n);
        for ($i = 1; $i <= $n; $i++) {
            $interest = $round2($balance * $r);
            $principalThis = $i === $n ? $round2($balance) : $principalFixed;
            $total = $round2($principalThis + $interest);
            $balance = $round2($balance - $principalThis);
            $totalInterest += $interest;
            if ($i === 1) $installmentAmount = $total;
            $rows[] = [
                'installment_number' => $i,
                'due_date' => $addPeriod($startDate, $frequency, $i),
                'principal_amount' => $principalThis,
                'interest_amount' => $interest,
                'total_amount' => $total,
                'remaining_balance' => max(0.0, $balance),
            ];
        }
    } else {
        // simple
        $totalSimpleInterest = $round2($principal * $r * $n);
        $interestPerInstallment = $round2($totalSimpleInterest / $n);
        $principalFixed = $round2($principal / $n);
        $installmentAmount = $round2($principalFixed + $interestPerInstallment);
        for ($i = 1; $i <= $n; $i++) {
            $interest = $i === $n
                ? $round2($totalSimpleInterest - $interestPerInstallment * ($n - 1))
                : $interestPerInstallment;
            $principalThis = $i === $n ? $round2($balance) : $principalFixed;
            $total = $round2($principalThis + $interest);
            $balance = $round2($balance - $principalThis);
            $totalInterest += $interest;
            $rows[] = [
                'installment_number' => $i,
                'due_date' => $addPeriod($startDate, $frequency, $i),
                'principal_amount' => $principalThis,
                'interest_amount' => $interest,
                'total_amount' => $total,
                'remaining_balance' => max(0.0, $balance),
            ];
        }
    }

    return [
        'installments' => $rows,
        'totalInterest' => $round2($totalInterest),
        'totalPayable' => $round2($principal + $totalInterest),
        'installmentAmount' => $round2($installmentAmount),
    ];
}

/**
 * Genera el siguiente número correlativo PRES-YYYY-NNNN consultando la BD
 * de finanzas para el último insertado en el año.
 */
function generateLoanNumberPHP(PDO $finanzasPdo): string {
    $year = (int) date('Y');
    $stmt = $finanzasPdo->prepare("SELECT loan_number FROM loans WHERE loan_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(["PRES-{$year}-%"]);
    $last = $stmt->fetchColumn();
    if (!$last || !preg_match('/^PRES-(\d{4})-(\d+)$/', $last, $m)) {
        return "PRES-{$year}-0001";
    }
    if ((int) $m[1] !== $year) {
        return "PRES-{$year}-0001";
    }
    $next = ((int) $m[2]) + 1;
    return "PRES-{$year}-" . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

/**
 * Valida Art. 201 Código de Trabajo R.D.: la cuota mensual equivalente
 * no debe exceder maxPercentage (default 33.33%) del salario.
 *
 * @return array{ok:bool,message:string,monthlyDeduction:float,maxAllowed:float,percentageUsed:float}
 */
function validateAffordabilityPHP(float $installmentAmount, float $monthlySalary, string $frequency, float $maxPercentage = 33.33): array {
    if ($monthlySalary <= 0) {
        return [
            'ok' => false,
            'monthlyDeduction' => 0.0,
            'maxAllowed' => 0.0,
            'percentageUsed' => 0.0,
            'message' => 'No se pudo validar Art. 201 CT: salario mensual no disponible.',
        ];
    }
    $perMonth = match ($frequency) {
        'weekly' => 4.33,
        'biweekly' => 2,
        'monthly' => 1,
        default => 1,
    };
    $monthlyDeduction = $installmentAmount * $perMonth;
    $maxAllowed = $monthlySalary * ($maxPercentage / 100);
    $pct = ($monthlyDeduction / $monthlySalary) * 100;
    $ok = $monthlyDeduction <= $maxAllowed;
    return [
        'ok' => $ok,
        'monthlyDeduction' => round($monthlyDeduction, 2),
        'maxAllowed' => round($maxAllowed, 2),
        'percentageUsed' => round($pct, 2),
        'message' => $ok
            ? sprintf('Cumple Art. 201 CT: la deducción mensual (%.2f%%) está dentro del tope %.2f%%.', $pct, $maxPercentage)
            : sprintf('EXCEDE Art. 201 CT: la deducción mensual de %.2f supera el tope de %.2f (%.2f%% del salario).',
                $monthlyDeduction, $maxAllowed, $maxPercentage),
    ];
}

/**
 * Lee una configuración del módulo de préstamos (tabla loan_settings de
 * finanzas). Es la misma tabla que edita la pantalla de Configuración de
 * la app Next.js, así que ambos sistemas comparten la política vigente.
 */
function getLoanSetting(PDO $finanzasPdo, string $key, ?string $default = null): ?string {
    try {
        $stmt = $finanzasPdo->prepare("SELECT setting_value FROM loan_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null && trim((string) $val) !== '') {
            return (string) $val;
        }
    } catch (Throwable $e) {}
    return $default;
}

/**
 * Lee una configuración de la tabla automation_config de finanzas o variable
 * de entorno (en ese orden de prioridad).
 */
function getFinanzasConfig(PDO $finanzasPdo, string $key): ?string {
    try {
        $stmt = $finanzasPdo->prepare("SELECT config_value FROM automation_config WHERE config_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null && trim((string) $val) !== '') {
            return (string) $val;
        }
    } catch (Throwable $e) {}
    $env = getenv($key);
    return $env !== false && $env !== '' ? $env : null;
}

/**
 * Envía la notificación de préstamo creado vía Resend (API REST) directamente
 * desde PHP. Esto desacopla el envío de que la app local esté corriendo.
 *
 * @return array{success:bool,sentTo:array,error?:string,skipped?:string}
 */
function sendLoanCreatedNotificationPHP(PDO $finanzasPdo, array $data): array {
    $sentTo = [];

    // Respect global pause flag
    $paused = getFinanzasConfig($finanzasPdo, 'AUTOMATIC_EMAILS_PAUSED');
    if ($paused && in_array(strtolower(trim($paused)), ['1', 'true', 'yes'], true)) {
        return ['success' => true, 'sentTo' => $sentTo, 'skipped' => 'AUTOMATIC_EMAILS_PAUSED activo'];
    }

    $apiKey = getenv('RESEND_API_KEY') ?: '';
    if (!$apiKey) {
        // Intento adicional: leer de un .env junto al proyecto (no estándar pero útil)
        $envFile = __DIR__ . '/../.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos(ltrim($line), 'RESEND_API_KEY=') === 0) {
                    $apiKey = trim(substr(ltrim($line), strlen('RESEND_API_KEY=')), " \t\n\r\0\x0B\"'");
                    break;
                }
            }
        }
    }
    if (!$apiKey) {
        return ['success' => false, 'sentTo' => $sentTo, 'error' => 'RESEND_API_KEY no configurada'];
    }

    $fromEmail = getenv('RESEND_FROM_EMAIL') ?: 'notificaciones@send.evallishbpo.com';

    $ceo   = trim((string) getFinanzasConfig($finanzasPdo, 'LOAN_NOTIFICATION_CEO_EMAIL'));
    $extra = trim((string) getFinanzasConfig($finanzasPdo, 'LOAN_NOTIFICATION_EXTRA_EMAIL'));
    $recipients = [];
    if ($ceo)   $recipients[] = $ceo;
    if ($extra && $extra !== $ceo) $recipients[] = $extra;
    if (empty($recipients)) {
        return [
            'success' => true,
            'sentTo' => $sentTo,
            'skipped' => 'Sin destinatarios configurados (LOAN_NOTIFICATION_CEO_EMAIL / LOAN_NOTIFICATION_EXTRA_EMAIL)',
        ];
    }

    // Resolver nombre de empresa
    $companyName = 'Evallish BPO';
    try {
        $stmt = $finanzasPdo->query("SELECT company_name FROM company_settings LIMIT 1");
        $cn = $stmt ? $stmt->fetchColumn() : null;
        if ($cn) $companyName = (string) $cn;
    } catch (Throwable $e) {}

    $fmtMoney = static fn(float $n, string $cur = 'DOP') =>
        ($cur === 'USD' ? 'US$ ' : 'RD$ ') . number_format($n, 2);
    $fmtDate = static function (?string $d) {
        if (!$d) return '—';
        try { return (new DateTime($d))->format('d/m/Y'); }
        catch (Throwable $e) { return $d; }
    };

    $cur = $data['currency'] ?? 'DOP';
    $statusLabels = [
        'pending'  => 'Pendiente de aprobación', 'approved' => 'Aprobado',
        'active'   => 'Vigente', 'paid' => 'Saldado',
    ];
    $statusLabel = $statusLabels[$data['status']] ?? $data['status'];
    $freqLabels = ['weekly' => 'Semanal', 'biweekly' => 'Quincenal', 'monthly' => 'Mensual'];
    $freqLabel = $freqLabels[$data['installment_frequency']] ?? $data['installment_frequency'];

    $rows = [
        ['Número',           '<strong style="font-family:monospace;color:#2563eb;">' . htmlspecialchars($data['loan_number']) . '</strong>'],
        ['Tipo',             htmlspecialchars($data['loan_type_name'])],
        ['Estado',           '<span style="display:inline-block;padding:3px 10px;border-radius:12px;background:#fef3c7;color:#92400e;font-size:11px;font-weight:600;">' . $statusLabel . '</span>'],
        ['Prestatario',      htmlspecialchars($data['borrower_name']) . ($data['borrower_document'] ? ' <span style="color:#6b7280;">(' . htmlspecialchars($data['borrower_document']) . ')</span>' : '')],
        ['Cargo · Depto',    htmlspecialchars(($data['borrower_position'] ?? '—') . ($data['borrower_department'] ? ' · ' . $data['borrower_department'] : ''))],
        ['Email · Teléfono', htmlspecialchars(($data['borrower_email'] ?? '—') . ($data['borrower_phone'] ? ' · ' . $data['borrower_phone'] : ''))],
        ['Salario mensual',  $data['borrower_monthly_salary'] ? $fmtMoney((float) $data['borrower_monthly_salary'], 'DOP') : '—'],
    ];
    $finRows = [
        ['Capital',          '<strong>' . $fmtMoney((float) $data['principal_amount'], $cur) . '</strong>'],
        ['Tasa de interés',  number_format((float) $data['interest_rate'], 2) . '% mensual (' . htmlspecialchars($data['interest_method']) . ')'],
        ['Cuotas',           (int) $data['installment_count'] . ' cuotas ' . strtolower($freqLabel)],
        ['Cuota',            '<strong style="color:#059669;">' . $fmtMoney((float) $data['installment_amount'], $cur) . '</strong>'],
        ['Intereses totales', $fmtMoney((float) $data['total_interest'], $cur)],
        ['Total a pagar',    '<strong>' . $fmtMoney((float) $data['total_payable'], $cur) . '</strong>'],
        ['Método de pago',   $data['payment_method'] === 'payroll_deduction' ? 'Descuento por nómina' : htmlspecialchars($data['payment_method'])],
        ['Primera cuota',    $fmtDate($data['first_due_date'] ?? null)],
        ['Última cuota',     $fmtDate($data['last_due_date'] ?? null)],
    ];
    if (!empty($data['has_guarantor']) && !empty($data['guarantor_name'])) {
        $finRows[] = ['Aval', htmlspecialchars($data['guarantor_name'])];
    }

    $rowHtml = static fn(array $row) =>
        '<tr><td style="padding:8px 12px;color:#6b7280;font-size:12px;border-bottom:1px solid #f3f4f6;width:38%;vertical-align:top;">' . $row[0] . '</td>'
      . '<td style="padding:8px 12px;color:#111827;font-size:13px;border-bottom:1px solid #f3f4f6;">' . $row[1] . '</td></tr>';

    $rowsHtml    = implode('', array_map($rowHtml, $rows));
    $finRowsHtml = implode('', array_map($rowHtml, $finRows));

    $warningBlock = '';
    if (!empty($data['affordability_warning'])) {
        $warningBlock = '<div style="margin:16px 0;padding:14px;background:#fff7ed;border-left:4px solid #f97316;border-radius:6px;">'
            . '<p style="margin:0;font-size:13px;font-weight:600;color:#9a3412;">⚠ Aviso Art. 201 Código de Trabajo R.D.</p>'
            . '<p style="margin:6px 0 0 0;font-size:12px;color:#7c2d12;">' . htmlspecialchars($data['affordability_warning']) . '</p></div>';
    }

    $purposeBlock = '';
    if (!empty($data['purpose'])) {
        $purposeBlock = '<tr><td style="padding:8px 32px;">'
            . '<h3 style="margin:16px 0 8px 0;font-size:13px;color:#374151;text-transform:uppercase;letter-spacing:.05em;">Propósito</h3>'
            . '<p style="margin:0;padding:12px 14px;font-size:13px;color:#374151;background:#f9fafb;border-radius:8px;font-style:italic;border:1px solid #e5e7eb;">' . htmlspecialchars($data['purpose']) . '</p>'
            . '</td></tr>';
    }

    $origin = ($data['source'] ?? '') === 'agent_portal'
        ? '🧑‍💼 Solicitud originada en el <strong>Portal del Agente</strong> (ponche-xtreme)'
        : '📝 Creado en la app de Finanzas';

    $subject = '[Préstamo] ' . $data['loan_number']
            . ' · ' . $data['borrower_name']
            . ' · ' . $fmtMoney((float) $data['principal_amount'], $cur);

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' . htmlspecialchars($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:#f3f4f6;">'
        . '<table role="presentation" style="width:100%;border-collapse:collapse;background:#f3f4f6;"><tr><td align="center" style="padding:32px 16px;">'
        . '<table role="presentation" style="width:100%;max-width:640px;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.06);">'
        . '<tr><td style="background:linear-gradient(135deg,#3b82f6 0%,#8b5cf6 100%);padding:24px 32px;color:white;">'
        . '<p style="margin:0;font-size:11px;opacity:.85;letter-spacing:.1em;text-transform:uppercase;">' . htmlspecialchars($companyName) . '</p>'
        . '<h1 style="margin:6px 0 0 0;font-size:22px;font-weight:700;">Nuevo Préstamo Registrado</h1>'
        . '<p style="margin:6px 0 0 0;font-size:13px;opacity:.9;">' . htmlspecialchars($data['loan_number']) . ' · ' . $fmtMoney((float) $data['principal_amount'], $cur) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:20px 32px 8px;"><p style="margin:0;font-size:12px;color:#6b7280;">' . $origin . '</p></td></tr>'
        . '<tr><td style="padding:8px 32px;"><h3 style="margin:16px 0 8px 0;font-size:13px;color:#374151;text-transform:uppercase;letter-spacing:.05em;">Prestatario</h3>'
        . '<table role="presentation" style="width:100%;border-collapse:collapse;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;">' . $rowsHtml . '</table></td></tr>'
        . '<tr><td style="padding:8px 32px;"><h3 style="margin:16px 0 8px 0;font-size:13px;color:#374151;text-transform:uppercase;letter-spacing:.05em;">Términos Financieros</h3>'
        . '<table role="presentation" style="width:100%;border-collapse:collapse;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;">' . $finRowsHtml . '</table></td></tr>'
        . $purposeBlock
        . '<tr><td style="padding:0 32px;">' . $warningBlock . '</td></tr>'
        . '<tr><td style="padding:16px 32px 24px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:11px;color:#6b7280;">'
        . 'Notificación automática del módulo de Préstamos · ' . date('d/m/Y H:i') . '<br>'
        . 'Cumple Art. 200 y 201 Código de Trabajo R.D., Ley 87-01 (TSS), DGII (ISR).</td></tr>'
        . '</table></td></tr></table></body></html>';

    // POST a Resend API
    $payload = [
        'from'    => $companyName . ' <' . $fromEmail . '>',
        'to'      => $recipients,
        'subject' => $subject,
        'html'    => $html,
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'sentTo' => $sentTo, 'error' => 'cURL error: ' . $error];
    }
    if ($status < 200 || $status >= 300) {
        $body = json_decode($response, true);
        return ['success' => false, 'sentTo' => $sentTo, 'error' => $body['message'] ?? ('HTTP ' . $status)];
    }

    return ['success' => true, 'sentTo' => $recipients];
}

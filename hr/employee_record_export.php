<?php
/**
 * hr/employee_record_export.php
 *
 * Exporta el expediente COMPLETO de un colaborador en Excel o PDF:
 * información general, historial laboral, tardanzas, ausencias, permisos,
 * amonestaciones, licencias médicas, campañas, documentación y salario.
 *
 * Uso: employee_record_export.php?id=123&formato=excel|pdf
 *
 * El Excel se arma como tabla HTML con Content-Type de Excel, que es el mismo
 * patrón que usan los demás exportables del sistema (abre en Excel sin líos de
 * codificación). El PDF va por Dompdf, que ya está en el vendor.
 */

session_start();
require_once '../db.php';
require_once '../lib/employee_record.php';

ensurePermission('hr_employees', '../unauthorized.php');

$employeeId = (int) ($_GET['id'] ?? 0);
$formato    = strtolower((string) ($_GET['formato'] ?? 'excel'));
if (!in_array($formato, ['excel', 'pdf'], true)) {
    $formato = 'excel';
}
$historyDays = (int) ($_GET['dias'] ?? 365);
if (!in_array($historyDays, [30, 60, 90, 180, 365, 730], true)) {
    $historyDays = 365;
}

if ($employeeId <= 0) {
    header('Location: employees.php');
    exit;
}

// --------------------------------------------------------------------------
// Datos del expediente
// --------------------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT e.*, u.username, u.role, u.is_active,
           u.hourly_rate, u.hourly_rate_dop, u.monthly_salary, u.monthly_salary_dop,
           u.preferred_currency, u.compensation_type,
           COALESCE(u.payroll_source, 'manual') AS payroll_source,
           d.name AS department_name,
           b.name AS bank_name,
           s.full_name AS supervisor_name,
           TIMESTAMPDIFF(YEAR, e.birth_date, CURDATE()) AS age
    FROM employees e
    JOIN users u ON u.id = e.user_id
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN banks b ON b.id = e.bank_id
    LEFT JOIN users s ON s.id = e.supervisor_id
    WHERE e.id = ?
");
$stmt->execute([$employeeId]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emp) {
    header('Location: employees.php');
    exit;
}

$fullName = trim($emp['first_name'] . ' ' . $emp['last_name']);
$tenure   = employeeTenure($emp['hire_date'], $emp['termination_date'] ?? null);

$paymentType = resolvePaymentType(
    $emp['compensation_type'] ?? '',
    $emp['role'] ?? '',
    max((float) $emp['monthly_salary'], (float) $emp['monthly_salary_dop'])
);
$isFixedPay = ($paymentType === 'fixed');
$prefersDop = strtoupper((string) ($emp['preferred_currency'] ?? 'DOP')) === 'DOP';

$historyFrom = date('Y-m-d', strtotime("-{$historyDays} days"));
$attendance  = employeeAttendanceHistory($pdo, $employeeId, (int) $emp['user_id'], $historyFrom, date('Y-m-d'));

$campaigns = employeeCampaignHistory($pdo, $employeeId);
$warnings  = employeeWarnings($pdo, $employeeId, 200);
$leaves    = employeeMedicalLeaves($pdo, $employeeId, 200);
$docStatus = employeeRequiredDocumentsStatus($pdo, $employeeId);
$personal  = employeePersonalDataStatus($emp);
$labels    = employeeTerminationLabels();
$wLabels   = employeeWarningLabels();

$permStmt = $pdo->prepare("
    SELECT p.*, r.full_name AS reviewed_by_name
    FROM permission_requests p
    LEFT JOIN users r ON r.id = p.reviewed_by
    WHERE p.employee_id = ?
    ORDER BY p.start_date DESC LIMIT 100
");
$permStmt->execute([$employeeId]);
$permissions = $permStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$vacStmt = $pdo->prepare("SELECT * FROM vacation_requests WHERE employee_id = ? ORDER BY start_date DESC LIMIT 100");
$vacStmt->execute([$employeeId]);
$vacations = $vacStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$docsStmt = $pdo->prepare("
    SELECT document_type, document_name, uploaded_at, file_extension
    FROM employee_documents WHERE employee_id = ?
    ORDER BY uploaded_at DESC LIMIT 200
");
$docsStmt->execute([$employeeId]);
$documents = $docsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// --------------------------------------------------------------------------
// Helpers de salida
// --------------------------------------------------------------------------
function ex(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function exDate(?string $v, string $fmt = 'd/m/Y'): string {
    if (empty($v)) return '—';
    $ts = strtotime($v);
    return $ts ? date($fmt, $ts) : '—';
}
function exMoney(float $v, bool $dop): string {
    return ($dop ? 'RD$' : '$') . number_format($v, 2);
}

$statusLabels = [
    'AUSENCIA'             => 'Ausencia',
    'AUSENCIA_JUSTIFICADA' => 'Ausencia justificada',
    'TARDANZA'             => 'Tardanza',
];

// Compensación a mostrar
if ($isFixedPay) {
    $payLabel = 'Sueldo mensual';
    $payValue = exMoney($prefersDop ? (float) $emp['monthly_salary_dop'] : (float) $emp['monthly_salary'], $prefersDop);
} else {
    $payLabel = 'Tarifa por hora';
    $payValue = exMoney($prefersDop ? (float) $emp['hourly_rate_dop'] : (float) $emp['hourly_rate'], $prefersDop);
}

ob_start();
?>
<style>
    body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #111; }
    h1 { font-size: 18px; margin: 0 0 2px; }
    h2 { font-size: 13px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 2px solid #244886; color: #244886; }
    .sub { color: #555; font-size: 11px; margin: 0 0 12px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th, td { border: 1px solid #cfd6e4; padding: 4px 6px; text-align: left; vertical-align: top; }
    th { background: #eef2f8; font-weight: bold; }
    .kv td:first-child { width: 32%; background: #f7f9fc; font-weight: bold; }
    .muted { color: #777; }
    .tot { background: #f7f9fc; font-weight: bold; }
</style>

<h1>Expediente del Colaborador</h1>
<p class="sub">
    <?= ex($fullName) ?> · <?= ex($emp['employee_code']) ?> ·
    Generado el <?= date('d/m/Y H:i') ?>
</p>

<h2>Información general</h2>
<table class="kv">
    <tr><td>Nombre completo</td><td><?= ex($fullName) ?></td></tr>
    <tr><td>Código de empleado</td><td><?= ex($emp['employee_code']) ?></td></tr>
    <tr><td>Usuario del sistema</td><td><?= ex($emp['username']) ?> (<?= ex($emp['role']) ?>)</td></tr>
    <tr><td>Cédula</td><td><?= ex($emp['id_card_number'] ?: $emp['identification_number']) ?></td></tr>
    <tr><td>Fecha de nacimiento</td><td><?= exDate($emp['birth_date']) ?><?= $emp['age'] ? ' (' . (int) $emp['age'] . ' años)' : '' ?></td></tr>
    <tr><td>Género</td><td><?= ex($emp['gender']) ?></td></tr>
    <tr><td>Estado civil</td><td><?= ex($emp['marital_status']) ?></td></tr>
    <tr><td>Tipo de sangre</td><td><?= ex($emp['blood_type']) ?></td></tr>
    <tr><td>Teléfono</td><td><?= ex($emp['phone']) ?><?= $emp['mobile'] ? ' / ' . ex($emp['mobile']) : '' ?></td></tr>
    <tr><td>Correo</td><td><?= ex($emp['email']) ?></td></tr>
    <tr><td>Dirección</td><td><?= ex(trim(($emp['address'] ?? '') . ' ' . ($emp['city'] ? ', ' . $emp['city'] : '') . ($emp['state'] ? ', ' . $emp['state'] : ''))) ?></td></tr>
    <tr><td>Contacto de emergencia</td><td><?= ex($emp['emergency_contact_name']) ?><?= $emp['emergency_contact_phone'] ? ' · ' . ex($emp['emergency_contact_phone']) : '' ?><?= $emp['emergency_contact_relationship'] ? ' (' . ex($emp['emergency_contact_relationship']) . ')' : '' ?></td></tr>
</table>

<h2>Historial laboral</h2>
<table class="kv">
    <tr><td>Fecha de ingreso</td><td><?= exDate($emp['hire_date']) ?></td></tr>
    <tr><td>Tiempo laborando</td><td><?= ex($tenure['label']) ?> (<?= number_format($tenure['total_days']) ?> días)</td></tr>
    <tr><td>Estado</td><td><?= ex($emp['employment_status']) ?></td></tr>
    <tr><td>Posición</td><td><?= ex($emp['position']) ?></td></tr>
    <tr><td>Departamento</td><td><?= ex($emp['department_name']) ?></td></tr>
    <tr><td>Supervisor</td><td><?= ex($emp['supervisor_name']) ?></td></tr>
    <tr><td>Tipo de contrato</td><td><?= ex($emp['employment_type']) ?></td></tr>
    <?php if (!empty($emp['termination_date'])): ?>
        <tr><td>Fecha de salida</td><td><?= exDate($emp['termination_date']) ?></td></tr>
        <tr><td>Motivo de salida</td><td><?= ex($labels['reasons'][$emp['termination_reason']] ?? $emp['termination_reason']) ?></td></tr>
        <tr><td>Detalle del motivo</td><td><?= ex($emp['termination_notes']) ?></td></tr>
        <tr><td>Recontratación</td><td><?= ex($labels['rehire'][$emp['rehire_eligibility']] ?? $emp['rehire_eligibility']) ?></td></tr>
        <tr><td>Notas de recontratación</td><td><?= ex($emp['rehire_notes']) ?></td></tr>
    <?php endif; ?>
</table>

<h2>Compensación</h2>
<table class="kv">
    <tr><td>Forma de pago</td><td><?= $isFixedPay ? 'Sueldo mensual fijo' : 'Por hora' ?></td></tr>
    <tr><td><?= ex($payLabel) ?></td><td><?= $payValue ?></td></tr>
    <tr><td>Moneda</td><td><?= ex($emp['preferred_currency'] ?: 'DOP') ?></td></tr>
    <tr><td>Cálculo de horas</td><td><?= $emp['payroll_source'] === 'vicidial' ? 'Vicidial (discador)' : 'Sistema de ponche' ?></td></tr>
    <tr><td>Banco</td><td><?= ex($emp['bank_name']) ?></td></tr>
    <tr><td>Cuenta bancaria</td><td><?= ex($emp['bank_account_number']) ?><?= $emp['bank_account_type'] ? ' (' . ex($emp['bank_account_type']) . ')' : '' ?></td></tr>
</table>

<h2>Asistencia — últimos <?= (int) $historyDays ?> días</h2>
<table>
    <tr class="tot">
        <th>Días programados</th><th>Trabajados</th><th>Ausencias</th>
        <th>Ausencias justificadas</th><th>Tardanzas</th><th>Minutos de tardanza</th>
    </tr>
    <tr>
        <td><?= (int) $attendance['summary']['scheduled'] ?></td>
        <td><?= (int) $attendance['summary']['worked'] ?></td>
        <td><?= (int) $attendance['summary']['absent'] ?></td>
        <td><?= (int) $attendance['summary']['absent_justified'] ?></td>
        <td><?= (int) $attendance['summary']['late'] ?></td>
        <td><?= number_format((int) $attendance['summary']['late_minutes']) ?></td>
    </tr>
</table>

<?php if (!empty($attendance['days'])): ?>
    <table>
        <tr><th>Fecha</th><th>Situación</th><th>Llegada</th><th>Programada</th><th>Minutos tarde</th><th>Justificación</th></tr>
        <?php foreach ($attendance['days'] as $d): ?>
            <tr>
                <td><?= exDate($d['date']) ?></td>
                <td><?= ex($statusLabels[$d['status']] ?? $d['status']) ?></td>
                <td><?= $d['arrival'] ? exDate($d['arrival'], 'H:i') : '—' ?></td>
                <td><?= $d['scheduled_entry'] ? substr((string) $d['scheduled_entry'], 0, 5) : '—' ?></td>
                <td><?= $d['late_minutes'] > 0 ? (int) $d['late_minutes'] : '' ?></td>
                <td><?php
                    $j = [];
                    foreach ($d['justification'] as $x) { $j[] = $x['label']; }
                    echo ex(implode('; ', $j));
                ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <p class="muted">Sin ausencias ni tardanzas en el período.</p>
<?php endif; ?>

<h2>Permisos (<?= count($permissions) ?>)</h2>
<?php if (empty($permissions)): ?>
    <p class="muted">Sin permisos registrados.</p>
<?php else: ?>
    <table>
        <tr><th>Tipo</th><th>Desde</th><th>Hasta</th><th>Días</th><th>Estado</th><th>Motivo</th><th>Revisó</th></tr>
        <?php foreach ($permissions as $p): ?>
            <tr>
                <td><?= ex(str_replace('_', ' ', ucwords(strtolower((string) $p['request_type'])))) ?></td>
                <td><?= exDate($p['start_date']) ?></td>
                <td><?= exDate($p['end_date']) ?></td>
                <td><?= $p['total_days'] !== null ? rtrim(rtrim(number_format((float) $p['total_days'], 1), '0'), '.') : '' ?></td>
                <td><?= ex($p['status']) ?></td>
                <td><?= ex(mb_substr((string) $p['reason'], 0, 120)) ?></td>
                <td><?= ex($p['reviewed_by_name']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>Vacaciones (<?= count($vacations) ?>)</h2>
<?php if (empty($vacations)): ?>
    <p class="muted">Sin vacaciones registradas.</p>
<?php else: ?>
    <table>
        <tr><th>Tipo</th><th>Desde</th><th>Hasta</th><th>Días</th><th>Estado</th></tr>
        <?php foreach ($vacations as $v): ?>
            <tr>
                <td><?= ex($v['vacation_type']) ?></td>
                <td><?= exDate($v['start_date']) ?></td>
                <td><?= exDate($v['end_date']) ?></td>
                <td><?= $v['total_days'] !== null ? rtrim(rtrim(number_format((float) $v['total_days'], 1), '0'), '.') : '' ?></td>
                <td><?= ex($v['status']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>Amonestaciones (<?= count($warnings) ?>)</h2>
<?php if (empty($warnings)): ?>
    <p class="muted">Sin amonestaciones registradas.</p>
<?php else: ?>
    <table>
        <tr><th>Fecha</th><th>Tipo</th><th>Gravedad</th><th>Asunto</th><th>Descripción</th><th>Suspensión</th><th>Estado</th><th>Emitió</th></tr>
        <?php foreach ($warnings as $w): ?>
            <tr>
                <td><?= exDate($w['incident_date']) ?></td>
                <td><?= ex($wLabels['types'][$w['warning_type']] ?? $w['warning_type']) ?></td>
                <td><?= ex($wLabels['severities'][$w['severity']] ?? $w['severity']) ?></td>
                <td><?= ex($w['subject']) ?></td>
                <td><?= ex(mb_substr((string) $w['description'], 0, 200)) ?></td>
                <td><?= $w['suspension_days'] ? rtrim(rtrim(number_format((float) $w['suspension_days'], 1), '0'), '.') . ' día(s)' : '' ?></td>
                <td><?= ex($wLabels['statuses'][$w['status']] ?? $w['status']) ?></td>
                <td><?= ex($w['issued_by_name']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>Licencias médicas (<?= count($leaves) ?>)</h2>
<?php if (empty($leaves)): ?>
    <p class="muted">Sin licencias médicas registradas.</p>
<?php else: ?>
    <table>
        <tr><th>Desde</th><th>Hasta</th><th>Días</th><th>Tipo</th><th>Diagnóstico</th><th>Médico / Centro</th><th>Con goce</th><th>Estado</th></tr>
        <?php foreach ($leaves as $l): ?>
            <tr>
                <td><?= exDate($l['start_date']) ?></td>
                <td><?= exDate($l['end_date']) ?></td>
                <td><?= $l['total_days'] !== null ? rtrim(rtrim(number_format((float) $l['total_days'], 1), '0'), '.') : '' ?></td>
                <td><?= ex($l['leave_type']) ?></td>
                <td><?= ex($l['diagnosis']) ?></td>
                <td><?= ex(trim(($l['doctor_name'] ?? '') . ' ' . ($l['medical_center'] ? '· ' . $l['medical_center'] : ''))) ?></td>
                <td><?= ((int) $l['is_paid'] === 1) ? 'Sí' : 'No' ?></td>
                <td><?= ex($l['status']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>Historial de campañas (<?= count($campaigns) ?>)</h2>
<?php if (empty($campaigns)): ?>
    <p class="muted">Sin campañas asignadas.</p>
<?php else: ?>
    <table>
        <tr><th>Campaña</th><th>Desde</th><th>Hasta</th><th>Principal</th><th>Asignó</th></tr>
        <?php foreach ($campaigns as $c): ?>
            <tr>
                <td><?= ex($c['campaign_name']) ?></td>
                <td><?= exDate($c['start_date']) ?></td>
                <td><?= empty($c['end_date']) ? 'Vigente' : exDate($c['end_date']) ?></td>
                <td><?= !empty($c['is_primary']) ? 'Sí' : 'No' ?></td>
                <td><?= ex($c['assigned_by_name']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>Documentación obligatoria (<?= (int) $docStatus['pct'] ?>% completa)</h2>
<table>
    <tr><th>Documento</th><th>Estado</th><th>Cargado</th><th>Requiere firma</th><th>Firma</th></tr>
    <?php foreach ($docStatus['items'] as $it): ?>
        <tr>
            <td><?= ex($it['label']) ?></td>
            <td><?= $it['present'] ? 'Presente' : 'FALTA' ?></td>
            <td><?= exDate($it['uploaded_at']) ?></td>
            <td><?= $it['requires_signature'] ? 'Sí' : 'No' ?></td>
            <td><?= ex($it['signature_status'] ?: '—') ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php if (!$personal['is_complete']): ?>
    <p><strong>Datos personales faltantes:</strong> <?= ex(implode(', ', $personal['missing'])) ?></p>
<?php endif; ?>

<h2>Documentos en el expediente (<?= count($documents) ?>)</h2>
<?php if (empty($documents)): ?>
    <p class="muted">Sin documentos cargados.</p>
<?php else: ?>
    <table>
        <tr><th>Tipo</th><th>Nombre</th><th>Formato</th><th>Cargado</th></tr>
        <?php foreach ($documents as $d): ?>
            <tr>
                <td><?= ex($d['document_type']) ?></td>
                <td><?= ex($d['document_name']) ?></td>
                <td><?= ex(strtoupper((string) $d['file_extension'])) ?></td>
                <td><?= exDate($d['uploaded_at']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<p class="muted" style="margin-top:18px;">
    Documento generado automáticamente por el Sistema de Ponche · <?= date('d/m/Y H:i') ?> ·
    Usuario: <?= ex($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?>
</p>
<?php
$body = ob_get_clean();

$safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $emp['employee_code'] . '_' . $fullName);
$safeName = trim($safeName, '_');

// --------------------------------------------------------------------------
// Salida
// --------------------------------------------------------------------------
if ($formato === 'pdf') {
    require_once __DIR__ . '/../vendor/autoload.php';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans'); // con acentos y ñ

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml('<html><head><meta charset="UTF-8"></head><body>' . $body . '</body></html>', 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    $dompdf->stream('expediente_' . $safeName . '.pdf', ['Attachment' => true]);
    exit;
}

// Excel: tabla HTML con el Content-Type de Excel, como el resto del sistema.
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="expediente_' . $safeName . '.xls"');
header('Cache-Control: max-age=0');
echo "\xEF\xBB\xBF"; // BOM: Excel abre los acentos correctamente
echo '<html><head><meta charset="UTF-8"></head><body>' . $body . '</body></html>';
exit;

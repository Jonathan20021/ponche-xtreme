<?php
/**
 * Instalador idempotente de los ajustes de Vacaciones.
 *
 *   1. Ajustes del calculo (sabado 0.5, domingo 0, feriado 0, dias por antiguedad)
 *   2. Comprobante de pago en la solicitud
 *   3. RECALCULO de las solicitudes y balances existentes, que quedaron inflados
 *      porque se contaban dias calendario.
 *
 * MySQL 5.7: sin `IF NOT EXISTS` en ALTER.
 *
 * Uso:
 *   php run_vacations_module_migration.php              -> instala y muestra que cambiaria
 *   php run_vacations_module_migration.php --recalcular -> ademas aplica el recalculo
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/vacation_calculator.php';

$cli = (PHP_SAPI === 'cli');
$nl  = $cli ? "\n" : "<br>\n";
if (!$cli) {
    echo "<pre style='font-family:Consolas,monospace;background:#0f172a;color:#cbd5e1;padding:16px;'>";
}

$aplicar = in_array('--recalcular', $argv ?? [], true) || isset($_GET['recalcular']);

echo "=== Migracion: Vacaciones ==={$nl}DB: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "{$nl}{$nl}";

$ok = 0; $skipped = 0; $errors = 0;

function vacStep(string $label, callable $fn, &$ok, &$skipped, &$errors, string $nl): void
{
    echo "[*] $label{$nl}";
    try {
        $r = $fn();
        if ($r === 'SKIP') { echo "    SKIP{$nl}"; $skipped++; }
        else { echo "    OK" . (is_string($r) && $r !== 'OK' ? " ($r)" : '') . "{$nl}"; $ok++; }
    } catch (Throwable $e) {
        echo "    ERROR: " . $e->getMessage() . "{$nl}";
        $errors++;
    }
}

function vacColumnExists(PDO $pdo, string $table, string $column): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $column]);
    return (int) $s->fetchColumn() > 0;
}

// ---------------------------------------------------------------------------
// 1. Ajustes configurables
// ---------------------------------------------------------------------------
$settings = [
    'vacation_saturday_value'     => ['0.5', 'vacations'],
    'vacation_sunday_value'       => ['0',   'vacations'],
    'vacation_holiday_value'      => ['0',   'vacations'],
    'vacation_base_days'          => ['14',  'vacations'],
    'vacation_days_after_5_years' => ['18',  'vacations'],
    'vacation_notice_enabled'     => ['1',   'vacations'],
    'vacation_notice_days_before' => ['30',  'vacations'],
    'vacation_notice_roles'       => ['HR,Admin', 'vacations'],
    'vacation_notice_user_ids'    => ['',    'vacations'],
];
foreach ($settings as $key => [$value, $cat]) {
    vacStep("setting $key", function () use ($pdo, $key, $value, $cat) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $c->execute([$key]);
        if ((int) $c->fetchColumn() > 0) { return 'SKIP'; }
        $i = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, category) VALUES (?, ?, 'string', ?)");
        $i->execute([$key, $value, $cat]);
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

// ---------------------------------------------------------------------------
// 2. Comprobante de pago y desglose del calculo
// ---------------------------------------------------------------------------
$columnas = [
    'payment_receipt_file' => "VARCHAR(255) DEFAULT NULL COMMENT 'comprobante o constancia de pago de las vacaciones' AFTER `review_notes`",
    'payment_amount'       => "DECIMAL(12,2) DEFAULT NULL COMMENT 'monto pagado por las vacaciones' AFTER `payment_receipt_file`",
    'payment_date'         => "DATE DEFAULT NULL COMMENT 'fecha en que se pago' AFTER `payment_amount`",
    'payment_notes'        => "VARCHAR(255) DEFAULT NULL AFTER `payment_date`",
    'calendar_days'        => "INT DEFAULT NULL COMMENT 'dias calendario del periodo (para auditar el calculo)' AFTER `total_days`",
    'days_breakdown'       => "VARCHAR(255) DEFAULT NULL COMMENT 'desglose: completos, medios, domingos, feriados' AFTER `calendar_days`",
];
foreach ($columnas as $col => $def) {
    vacStep("vacation_requests.$col", function () use ($pdo, $col, $def) {
        if (vacColumnExists($pdo, 'vacation_requests', $col)) { return 'SKIP'; }
        $pdo->exec("ALTER TABLE `vacation_requests` ADD COLUMN `$col` $def");
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

// vacation_balances necesita clave unica para el ON DUPLICATE KEY del recalculo
vacStep('Indice unico vacation_balances (empleado, anio)', function () use ($pdo) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vacation_balances'
                          AND INDEX_NAME = 'uq_vacation_balance_employee_year'");
    $s->execute();
    if ((int) $s->fetchColumn() > 0) { return 'SKIP'; }

    // Antes de crear el unico hay que limpiar duplicados si los hubiera.
    $pdo->exec("
        DELETE b1 FROM vacation_balances b1
        INNER JOIN vacation_balances b2
        WHERE b1.employee_id = b2.employee_id AND b1.year = b2.year AND b1.id < b2.id
    ");
    $pdo->exec("ALTER TABLE `vacation_balances` ADD UNIQUE KEY `uq_vacation_balance_employee_year` (`employee_id`, `year`)");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 3. Recalculo de lo existente
// ---------------------------------------------------------------------------
echo "{$nl}=== Recalculo de solicitudes existentes ==={$nl}";
if (!$aplicar) {
    echo "MODO SIMULACION. Corre con --recalcular para aplicar los cambios.{$nl}{$nl}";
}

try {
    $requests = $pdo->query("
        SELECT vr.id, vr.employee_id, vr.start_date, vr.end_date, vr.total_days, vr.status,
               e.first_name, e.last_name
        FROM vacation_requests vr
        LEFT JOIN employees e ON e.id = vr.employee_id
        ORDER BY vr.start_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $requests = [];
    echo "ERROR al leer solicitudes: " . $e->getMessage() . "{$nl}";
}

$cambiadas = 0; $diferenciaTotal = 0.0; $invalidas = [];
printf("%-34s %-24s %8s %8s %8s{$nl}", 'COLABORADOR', 'PERIODO', 'ANTES', 'AHORA', 'DIF');

foreach ($requests as $r) {
    $nombre = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));

    // Fechas invertidas: es un error de captura, no un caso a recalcular. Si se
    // recalculara daria 0 y le borrariamos dias al colaborador sin que nadie se
    // entere. Se deja como esta y se reporta para que RRHH lo corrija.
    if ($r['end_date'] < $r['start_date']) {
        $invalidas[] = [
            'id' => $r['id'], 'nombre' => $nombre,
            'periodo' => $r['start_date'] . ' a ' . $r['end_date'],
            'dias' => (float) $r['total_days'],
        ];
        continue;
    }

    $calc  = calculateVacationDays($pdo, $r['start_date'], $r['end_date']);
    $antes = (float) $r['total_days'];
    $ahora = (float) $calc['total'];

    if (abs($antes - $ahora) < 0.01) {
        continue;
    }

    $cambiadas++;
    $diferenciaTotal += ($antes - $ahora);

    printf("%-34s %-24s %8s %8s %8s{$nl}",
        substr(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), 0, 34),
        $r['start_date'] . ' a ' . $r['end_date'],
        number_format($antes, 1), number_format($ahora, 1),
        ($ahora - $antes >= 0 ? '+' : '') . number_format($ahora - $antes, 1));

    if ($aplicar) {
        $b = $calc['breakdown'];
        $up = $pdo->prepare("
            UPDATE vacation_requests
            SET total_days = ?, calendar_days = ?, days_breakdown = ?
            WHERE id = ?
        ");
        $up->execute([
            $ahora,
            $calc['calendar_days'],
            sprintf('%d completos, %d medios, %d domingos, %d feriados',
                $b['full'], $b['half'], $b['sundays'], $b['holidays']),
            $r['id'],
        ]);
    }
}

echo "{$nl}Solicitudes con diferencia: $cambiadas de " . count($requests)
    . " · dias devueltos en total: " . number_format($diferenciaTotal, 1) . "{$nl}";

if (!empty($invalidas)) {
    echo "{$nl}*** REVISAR A MANO: " . count($invalidas) . " solicitud(es) con la fecha final ANTERIOR a la inicial.{$nl}";
    echo "    No se tocaron (recalcularlas daria 0 dias y le quitaria vacaciones al colaborador).{$nl}";
    foreach ($invalidas as $x) {
        printf("      #%-5s %-34s %-24s %s dias registrados{$nl}",
            $x['id'], substr($x['nombre'], 0, 34), $x['periodo'], number_format($x['dias'], 1));
    }
}

// Balances
echo "{$nl}=== Balances ==={$nl}";
try {
    $empleados = $pdo->query("
        SELECT DISTINCT employee_id FROM vacation_requests
        UNION
        SELECT DISTINCT employee_id FROM vacation_balances
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $empleados = [];
}

$anio = (int) date('Y');
$negativosAntes = (int) $pdo->query("SELECT COUNT(*) FROM vacation_balances WHERE remaining_days < 0")->fetchColumn();
echo "Balances en negativo ANTES: $negativosAntes{$nl}";

if ($aplicar) {
    foreach ($empleados as $eid) {
        vacationRecalculateBalance($pdo, (int) $eid, $anio);
    }
    $negativosDespues = (int) $pdo->query("SELECT COUNT(*) FROM vacation_balances WHERE remaining_days < 0")->fetchColumn();
    echo "Balances en negativo DESPUES: $negativosDespues{$nl}";
} else {
    echo "(se recalcularian " . count($empleados) . " colaboradores){$nl}";
}

echo "{$nl}=== Resumen ==={$nl}OK: $ok   SKIP: $skipped   ERRORES: $errors{$nl}";
if (!$aplicar) {
    echo "{$nl}Nada de lo anterior se aplico. Vuelve a correrlo con --recalcular.{$nl}";
}

if (!$cli) {
    echo "</pre>";
}

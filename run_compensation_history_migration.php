<?php
/**
 * Migración: compensación con FECHA EFECTIVA.
 *
 *   1. employee_compensation_changes  -> cambios de salario con la fecha desde la
 *      que aplican (foto ANTES y DESPUÉS). Es lo que permite que un cambio de
 *      campaña a mitad de quincena NO repague los días ya trabajados.
 *   2. payroll_records.salary_segments -> JSON con el desglose por tramo cuando un
 *      período se pagó con más de un salario.
 *
 * NO se hace backfill: sin filas, la nómina se comporta EXACTAMENTE igual que
 * antes (un solo salario por período, el de `users`). El prorrateo empieza a
 * actuar desde el primer cambio que se registre por la UI.
 *
 * Corre por navegador o por CLI, las veces que haga falta. Es idempotente.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/compensation_history.php';

$cli = (PHP_SAPI === 'cli');
$nl  = $cli ? "\n" : "<br>\n";
if (!$cli) {
    echo "<pre style='font-family:Consolas,monospace;background:#0f172a;color:#cbd5e1;padding:16px;'>";
}

$schema = $pdo->query("SELECT DATABASE()")->fetchColumn();
echo "=== Migracion: Compensacion con fecha efectiva ==={$nl}DB: $schema{$nl}{$nl}";

$ok = 0;
$fail = 0;

/** Corre un paso y reporta sin abortar el resto. */
$step = function (string $label, callable $fn) use (&$ok, &$fail, $nl): void {
    try {
        $fn();
        $ok++;
        echo "[OK]   $label{$nl}";
    } catch (Throwable $e) {
        $fail++;
        echo "[FALLA] $label -> " . $e->getMessage() . $nl;
    }
};

$step('Tabla employee_compensation_changes', function () use ($pdo) {
    if (!ensureCompensationChangesTable($pdo)) {
        throw new RuntimeException('no se pudo crear la tabla (ver error_log)');
    }
});

$step('Columna payroll_records.salary_segments', function () use ($pdo) {
    $columns = $pdo->query("SHOW COLUMNS FROM payroll_records")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('salary_segments', $columns, true)) {
        $pdo->exec("ALTER TABLE payroll_records ADD COLUMN salary_segments TEXT NULL COMMENT 'JSON: desglose por tramo cuando hubo cambio de salario en el periodo'");
    }
});

$step('Aplicar cambios de salario ya vencidos (si los hubiera)', function () use ($pdo, $nl) {
    $applied = applyDueCompensationChanges($pdo);
    echo "       aplicados: {$applied}{$nl}";
});

echo $nl . "=== Resultado: {$ok} paso(s) OK, {$fail} con fallo ==={$nl}";
echo "Siguiente paso: registra los cambios de salario desde el perfil del colaborador{$nl}";
echo "(Cambiar salario / Asignar campana) o desde Ajustes > Gestionar usuarios.{$nl}";

if (!$cli) {
    echo "</pre>";
}

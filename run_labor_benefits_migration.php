<?php
/**
 * Instalador idempotente de la Calculadora de Prestaciones Laborales.
 *
 *   1. Escalas legales configurables (días de preaviso, cesantía, vacaciones,
 *      divisores del salario diario) en system_settings.
 *   2. Sección de permisos `hr_labor_benefits`.
 *   3. Tabla `labor_benefit_calculations` con el historial de cálculos.
 *
 * Se puede correr las veces que haga falta: lo que ya existe se salta.
 * MySQL 5.7, así que nada de `IF NOT EXISTS` en ALTER.
 *
 * Uso:  php run_labor_benefits_migration.php
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/labor_benefits_calculator.php';

$cli = (PHP_SAPI === 'cli');
$nl  = $cli ? "\n" : "<br>\n";
if (!$cli) {
    echo "<pre style='font-family:Consolas,monospace;background:#0f172a;color:#cbd5e1;padding:16px;'>";
}

echo "=== Migracion: Calculadora de Prestaciones Laborales ==={$nl}";
echo "DB: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "{$nl}{$nl}";

$ok = 0; $skipped = 0; $errors = 0;

function lbmStep(string $label, callable $fn, &$ok, &$skipped, &$errors, string $nl): void
{
    echo "[*] $label{$nl}";
    try {
        $r = $fn();
        if ($r === 'SKIP') {
            echo "    SKIP{$nl}";
            $skipped++;
        } else {
            echo "    OK" . (is_string($r) && $r !== 'OK' ? " ($r)" : '') . "{$nl}";
            $ok++;
        }
    } catch (Throwable $e) {
        echo "    ERROR: " . $e->getMessage() . "{$nl}";
        $errors++;
    }
}

// ---------------------------------------------------------------------------
// 1. Escalas legales
// ---------------------------------------------------------------------------
echo "--- Escalas legales (system_settings) ---{$nl}";

$descripciones = [
    'benefits_divisores_ordinario'    => 'Divisores del salario del periodo a salario diario, trabajo ordinario (Mensual,Quincenal,Semanal,Diario)',
    'benefits_divisores_intermitente' => 'Divisores del salario del periodo a salario diario, trabajo intermitente y domestico',
    'benefits_semanas_por_mes'        => 'Semanas promedio por mes, para llevar el salario semanal a mensual',
    'benefits_preaviso_3_6_meses'     => 'Dias de preaviso de 3 a 6 meses de antiguedad (art. 76)',
    'benefits_preaviso_6_12_meses'    => 'Dias de preaviso de 6 a 12 meses de antiguedad (art. 76)',
    'benefits_preaviso_12_meses_mas'  => 'Dias de preaviso desde 1 anio de antiguedad (art. 76)',
    'benefits_cesantia_1_5_anios'     => 'Dias de cesantia por cada anio, de 1 a 5 anios (art. 80)',
    'benefits_cesantia_5_anios_mas'   => 'Dias de cesantia por cada anio, desde 5 anios (art. 80)',
    'benefits_cesantia_fraccion_3_6'  => 'Dias de cesantia por fraccion de anio de 3 a 6 meses (art. 81)',
    'benefits_cesantia_fraccion_6_12' => 'Dias de cesantia por fraccion de anio mayor de 6 meses (art. 81)',
    'benefits_cesantia_antes_codigo'  => 'Dias de cesantia por anio trabajado antes del Codigo de Trabajo de 1992',
    'benefits_fecha_codigo_trabajo'   => 'Entrada en vigencia del Codigo de Trabajo (Ley 16-92)',
    'benefits_vacaciones_1_5_anios'   => 'Dias de vacaciones de 1 a 5 anios de antiguedad (art. 177)',
    'benefits_vacaciones_5_anios_mas' => 'Dias de vacaciones desde 5 anios de antiguedad (art. 177)',
    'benefits_vacaciones_domestico'   => 'Dias de vacaciones del trabajo domestico',
];

foreach (laborBenefitsDefaults() as $key => $value) {
    lbmStep("setting $key = $value", function () use ($pdo, $key, $value, $descripciones) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $c->execute([$key]);
        if ((int) $c->fetchColumn() > 0) {
            return 'SKIP';
        }
        $i = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_type, description, category)
            VALUES (?, ?, 'string', ?, 'labor_benefits')
        ");
        $i->execute([$key, $value, $descripciones[$key] ?? '']);
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

// ---------------------------------------------------------------------------
// 1b. Identificacion de la empresa (encabeza el PDF de liquidacion)
// ---------------------------------------------------------------------------
echo "{$nl}--- Datos de la empresa (system_settings) ---{$nl}";

foreach (laborBenefitsCompanyDefaults() as $key => $value) {
    lbmStep("setting $key" . ($value !== '' ? " = $value" : ' (vacio)'), function () use ($pdo, $key, $value) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
        $c->execute([$key]);
        if ((int) $c->fetchColumn() > 0) {
            return 'SKIP';
        }
        $i = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_type, description, category)
            VALUES (?, ?, 'string', 'Dato de la empresa para el PDF de liquidacion', 'company')
        ");
        $i->execute([$key, $value]);
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

// ---------------------------------------------------------------------------
// 2. Permisos
// ---------------------------------------------------------------------------
echo "{$nl}--- Permisos de la seccion hr_labor_benefits ---{$nl}";

// Los mismos roles que ya ven la Nomina: son quienes liquidan a un colaborador.
$roles = ['Admin', 'Desarrollador', 'DIRECTOR', 'ENCARGADODEGESTIONHUMANA', 'HR', 'IT'];

foreach ($roles as $rol) {
    lbmStep("permiso hr_labor_benefits -> $rol", function () use ($pdo, $rol) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM section_permissions WHERE section_key = ? AND role = ?");
        $c->execute(['hr_labor_benefits', $rol]);
        if ((int) $c->fetchColumn() > 0) {
            return 'SKIP';
        }
        $i = $pdo->prepare("INSERT INTO section_permissions (section_key, role) VALUES (?, ?)");
        $i->execute(['hr_labor_benefits', $rol]);
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

// ---------------------------------------------------------------------------
// 3. Historial de calculos
// ---------------------------------------------------------------------------
echo "{$nl}--- Tabla labor_benefit_calculations ---{$nl}";

lbmStep('crear tabla labor_benefit_calculations', function () use ($pdo) {
    $c = $pdo->query("SHOW TABLES LIKE 'labor_benefit_calculations'");
    if ($c->fetch()) {
        return 'SKIP';
    }
    $pdo->exec("
        CREATE TABLE labor_benefit_calculations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id INT UNSIGNED NULL,
            employee_name VARCHAR(150) NOT NULL DEFAULT '',
            cedula VARCHAR(50) NOT NULL DEFAULT '',
            lugar_trabajo VARCHAR(150) NOT NULL DEFAULT '',
            fecha_ingreso DATE NOT NULL,
            fecha_salida DATE NOT NULL,
            periodo_idx TINYINT NOT NULL DEFAULT 0,
            tipo_calculo_idx TINYINT NOT NULL DEFAULT 0,
            preavisado TINYINT(1) NOT NULL DEFAULT 0,
            incluir_cesantia TINYINT(1) NOT NULL DEFAULT 1,
            incluir_navidad TINYINT(1) NOT NULL DEFAULT 1,
            vacaciones_tomadas TINYINT(1) NOT NULL DEFAULT 1,
            salarios_json TEXT NULL,
            tiempo_texto VARCHAR(80) NOT NULL DEFAULT '',
            promedio_mensual DECIMAL(14,2) NOT NULL DEFAULT 0,
            promedio_diario DECIMAL(14,2) NOT NULL DEFAULT 0,
            dias_preaviso INT NOT NULL DEFAULT 0,
            monto_preaviso DECIMAL(14,2) NOT NULL DEFAULT 0,
            dias_cesantia INT NOT NULL DEFAULT 0,
            monto_cesantia DECIMAL(14,2) NOT NULL DEFAULT 0,
            dias_vacaciones INT NOT NULL DEFAULT 0,
            monto_vacaciones DECIMAL(14,2) NOT NULL DEFAULT 0,
            monto_navidad DECIMAL(14,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
            total DECIMAL(14,2) NOT NULL DEFAULT 0,
            notas VARCHAR(255) NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_lbc_employee (employee_id),
            KEY idx_lbc_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// 4. Prueba de humo: el motor tiene que seguir dando lo mismo que el MT
// ---------------------------------------------------------------------------
echo "{$nl}--- Prueba de humo ---{$nl}";

lbmStep('calculo de control (RD$45,000 mensuales, 3 anios 7 meses)', function () use ($pdo) {
    $salarios = [];
    for ($i = 0; $i < 12; $i++) {
        $salarios[] = ['salario' => 45000.00, 'comision' => 0];
    }
    $r = laborBenefitsCalculate([
        'fecha_ingreso'      => '2021-01-15',
        'fecha_salida'       => '2024-08-14',
        'periodo_idx'        => 0,
        'tipo_calculo_idx'   => 0,
        'salarios'           => $salarios,
        'preavisado'         => false,
        'incluir_cesantia'   => true,
        'incluir_navidad'    => true,
        'vacaciones_tomadas' => true,
    ], laborBenefitsConfig($pdo));

    if (!$r['ok']) {
        throw new RuntimeException($r['error']);
    }

    // 3 anios y 7 meses -> cesantia 3x21 + 13 = 76 dias; preaviso 28 dias.
    if ($r['preaviso']['dias'] !== 28 || $r['cesantia_despues']['dias'] !== 76) {
        throw new RuntimeException(sprintf(
            'escalas fuera de lo esperado: preaviso=%d dias, cesantia=%d dias',
            $r['preaviso']['dias'], $r['cesantia_despues']['dias']
        ));
    }

    return sprintf('preaviso %d dias, cesantia %d dias, total RD$%s',
        $r['preaviso']['dias'], $r['cesantia_despues']['dias'], number_format($r['total'], 2));
}, $ok, $skipped, $errors, $nl);

lbmStep('logo para el PDF (assets/logo.png)', function () {
    $uri = laborBenefitsLogoBase64(260);
    if ($uri === '') {
        throw new RuntimeException('no se pudo preparar el logo: revisa assets/logo.png y la extension GD');
    }
    return 'listo, ' . round(strlen($uri) / 1024) . ' KB incrustados por documento';
}, $ok, $skipped, $errors, $nl);

lbmStep('dompdf disponible', function () {
    if (!is_file(__DIR__ . '/vendor/autoload.php')) {
        throw new RuntimeException('falta vendor/autoload.php: corre composer install');
    }
    require_once __DIR__ . '/vendor/autoload.php';
    if (!class_exists('Dompdf\Dompdf')) {
        throw new RuntimeException('dompdf no esta instalado: corre composer install');
    }
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
echo "{$nl}=== Resumen ==={$nl}";
echo "OK: $ok | Saltados: $skipped | Errores: $errors{$nl}{$nl}";

if ($errors === 0) {
    echo "Listo. La calculadora esta en Recursos Humanos -> Prestaciones Laborales.{$nl}";
    echo "Las escalas legales se editan en Nomina -> Configuracion.{$nl}";
} else {
    echo "Hubo errores. Revisalos antes de usar la calculadora en produccion.{$nl}";
}

if (!$cli) {
    echo "</pre>";
}

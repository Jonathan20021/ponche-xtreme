<?php
/**
 * tests/labor_benefits_calculator_test.php
 *
 * Comprueba que lib/labor_benefits_calculator.php sigue dando EXACTAMENTE lo
 * mismo que la calculadora oficial del Ministerio de Trabajo.
 *
 * `fixtures_labor_benefits_mt.json` no son valores inventados: se generaron
 * ejecutando el propio `js/site.min.js` de https://calculo.mt.gob.do sobre 300
 * casos (bordes del Código de Trabajo + aleatorios) y guardando su salida. Si
 * alguien toca el motor y rompe la paridad, este test lo canta.
 *
 * Uso:  php tests/labor_benefits_calculator_test.php
 */

require_once __DIR__ . '/../lib/labor_benefits_calculator.php';

$fixtures = json_decode(file_get_contents(__DIR__ . '/fixtures_labor_benefits_mt.json'), true);
if (!$fixtures) {
    fwrite(STDERR, "No se pudieron leer los fixtures.\n");
    exit(2);
}

// Escalas de fábrica: el test compara contra el MT, no contra lo que tenga
// configurado la empresa en system_settings.
$cfg = laborBenefitsDefaults();

$campos = [
    'diasPreaviso'   => ['preaviso', 'dias'],
    'preaviso'       => ['preaviso', 'monto'],
    'diasCesAntes'   => ['cesantia_antes', 'dias'],
    'cesAntes'       => ['cesantia_antes', 'monto'],
    'diasCesDespues' => ['cesantia_despues', 'dias'],
    'cesDespues'     => ['cesantia_despues', 'monto'],
    'diasVacaciones' => ['vacaciones', 'dias'],
    'vacaciones'     => ['vacaciones', 'monto'],
    'navidad'        => ['navidad', 'monto'],
];
$planos = [
    'promedioMensual' => 'promedio_mensual',
    'promedioDiario'  => 'promedio_diario',
    'subtotal'        => 'subtotal',
    'total'           => 'total',
];

$fallos = 0;

// ---------------------------------------------------------------------------
// Redondeo: tiene que comportarse como JavaScript, no como PHP
// ---------------------------------------------------------------------------
// Los valores esperados se sacaron ejecutando Math.round(x*100)/100 y
// Number(x.toFixed(2)) en Node.
//
// El primer caso es el que destapó un fallo real: el atajo
// floor($x * 100 + 0.5) devolvía 0.01 donde JavaScript devuelve 0.00, porque
// 0.49999999999999994 + 0.5 da exactamente 1.0 en coma flotante.
$redondeos = [
    // [valor,                  Math.round(x*100)/100, Number(x.toFixed(2))]
    [0.0049999999999999992367, 0.00,      0.00],
    [0.005,                    0.01,      0.01],
    [1.0049999999999998934,    1.00,      1.00],
    [2.6749999999999998224,    2.68,      2.67],
    [0.125,                    0.13,      0.13],
    [1234.5650000000000546,    1234.57,   1234.57],
    [197834.625,               197834.63, 197834.63],
    [0.0,                      0.00,      0.00],
];

foreach ($redondeos as [$x, $esperadoRound, $esperadoFixed]) {
    if (lbRound2($x) !== $esperadoRound) {
        $fallos++;
        printf("FALLA redondeo: lbRound2(%.20f) = %s, JavaScript da %s\n", $x, lbRound2($x), $esperadoRound);
    }
    if (lbFixed2($x) !== $esperadoFixed) {
        $fallos++;
        printf("FALLA redondeo: lbFixed2(%.20f) = %s, JavaScript da %s\n", $x, lbFixed2($x), $esperadoFixed);
    }
}

foreach ($fixtures as $n => $f) {
    $c = $f['in'];
    $e = $f['mt'];

    $r = laborBenefitsCalculate([
        'fecha_ingreso'      => $c['ingreso'],
        'fecha_salida'       => $c['salida'],
        'periodo_idx'        => $c['periodo'],
        'tipo_calculo_idx'   => $c['tipo'],
        'salarios'           => $c['salarios'],
        'preavisado'         => $c['preavisado'],
        'incluir_cesantia'   => $c['cesantia'],
        'incluir_navidad'    => $c['navidad'],
        'vacaciones_tomadas' => $c['vacTomadas'],
    ], $cfg);

    $diffs = [];

    if (!$r['ok']) {
        $diffs[] = 'el motor devolvió error: ' . $r['error'];
    } else {
        foreach (['years', 'months', 'days'] as $k) {
            if ((int) $r['tiempo'][$k] !== (int) $e['tiempo'][$k]) {
                $diffs[] = "tiempo.$k = {$r['tiempo'][$k]}, MT dice {$e['tiempo'][$k]}";
            }
        }
        foreach ($campos as $mtKey => [$g, $sub]) {
            if (abs((float) $r[$g][$sub] - (float) $e[$mtKey]) > 0.005) {
                $diffs[] = "$mtKey = {$r[$g][$sub]}, MT dice {$e[$mtKey]}";
            }
        }
        foreach ($planos as $mtKey => $phpKey) {
            if (abs((float) $r[$phpKey] - (float) $e[$mtKey]) > 0.005) {
                $diffs[] = "$mtKey = {$r[$phpKey]}, MT dice {$e[$mtKey]}";
            }
        }
    }

    if ($diffs) {
        $fallos++;
        printf(
            "FALLA #%d  %s -> %s  periodo=%d tipo=%d preavisado=%d cesantia=%d navidad=%d vacTomadas=%d\n",
            $n, $c['ingreso'], $c['salida'], $c['periodo'], $c['tipo'],
            $c['preavisado'], $c['cesantia'], $c['navidad'], $c['vacTomadas']
        );
        foreach ($diffs as $d) {
            echo "    $d\n";
        }
    }
}

echo "\n";
echo count($redondeos) . " casos de redondeo + " . count($fixtures)
    . " liquidaciones comparadas contra la calculadora del Ministerio de Trabajo.\n";

if ($fallos > 0) {
    echo "FALLARON: $fallos\n";
    exit(1);
}

echo "Todos coinciden al centavo.\n";
exit(0);

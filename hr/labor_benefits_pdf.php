<?php
/**
 * hr/labor_benefits_pdf.php
 *
 * Documento de liquidación de prestaciones laborales en PDF (dompdf).
 *
 * Se puede llamar de dos maneras:
 *   - POST con los mismos campos del formulario -> liquidación en vivo.
 *   - GET ?id=N  -> reconstruye una liquidación ya guardada en el historial.
 *
 * El cálculo NO se repite aquí a mano: se le pide al motor
 * (lib/labor_benefits_calculator.php), el mismo que alimenta la pantalla, para
 * que el papel no pueda decir una cosa y el navegador otra.
 */

session_start();
require_once '../db.php';
require_once '../lib/labor_benefits_calculator.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$seccion = 'hr_payroll';
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM section_permissions WHERE section_key = ?");
    $st->execute(['hr_labor_benefits']);
    if ((int) $st->fetchColumn() > 0) {
        $seccion = 'hr_labor_benefits';
    }
} catch (Throwable $e) { /* se queda con hr_payroll */ }

ensurePermission($seccion, '../unauthorized.php');

// ---------------------------------------------------------------------------
// Entrada
// ---------------------------------------------------------------------------
$guardadoId = (int) ($_GET['id'] ?? 0);
$encabezado = ['nombre' => '', 'cedula' => '', 'lugar' => '', 'notas' => '', 'referencia' => '', 'fecha' => date('Y-m-d H:i')];

if ($guardadoId > 0) {
    $st = $pdo->prepare("
        SELECT c.*, u.full_name AS creado_por
        FROM labor_benefit_calculations c
        LEFT JOIN users u ON u.id = c.created_by
        WHERE c.id = ?
    ");
    $st->execute([$guardadoId]);
    $g = $st->fetch(PDO::FETCH_ASSOC);

    if (!$g) {
        http_response_code(404);
        exit('Liquidación no encontrada.');
    }

    $salarios = json_decode((string) $g['salarios_json'], true) ?: [];
    $entrada = [
        'fecha_ingreso'      => $g['fecha_ingreso'],
        'fecha_salida'       => $g['fecha_salida'],
        'periodo_idx'        => (int) $g['periodo_idx'],
        'tipo_calculo_idx'   => (int) $g['tipo_calculo_idx'],
        'salarios'           => $salarios,
        'preavisado'         => (int) $g['preavisado'] === 1,
        'incluir_cesantia'   => (int) $g['incluir_cesantia'] === 1,
        'incluir_navidad'    => (int) $g['incluir_navidad'] === 1,
        'vacaciones_tomadas' => (int) $g['vacaciones_tomadas'] === 1,
    ];
    $encabezado = [
        'nombre'     => $g['employee_name'],
        'cedula'     => $g['cedula'],
        'lugar'      => $g['lugar_trabajo'],
        'notas'      => (string) $g['notas'],
        'referencia' => sprintf('LIQ-%s-%05d', date('Y', strtotime($g['created_at'])), (int) $g['id']),
        'fecha'      => date('d/m/Y H:i', strtotime($g['created_at'])),
        'emitido_por' => (string) ($g['creado_por'] ?? ''),
    ];
    $empleadoId = (int) $g['employee_id'];
} else {
    $salarios = [];
    for ($i = 0; $i < 12; $i++) {
        $salarios[$i] = [
            'salario'  => (float) str_replace(',', '', (string) ($_POST['salario'][$i] ?? 0)),
            'comision' => (float) str_replace(',', '', (string) ($_POST['comision'][$i] ?? 0)),
        ];
    }
    $entrada = [
        'fecha_ingreso'      => trim((string) ($_POST['fecha_ingreso'] ?? '')),
        'fecha_salida'       => trim((string) ($_POST['fecha_salida'] ?? '')),
        'periodo_idx'        => (int) ($_POST['periodo_idx'] ?? 0),
        'tipo_calculo_idx'   => (int) ($_POST['tipo_calculo_idx'] ?? 0),
        'salarios'           => $salarios,
        'preavisado'         => !empty($_POST['preavisado']),
        'incluir_cesantia'   => !empty($_POST['incluir_cesantia']),
        'incluir_navidad'    => !empty($_POST['incluir_navidad']),
        'vacaciones_tomadas' => !empty($_POST['vacaciones_tomadas']),
    ];
    $encabezado['nombre']      = trim((string) ($_POST['employee_name'] ?? ''));
    $encabezado['cedula']      = trim((string) ($_POST['cedula'] ?? ''));
    $encabezado['lugar']       = trim((string) ($_POST['lugar_trabajo'] ?? ''));
    $encabezado['referencia']  = 'PRELIMINAR';
    $encabezado['fecha']       = date('d/m/Y H:i');
    $encabezado['emitido_por'] = (string) ($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
    $empleadoId = (int) ($_POST['employee_id'] ?? 0);
}

$r = laborBenefitsCalculate($entrada, laborBenefitsConfig($pdo));

if (!$r['ok']) {
    http_response_code(400);
    exit('No se puede emitir el documento: ' . htmlspecialchars($r['error']));
}

// Datos del colaborador, si viene de la ficha
$empleado = $empleadoId > 0 ? laborBenefitsEmployeeDefaults($pdo, $empleadoId) : null;
if ($empleado) {
    if ($encabezado['nombre'] === '') { $encabezado['nombre'] = $empleado['nombre']; }
    if ($encabezado['cedula'] === '') { $encabezado['cedula'] = $empleado['cedula']; }
}

// Quién emite, si no vino en el registro
if (empty($encabezado['emitido_por']) && !empty($_SESSION['user_id'])) {
    try {
        $st = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $st->execute([$_SESSION['user_id']]);
        $encabezado['emitido_por'] = (string) ($st->fetchColumn() ?: '');
    } catch (Throwable $e) { /* opcional */ }
}

$empresa = laborBenefitsCompany($pdo);
$logo    = laborBenefitsLogoBase64(260);

// ---------------------------------------------------------------------------
// Ayudas de formato
// ---------------------------------------------------------------------------
$esc = function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$rd = function ($v): string {
    return 'RD$ ' . number_format((float) $v, 2);
};
$fec = function (?string $f): string {
    return $f ? date('d/m/Y', strtotime($f)) : '—';
};

$conceptos = [];

$conceptos[] = [
    'concepto' => 'Preaviso',
    'base'     => $r['preaviso']['omitido']
        ? 'Recibió el preaviso: no procede la indemnización sustitutiva'
        : 'Indemnización sustitutiva del preaviso',
    'ley'      => 'Arts. 76 y 79',
    'dias'     => $r['preaviso']['dias'],
    'tarifa'   => $r['promedio_diario'],
    'importe'  => $r['preaviso']['monto'],
];

if ($r['cesantia_antes']['dias'] > 0) {
    $conceptos[] = [
        'concepto' => 'Auxilio de cesantía (antes del Código de 1992)',
        'base'     => 'Tiempo servido antes del 17/06/1992',
        'ley'      => 'Régimen anterior',
        'dias'     => $r['cesantia_antes']['dias'],
        'tarifa'   => $r['promedio_diario'],
        'importe'  => $r['cesantia_antes']['monto'],
    ];
}

$conceptos[] = [
    'concepto' => $r['cesantia_antes']['dias'] > 0
        ? 'Auxilio de cesantía (Código de 1992)'
        : 'Auxilio de cesantía',
    'base'     => 'Por año de servicio prestado y fracción',
    'ley'      => 'Arts. 80 y 81',
    'dias'     => $r['cesantia_despues']['dias'],
    'tarifa'   => $r['promedio_diario'],
    'importe'  => $r['cesantia_despues']['monto'],
];

$tarifaVacaciones = $r['factor_actual'] > 0 ? $r['ultimo_salario'] / $r['factor_actual'] : 0.0;
$conceptos[] = [
    'concepto' => 'Vacaciones',
    'base'     => $r['vacaciones']['tomadas']
        ? 'Fracción corrida desde el último aniversario (ya disfrutó las del año)'
        : 'Vacaciones no disfrutadas',
    'ley'      => 'Art. 177',
    'dias'     => $r['vacaciones']['dias'],
    'tarifa'   => $tarifaVacaciones,
    'importe'  => $r['vacaciones']['monto'],
];

$conceptos[] = [
    'concepto' => 'Salario de Navidad (regalía pascual)',
    'base'     => 'Duodécima parte del salario ordinario del año calendario · ' . $r['navidad']['texto'],
    'ley'      => 'Art. 219',
    'dias'     => null,
    'tarifa'   => null,
    'importe'  => $r['navidad']['monto'],
];

// Períodos con monto, para no imprimir doce filas vacías cuando no aplican.
$periodosConMonto = [];
foreach ($r['salarios'] as $i => $f) {
    if ($i < $r['meses_activos']) {
        $periodosConMonto[$i] = $f;
    }
}
$vacios = 0;
foreach ($periodosConMonto as $f) {
    if ((float) $f['total'] <= 0) { $vacios++; }
}

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 132px 42px 78px 42px; }

    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 9.2pt;
        color: #1f2937;
        margin: 0;
    }

    /* ---- Encabezado y pie fijos en todas las páginas ---- */
    #cabecera { position: fixed; top: -112px; left: 0; right: 0; height: 96px; }
    #cabecera table { width: 100%; border-collapse: collapse; }
    #cabecera td { vertical-align: top; border: none; padding: 0; }
    .logo { width: 62px; }
    .empresa-nombre { font-size: 14pt; font-weight: bold; color: #244886; letter-spacing: .3px; }
    .empresa-dato { font-size: 8pt; color: #4b5563; line-height: 1.45; }
    .doc-titulo {
        background: #244886; color: #ffffff;
        padding: 7px 12px; margin-top: 10px;
        font-size: 10.5pt; font-weight: bold; letter-spacing: .4px;
    }
    .doc-titulo .ref { float: right; font-weight: normal; font-size: 8.6pt; }

    #pie {
        position: fixed; bottom: -58px; left: 0; right: 0; height: 44px;
        border-top: 1px solid #d1d5db; padding-top: 6px;
        font-size: 7.4pt; color: #6b7280;
    }
    #pie table { width: 100%; border-collapse: collapse; }
    #pie td { border: none; padding: 0; }

    /* La numeración "Página X de Y" NO se pone aquí con counter(pages): dompdf
       aún no sabe cuántas páginas habrá mientras maqueta, y sale "de 0". Se
       estampa sobre el lienzo después de render(), más abajo. */

    /* Bloques que no deben partirse entre páginas: si no, el título de una
       sección se queda solo al pie y su tabla arranca en la siguiente. */
    .bloque { page-break-inside: avoid; }

    /* ---- Secciones ---- */
    h2 {
        font-size: 9pt; font-weight: bold; color: #244886;
        text-transform: uppercase; letter-spacing: .6px;
        border-bottom: 1.4px solid #244886;
        padding-bottom: 3px; margin: 16px 0 8px 0;
    }

    table.datos { width: 100%; border-collapse: collapse; }
    table.datos td { padding: 3.5px 0; vertical-align: top; border: none; }
    table.datos .et { color: #6b7280; width: 26%; font-size: 8.3pt; }
    table.datos .va { font-weight: bold; width: 24%; }

    table.rejilla { width: 100%; border-collapse: collapse; }
    table.rejilla th {
        background: #eef2f9; color: #244886;
        font-size: 7.8pt; text-transform: uppercase; letter-spacing: .3px;
        padding: 5px 6px; text-align: left;
        border-bottom: 1px solid #c7d2e4;
    }
    table.rejilla td { padding: 5px 6px; border-bottom: 1px solid #eceff4; }
    table.rejilla .num { text-align: right; }
    table.rejilla tr.par td { background: #fafbfd; }
    .apagado { color: #9ca3af; }
    .menudo { font-size: 7.6pt; color: #6b7280; }

    /* ---- Totales ---- */
    table.totales { width: 100%; border-collapse: collapse; margin-top: 10px; }
    table.totales td { padding: 6px 8px; border: none; }
    table.totales .rot { text-align: right; color: #374151; }
    /* Ancho holgado y sin partir: a 12,5 pt un "RD$ 78,745.98" no cabe en 130px
       y el importe del total se partía en dos líneas. */
    table.totales .imp { text-align: right; font-weight: bold; width: 168px; white-space: nowrap; }
    tr.subtotal td { border-top: 1px solid #d1d5db; }
    tr.total td {
        background: #244886; color: #ffffff;
        font-size: 12.5pt; font-weight: bold;
        padding: 10px 8px;
    }
    .en-letras {
        margin-top: 6px; padding: 7px 9px;
        background: #f3f6fb; border-left: 3px solid #244886;
        font-size: 8.2pt; color: #374151;
    }

    .aviso {
        margin-top: 12px; padding: 8px 10px;
        background: #fff8e6; border-left: 3px solid #d99e00;
        font-size: 8pt; color: #7a5c00;
    }
    .notas { margin-top: 12px; font-size: 7.8pt; color: #6b7280; line-height: 1.5; }

    /* ---- Firmas ---- */
    table.firmas { width: 100%; border-collapse: collapse; margin-top: 34px; }
    table.firmas td { border: none; width: 50%; padding: 0 18px; text-align: center; vertical-align: bottom; }
    .linea-firma { border-top: 1px solid #4b5563; padding-top: 5px; font-size: 8.4pt; }
    .linea-firma strong { display: block; font-size: 8.8pt; }
    .linea-firma span { color: #6b7280; font-size: 7.6pt; }
</style>
</head>
<body>

<div id="cabecera">
    <table>
        <tr>
            <?php if ($logo): ?>
                <td style="width:74px;"><img src="<?= $logo ?>" class="logo" alt=""></td>
            <?php endif; ?>
            <td>
                <div class="empresa-nombre"><?= $esc($empresa['company_name']) ?></div>
                <div class="empresa-dato">
                    <?php if ($empresa['company_rnc'] !== ''): ?>RNC <?= $esc($empresa['company_rnc']) ?><br><?php endif; ?>
                    <?= $esc($empresa['company_address']) ?>
                    <?php if ($empresa['company_phone'] !== '' || $empresa['company_email'] !== ''): ?>
                        <br><?= $esc(trim($empresa['company_phone'] . '  ' . $empresa['company_email'])) ?>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    </table>
    <div class="doc-titulo">
        LIQUIDACIÓN DE PRESTACIONES LABORALES Y DERECHOS ADQUIRIDOS
        <span class="ref"><?= $esc($encabezado['referencia']) ?></span>
    </div>
</div>

<div id="pie">
    <table>
        <tr>
            <td>
                <?= $esc($empresa['company_name']) ?> · Documento generado el <?= $esc($encabezado['fecha']) ?>
                <?php if (!empty($encabezado['emitido_por'])): ?>
                    por <?= $esc($encabezado['emitido_por']) ?>
                <?php endif; ?>
            </td>
        </tr>
    </table>
</div>

<h2>Datos del colaborador</h2>
<table class="datos">
    <tr>
        <td class="et">Nombre</td>
        <td class="va" colspan="3"><?= $esc($encabezado['nombre'] ?: '—') ?></td>
    </tr>
    <tr>
        <td class="et">Cédula</td>
        <td class="va"><?= $esc($encabezado['cedula'] ?: '—') ?></td>
        <td class="et">Código</td>
        <td class="va"><?= $esc($empleado['codigo'] ?? '—') ?></td>
    </tr>
    <tr>
        <td class="et">Posición</td>
        <td class="va"><?= $esc($empleado['posicion'] ?? '—') ?></td>
        <td class="et">Departamento</td>
        <td class="va"><?= $esc($empleado['departamento'] ?? '—') ?></td>
    </tr>
    <tr>
        <td class="et">Fecha de ingreso</td>
        <td class="va"><?= $fec($r['fecha_ingreso']) ?></td>
        <td class="et">Fecha de salida</td>
        <td class="va"><?= $fec($r['fecha_salida']) ?></td>
    </tr>
    <tr>
        <td class="et">Tiempo laborado</td>
        <td class="va" colspan="3"><?= $esc($r['tiempo_texto']) ?></td>
    </tr>
</table>

<h2>Base de cálculo</h2>
<table class="datos">
    <tr>
        <td class="et">Frecuencia de pago</td>
        <td class="va"><?= $esc($r['periodo']) ?></td>
        <td class="et">Tipo de cálculo</td>
        <td class="va"><?= $esc($r['tipo_calculo']) ?></td>
    </tr>
    <tr>
        <td class="et">Salarios acumulados</td>
        <td class="va"><?= $rd($r['salario_acumulado']) ?></td>
        <td class="et">Salario del último período</td>
        <td class="va"><?= $rd($r['ultimo_salario']) ?></td>
    </tr>
    <tr>
        <td class="et">Salario promedio mensual</td>
        <td class="va"><?= $rd($r['promedio_mensual']) ?></td>
        <td class="et">Salario promedio diario</td>
        <td class="va"><?= $rd($r['promedio_diario']) ?></td>
    </tr>
    <tr>
        <td class="et">Divisor aplicado</td>
        <td class="va" colspan="3">
            <?= $esc(rtrim(rtrim(number_format($r['factor_actual'], 4), '0'), '.')) ?>
            <span class="menudo">
                (salario del período ÷ divisor = salario diario, Reglamento 258-93)
            </span>
        </td>
    </tr>
</table>

<h2>Salarios del período (<?= (int) $r['meses_activos'] ?>)</h2>
<table class="rejilla">
    <thead>
        <tr>
            <th style="width:12%">Período</th>
            <th class="num" style="width:29%">Salario</th>
            <th class="num" style="width:29%">Comisión</th>
            <th class="num" style="width:30%">Total</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($periodosConMonto as $i => $f): ?>
            <tr class="<?= $i % 2 ? 'par' : '' ?>">
                <td><?= $i + 1 ?></td>
                <td class="num <?= (float) $f['salario'] <= 0 ? 'apagado' : '' ?>">
                    <?= (float) $f['salario'] > 0 ? number_format((float) $f['salario'], 2) : '—' ?>
                </td>
                <td class="num <?= (float) $f['comision'] <= 0 ? 'apagado' : '' ?>">
                    <?= (float) $f['comision'] > 0 ? number_format((float) $f['comision'], 2) : '—' ?>
                </td>
                <td class="num"><strong><?= number_format((float) $f['total'], 2) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td colspan="3" style="text-align:right; border-top:1.4px solid #c7d2e4;"><strong>Acumulado</strong></td>
            <td class="num" style="border-top:1.4px solid #c7d2e4;"><strong><?= number_format($r['salario_acumulado'], 2) ?></strong></td>
        </tr>
    </tbody>
</table>

<?php if ($vacios > 0): ?>
    <div class="aviso">
        <strong>Atención:</strong> <?= (int) $vacios ?> de los <?= (int) $r['meses_activos'] ?>
        períodos que duró la relación laboral quedaron sin salario registrado. El promedio se
        divide entre los <?= (int) $r['meses_activos'] ?> períodos aunque estén vacíos, de modo
        que los importes de este documento están <strong>por debajo</strong> de lo que
        corresponde. Compléta los períodos faltantes antes de usarlo para pagar.
    </div>
<?php endif; ?>

<div class="bloque">
<h2>Desglose de la liquidación</h2>
<table class="rejilla">
    <thead>
        <tr>
            <th style="width:40%">Concepto</th>
            <th style="width:14%">Base legal</th>
            <th class="num" style="width:9%">Días</th>
            <th class="num" style="width:17%">Salario diario</th>
            <th class="num" style="width:20%">Importe</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($conceptos as $n => $c): ?>
            <tr class="<?= $n % 2 ? 'par' : '' ?>">
                <td>
                    <strong><?= $esc($c['concepto']) ?></strong>
                    <div class="menudo"><?= $esc($c['base']) ?></div>
                </td>
                <td class="menudo"><?= $esc($c['ley']) ?></td>
                <td class="num <?= $c['dias'] === null ? 'apagado' : '' ?>">
                    <?= $c['dias'] === null ? '—' : (int) $c['dias'] ?>
                </td>
                <td class="num <?= $c['tarifa'] === null ? 'apagado' : '' ?>">
                    <?= $c['tarifa'] === null ? '—' : number_format((float) $c['tarifa'], 2) ?>
                </td>
                <td class="num"><strong><?= number_format((float) $c['importe'], 2) ?></strong></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<table class="totales">
    <tr class="subtotal">
        <td class="rot">Subtotal prestaciones (preaviso, cesantía y vacaciones)</td>
        <td class="imp"><?= $rd($r['subtotal']) ?></td>
    </tr>
    <tr>
        <td class="rot">Salario de Navidad</td>
        <td class="imp"><?= $rd($r['navidad']['monto']) ?></td>
    </tr>
    <tr class="total">
        <td>TOTAL A RECIBIR</td>
        <td class="imp"><?= $rd($r['total']) ?></td>
    </tr>
</table>

<div class="en-letras">
    <strong>Son:</strong> <?= $esc(lbNumeroALetras((float) $r['total'])) ?>
</div>
</div><!-- /bloque desglose -->

<?php if (trim((string) $encabezado['notas']) !== ''): ?>
    <h2>Observaciones</h2>
    <div class="menudo"><?= nl2br($esc($encabezado['notas'])) ?></div>
<?php endif; ?>

<div class="notas">
    Cálculo realizado conforme al Código de Trabajo de la República Dominicana (Ley 16-92),
    con la misma metodología de la calculadora oficial del Ministerio de Trabajo.
    El salario diario se obtiene dividiendo el salario del período entre el divisor legal
    (Reglamento 258-93).
    <br><br>
    Este documento <strong>no incluye</strong> la participación en los beneficios de la empresa
    (art. 223), que depende de la utilidad neta del ejercicio, ni los salarios caídos del
    art. 95 ordinal 3, que dependen de la duración del proceso. Tampoco contempla descuentos
    por préstamos, avances u otras deducciones pendientes, que deben aplicarse por separado.
</div>

<table class="firmas bloque">
    <tr>
        <td>
            <div class="linea-firma">
                <strong><?= $esc($empresa['company_representative']) ?></strong>
                <span><?= $esc($empresa['company_representative_title']) ?> · <?= $esc($empresa['company_name']) ?></span>
            </div>
        </td>
        <td>
            <div class="linea-firma">
                <strong><?= $esc($encabezado['nombre'] ?: 'El colaborador') ?></strong>
                <span>Recibí conforme<?= $encabezado['cedula'] !== '' ? ' · Cédula ' . $esc($encabezado['cedula']) : '' ?></span>
            </div>
        </td>
    </tr>
</table>

</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
// Sin acceso remoto: todo va incrustado (el logo es un data URI). Así el PDF no
// depende de que el servidor pueda salir a internet ni se cuelga esperando.
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('Letter', 'portrait');
$dompdf->render();

// "Página X de Y" se estampa después de render(), cuando ya se sabe el total.
// Los marcadores {PAGE_NUM} y {PAGE_COUNT} los sustituye dompdf al escribir cada
// página. Se pide la fuente DejaVu Sans explícitamente porque la del núcleo no
// lleva acentos y "Página" saldría partida.
$canvas = $dompdf->getCanvas();
$metricas = $dompdf->getFontMetrics();
$fuente = $metricas->getFont('DejaVu Sans', 'normal');
if ($fuente) {
    $tamano = 7.4;
    $texto = 'Página {PAGE_NUM} de {PAGE_COUNT}';
    // El ancho se mide sobre un ejemplo ya sustituido: los marcadores son más
    // largos que los números que acabarán ocupando su lugar.
    $anchoTexto = $metricas->getTextWidth('Página 9 de 9', $fuente, $tamano);

    $canvas->page_text(
        $canvas->get_width() - 42 - $anchoTexto, // alineado al margen derecho
        $canvas->get_height() - 48,              // a la altura de la línea del pie
        $texto,
        $fuente, $tamano,
        [0.42, 0.45, 0.50]
    );
}

$nombreArchivo = 'Liquidacion_'
    . preg_replace('/[^A-Za-z0-9]+/', '_', $encabezado['nombre'] ?: 'colaborador')
    . '_' . date('Ymd', strtotime($r['fecha_salida'])) . '.pdf';

$dompdf->stream($nombreArchivo, ['Attachment' => isset($_GET['descargar'])]);
exit;

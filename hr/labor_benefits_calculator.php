<?php
/**
 * hr/labor_benefits_calculator.php
 *
 * Calculadora de prestaciones laborales, vacaciones y regalía pascual.
 *
 * El cálculo NO vive aquí: está en lib/labor_benefits_calculator.php, que es un
 * puerto exacto del algoritmo de https://calculo.mt.gob.do. Esta página solo
 * arma el formulario, llama al motor por AJAX (para que haya UNA sola
 * implementación y no se desincronice una copia en JavaScript) y muestra el
 * desglose.
 */

session_start();
require_once '../db.php';
require_once '../lib/labor_benefits_calculator.php';

/**
 * Acceso: sección propia si ya está configurada; si todavía no se ha corrido la
 * migración, se cae a la de Nómina para no dejar a nadie fuera.
 */
$seccion = 'hr_payroll';
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM section_permissions WHERE section_key = ?");
    $st->execute(['hr_labor_benefits']);
    if ((int) $st->fetchColumn() > 0) {
        $seccion = 'hr_labor_benefits';
    }
} catch (Throwable $e) { /* se queda con hr_payroll */ }

ensurePermission($seccion, '../unauthorized.php');

/**
 * Historial de cálculos. Se crea al vuelo para que la página funcione aunque no
 * se haya corrido el instalador.
 */
function ensureLaborBenefitsTable(PDO $pdo): void
{
    static $listo = false;
    if ($listo) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS labor_benefit_calculations (
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
        $listo = true;
    } catch (Throwable $e) {
        error_log('ensureLaborBenefitsTable: ' . $e->getMessage());
    }
}

/** Lee las 12 filas de salarios que manda el formulario. */
function leerSalariosPost(array $post): array
{
    $out = [];
    for ($i = 0; $i < 12; $i++) {
        $out[$i] = [
            'salario'  => (float) str_replace(',', '', (string) ($post['salario'][$i] ?? 0)),
            'comision' => (float) str_replace(',', '', (string) ($post['comision'][$i] ?? 0)),
        ];
    }
    return $out;
}

function entradaDesdePost(array $post): array
{
    return [
        'fecha_ingreso'      => trim((string) ($post['fecha_ingreso'] ?? '')),
        'fecha_salida'       => trim((string) ($post['fecha_salida'] ?? '')),
        'periodo_idx'        => (int) ($post['periodo_idx'] ?? 0),
        'tipo_calculo_idx'   => (int) ($post['tipo_calculo_idx'] ?? 0),
        'salarios'           => leerSalariosPost($post),
        'preavisado'         => !empty($post['preavisado']),
        'incluir_cesantia'   => !empty($post['incluir_cesantia']),
        'incluir_navidad'    => !empty($post['incluir_navidad']),
        'vacaciones_tomadas' => !empty($post['vacaciones_tomadas']),
    ];
}

// ---------------------------------------------------------------------------
// Endpoints AJAX
// ---------------------------------------------------------------------------
$accion = $_GET['ajax'] ?? '';

if ($accion !== '') {
    header('Content-Type: application/json; charset=utf-8');

    if ($accion === 'calcular') {
        echo json_encode(laborBenefitsCalculate(entradaDesdePost($_POST), laborBenefitsConfig($pdo)));
        exit;
    }

    if ($accion === 'empleado') {
        $datos = laborBenefitsEmployeeDefaults($pdo, (int) ($_GET['id'] ?? 0));
        echo json_encode($datos ? ['ok' => true, 'empleado' => $datos] : ['ok' => false, 'error' => 'Colaborador no encontrado.']);
        exit;
    }

    if ($accion === 'guardar') {
        ensureLaborBenefitsTable($pdo);
        $entrada = entradaDesdePost($_POST);
        $r = laborBenefitsCalculate($entrada, laborBenefitsConfig($pdo));

        if (!$r['ok']) {
            echo json_encode($r);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO labor_benefit_calculations
                    (employee_id, employee_name, cedula, lugar_trabajo, fecha_ingreso, fecha_salida,
                     periodo_idx, tipo_calculo_idx, preavisado, incluir_cesantia, incluir_navidad,
                     vacaciones_tomadas, salarios_json, tiempo_texto, promedio_mensual, promedio_diario,
                     dias_preaviso, monto_preaviso, dias_cesantia, monto_cesantia,
                     dias_vacaciones, monto_vacaciones, monto_navidad, subtotal, total, notas, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $empleadoId = (int) ($_POST['employee_id'] ?? 0);
            $stmt->execute([
                $empleadoId > 0 ? $empleadoId : null,
                mb_substr(trim((string) ($_POST['employee_name'] ?? '')), 0, 150),
                mb_substr(trim((string) ($_POST['cedula'] ?? '')), 0, 50),
                mb_substr(trim((string) ($_POST['lugar_trabajo'] ?? '')), 0, 150),
                $r['fecha_ingreso'], $r['fecha_salida'],
                $r['periodo_idx'], $r['tipo_calculo_idx'],
                $entrada['preavisado'] ? 1 : 0,
                $entrada['incluir_cesantia'] ? 1 : 0,
                $entrada['incluir_navidad'] ? 1 : 0,
                $entrada['vacaciones_tomadas'] ? 1 : 0,
                json_encode($r['salarios']),
                $r['tiempo_texto'],
                $r['promedio_mensual'], $r['promedio_diario'],
                $r['preaviso']['dias'], $r['preaviso']['monto'],
                $r['cesantia_antes']['dias'] + $r['cesantia_despues']['dias'], $r['cesantia_total'],
                $r['vacaciones']['dias'], $r['vacaciones']['monto'],
                $r['navidad']['monto'],
                $r['subtotal'], $r['total'],
                mb_substr(trim((string) ($_POST['notas'] ?? '')), 0, 255) ?: null,
                $_SESSION['user_id'] ?? null,
            ]);
            echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
        } catch (Throwable $e) {
            error_log('labor_benefits guardar: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el cálculo.']);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Acción no reconocida.']);
    exit;
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
ensureLaborBenefitsTable($pdo);

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$cfg = laborBenefitsConfig($pdo);

// Colaboradores para el selector: primero los que tienen salida registrada.
$empleados = [];
try {
    $empleados = $pdo->query("
        SELECT e.id, e.employee_code, e.first_name, e.last_name, e.employment_status,
               e.termination_date
        FROM employees e
        ORDER BY (e.termination_date IS NULL), e.termination_date DESC, e.first_name, e.last_name
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('labor_benefits empleados: ' . $e->getMessage());
}

$historial = [];
try {
    $historial = $pdo->query("
        SELECT c.*, u.full_name AS creado_por
        FROM labor_benefit_calculations c
        LEFT JOIN users u ON u.id = c.created_by
        ORDER BY c.created_at DESC
        LIMIT 25
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('labor_benefits historial: ' . $e->getMessage());
}

$divisoresOrd = lbDivisores($cfg['benefits_divisores_ordinario']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calculadora de Prestaciones Laborales - RH</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <style>
        .lbc-hero {
            position: relative; overflow: hidden;
            border-radius: 1.25rem; padding: 2rem;
            background:
                radial-gradient(1100px 380px at 88% -30%, rgba(16, 185, 129, .24), transparent 60%),
                radial-gradient(820px 460px at -10% 130%, rgba(59, 130, 246, .22), transparent 65%),
                linear-gradient(135deg, #0b1220 0%, #10203a 60%, #0b1220 100%);
            border: 1px solid rgba(16, 185, 129, .2);
            box-shadow: 0 12px 40px rgba(2, 6, 23, .5);
        }
        .lbc-orb {
            width: 64px; height: 64px; border-radius: 18px;
            background: linear-gradient(135deg, #34d399, #3b82f6 70%);
            display: grid; place-items: center; color: #04121f; font-size: 1.5rem;
            box-shadow: 0 12px 32px rgba(52, 211, 153, .32);
        }
        .lbc-chip {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .3rem .7rem; border-radius: 9999px;
            font-size: .74rem; font-weight: 600;
            background: rgba(15, 23, 42, .55);
            border: 1px solid rgba(148, 163, 184, .28);
            color: #94a3b8;
        }
        .lbc-chip.ok { background: rgba(16,185,129,.12); color: #6ee7b7; border-color: rgba(16,185,129,.4); }

        .lbc-field label { display:block; font-size:.78rem; font-weight:600; color:#94a3b8; margin-bottom:.3rem; }
        .lbc-field input, .lbc-field select {
            width: 100%; padding: .55rem .7rem; border-radius: .55rem;
            background: rgba(15, 23, 42, .7); border: 1px solid rgba(148, 163, 184, .22);
            color: #e2e8f0; font-size: .9rem;
        }
        .lbc-field input:focus, .lbc-field select:focus { outline: none; border-color: #10b981; }

        .lbc-radio { display:inline-flex; align-items:center; gap:.4rem; padding:.4rem .75rem;
            border-radius:.5rem; border:1px solid rgba(148,163,184,.22); cursor:pointer;
            font-size:.84rem; color:#94a3b8; background:rgba(15,23,42,.5); }
        .lbc-radio input { accent-color:#10b981; }
        .lbc-radio.is-active { border-color:#10b981; color:#a7f3d0; background:rgba(16,185,129,.12); }

        .lbc-switch { display:flex; align-items:center; justify-content:space-between; gap:1rem;
            padding:.7rem .85rem; border-radius:.6rem; background:rgba(15,23,42,.5);
            border:1px solid rgba(148,163,184,.16); }
        .lbc-switch span { font-size:.85rem; color:#cbd5e1; }
        .lbc-switch input { width:1.15rem; height:1.15rem; accent-color:#10b981; cursor:pointer; }

        .lbc-money { text-align:right; font-variant-numeric: tabular-nums; }
        /* header.php marca cada .overflow-x-auto como .responsive-scroll, y el
           tema le pone min-width:720px a las tablas de dentro (pensado para las
           tablas de datos anchas). Esta rejilla es estrecha: sin este override
           obligaba a desplazarse en horizontal aunque sobrara sitio. */
        .responsive-scroll .lbc-grid { min-width: 0; }
        .lbc-grid input { width:100%; min-width:0; padding:.35rem .5rem; border-radius:.4rem; text-align:right;
            background:rgba(15,23,42,.6); border:1px solid rgba(148,163,184,.18); color:#e2e8f0;
            font-size:.85rem; font-variant-numeric: tabular-nums; }
        .lbc-grid input:disabled { opacity:.35; }
        .lbc-grid td { padding:.28rem .35rem; }
        .lbc-grid th { font-size:.72rem; text-transform:uppercase; letter-spacing:.04em;
            color:#64748b; padding:.4rem .35rem; text-align:right; }
        .lbc-grid th:first-child, .lbc-grid td:first-child { text-align:left; }

        .lbc-result-row { display:flex; flex-wrap:wrap; align-items:baseline;
            justify-content:space-between; gap:0 1rem;
            padding:.7rem 0; border-bottom:1px dashed rgba(148,163,184,.16); }
        /* flex-basis 0 (no auto): al envolver, el reparto en líneas se decide con
           el tamaño BASE de cada elemento, no con el ya encogido. Con "auto", una
           base legal larga daba una base enorme y tiraba el importe a la línea de
           abajo aunque luego hubiera sitio de sobra. */
        .lbc-result-row .concepto { flex:1 1 0; min-width:0; font-size:.92rem; color:#e2e8f0; font-weight:500; }
        .lbc-result-row .base { font-size:.74rem; color:#64748b; display:block; margin-top:.15rem; }
        .lbc-result-row .monto { flex:0 0 auto; font-size:1.05rem; font-weight:700; color:#e2e8f0;
            font-variant-numeric: tabular-nums; white-space:nowrap; }
        .lbc-result-row.is-zero .concepto, .lbc-result-row.is-zero .monto { opacity:.45; }

        .lbc-total { border-radius:.85rem; padding:1.1rem 1.25rem;
            background:linear-gradient(135deg, rgba(16,185,129,.16), rgba(59,130,246,.14));
            border:1px solid rgba(16,185,129,.35); }
        .lbc-total .valor { font-size:2rem; font-weight:800; color:#6ee7b7;
            font-variant-numeric: tabular-nums; letter-spacing:-.02em; }

        .lbc-note { font-size:.78rem; color:#94a3b8; line-height:1.5; }
        .lbc-error { background:rgba(244,63,94,.12); border:1px solid rgba(244,63,94,.4);
            color:#fda4af; border-radius:.6rem; padding:.7rem .9rem; font-size:.86rem; }
        .lbc-aviso { background:rgba(245,158,11,.12); border:1px solid rgba(245,158,11,.45);
            color:#fcd34d; border-radius:.6rem; padding:.7rem .9rem; font-size:.84rem; line-height:1.5; }

        /* Estado vacío: "falta un dato" no es un error, y en rojo lo parecía. */
        .lbc-vacio { text-align:center; padding:2.2rem 1rem; color:#64748b; }
        .lbc-vacio i { font-size:2rem; opacity:.35; display:block; margin-bottom:.7rem; }
        .lbc-vacio p { font-size:.88rem; line-height:1.5; }

        /* Recalculando: se atenúa en vez de parpadear a vacío. */
        .lbc-cargando { opacity:.45; transition:opacity .15s ease; }

        /* Barra de proporción de cada concepto sobre el total. Ocupa su propia
           línea a lo ancho de la fila: si fuera detrás del texto, cada barra
           tendría una pista de distinto largo y no se podrían comparar. */
        .lbc-barra { flex:0 0 100%; height:3px; border-radius:2px;
            background:rgba(148,163,184,.14); margin-top:.55rem; overflow:hidden; }
        .lbc-barra span { display:block; height:100%; border-radius:2px;
            background:linear-gradient(90deg,#34d399,#3b82f6); transition:width .3s ease; }
        .lbc-result-row.is-zero .lbc-barra { display:none; }

        .lbc-num { font-variant-numeric: tabular-nums; }
        .lbc-mes { font-size:.72rem; }

        /* ---- Buscador de colaborador ---- */
        .lbc-combo { position:relative; }
        .lbc-combo-lupa {
            position:absolute; left:.7rem; top:50%; transform:translateY(-50%);
            color:#64748b; font-size:.82rem; pointer-events:none;
        }
        .lbc-combo input {
            width:100%; padding:.55rem .7rem .55rem 2.1rem; border-radius:.55rem;
            background:rgba(15,23,42,.7); border:1px solid rgba(148,163,184,.22);
            color:#e2e8f0; font-size:.9rem;
        }
        .lbc-combo input:focus { outline:none; border-color:#10b981; }
        .lbc-combo input::placeholder { color:#64748b; }
        #lbc-combo-limpiar {
            position:absolute; right:.5rem; top:50%; transform:translateY(-50%);
            width:1.5rem; height:1.5rem; border-radius:9999px;
            color:#94a3b8; background:transparent; border:none; cursor:pointer; font-size:.8rem;
        }
        #lbc-combo-limpiar:hover { color:#e2e8f0; background:rgba(148,163,184,.14); }

        #lbc-combo-lista {
            position:absolute; z-index:40; left:0; right:0; top:calc(100% + .3rem);
            max-height:17rem; overflow-y:auto;
            background:#0f172a; border:1px solid rgba(148,163,184,.28);
            border-radius:.6rem; box-shadow:0 16px 40px rgba(2,6,23,.6);
            padding:.25rem;
        }
        #lbc-combo-lista li {
            padding:.45rem .6rem; border-radius:.4rem; cursor:pointer;
            font-size:.86rem; color:#cbd5e1; line-height:1.35;
        }
        #lbc-combo-lista li .cod { color:#64748b; font-size:.74rem; }
        #lbc-combo-lista li mark { background:transparent; color:#6ee7b7; font-weight:600; }
        #lbc-combo-lista li.is-activa { background:rgba(16,185,129,.16); color:#e2e8f0; }
        #lbc-combo-lista li.sin-resultados { color:#64748b; cursor:default; text-align:center; padding:.9rem; }
        #lbc-combo-lista li.sin-resultados:hover { background:transparent; }

        /* El <select> nativo solo se oculta si el buscador llegó a montarse. */
        .lbc-combo-activo #lbc-empleado { display:none; }

        /* El panel de resultado solo se fija cuando hay dos columnas. Apilado en
           móvil, "sticky" no aporta y puede tapar el formulario. */
        @media (min-width: 1280px) {
            .lbc-panel { position: sticky; top: 88px; max-height: calc(100vh - 104px); overflow-y: auto; }
        }

        @media print {
            body { background:#fff !important; }
            .lbc-no-print, header, nav, footer { display:none !important; }
            #lbc-comprobante { display:block !important; color:#000; }
            #lbc-comprobante * { color:#000 !important; }
        }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">

        <!-- Encabezado -->
        <div class="lbc-hero mb-8 lbc-no-print">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-4">
                    <div class="lbc-orb"><i class="fas fa-scale-balanced"></i></div>
                    <div>
                        <h1 class="text-2xl md:text-3xl font-bold text-white mb-1">Calculadora de Prestaciones Laborales</h1>
                        <p class="text-slate-300 text-sm max-w-2xl">
                            Preaviso, auxilio de cesantía, vacaciones y salario de Navidad (regalía pascual)
                            sobre el salario ordinario devengado.
                        </p>
                        <div class="flex flex-wrap gap-2 mt-3">
                            <span class="lbc-chip ok"><i class="fas fa-check-circle"></i> Idéntica a calculo.mt.gob.do</span>
                            <span class="lbc-chip"><i class="fas fa-book"></i> Código de Trabajo, Ley 16-92</span>
                            <span class="lbc-chip"><i class="fas fa-divide"></i> Salario diario = mensual ÷ <?= rtrim(rtrim(number_format($divisoresOrd[0], 2), '0'), '.') ?></span>
                        </div>
                    </div>
                </div>
                <a href="index.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> Panel RH</a>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-5 gap-6">

            <!-- ============ FORMULARIO ============ -->
            <div class="xl:col-span-3 space-y-6 lbc-no-print">

                <div class="glass-card">
                    <h2 class="text-lg font-semibold mb-4">
                        <i class="fas fa-user-tie text-emerald-400 mr-2"></i> Datos del colaborador
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div class="lbc-field md:col-span-2">
                            <label for="lbc-buscar">Traer datos de un colaborador (opcional)</label>

                            <!-- El <select> sigue siendo la fuente de verdad (lo leen el
                                 guardado, el PDF y la carga del colaborador). El buscador
                                 de abajo solo lo maneja; si el JS falla, el desplegable
                                 normal queda visible y utilizable. -->
                            <select id="lbc-empleado">
                                <option value="">— Escribir los datos a mano —</option>
                                <?php foreach ($empleados as $e): ?>
                                    <option value="<?= (int) $e['id'] ?>">
                                        <?= htmlspecialchars(trim($e['first_name'] . ' ' . $e['last_name'])) ?>
                                        <?= $e['employee_code'] ? '(' . htmlspecialchars($e['employee_code']) . ')' : '' ?>
                                        <?= $e['termination_date'] ? ' — salió ' . date('d/m/Y', strtotime($e['termination_date'])) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div class="lbc-combo" id="lbc-combo" hidden>
                                <i class="fas fa-magnifying-glass lbc-combo-lupa"></i>
                                <input type="text" id="lbc-buscar" autocomplete="off" spellcheck="false"
                                       role="combobox" aria-expanded="false" aria-autocomplete="list"
                                       aria-controls="lbc-combo-lista"
                                       placeholder="Busca por nombre o código, o escribe los datos a mano">
                                <button type="button" id="lbc-combo-limpiar" title="Limpiar" hidden>
                                    <i class="fas fa-xmark"></i>
                                </button>
                                <ul id="lbc-combo-lista" role="listbox" hidden></ul>
                            </div>

                            <p id="lbc-empleado-nota" class="lbc-note mt-1"></p>
                        </div>

                        <div class="lbc-field">
                            <label for="lbc-nombre">Nombre</label>
                            <input type="text" id="lbc-nombre" placeholder="Nombre del colaborador">
                        </div>
                        <div class="lbc-field">
                            <label for="lbc-cedula">Cédula</label>
                            <input type="text" id="lbc-cedula" placeholder="000-0000000-0">
                        </div>
                        <div class="lbc-field md:col-span-2">
                            <label for="lbc-lugar">Lugar de trabajo / empresa</label>
                            <input type="text" id="lbc-lugar" placeholder="Nombre de la empresa">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="lbc-field">
                            <label for="lbc-ingreso">Fecha de ingreso *</label>
                            <input type="date" id="lbc-ingreso">
                        </div>
                        <div class="lbc-field">
                            <label for="lbc-salida">Fecha de salida (último día laborado) *</label>
                            <input type="date" id="lbc-salida">
                        </div>
                    </div>
                </div>

                <div class="glass-card">
                    <h2 class="text-lg font-semibold mb-4">
                        <i class="fas fa-sliders text-blue-400 mr-2"></i> Condiciones del cálculo
                    </h2>

                    <div class="lbc-field mb-2">
                        <label for="lbc-motivo">Motivo de la terminación</label>
                        <select id="lbc-motivo">
                            <option value="desahucio">Desahucio ejercido por el empleador</option>
                            <option value="despido_injustificado">Despido injustificado</option>
                            <option value="dimision_justificada">Dimisión justificada</option>
                            <option value="renuncia">Renuncia (desahucio del trabajador)</option>
                            <option value="despido_justificado">Despido justificado</option>
                            <option value="dimision_injustificada">Dimisión injustificada</option>
                            <option value="fin_contrato">Fin de contrato o mutuo acuerdo</option>
                            <option value="personalizado">Personalizado</option>
                        </select>
                    </div>
                    <p id="lbc-motivo-nota" class="lbc-note mb-5"></p>

                    <div class="mb-5">
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Frecuencia de pago</p>
                        <div class="flex flex-wrap gap-2" id="lbc-periodos">
                            <?php foreach (laborBenefitsPeriodos() as $i => $label): ?>
                                <label class="lbc-radio<?= $i === 0 ? ' is-active' : '' ?>">
                                    <input type="radio" name="periodo_idx" value="<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mb-5">
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Tipo de cálculo</p>
                        <div class="flex flex-wrap gap-2" id="lbc-tipos">
                            <?php foreach (laborBenefitsTiposCalculo() as $i => $label): ?>
                                <label class="lbc-radio<?= $i === 0 ? ' is-active' : '' ?>">
                                    <input type="radio" name="tipo_calculo_idx" value="<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="lbc-note mt-2">
                            <strong>Ordinario</strong> divide entre <?= rtrim(rtrim(number_format($divisoresOrd[0], 2), '0'), '.') ?>;
                            <strong>intermitente</strong> y <strong>doméstico</strong>, entre
                            <?= rtrim(rtrim(number_format(lbDivisores($cfg['benefits_divisores_intermitente'])[0], 2), '0'), '.') ?>.
                            El trabajo doméstico no genera vacaciones proporcionales.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label class="lbc-switch">
                            <span>¿Ha sido pre-avisado?</span>
                            <input type="checkbox" id="lbc-preavisado">
                        </label>
                        <label class="lbc-switch">
                            <span>¿Incluir cesantía?</span>
                            <input type="checkbox" id="lbc-cesantia" checked>
                        </label>
                        <label class="lbc-switch">
                            <span>¿Incluir salario de Navidad?</span>
                            <input type="checkbox" id="lbc-navidad" checked>
                        </label>
                        <label class="lbc-switch">
                            <span>¿Tomó las vacaciones del último año?</span>
                            <input type="checkbox" id="lbc-vac-tomadas" checked>
                        </label>
                    </div>
                    <p class="lbc-note mt-3">
                        El motivo de arriba ajusta el preaviso y la cesantía. Si los cambias a mano,
                        el motivo pasa a <em>Personalizado</em>. Si el colaborador ya disfrutó las
                        vacaciones del último año solo se paga la fracción corrida desde el
                        aniversario (párrafo del art. 177).
                    </p>
                </div>

                <div class="glass-card">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <h2 class="text-lg font-semibold">
                            <i class="fas fa-money-bill-wave text-amber-400 mr-2"></i> Salario ordinario devengado
                        </h2>
                        <span id="lbc-meses-activos" class="lbc-chip"></span>
                    </div>

                    <!-- flex y no grid: el proyecto usa Tailwind 2.2, que no
                         entiende los tamaños arbitrarios tipo grid-cols-[1fr_auto]
                         y dejaba el botón ocupando todo el ancho. -->
                    <div class="flex flex-wrap gap-3 items-end mb-5">
                        <div class="lbc-field" style="flex:1 1 240px">
                            <label for="lbc-salario-fijo">Salario del período (si fue el mismo todos los meses)</label>
                            <input type="number" id="lbc-salario-fijo" step="0.01" min="0" placeholder="Ej. 45000.00">
                        </div>
                        <button type="button" id="lbc-aplicar-fijo" class="btn-secondary" style="flex:0 0 auto">
                            <i class="fas fa-arrow-down"></i> Aplicar a todos los meses
                        </button>
                    </div>

                    <p class="lbc-note mb-3">
                        El período <strong>12</strong> es el último antes de la salida. El promedio sale de estos
                        montos; las vacaciones se pagan al salario del último período.
                        Un mes sin salario base no suma, aunque tenga comisión.
                    </p>

                    <div class="overflow-x-auto">
                        <table class="w-full lbc-grid">
                            <thead>
                                <tr>
                                    <th>Período</th>
                                    <th>Salario</th>
                                    <th>Comisión</th>
                                    <th>Total</th>
                                    <th class="text-center" style="width:2.5rem"></th>
                                </tr>
                            </thead>
                            <tbody id="lbc-filas"></tbody>
                        </table>
                    </div>

                    <!-- Devengado real, para quien cobra por hora -->
                    <div id="lbc-nomina" class="mt-6 pt-5" style="display:none; border-top:1px solid rgba(148,163,184,.16)">
                        <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
                            <h3 class="text-sm font-semibold text-slate-200">
                                <i class="fas fa-file-invoice-dollar text-amber-400 mr-2"></i>
                                Devengado real según la nómina
                            </h3>
                            <button type="button" id="lbc-usar-nomina" class="btn-secondary">
                                <i class="fas fa-wand-magic-sparkles"></i> Usar estos montos
                            </button>
                        </div>
                        <p class="lbc-note mb-3">
                            Es lo que devengó de verdad, mes a mes. Cuando hay nómina cargada sale de ahí;
                            si no la cubre, el mes se calcula desde el <strong>ponche</strong> (tarifa × horas
                            marcadas, con el mismo corte semanal y recargo que aplica la nómina). Los meses
                            <strong>parciales</strong> solo están cubiertos en parte: revísalos antes de
                            liquidar porque quedan por debajo de lo real.
                        </p>
                        <div class="overflow-x-auto">
                            <table class="w-full lbc-grid">
                                <thead>
                                    <tr>
                                        <th>Mes</th>
                                        <th>Devengado</th>
                                        <th style="text-align:left">Cobertura</th>
                                    </tr>
                                </thead>
                                <tbody id="lbc-nomina-filas"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ RESULTADOS ============ -->
            <div class="xl:col-span-2">
                <div class="glass-card lbc-panel">
                    <div id="lbc-comprobante">
                        <div class="flex items-center justify-between gap-3 mb-4">
                            <h2 class="text-lg font-semibold">
                                <i class="fas fa-receipt text-emerald-400 mr-2"></i> Resultado
                            </h2>
                            <span id="lbc-estado" class="lbc-chip"></span>
                        </div>

                        <div id="lbc-error" class="lbc-error mb-4" style="display:none"></div>

                        <div id="lbc-vacio" class="lbc-vacio">
                            <i class="fas fa-calendar-day"></i>
                            <p>Indica la <strong>fecha de ingreso</strong> y la <strong>fecha de salida</strong><br>
                               para ver la liquidación.</p>
                        </div>

                        <div id="lbc-aviso" class="lbc-aviso mb-4" style="display:none"></div>

                        <div id="lbc-resumen" style="display:none">
                            <div class="grid grid-cols-2 gap-3 mb-4">
                                <div>
                                    <p class="text-xs text-slate-400 mb-1">Tiempo laborado</p>
                                    <p id="lbc-tiempo" class="text-sm font-semibold text-slate-100"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-slate-400 mb-1">Salario promedio mensual</p>
                                    <p id="lbc-prom-mensual" class="text-sm font-semibold text-slate-100 lbc-money"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-slate-400 mb-1">Salario promedio diario</p>
                                    <p id="lbc-prom-diario" class="text-sm font-semibold text-slate-100 lbc-money"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-slate-400 mb-1">Salarios acumulados</p>
                                    <p id="lbc-acumulado" class="text-sm font-semibold text-slate-100 lbc-money"></p>
                                </div>
                            </div>

                            <div id="lbc-conceptos"></div>

                            <div class="lbc-result-row" style="border-bottom:none">
                                <span class="concepto">Subtotal prestaciones</span>
                                <span id="lbc-subtotal" class="monto"></span>
                            </div>

                            <div class="lbc-total mt-3">
                                <p class="text-xs uppercase tracking-wide text-emerald-200 mb-1">Total a recibir</p>
                                <p id="lbc-total" class="valor"></p>
                            </div>

                            <p class="lbc-note mt-4">
                                Cálculo estimado conforme al Código de Trabajo (Ley 16-92). No incluye
                                la participación en los beneficios de la empresa (art. 223) ni salarios
                                caídos del art. 95, que dependen del tipo de terminación y de la utilidad del año.
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2 mt-5 lbc-no-print">
                        <button type="button" id="lbc-pdf" class="btn-primary flex-1" disabled>
                            <i class="fas fa-file-pdf"></i> Descargar PDF
                        </button>
                        <button type="button" id="lbc-guardar" class="btn-secondary" disabled>
                            <i class="fas fa-floppy-disk"></i> Guardar
                        </button>
                    </div>
                    <p class="lbc-note mt-2 lbc-no-print">
                        El PDF trae el desglose completo con los datos de la empresa, el importe en
                        letras y las líneas de firma. <strong>Guardar</strong> lo deja en el historial
                        con un número de referencia.
                    </p>
                    <p id="lbc-guardado" class="lbc-note mt-2 lbc-no-print"></p>
                </div>
            </div>
        </div>

        <!-- ============ HISTORIAL ============ -->
        <div class="glass-card mt-8 lbc-no-print">
            <h2 class="text-lg font-semibold mb-4">
                <i class="fas fa-clock-rotate-left text-purple-400 mr-2"></i> Últimos cálculos guardados
            </h2>

            <?php if (empty($historial)): ?>
                <p class="text-slate-400 text-sm py-4">Todavía no se ha guardado ningún cálculo.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-700 text-slate-400">
                                <th class="text-left py-2 px-3">Colaborador</th>
                                <th class="text-left py-2 px-3">Período laborado</th>
                                <th class="text-left py-2 px-3">Tiempo</th>
                                <th class="text-right py-2 px-3">Preaviso</th>
                                <th class="text-right py-2 px-3">Cesantía</th>
                                <th class="text-right py-2 px-3">Vacaciones</th>
                                <th class="text-right py-2 px-3">Navidad</th>
                                <th class="text-right py-2 px-3">Total</th>
                                <th class="text-left py-2 px-3">Registrado</th>
                                <th class="text-center py-2 px-3">PDF</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historial as $h): ?>
                                <tr class="border-b border-slate-800">
                                    <td class="py-2 px-3">
                                        <span class="font-medium"><?= htmlspecialchars($h['employee_name'] ?: 'Sin nombre') ?></span>
                                        <?php if ($h['cedula']): ?>
                                            <span class="block text-xs text-slate-500"><?= htmlspecialchars($h['cedula']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 px-3 text-slate-300">
                                        <?= date('d/m/Y', strtotime($h['fecha_ingreso'])) ?> —
                                        <?= date('d/m/Y', strtotime($h['fecha_salida'])) ?>
                                    </td>
                                    <td class="py-2 px-3 text-slate-400"><?= htmlspecialchars($h['tiempo_texto']) ?></td>
                                    <td class="py-2 px-3 lbc-money"><?= number_format((float) $h['monto_preaviso'], 2) ?></td>
                                    <td class="py-2 px-3 lbc-money"><?= number_format((float) $h['monto_cesantia'], 2) ?></td>
                                    <td class="py-2 px-3 lbc-money"><?= number_format((float) $h['monto_vacaciones'], 2) ?></td>
                                    <td class="py-2 px-3 lbc-money"><?= number_format((float) $h['monto_navidad'], 2) ?></td>
                                    <td class="py-2 px-3 lbc-money font-bold text-emerald-400">
                                        <?= number_format((float) $h['total'], 2) ?>
                                    </td>
                                    <td class="py-2 px-3 text-xs text-slate-500">
                                        <?= date('d/m/Y H:i', strtotime($h['created_at'])) ?><br>
                                        <?= htmlspecialchars($h['creado_por'] ?: '') ?>
                                        <span class="block text-slate-600">
                                            <?= sprintf('LIQ-%s-%05d', date('Y', strtotime($h['created_at'])), (int) $h['id']) ?>
                                        </span>
                                    </td>
                                    <td class="py-2 px-3 text-center">
                                        <a href="labor_benefits_pdf.php?id=<?= (int) $h['id'] ?>"
                                           target="_blank" rel="noopener"
                                           title="Abrir la liquidación en PDF"
                                           class="inline-block px-2 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../footer.php'; ?>

    <script>
    (function () {
        'use strict';

        var FILAS = 12;
        var $ = function (id) { return document.getElementById(id); };
        var ultimoResultado = null;

        function moneda(n) {
            return 'RD$' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function numero(input) {
            var v = parseFloat(String(input.value).replace(/,/g, ''));
            return isNaN(v) ? 0 : v;
        }

        // ---- Tabla de salarios ------------------------------------------
        function construirFilas() {
            var tbody = $('lbc-filas');
            var html = '';
            for (var i = 0; i < FILAS; i++) {
                html += '<tr data-fila="' + i + '">'
                    + '<td class="text-slate-400 text-sm">'
                    + '<span class="lbc-num">' + (i + 1) + '</span>'
                    + '<span class="lbc-mes text-slate-500"></span>'
                    + '</td>'
                    + '<td><input type="number" step="0.01" min="0" class="lbc-sal" data-i="' + i + '" placeholder="0.00"></td>'
                    + '<td><input type="number" step="0.01" min="0" class="lbc-com" data-i="' + i + '" placeholder="0.00"></td>'
                    + '<td><input type="text" class="lbc-tot" data-i="' + i + '" readonly tabindex="-1"></td>'
                    + '<td class="text-center">'
                    + '<button type="button" class="lbc-replicar text-slate-500 hover:text-emerald-400" data-i="' + i + '"'
                    + ' title="Copiar esta fila hacia abajo"><i class="fas fa-angles-down"></i></button>'
                    + '</td></tr>';
            }
            tbody.innerHTML = html;

            tbody.addEventListener('input', function (ev) {
                if (ev.target.classList.contains('lbc-sal') || ev.target.classList.contains('lbc-com')) {
                    actualizarTotalFila(parseInt(ev.target.dataset.i, 10));
                    programarCalculo();
                }
            });
            tbody.addEventListener('click', function (ev) {
                var btn = ev.target.closest('.lbc-replicar');
                if (!btn) { return; }
                var desde = parseInt(btn.dataset.i, 10);
                var sal = document.querySelector('.lbc-sal[data-i="' + desde + '"]').value;
                var com = document.querySelector('.lbc-com[data-i="' + desde + '"]').value;
                for (var k = desde + 1; k < FILAS; k++) {
                    document.querySelector('.lbc-sal[data-i="' + k + '"]').value = sal;
                    document.querySelector('.lbc-com[data-i="' + k + '"]').value = com;
                    actualizarTotalFila(k);
                }
                programarCalculo();
            });
        }

        function actualizarTotalFila(i) {
            var sal = numero(document.querySelector('.lbc-sal[data-i="' + i + '"]'));
            var com = numero(document.querySelector('.lbc-com[data-i="' + i + '"]'));
            // Misma regla del motor: sin salario base el mes no cuenta.
            var tot = sal === 0 ? 0 : sal + com;
            var campo = document.querySelector('.lbc-tot[data-i="' + i + '"]');
            campo.value = tot === 0 ? '' : tot.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function aplicarMesesActivos(activos, etiquetas) {
            for (var i = 0; i < FILAS; i++) {
                var fuera = i >= activos;
                document.querySelector('.lbc-sal[data-i="' + i + '"]').disabled = fuera;
                document.querySelector('.lbc-com[data-i="' + i + '"]').disabled = fuera;
                document.querySelector('.lbc-tot[data-i="' + i + '"]').disabled = fuera;
                document.querySelector('tr[data-fila="' + i + '"]').style.opacity = fuera ? '.4' : '1';

                // Con pago mensual cada casilla se nombra por su mes real: es
                // mucho menos ambiguo que "período 7" cuando hay que teclear
                // doce importes distintos.
                var celdaMes = document.querySelector('tr[data-fila="' + i + '"] .lbc-mes');
                if (celdaMes) {
                    celdaMes.textContent = (etiquetas && etiquetas[i]) ? ' · ' + etiquetas[i] : '';
                }
            }
            $('lbc-meses-activos').textContent = activos === 1
                ? '1 período a registrar'
                : activos + ' períodos a registrar';
        }

        // ---- Envío ------------------------------------------------------
        function construirFormData() {
            var fd = new FormData();
            fd.append('fecha_ingreso', $('lbc-ingreso').value);
            fd.append('fecha_salida', $('lbc-salida').value);
            fd.append('periodo_idx', document.querySelector('input[name="periodo_idx"]:checked').value);
            fd.append('tipo_calculo_idx', document.querySelector('input[name="tipo_calculo_idx"]:checked').value);
            if ($('lbc-preavisado').checked)  { fd.append('preavisado', '1'); }
            if ($('lbc-cesantia').checked)    { fd.append('incluir_cesantia', '1'); }
            if ($('lbc-navidad').checked)     { fd.append('incluir_navidad', '1'); }
            if ($('lbc-vac-tomadas').checked) { fd.append('vacaciones_tomadas', '1'); }
            for (var i = 0; i < FILAS; i++) {
                fd.append('salario[' + i + ']', document.querySelector('.lbc-sal[data-i="' + i + '"]').value || '0');
                fd.append('comision[' + i + ']', document.querySelector('.lbc-com[data-i="' + i + '"]').value || '0');
            }
            return fd;
        }

        var temporizador = null;
        function programarCalculo() {
            clearTimeout(temporizador);
            temporizador = setTimeout(calcular, 250);
        }

        function calcular() {
            if (!$('lbc-ingreso').value || !$('lbc-salida').value) {
                mostrarVacio();
                return;
            }
            $('lbc-estado').textContent = 'Calculando…';
            $('lbc-resumen').classList.add('lbc-cargando');

            fetch('labor_benefits_calculator.php?ajax=calcular', { method: 'POST', body: construirFormData() })
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    if (!r || !r.ok) {
                        mostrarError((r && r.error) || 'No se pudo calcular.');
                        return;
                    }
                    ultimoResultado = r;
                    aplicarMesesActivos(r.meses_activos, r.periodos_etiquetas);
                    pintar(r);
                })
                .catch(function () { mostrarError('No se pudo contactar al servidor.'); });
        }

        /** Deja el panel en blanco sin botones activos. */
        function limpiarPanel() {
            ultimoResultado = null;
            $('lbc-aviso').style.display = 'none';
            $('lbc-resumen').style.display = 'none';
            $('lbc-resumen').classList.remove('lbc-cargando');
            $('lbc-estado').textContent = '';
            $('lbc-guardar').disabled = true;
            $('lbc-pdf').disabled = true;
        }

        // Faltan datos: no es un fallo, así que no se pinta en rojo.
        function mostrarVacio() {
            limpiarPanel();
            $('lbc-error').style.display = 'none';
            $('lbc-vacio').style.display = 'block';
        }

        function mostrarError(msg) {
            limpiarPanel();
            $('lbc-vacio').style.display = 'none';

            // "Falta el salario" no es un fallo del sistema cuando ya sabemos
            // exactamente qué dato falta en la ficha: en ese caso se explica en
            // ámbar, con qué hay que corregir y dónde.
            if (diagnosticoEmpleado) {
                $('lbc-error').style.display = 'none';
                $('lbc-aviso').innerHTML = '<strong>No se pudo llenar solo.</strong> ' + diagnosticoEmpleado;
                $('lbc-aviso').style.display = 'block';
                return;
            }

            $('lbc-error').textContent = msg;
            $('lbc-error').style.display = 'block';
        }

        function filaConcepto(concepto, base, monto, total) {
            var cero = Number(monto) === 0;
            // Barra con el peso del concepto sobre el total: de un vistazo se ve
            // si la cesantía se comió la liquidación o si algo salió raro.
            var pct = (total > 0) ? Math.max(0, Math.min(100, (Number(monto) / total) * 100)) : 0;
            return '<div class="lbc-result-row' + (cero ? ' is-zero' : '') + '">'
                + '<span class="concepto">' + concepto + '<span class="base">' + base + '</span></span>'
                + '<span class="monto">' + moneda(monto) + '</span>'
                + '<span class="lbc-barra" title="' + pct.toFixed(1) + '% del total">'
                + '<span style="width:' + pct.toFixed(1) + '%"></span></span>'
                + '</div>';
        }

        // El promedio se divide entre los períodos que duró la relación, NO entre
        // los que tienen monto. Un mes en blanco no se "salta": arrastra el
        // promedio hacia abajo y liquida por debajo de lo que toca. Como el
        // formulario se puede prellenar con una nómina incompleta, esto hay que
        // cantarlo o pasa desapercibido.
        function avisoMesesVacios(r) {
            var vacios = [];
            for (var i = 0; i < r.meses_activos; i++) {
                if (!r.salarios[i] || Number(r.salarios[i].total) === 0) { vacios.push(i + 1); }
            }
            if (!vacios.length) {
                $('lbc-aviso').style.display = 'none';
                return;
            }
            $('lbc-aviso').innerHTML = '<strong>Faltan ' + vacios.length + ' de '
                + r.meses_activos + ' períodos por llenar</strong> (el ' + vacios.join(', ')
                + '). El promedio se divide entre los ' + r.meses_activos
                + ' que duró la relación aunque estén en blanco, así que este total está '
                + '<strong>por debajo</strong> del real. Complétalos antes de liquidar.';
            $('lbc-aviso').style.display = 'block';
        }

        function pintar(r) {
            $('lbc-error').style.display = 'none';
            $('lbc-vacio').style.display = 'none';
            $('lbc-resumen').style.display = 'block';
            $('lbc-resumen').classList.remove('lbc-cargando');
            avisoMesesVacios(r);
            $('lbc-estado').textContent = r.periodo + ' · ' + r.tipo_calculo;
            $('lbc-guardar').disabled = false;
            $('lbc-pdf').disabled = false;
            $('lbc-guardado').textContent = '';

            $('lbc-tiempo').textContent = r.tiempo_texto;
            $('lbc-prom-mensual').textContent = moneda(r.promedio_mensual);
            $('lbc-prom-diario').textContent = moneda(r.promedio_diario);
            $('lbc-acumulado').textContent = moneda(r.salario_acumulado);

            var tot = Number(r.total) || 0;
            var html = '';

            html += filaConcepto(
                'Preaviso',
                r.preaviso.omitido
                    ? 'Ya fue pre-avisado — no se indemniza (art. 79)'
                    : r.preaviso.dias + ' día(s) × ' + moneda(r.promedio_diario) + ' (art. 76)',
                r.preaviso.monto, tot
            );

            if (r.cesantia_antes.dias > 0) {
                html += filaConcepto(
                    'Cesantía anterior al Código de 1992',
                    r.cesantia_antes.dias + ' día(s) × ' + moneda(r.promedio_diario),
                    r.cesantia_antes.monto, tot
                );
            }

            html += filaConcepto(
                r.cesantia_antes.dias > 0 ? 'Cesantía bajo el Código de 1992' : 'Auxilio de cesantía',
                r.cesantia_despues.dias + ' día(s) × ' + moneda(r.promedio_diario) + ' (arts. 80 y 81)',
                r.cesantia_despues.monto, tot
            );

            html += filaConcepto(
                'Vacaciones',
                r.vacaciones.dias + ' día(s) × ' + moneda(r.ultimo_salario / r.factor_actual)
                    + (r.vacaciones.tomadas ? ' — solo la fracción del último año' : '') + ' (art. 177)',
                r.vacaciones.monto, tot
            );

            html += filaConcepto(
                'Salario de Navidad (regalía pascual)',
                r.navidad.texto + ' del año calendario ÷ 12 (art. 219)',
                r.navidad.monto, tot
            );

            $('lbc-conceptos').innerHTML = html;
            $('lbc-subtotal').textContent = moneda(r.subtotal);
            $('lbc-total').textContent = moneda(r.total);
        }

        // ---- PDF ---------------------------------------------------------
        // Se manda por POST porque van los doce períodos de salario: en una URL
        // no caben con holgura y además quedarían en el historial del navegador.
        $('lbc-pdf').addEventListener('click', function () {
            if (!ultimoResultado) { return; }

            var form = document.createElement('form');
            form.method = 'POST';
            form.action = 'labor_benefits_pdf.php';
            form.target = '_blank';
            form.style.display = 'none';

            var fd = construirFormData();
            fd.append('employee_id', $('lbc-empleado').value || '0');
            fd.append('employee_name', $('lbc-nombre').value);
            fd.append('cedula', $('lbc-cedula').value);
            fd.append('lugar_trabajo', $('lbc-lugar').value);

            fd.forEach(function (valor, clave) {
                var campo = document.createElement('input');
                campo.type = 'hidden';
                campo.name = clave;
                campo.value = valor;
                form.appendChild(campo);
            });

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        });

        // ---- Guardar ----------------------------------------------------
        $('lbc-guardar').addEventListener('click', function () {
            if (!ultimoResultado) { return; }
            var fd = construirFormData();
            fd.append('employee_id', $('lbc-empleado').value || '0');
            fd.append('employee_name', $('lbc-nombre').value);
            fd.append('cedula', $('lbc-cedula').value);
            fd.append('lugar_trabajo', $('lbc-lugar').value);

            $('lbc-guardar').disabled = true;
            $('lbc-guardado').textContent = 'Guardando…';

            fetch('labor_benefits_calculator.php?ajax=guardar', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    if (r && r.ok) {
                        $('lbc-guardado').textContent = 'Guardado. Recargando el historial…';
                        setTimeout(function () { window.location.reload(); }, 700);
                    } else {
                        $('lbc-guardado').textContent = (r && r.error) || 'No se pudo guardar.';
                        $('lbc-guardar').disabled = false;
                    }
                })
                .catch(function () {
                    $('lbc-guardado').textContent = 'No se pudo contactar al servidor.';
                    $('lbc-guardar').disabled = false;
                });
        });

        // Motivo concreto por el que un colaborador no se pudo rellenar solo.
        // Lo pone el servidor al cargar la ficha; se limpia al cambiar de persona.
        var diagnosticoEmpleado = '';

        // ---- Buscador de colaborador --------------------------------------
        // Con casi 150 nombres, el desplegable nativo obliga a bajar a ojo. Esto
        // filtra en vivo por nombre y por código, sin librerías: el <select>
        // sigue existiendo y siendo quien manda, así que el resto del código no
        // se entera y sin JavaScript el desplegable normal sigue funcionando.
        (function montarBuscador() {
            var selectEmp = $('lbc-empleado');
            var caja      = $('lbc-combo');
            var entrada   = $('lbc-buscar');
            var lista     = $('lbc-combo-lista');
            var limpiar   = $('lbc-combo-limpiar');
            if (!selectEmp || !caja) { return; }

            // Sin tildes y en minúsculas: buscar "hernandez" tiene que encontrar
            // a "Hernández", que es como está escrito en la ficha.
            function normalizar(t) {
                // NFD separa la letra de su tilde, y el rango U+0300–U+036F borra
                // los diacríticos ya sueltos. Va con escapes \u a propósito: esos
                // caracteres escritos tal cual son invisibles en el editor.
                return (t || '').toString().toLowerCase()
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }

            var opciones = Array.prototype.slice.call(selectEmp.options).map(function (o, i) {
                var texto = o.textContent.replace(/\s+/g, ' ').trim();
                return { valor: o.value, texto: texto, busqueda: normalizar(texto), indice: i };
            });

            var visibles = opciones.slice();
            var activa = -1;

            function escapar(t) {
                return t.replace(/[&<>"]/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
                });
            }

            // Resalta el trozo que coincide, sobre el texto original con tildes.
            function resaltar(texto, termino) {
                if (!termino) { return escapar(texto); }
                var pos = normalizar(texto).indexOf(termino);
                if (pos < 0) { return escapar(texto); }
                return escapar(texto.slice(0, pos))
                    + '<mark>' + escapar(texto.slice(pos, pos + termino.length)) + '</mark>'
                    + escapar(texto.slice(pos + termino.length));
            }

            function pintarLista(termino) {
                if (!visibles.length) {
                    lista.innerHTML = '<li class="sin-resultados">Ningún colaborador coincide con «'
                        + escapar(entrada.value) + '»</li>';
                    return;
                }
                lista.innerHTML = visibles.map(function (o, i) {
                    return '<li role="option" data-i="' + i + '"'
                        + (i === activa ? ' class="is-activa" aria-selected="true"' : '')
                        + '>' + resaltar(o.texto, termino) + '</li>';
                }).join('');
            }

            function abrir() {
                lista.hidden = false;
                entrada.setAttribute('aria-expanded', 'true');
            }

            function cerrar() {
                lista.hidden = true;
                entrada.setAttribute('aria-expanded', 'false');
                activa = -1;
            }

            /** Deja en la caja el texto de lo que esté seleccionado. */
            function reflejarSeleccion() {
                var o = selectEmp.options[selectEmp.selectedIndex];
                entrada.value = (o && o.value) ? o.textContent.replace(/\s+/g, ' ').trim() : '';
                limpiar.hidden = !entrada.value;
            }

            function elegir(o) {
                selectEmp.value = o.valor;
                selectEmp.dispatchEvent(new Event('change'));
                reflejarSeleccion();
                cerrar();
            }

            function filtrar() {
                var termino = normalizar(entrada.value);
                visibles = termino
                    ? opciones.filter(function (o) { return o.busqueda.indexOf(termino) !== -1; })
                    : opciones.slice();
                activa = visibles.length ? 0 : -1;
                pintarLista(termino);
                abrir();
            }

            function moverActiva(paso) {
                if (!visibles.length) { return; }
                activa = (activa + paso + visibles.length) % visibles.length;
                pintarLista(normalizar(entrada.value));
                var el = lista.querySelector('.is-activa');
                if (el) { el.scrollIntoView({ block: 'nearest' }); }
            }

            entrada.addEventListener('input', filtrar);
            entrada.addEventListener('focus', function () { this.select(); filtrar(); });

            entrada.addEventListener('keydown', function (ev) {
                if (ev.key === 'ArrowDown')      { ev.preventDefault(); if (lista.hidden) { filtrar(); } else { moverActiva(1); } }
                else if (ev.key === 'ArrowUp')   { ev.preventDefault(); moverActiva(-1); }
                else if (ev.key === 'Enter')     {
                    if (!lista.hidden && activa >= 0) { ev.preventDefault(); elegir(visibles[activa]); }
                }
                else if (ev.key === 'Escape')    { cerrar(); reflejarSeleccion(); entrada.blur(); }
            });

            lista.addEventListener('mousedown', function (ev) {
                // mousedown y no click: el blur de la caja cerraría la lista antes.
                var li = ev.target.closest('li[data-i]');
                if (!li) { return; }
                ev.preventDefault();
                elegir(visibles[parseInt(li.dataset.i, 10)]);
            });

            limpiar.addEventListener('click', function () {
                entrada.value = '';
                elegir(opciones[0]);          // "— Escribir los datos a mano —"
                entrada.focus();
            });

            document.addEventListener('click', function (ev) {
                if (!caja.contains(ev.target)) { cerrar(); reflejarSeleccion(); }
            });

            // Si se elige desde otro sitio (o sin JS se usó el select), refleja.
            selectEmp.addEventListener('change', reflejarSeleccion);

            // Solo ahora se oculta el <select>: si algo hubiera fallado antes,
            // el desplegable nativo sigue a la vista y la página es usable.
            caja.parentElement.classList.add('lbc-combo-activo');
            caja.hidden = false;
            reflejarSeleccion();
        })();

        // ---- Colaborador ------------------------------------------------
        var mesesNomina = [];

        function limpiarSalarios() {
            $('lbc-salario-fijo').value = '';
            for (var i = 0; i < FILAS; i++) {
                document.querySelector('.lbc-sal[data-i="' + i + '"]').value = '';
                document.querySelector('.lbc-com[data-i="' + i + '"]').value = '';
                actualizarTotalFila(i);
            }
        }

        function pintarNomina(meses) {
            mesesNomina = meses || [];
            var conDatos = mesesNomina.filter(function (m) { return m.cobertura !== 'sin_datos'; });

            if (!conDatos.length) {
                $('lbc-nomina').style.display = 'none';
                return;
            }

            var etiquetas = {
                completa:  'Completo',
                parcial:   'Parcial',
                sin_datos: 'Sin datos',
                no_aplica: 'No trabajó ese mes'
            };
            var colores = {
                completa:  '#6ee7b7',
                parcial:   '#fcd34d',
                sin_datos: '#f87171',
                no_aplica: '#475569'
            };
            var fuentes = { nomina: 'nómina', ponche: 'ponche' };

            $('lbc-nomina-filas').innerHTML = mesesNomina.map(function (m, i) {
                var detalle = etiquetas[m.cobertura] || m.cobertura;
                if (m.cobertura === 'completa' && fuentes[m.fuente]) {
                    detalle += ' · ' + fuentes[m.fuente];
                } else if (m.cobertura === 'parcial') {
                    detalle += ' (' + m.dias_cubiertos + '/' + m.dias_empleado + ' días)';
                }
                return '<tr>'
                    + '<td class="text-slate-400 text-sm">' + (i + 1) + '. ' + m.etiqueta + '</td>'
                    + '<td class="lbc-money text-sm" style="color:' + colores[m.cobertura] + '">'
                    + (m.monto > 0 ? moneda(m.monto) : '—') + '</td>'
                    + '<td class="text-xs" style="text-align:left; color:' + colores[m.cobertura] + '">'
                    + detalle + '</td></tr>';
            }).join('');

            $('lbc-nomina').style.display = 'block';
        }

        /**
         * Vuelca el devengado en la rejilla. Devuelve cuántos períodos llenó.
         *
         * mesesNomina ya viene alineado con la rejilla desde el servidor: la
         * casilla g de aquí es la casilla g de la tabla de salarios.
         */
        function aplicarMontosDeNomina() {
            if (!mesesNomina.length) { return 0; }

            var llenos = 0;
            $('lbc-salario-fijo').value = '';
            for (var g = 0; g < FILAS; g++) {
                var m = mesesNomina[g];
                var campo = document.querySelector('.lbc-sal[data-i="' + g + '"]');
                if (m && m.monto > 0) {
                    campo.value = m.monto;
                    llenos++;
                } else {
                    campo.value = '';
                }
                actualizarTotalFila(g);
            }
            programarCalculo();
            return llenos;
        }

        $('lbc-usar-nomina').addEventListener('click', aplicarMontosDeNomina);

        $('lbc-empleado').addEventListener('change', function () {
            var id = this.value;
            $('lbc-empleado-nota').textContent = '';
            // Se limpia ya: si no, al cambiar de colaborador se quedaría en
            // pantalla el motivo del anterior.
            diagnosticoEmpleado = '';
            if (!id) {
                $('lbc-nomina').style.display = 'none';
                mesesNomina = [];
                return;
            }

            // Traer los datos puede tardar unos segundos: para quien cobra por
            // hora hay que recorrer el ponche mes a mes. Sin este aviso parece
            // que la pantalla no hace nada, y da tiempo a pulsar "Usar estos
            // montos" antes de que lleguen los importes.
            mesesNomina = [];
            $('lbc-nomina').style.display = 'none';
            $('lbc-usar-nomina').disabled = true;
            $('lbc-empleado-nota').innerHTML =
                '<i class="fas fa-circle-notch fa-spin"></i> Buscando lo devengado en nómina y ponche…';

            fetch('labor_benefits_calculator.php?ajax=empleado&id=' + encodeURIComponent(id))
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    $('lbc-usar-nomina').disabled = false;
                    if (!r || !r.ok) {
                        $('lbc-empleado-nota').textContent = (r && r.error) || 'No se pudieron traer los datos.';
                        return;
                    }
                    var e = r.empleado;
                    $('lbc-nombre').value = e.nombre || '';
                    $('lbc-cedula').value = e.cedula || '';
                    if (e.hire_date) { $('lbc-ingreso').value = e.hire_date; }
                    $('lbc-salida').value = e.termination_date || new Date().toISOString().slice(0, 10);

                    limpiarSalarios();
                    pintarNomina(e.meses_nomina);

                    // La rejilla se llena SOLA. Con salario fijo, del sueldo
                    // registrado; si cobra por hora, del devengado real que
                    // acabamos de traer. Lo que nunca se hace es inventar un
                    // salario teórico cuando no hay ningún dato.
                    var llenadosDeNomina = 0;
                    if (e.salario_fijo && e.salario_mensual > 0) {
                        $('lbc-salario-fijo').value = e.salario_mensual;
                        aplicarSalarioFijo();
                    } else {
                        llenadosDeNomina = aplicarMontosDeNomina();
                    }

                    var nota;
                    if (e.salario_fijo && e.salario_mensual > 0) {
                        nota = e.salario_origen + ' · aplicado a los 12 períodos.';
                    } else if (llenadosDeNomina > 0) {
                        nota = 'Cobra por hora (RD$' + Number(e.tarifa_hora || 0).toFixed(2) + '/h). '
                             + 'Se llenaron ' + llenadosDeNomina + ' período(s) con lo que devengó de verdad '
                             + '(ver el detalle abajo). Revísalos antes de liquidar.';
                    } else {
                        nota = e.salario_origen;
                    }
                    if (!e.termination_date) {
                        nota += ' Sin fecha de salida registrada, se usó la de hoy.';
                    }
                    $('lbc-empleado-nota').textContent = nota;

                    // Si no se pudo rellenar nada, el motivo concreto se muestra
                    // donde va el resultado, para que no parezca un fallo del
                    // sistema cuando lo que falta es un dato de la ficha.
                    diagnosticoEmpleado = e.diagnostico || '';
                    programarCalculo();
                })
                .catch(function () {
                    $('lbc-usar-nomina').disabled = false;
                    $('lbc-empleado-nota').textContent = 'No se pudo contactar al servidor.';
                });
        });

        // ---- Salario fijo -----------------------------------------------
        function aplicarSalarioFijo() {
            var v = $('lbc-salario-fijo').value;
            if (v === '') { return; }
            for (var i = 0; i < FILAS; i++) {
                document.querySelector('.lbc-sal[data-i="' + i + '"]').value = v;
                actualizarTotalFila(i);
            }
            programarCalculo();
        }
        $('lbc-aplicar-fijo').addEventListener('click', aplicarSalarioFijo);
        $('lbc-salario-fijo').addEventListener('input', function () {
            clearTimeout(temporizador);
            temporizador = setTimeout(aplicarSalarioFijo, 350);
        });

        // ---- Motivo de la terminación -------------------------------------
        // Qué genera cada forma de terminar el contrato, según el Código de
        // Trabajo. Evita que haya que recordar de memoria que una renuncia no
        // paga preaviso ni cesantía, que es el error fácil de cometer aquí.
        // `preavisado: true` significa "no se indemniza el preaviso".
        var MOTIVOS = {
            desahucio: {
                preavisado: false, cesantia: true,
                nota: 'El empleador termina el contrato sin causa: paga preaviso y cesantía (arts. 76, 79 y 80). ' +
                      'Si sí dio el preaviso con antelación, marca el interruptor y no se indemniza.'
            },
            despido_injustificado: {
                preavisado: false, cesantia: true,
                nota: 'Despido sin causa probada: el empleador debe preaviso y cesantía como indemnización (art. 95). ' +
                      'Los salarios caídos del ordinal 3 no entran aquí, dependen del proceso.'
            },
            dimision_justificada: {
                preavisado: false, cesantia: true,
                nota: 'El trabajador termina por falta del empleador: cobra igual que en un despido injustificado (art. 101).'
            },
            renuncia: {
                preavisado: true, cesantia: false,
                nota: 'Desahucio ejercido por el trabajador: no genera preaviso a su favor ni cesantía. ' +
                      'Conserva vacaciones y salario de Navidad.'
            },
            despido_justificado: {
                preavisado: true, cesantia: false,
                nota: 'Despido por falta del trabajador: no genera preaviso ni cesantía (art. 88). ' +
                      'Conserva vacaciones y salario de Navidad.'
            },
            dimision_injustificada: {
                preavisado: true, cesantia: false,
                nota: 'Dimisión sin causa probada: no genera preaviso ni cesantía. ' +
                      'Conserva vacaciones y salario de Navidad.'
            },
            fin_contrato: {
                preavisado: true, cesantia: false,
                nota: 'Contrato cumplido o terminado de común acuerdo: no hay preaviso ni cesantía, ' +
                      'solo los derechos adquiridos.'
            },
            personalizado: {
                preavisado: null, cesantia: null,
                nota: 'Los interruptores de abajo mandan.'
            }
        };

        var aplicandoMotivo = false;

        function aplicarMotivo() {
            var m = MOTIVOS[$('lbc-motivo').value] || MOTIVOS.personalizado;
            $('lbc-motivo-nota').textContent = m.nota;

            if (m.preavisado === null) { return; }

            // Bandera para que el cambio programático no rebote a "Personalizado".
            aplicandoMotivo = true;
            $('lbc-preavisado').checked = m.preavisado;
            $('lbc-cesantia').checked = m.cesantia;
            aplicandoMotivo = false;

            programarCalculo();
        }

        $('lbc-motivo').addEventListener('change', aplicarMotivo);

        // ---- Radios y switches -------------------------------------------
        ['lbc-periodos', 'lbc-tipos'].forEach(function (grupo) {
            $(grupo).addEventListener('change', function () {
                Array.prototype.forEach.call(this.querySelectorAll('.lbc-radio'), function (l) {
                    l.classList.toggle('is-active', l.querySelector('input').checked);
                });
                programarCalculo();
            });
        });
        ['lbc-preavisado', 'lbc-cesantia', 'lbc-navidad', 'lbc-vac-tomadas'].forEach(function (id) {
            $(id).addEventListener('change', function () {
                // Tocar a mano el preaviso o la cesantía deja de corresponder a
                // un motivo concreto: el selector pasa a Personalizado.
                if (!aplicandoMotivo && (id === 'lbc-preavisado' || id === 'lbc-cesantia')) {
                    $('lbc-motivo').value = 'personalizado';
                    $('lbc-motivo-nota').textContent = MOTIVOS.personalizado.nota;
                }
                programarCalculo();
            });
        });
        ['lbc-ingreso', 'lbc-salida'].forEach(function (id) {
            $(id).addEventListener('change', programarCalculo);
        });

        construirFilas();
        aplicarMesesActivos(12);
        aplicarMotivo();   // deja el desahucio (lo más común) ya configurado
        mostrarVacio();
    })();
    </script>
</body>
</html>

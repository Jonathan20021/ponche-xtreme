<?php
/**
 * Instalador idempotente del generador de documentos.
 *
 * Crea `document_templates` con los 12 documentos que pidio el cliente. Cada
 * plantilla es HTML con marcadores ({{nombre}}, {{cedula}}, ...) que se
 * reemplazan con los datos del colaborador al generar el PDF.
 *
 * Los formatos definitivos los va a suministrar el cliente despues, asi que cada
 * plantilla queda EDITABLE desde la interfaz (hr/document_templates.php). Las que
 * ya tienen formato aprobado (contrato de trabajo y confidencialidad) siguen
 * usando su generador existente y se marcan como 'builtin'.
 *
 * MySQL 5.7: sin `IF NOT EXISTS` en ALTER.
 */

require_once __DIR__ . '/db.php';

$cli = (PHP_SAPI === 'cli');
$nl  = $cli ? "\n" : "<br>\n";
if (!$cli) {
    echo "<pre style='font-family:Consolas,monospace;background:#0f172a;color:#cbd5e1;padding:16px;'>";
}

echo "=== Migracion: Generador de Documentos ==={$nl}DB: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "{$nl}{$nl}";

$ok = 0; $skipped = 0; $errors = 0;

function docStep(string $label, callable $fn, &$ok, &$skipped, &$errors, string $nl): void
{
    echo "[*] $label{$nl}";
    try {
        $r = $fn();
        if ($r === 'SKIP') { echo "    SKIP{$nl}"; $skipped++; }
        else { echo "    OK{$nl}"; $ok++; }
    } catch (Throwable $e) {
        echo "    ERROR: " . $e->getMessage() . "{$nl}";
        $errors++;
    }
}

docStep('Tabla document_templates', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `document_templates` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `doc_key` VARCHAR(60) NOT NULL COMMENT 'coincide con required_document_types.doc_key cuando aplica',
          `name` VARCHAR(150) NOT NULL,
          `category` VARCHAR(60) DEFAULT NULL COMMENT 'Disciplinario, Contractual, Induccion, Constancias',
          `body_html` MEDIUMTEXT COMMENT 'plantilla con marcadores {{campo}}',
          `render_mode` VARCHAR(20) NOT NULL DEFAULT 'template'
            COMMENT 'template = usa body_html; builtin = usa el generador propio; upload = no se genera, se carga',
          `builtin_handler` VARCHAR(80) DEFAULT NULL COMMENT 'funcion PHP que arma el cuerpo cuando render_mode=builtin',
          `needs_extra_fields` VARCHAR(255) DEFAULT NULL COMMENT 'CSV de campos que hay que pedir al generar',
          `file_to_expediente` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'archivar solo en el expediente',
          `requires_signature` TINYINT(1) NOT NULL DEFAULT 0,
          `sort_order` INT NOT NULL DEFAULT 0,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `updated_by` INT UNSIGNED DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_document_template_key` (`doc_key`),
          KEY `idx_document_template_active` (`is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// Registro de lo generado, para saber quien emitio que y cuando.
docStep('Tabla generated_documents', function () use ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `generated_documents` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `employee_id` INT UNSIGNED NOT NULL,
          `doc_key` VARCHAR(60) NOT NULL,
          `document_name` VARCHAR(255) NOT NULL,
          `employee_document_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'fila en employee_documents si se archivo',
          `payload_json` LONGTEXT DEFAULT NULL COMMENT 'datos con los que se genero (evidencia)',
          `generated_by` INT UNSIGNED DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_generated_docs_employee` (`employee_id`),
          KEY `idx_generated_docs_key` (`doc_key`),
          CONSTRAINT `fk_generated_docs_employee` FOREIGN KEY (`employee_id`)
            REFERENCES `employees` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    return 'OK';
}, $ok, $skipped, $errors, $nl);

// ---------------------------------------------------------------------------
// Plantilla de arranque para los documentos cuyo formato llega despues.
// No es un texto de relleno: trae la estructura, el encabezado de la empresa y
// los marcadores listos, para que RRHH solo pegue el contenido definitivo.
// ---------------------------------------------------------------------------
function docStarterTemplate(string $titulo, string $cuerpo, bool $conFirmaEmpleado = true): string
{
    $firma = $conFirmaEmpleado ? '
    <div class="firmas">
        <div class="firma">
            <div class="linea"></div>
            <p><strong>{{nombre}}</strong><br>Cédula {{cedula}}<br>Colaborador</p>
        </div>
        <div class="firma">
            <div class="linea"></div>
            <p><strong>Hugo Antonio Hidalgo Núñez</strong><br>Gerente General<br>Evallish SRL</p>
        </div>
    </div>' : '';

    return '<div class="doc">
    <h1>' . $titulo . '</h1>
    <p class="empresa">EVALLISH SRL · RNC 1-3263745-3</p>

    <table class="datos">
        <tr><td>Colaborador</td><td><strong>{{nombre}}</strong></td></tr>
        <tr><td>Cédula</td><td>{{cedula}}</td></tr>
        <tr><td>Posición</td><td>{{posicion}}</td></tr>
        <tr><td>Departamento</td><td>{{departamento}}</td></tr>
        <tr><td>Fecha</td><td>{{fecha_larga}}</td></tr>
    </table>

' . $cuerpo . '
' . $firma . '
</div>';
}

$plantillas = [
    // doc_key, nombre, categoria, render_mode, handler, campos extra, firma, orden, cuerpo
    ['amonestacion', 'Amonestación', 'Disciplinario', 'template', null, 'motivo,tipo_falta,medida', 1, 10,
        docStarterTemplate('AMONESTACIÓN', '
    <p>Por medio del presente documento se deja constancia de la amonestación aplicada al colaborador
    arriba identificado, por los hechos que se describen a continuación:</p>

    <p class="titulo">HECHOS</p>
    <p>{{motivo}}</p>

    <p class="titulo">TIPO DE FALTA</p>
    <p>{{tipo_falta}}</p>

    <p class="titulo">MEDIDA APLICADA</p>
    <p>{{medida}}</p>

    <p>El colaborador declara haber sido informado de los hechos y de la medida adoptada, y queda
    advertido de que la reincidencia podrá dar lugar a sanciones mayores conforme al Reglamento
    Interno y al Código de Trabajo de la República Dominicana.</p>')],

    ['retroalimentacion', 'Retroalimentación', 'Desempeño', 'template', null, 'aspectos_positivos,areas_mejora,compromisos', 1, 20,
        docStarterTemplate('RETROALIMENTACIÓN', '
    <p>Se deja constancia de la sesión de retroalimentación sostenida con el colaborador:</p>

    <p class="titulo">ASPECTOS POSITIVOS</p>
    <p>{{aspectos_positivos}}</p>

    <p class="titulo">ÁREAS DE MEJORA</p>
    <p>{{areas_mejora}}</p>

    <p class="titulo">COMPROMISOS ACORDADOS</p>
    <p>{{compromisos}}</p>

    <p>Ambas partes acuerdan dar seguimiento a los compromisos establecidos.</p>')],

    ['descargo_laboral', 'Descargo laboral', 'Disciplinario', 'template', null, 'hechos,descargo_empleado', 1, 30,
        docStarterTemplate('DESCARGO LABORAL', '
    <p>En cumplimiento del debido proceso, se concede al colaborador la oportunidad de presentar su
    descargo respecto de los hechos que se le atribuyen:</p>

    <p class="titulo">HECHOS ATRIBUIDOS</p>
    <p>{{hechos}}</p>

    <p class="titulo">DESCARGO DEL COLABORADOR</p>
    <p>{{descargo_empleado}}</p>

    <p>El colaborador declara que lo aquí consignado corresponde a su versión de los hechos, expresada
    de forma libre y voluntaria.</p>')],

    ['politicas_equipos', 'Políticas de asignación y uso de equipos tecnológicos', 'Inducción', 'template', null, '', 1, 40,
        docStarterTemplate('POLÍTICAS DE ASIGNACIÓN Y USO DE EQUIPOS TECNOLÓGICOS', '
    <p class="pendiente">Pega aquí el formato definitivo de la política. Puedes usar los marcadores
    de la lista lateral ({{nombre}}, {{cedula}}, {{posicion}}, {{fecha_larga}}, ...) donde necesites
    los datos del colaborador.</p>

    <p>El colaborador declara haber recibido, leído y aceptado las políticas de asignación y uso de
    los equipos tecnológicos propiedad de Evallish SRL, y se compromete a darles el uso adecuado y a
    devolverlos en buen estado al finalizar la relación laboral.</p>')],

    ['acta_descargo', 'Acta de descargo de carnet, uniforme y llave de acceso', 'Salida', 'template', null, 'articulos_devueltos,observaciones', 1, 50,
        docStarterTemplate('ACTA DE DESCARGO', '
    <p>Se deja constancia de la devolución, por parte del colaborador, de los artículos entregados
    por la empresa:</p>

    <p class="titulo">ARTÍCULOS DEVUELTOS</p>
    <p>{{articulos_devueltos}}</p>

    <p class="titulo">OBSERVACIONES</p>
    <p>{{observaciones}}</p>

    <p>Con la firma del presente documento, Evallish SRL da por recibidos los artículos descritos y el
    colaborador queda descargado de la responsabilidad sobre los mismos.</p>')],

    ['constancia_ingreso', 'Constancia de ingreso', 'Constancias', 'template', null, '', 0, 60,
        docStarterTemplate('CONSTANCIA DE INGRESO', '
    <p>A quien pueda interesar:</p>

    <p>Por medio de la presente hacemos constar que <strong>{{nombre}}</strong>, portador(a) de la
    cédula de identidad y electoral No. <strong>{{cedula}}</strong>, labora en <strong>EVALLISH SRL</strong>
    desde el <strong>{{fecha_ingreso_larga}}</strong>, desempeñando el cargo de
    <strong>{{posicion}}</strong> en el departamento de <strong>{{departamento}}</strong>.</p>

    <p>La presente constancia se expide a solicitud del interesado, a los {{dia}} días del mes de
    {{mes}} del año {{anio}}, en la ciudad de Santiago de los Caballeros, República Dominicana.</p>

    <div class="firmas">
        <div class="firma">
            <div class="linea"></div>
            <p><strong>Recursos Humanos</strong><br>Evallish SRL</p>
        </div>
    </div>', false)],

    ['oferta_laboral', 'Oferta laboral', 'Contractual', 'template', null, 'fecha_inicio,beneficios', 1, 70,
        docStarterTemplate('OFERTA LABORAL', '
    <p>Estimado(a) <strong>{{nombre}}</strong>:</p>

    <p>Nos complace extenderle la presente oferta de empleo para integrarse a <strong>EVALLISH SRL</strong>
    en la posición de <strong>{{posicion}}</strong>, bajo las siguientes condiciones:</p>

    <table class="datos">
        <tr><td>Posición</td><td>{{posicion}}</td></tr>
        <tr><td>Departamento</td><td>{{departamento}}</td></tr>
        <tr><td>Remuneración</td><td>{{salario}}</td></tr>
        <tr><td>Fecha de inicio</td><td>{{fecha_inicio}}</td></tr>
    </table>

    <p class="titulo">BENEFICIOS</p>
    <p>{{beneficios}}</p>

    <p>Esta oferta queda sujeta a la aceptación del colaborador y al cumplimiento de los requisitos
    de contratación establecidos por la empresa.</p>')],

    ['normas_seguridad', 'Normas de seguridad', 'Inducción', 'template', null, '', 1, 80,
        docStarterTemplate('NORMAS DE SEGURIDAD', '
    <p class="pendiente">Pega aquí el formato definitivo de las normas de seguridad.</p>

    <p>El colaborador declara haber recibido, leído y comprendido las normas de seguridad de
    Evallish SRL, y se compromete a cumplirlas durante toda su relación laboral.</p>')],

    ['guia_induccion', 'Guía de inducción', 'Inducción', 'template', null, '', 1, 90,
        docStarterTemplate('GUÍA DE INDUCCIÓN', '
    <p class="pendiente">Pega aquí el formato definitivo de la guía de inducción.</p>

    <p>El colaborador declara haber recibido la inducción correspondiente a su puesto y haber sido
    informado de las políticas, procedimientos y normas de la empresa.</p>')],

    // Estos dos YA tienen formato aprobado y su propio generador.
    ['contrato_confidencialidad', 'Contrato de confidencialidad', 'Contractual', 'builtin', 'buildConfidentialityContractHtml', '', 1, 100, null],
    ['contrato_trabajo', 'Contrato de trabajo', 'Contractual', 'builtin', 'buildEmploymentContractHtml', '', 1, 110, null],

    // La cédula no se genera: es un documento de identidad que se escanea.
    ['cedula', 'Cédula de identidad', 'Identificación', 'upload', null, '', 0, 120, null],
];

foreach ($plantillas as [$key, $name, $cat, $mode, $handler, $extra, $sign, $order, $body]) {
    docStep("plantilla: $name", function () use ($pdo, $key, $name, $cat, $mode, $handler, $extra, $sign, $order, $body) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM document_templates WHERE doc_key = ?");
        $c->execute([$key]);
        if ((int) $c->fetchColumn() > 0) {
            return 'SKIP';
        }
        $i = $pdo->prepare("
            INSERT INTO document_templates
                (doc_key, name, category, body_html, render_mode, builtin_handler,
                 needs_extra_fields, requires_signature, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $i->execute([$key, $name, $cat, $body, $mode, $handler, $extra ?: null, $sign, $order]);
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

// Los tres tipos nuevos también entran al checklist del expediente, pero NO como
// obligatorios: una amonestación no se le exige a todo el mundo.
$nuevosRequeridos = [
    ['amonestacion',     'Amonestación',      0, 1, 200],
    ['retroalimentacion','Retroalimentación', 0, 1, 210],
    ['descargo_laboral', 'Descargo laboral',  0, 1, 220],
];
foreach ($nuevosRequeridos as [$key, $label, $required, $signature, $order]) {
    docStep("tipo de expediente: $label", function () use ($pdo, $key, $label, $required, $signature, $order) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM required_document_types WHERE doc_key = ?");
        $c->execute([$key]);
        if ((int) $c->fetchColumn() > 0) {
            return 'SKIP';
        }
        $i = $pdo->prepare("
            INSERT INTO required_document_types (doc_key, label, is_required, requires_signature, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ");
        $i->execute([$key, $label, $required, $signature, $order]);
        return 'OK';
    }, $ok, $skipped, $errors, $nl);
}

echo "{$nl}=== Resumen ==={$nl}OK: $ok   SKIP: $skipped   ERRORES: $errors{$nl}";
if (!$cli) {
    echo "</pre>";
}

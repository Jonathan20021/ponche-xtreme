<?php
/**
 * hr/document_templates.php
 *
 * Edición de los formatos de los documentos.
 *
 * Aquí es donde RRHH pega el formato definitivo de cada documento cuando lo
 * suministre el cliente, sin tocar código. El texto es HTML sencillo con
 * marcadores {{campo}} que se reemplazan con los datos del colaborador.
 */

session_start();
require_once '../db.php';
require_once '../lib/document_generator.php';

ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$ok = []; $err = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_template') {
            $key = (string) ($_POST['doc_key'] ?? '');
            $tpl = documentTemplateByKey($pdo, $key);

            if (!$tpl) {
                $err[] = 'Plantilla no encontrada.';
            } elseif ($tpl['render_mode'] === 'builtin') {
                $err[] = 'Este documento tiene texto legal aprobado y no se edita desde aquí.';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE document_templates
                    SET name = ?, category = ?, body_html = ?, needs_extra_fields = ?,
                        requires_signature = ?, is_active = ?, updated_by = ?
                    WHERE doc_key = ?
                ");
                $stmt->execute([
                    trim((string) ($_POST['name'] ?? $tpl['name'])) ?: $tpl['name'],
                    trim((string) ($_POST['category'] ?? '')) ?: null,
                    (string) ($_POST['body_html'] ?? ''),
                    trim((string) ($_POST['needs_extra_fields'] ?? '')) ?: null,
                    isset($_POST['requires_signature']) ? 1 : 0,
                    isset($_POST['is_active']) ? 1 : 0,
                    (int) $_SESSION['user_id'],
                    $key,
                ]);
                $ok[] = 'Formato de "' . $tpl['name'] . '" guardado.';
            }
        }
    } catch (Throwable $e) {
        $err[] = 'No se pudo guardar: ' . $e->getMessage();
    }

    $_SESSION['dt_flash'] = ['ok' => $ok, 'err' => $err];
    header('Location: document_templates.php?doc_key=' . urlencode($_POST['doc_key'] ?? ''));
    exit;
}

$flash = $_SESSION['dt_flash'] ?? ['ok' => [], 'err' => []];
unset($_SESSION['dt_flash']);

$templates = documentTemplates($pdo, false, false);
$selected  = null;
$docKey    = (string) ($_GET['doc_key'] ?? '');
if ($docKey !== '') {
    $selected = documentTemplateByKey($pdo, $docKey);
}

// Marcadores disponibles, para que RRHH no tenga que adivinarlos.
$marcadores = [
    'nombre' => 'Nombre completo', 'primer_nombre' => 'Primer nombre', 'apellido' => 'Apellido',
    'codigo' => 'Código de empleado', 'cedula' => 'Cédula', 'posicion' => 'Posición',
    'departamento' => 'Departamento', 'campana' => 'Campaña', 'supervisor' => 'Supervisor',
    'correo' => 'Correo', 'telefono' => 'Teléfono', 'direccion' => 'Dirección',
    'provincia' => 'Provincia', 'salario' => 'Salario o tarifa',
    'fecha' => 'Fecha (dd/mm/aaaa)', 'fecha_larga' => 'Fecha en letras',
    'dia' => 'Día', 'mes' => 'Mes', 'anio' => 'Año',
    'fecha_ingreso' => 'Fecha de ingreso', 'fecha_ingreso_larga' => 'Ingreso en letras',
    'empresa' => 'Empresa', 'rnc' => 'RNC', 'representante' => 'Representante', 'ciudad' => 'Ciudad',
];

$inputCls = 'w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 text-white text-sm';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formatos de Documentos</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-wrap justify-between items-start gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-file-pen text-cyan-400 mr-3"></i>Formatos de Documentos
                </h1>
                <p class="text-slate-400 text-sm">
                    El texto de cada documento. Cuando llegue el formato definitivo, se pega aquí
                    y queda listo para generarse — sin tocar código.
                </p>
            </div>
            <a href="employees.php" class="btn-secondary"><i class="fas fa-arrow-left"></i> Empleados</a>
        </div>

        <?php foreach ($flash['ok'] as $m): ?>
            <div class="mb-3 px-4 py-3 rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-200 text-sm">
                <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($m) ?>
            </div>
        <?php endforeach; ?>
        <?php foreach ($flash['err'] as $m): ?>
            <div class="mb-3 px-4 py-3 rounded-lg bg-rose-500/15 border border-rose-500/30 text-rose-200 text-sm">
                <i class="fas fa-triangle-exclamation mr-2"></i><?= htmlspecialchars($m) ?>
            </div>
        <?php endforeach; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Lista -->
            <div class="glass-card">
                <h2 class="text-lg font-semibold text-white mb-4">Documentos</h2>
                <div class="space-y-1" style="max-height: 34rem; overflow-y: auto;">
                    <?php foreach ($templates as $t): ?>
                        <a href="?doc_key=<?= urlencode($t['doc_key']) ?>"
                           class="block p-3 rounded <?= $docKey === $t['doc_key'] ? 'bg-cyan-600/20 border border-cyan-500/40' : 'bg-slate-800/50 hover:bg-slate-700/50' ?>">
                            <p class="text-white text-sm"><?= htmlspecialchars($t['name']) ?></p>
                            <p class="text-slate-400 text-xs mt-1">
                                <?= htmlspecialchars($t['category'] ?: 'Otros') ?>
                                ·
                                <?php if ($t['render_mode'] === 'builtin'): ?>
                                    <span class="text-emerald-300">formato aprobado</span>
                                <?php elseif ($t['render_mode'] === 'upload'): ?>
                                    <span class="text-slate-500">solo se carga</span>
                                <?php elseif (strpos((string) $t['body_html'], 'class="pendiente"') !== false): ?>
                                    <span class="text-amber-300">falta el formato</span>
                                <?php else: ?>
                                    <span class="text-cyan-300">editable</span>
                                <?php endif; ?>
                                <?= (int) $t['is_active'] === 0 ? ' · inactivo' : '' ?>
                            </p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Editor -->
            <div class="glass-card lg:col-span-2">
                <?php if (!$selected): ?>
                    <p class="text-slate-400 text-center py-12">
                        <i class="fas fa-hand-pointer text-3xl block mb-3 opacity-40"></i>
                        Elige un documento de la lista para ver o editar su formato.
                    </p>
                <?php elseif ($selected['render_mode'] === 'builtin'): ?>
                    <h2 class="text-lg font-semibold text-white mb-2"><?= htmlspecialchars($selected['name']) ?></h2>
                    <div class="p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-200 text-sm">
                        <i class="fas fa-lock mr-2"></i>
                        Este documento tiene <strong>texto legal aprobado</strong> y se genera con su propio
                        formato validado. No se edita desde aquí para evitar que alguien altere las cláusulas
                        por accidente. Si hay que cambiarlo, avisa a desarrollo.
                    </div>
                <?php elseif ($selected['render_mode'] === 'upload'): ?>
                    <h2 class="text-lg font-semibold text-white mb-2"><?= htmlspecialchars($selected['name']) ?></h2>
                    <div class="p-4 rounded-lg bg-slate-700/40 border border-slate-600 text-slate-300 text-sm">
                        <i class="fas fa-upload mr-2"></i>
                        Este documento no se genera: se escanea y se carga desde el expediente del colaborador.
                    </div>
                <?php else: ?>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="save_template">
                        <input type="hidden" name="doc_key" value="<?= htmlspecialchars($selected['doc_key']) ?>">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-slate-300 text-sm mb-1">Nombre</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($selected['name']) ?>" class="<?= $inputCls ?>">
                            </div>
                            <div>
                                <label class="block text-slate-300 text-sm mb-1">Categoría</label>
                                <input type="text" name="category" value="<?= htmlspecialchars((string) $selected['category']) ?>" class="<?= $inputCls ?>">
                            </div>
                        </div>

                        <div>
                            <label class="block text-slate-300 text-sm mb-1">
                                Campos que se piden al generar
                                <span class="text-slate-500 text-xs">(separados por coma, sin espacios)</span>
                            </label>
                            <input type="text" name="needs_extra_fields"
                                   value="<?= htmlspecialchars((string) $selected['needs_extra_fields']) ?>"
                                   placeholder="motivo,medida" class="<?= $inputCls ?>">
                            <p class="text-xs text-slate-400 mt-1">
                                Cada uno se convierte en un marcador que puedes usar abajo. Ej: <code>motivo</code> → <code>{{motivo}}</code>
                            </p>
                        </div>

                        <div>
                            <label class="block text-slate-300 text-sm mb-1">Formato del documento (HTML)</label>
                            <textarea name="body_html" rows="20" spellcheck="false"
                                      class="<?= $inputCls ?>" style="font-family: Consolas, monospace; font-size: 12px; line-height: 1.5;"><?= htmlspecialchars((string) $selected['body_html']) ?></textarea>
                        </div>

                        <div class="flex flex-wrap gap-4">
                            <label class="inline-flex items-center gap-2 text-slate-300 text-sm">
                                <input type="checkbox" name="requires_signature" value="1" class="w-4 h-4"
                                    <?= (int) $selected['requires_signature'] === 1 ? 'checked' : '' ?>>
                                Requiere firma del colaborador
                            </label>
                            <label class="inline-flex items-center gap-2 text-slate-300 text-sm">
                                <input type="checkbox" name="is_active" value="1" class="w-4 h-4"
                                    <?= (int) $selected['is_active'] === 1 ? 'checked' : '' ?>>
                                Activo
                            </label>
                        </div>

                        <div class="p-3 rounded-lg bg-slate-800/60 border border-slate-700">
                            <p class="text-slate-300 text-xs font-semibold mb-2">
                                <i class="fas fa-code mr-1"></i> Marcadores disponibles — haz clic para copiar
                            </p>
                            <div class="flex flex-wrap gap-1">
                                <?php foreach ($marcadores as $k => $label): ?>
                                    <button type="button" onclick="copiarMarcador('<?= $k ?>')"
                                            title="<?= htmlspecialchars($label) ?>"
                                            class="px-2 py-1 rounded bg-slate-700 hover:bg-cyan-600 text-slate-200 text-xs">
                                        {{<?= $k ?>}}
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-slate-500 text-xs mt-2">
                                Clases de estilo listas: <code>titulo</code> para subtítulos,
                                <code>datos</code> para tablas, <code>firmas</code>/<code>firma</code> para el bloque de firmas.
                            </p>
                        </div>

                        <div class="flex justify-end gap-2">
                            <a href="generate_document.php?employee_id=0&doc_key=<?= urlencode($selected['doc_key']) ?>"
                               class="btn-secondary" onclick="alert('Abre el documento desde el perfil de un colaborador para verlo con datos reales.'); return false;">
                                <i class="fas fa-eye"></i> Cómo probarlo
                            </a>
                            <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Guardar formato</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function copiarMarcador(nombre) {
            const texto = '{{' + nombre + '}}';
            const area = document.querySelector('textarea[name="body_html"]');
            if (area) {
                // Insertar donde está el cursor, que es lo que uno espera al redactar.
                const ini = area.selectionStart, fin = area.selectionEnd;
                area.value = area.value.substring(0, ini) + texto + area.value.substring(fin);
                area.selectionStart = area.selectionEnd = ini + texto.length;
                area.focus();
            } else if (navigator.clipboard) {
                navigator.clipboard.writeText(texto);
            }
        }
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>

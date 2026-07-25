<?php
/**
 * hr/generate_document.php
 *
 * Genera cualquiera de los documentos del colaborador desde una plantilla y lo
 * archiva solo en su expediente.
 *
 * GET  ?employee_id=X            -> formulario
 * GET  ?employee_id=X&doc_key=Y  -> formulario con el tipo preseleccionado
 * POST                           -> genera el PDF
 */

session_start();
require_once '../db.php';
require_once '../lib/document_generator.php';

ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$employeeId = (int) ($_GET['employee_id'] ?? $_POST['employee_id'] ?? 0);
$docKey     = (string) ($_GET['doc_key'] ?? $_POST['doc_key'] ?? '');
$error      = null;

$employee = null;
if ($employeeId > 0) {
    $stmt = $pdo->prepare("
        SELECT e.id, e.employee_code, e.first_name, e.last_name, e.position, d.name AS department_name
        FROM employees e LEFT JOIN departments d ON d.id = e.department_id
        WHERE e.id = ?
    ");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$employee) {
    header('Location: employees.php');
    exit;
}

$templates = documentTemplates($pdo, true, true);
$template  = $docKey !== '' ? documentTemplateByKey($pdo, $docKey) : null;
$extraDefs = $template ? documentExtraFields($template['needs_extra_fields'] ?? null) : [];

// ---------------------------------------------------------------------------
// Generar
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $template) {
    $extra = [];
    foreach ($extraDefs as $f) {
        $extra[$f['key']] = trim((string) ($_POST['extra'][$f['key']] ?? ''));
    }

    $faltantes = [];
    foreach ($extraDefs as $f) {
        if ($extra[$f['key']] === '') {
            $faltantes[] = $f['label'];
        }
    }

    if (!empty($faltantes)) {
        $error = 'Faltan campos por llenar: ' . implode(', ', $faltantes);
    } else {
        $archivar = isset($_POST['file_to_expediente']);
        $res = documentGenerateAndFile($pdo, $docKey, $employeeId, $extra, $archivar, (int) $_SESSION['user_id']);

        if (!$res['ok']) {
            $error = $res['error'];
        } else {
            // El PDF se manda al navegador; si se archivó, ya quedó en el expediente.
            $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '_',
                $template['name'] . '_' . $employee['first_name'] . '_' . $employee['last_name']);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . trim($nombre, '_') . '_' . date('Y-m-d') . '.pdf"');
            header('Content-Length: ' . strlen($res['pdf']));
            echo $res['pdf'];
            exit;
        }
    }
}

$inputCls = 'w-full bg-slate-800 border border-slate-700 rounded px-3 py-2 text-white text-sm';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar documento — <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="max-w-3xl mx-auto">
            <div class="flex items-center gap-4 mb-6">
                <a href="employee_profile.php?id=<?= (int) $employeeId ?>" class="btn-secondary"><i class="fas fa-arrow-left"></i></a>
                <div>
                    <h1 class="text-2xl font-bold text-white">Generar documento</h1>
                    <p class="text-slate-400 text-sm">
                        <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>
                        · <?= htmlspecialchars((string) $employee['employee_code']) ?>
                        <?= $employee['position'] ? ' · ' . htmlspecialchars($employee['position']) : '' ?>
                    </p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="mb-4 px-4 py-3 rounded-lg bg-rose-500/15 border border-rose-500/30 text-rose-200 text-sm">
                    <i class="fas fa-triangle-exclamation mr-2"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="glass-card">
                <form method="GET" class="mb-5 pb-5 border-b border-slate-700">
                    <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
                    <label class="block text-slate-300 text-sm mb-1">Tipo de documento</label>
                    <select name="doc_key" onchange="this.form.submit()" class="<?= $inputCls ?>">
                        <option value="">Seleccionar...</option>
                        <?php
                            $porCategoria = [];
                            foreach ($templates as $t) {
                                $porCategoria[$t['category'] ?: 'Otros'][] = $t;
                            }
                        ?>
                        <?php foreach ($porCategoria as $cat => $items): ?>
                            <optgroup label="<?= htmlspecialchars($cat) ?>">
                                <?php foreach ($items as $t): ?>
                                    <option value="<?= htmlspecialchars($t['doc_key']) ?>" <?= $t['doc_key'] === $docKey ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if (!$template): ?>
                    <p class="text-slate-400 text-center py-8">
                        <i class="fas fa-file-lines text-3xl block mb-3 opacity-40"></i>
                        Elige el tipo de documento que quieres generar.
                    </p>
                <?php else: ?>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
                        <input type="hidden" name="doc_key" value="<?= htmlspecialchars($docKey) ?>">

                        <div class="p-3 rounded-lg bg-slate-800/60 border border-slate-700">
                            <p class="text-white text-sm font-semibold"><?= htmlspecialchars($template['name']) ?></p>
                            <p class="text-slate-400 text-xs mt-1">
                                <?php if ($template['render_mode'] === 'builtin'): ?>
                                    Formato aprobado con texto legal fijo. No se edita desde plantillas.
                                <?php else: ?>
                                    Se arma desde la plantilla editable.
                                    <a href="document_templates.php?doc_key=<?= htmlspecialchars($docKey) ?>" class="text-cyan-300 hover:underline">Editar formato</a>
                                <?php endif; ?>
                                <?php if ((int) $template['requires_signature'] === 1): ?>
                                    · Requiere firma del colaborador
                                <?php endif; ?>
                            </p>
                        </div>

                        <?php foreach ($extraDefs as $f): ?>
                            <div>
                                <label class="block text-slate-300 text-sm mb-1"><?= htmlspecialchars($f['label']) ?> *</label>
                                <textarea name="extra[<?= htmlspecialchars($f['key']) ?>]" rows="3" required
                                          class="<?= $inputCls ?>"><?= htmlspecialchars($_POST['extra'][$f['key']] ?? '') ?></textarea>
                            </div>
                        <?php endforeach; ?>

                        <label class="inline-flex items-center gap-2 text-slate-300 text-sm">
                            <input type="checkbox" name="file_to_expediente" value="1" checked class="w-4 h-4">
                            Archivar automáticamente en el expediente del colaborador
                        </label>

                        <div class="flex justify-end gap-2 pt-2">
                            <a href="employee_profile.php?id=<?= (int) $employeeId ?>" class="btn-secondary">Cancelar</a>
                            <button type="submit" class="btn-primary">
                                <i class="fas fa-file-pdf"></i> Generar PDF
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>
</body>
</html>

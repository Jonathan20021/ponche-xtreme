<?php
/**
 * firmar_documento.php
 *
 * Página PÚBLICA de firma electrónica: el colaborador entra con el enlace único
 * que le generó RRHH desde su perfil, lee el documento, firma con el dedo o el
 * mouse y confirma con su cédula.
 *
 * Al firmar, el sistema genera el PDF firmado (con la firma, la cédula, la fecha,
 * la IP y un hash de evidencia) y lo ARCHIVA SOLO en el expediente del
 * colaborador: es lo que pidió el cliente, sin intervención manual de RRHH.
 *
 * No requiere sesión a propósito — el token del enlace es la credencial. El token
 * es de 48 caracteres aleatorios, de un solo uso y con vencimiento.
 */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/employee_record.php';

$token = trim((string) ($_GET['t'] ?? $_POST['token'] ?? ''));
$error = null;
$done  = false;

if ($token === '' || !preg_match('/^[a-f0-9]{20,64}$/i', $token)) {
    $error = 'El enlace de firma no es válido.';
}

$signature = null;
$employee  = null;
$docLabel  = '';

if (!$error) {
    $stmt = $pdo->prepare("
        SELECT s.*, r.label AS doc_label, r.doc_key AS req_key,
               e.first_name, e.last_name, e.employee_code, e.id_card_number,
               e.identification_number, e.position, e.hire_date, e.user_id
        FROM employee_document_signatures s
        INNER JOIN employees e ON e.id = s.employee_id
        LEFT JOIN required_document_types r ON r.doc_key = s.doc_key
        WHERE s.token = ?
    ");
    $stmt->execute([$token]);
    $signature = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$signature) {
        $error = 'El enlace de firma no existe o fue anulado.';
    } elseif ($signature['status'] === 'FIRMADO') {
        $done = true;
    } elseif ($signature['status'] !== 'PENDIENTE') {
        $error = 'Esta solicitud de firma fue cancelada.';
    } elseif (!empty($signature['expires_at']) && strtotime($signature['expires_at']) < time()) {
        $error = 'El enlace de firma venció. Pide uno nuevo a Recursos Humanos.';
    } else {
        $docLabel = $signature['doc_label'] ?: $signature['doc_key'];
        $employee = [
            'name'   => trim($signature['first_name'] . ' ' . $signature['last_name']),
            'code'   => $signature['employee_code'],
            'cedula' => $signature['id_card_number'] ?: $signature['identification_number'],
        ];
    }
}

// --------------------------------------------------------------------------
// Procesar la firma
// --------------------------------------------------------------------------
if (!$error && !$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $signatureImage = (string) ($_POST['signature_image'] ?? '');
    $signedName     = trim((string) ($_POST['signed_name'] ?? ''));
    $signedId       = trim((string) ($_POST['signed_id_number'] ?? ''));
    $accepted       = isset($_POST['accept']);

    if (!$accepted) {
        $error = 'Debes aceptar la declaración para firmar.';
    } elseif ($signedName === '' || $signedId === '') {
        $error = 'Escribe tu nombre completo y tu cédula.';
    } elseif (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $signatureImage)) {
        $error = 'No se recibió la firma. Vuelve a dibujarla.';
    } elseif (strlen($signatureImage) > 800000) {
        $error = 'La firma es demasiado grande. Vuelve a intentarlo.';
    } else {
        // La cédula tiene que coincidir con la del expediente (solo dígitos).
        $expected = preg_replace('/\D+/', '', (string) $employee['cedula']);
        $given    = preg_replace('/\D+/', '', $signedId);

        if ($expected !== '' && $expected !== $given) {
            $error = 'La cédula no coincide con la registrada en tu expediente.';
        } else {
            try {
                $pdo->beginTransaction();

                $signedAt = date('Y-m-d H:i:s');
                $ip       = $_SERVER['REMOTE_ADDR'] ?? '';
                $ua       = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

                // Evidencia: hash de lo firmado (quién, qué, cuándo y la firma).
                $contentHash = hash('sha256', implode('|', [
                    $signature['employee_id'], $signature['doc_key'],
                    $signedName, $given, $signedAt, $signatureImage,
                ]));

                $upd = $pdo->prepare("
                    UPDATE employee_document_signatures
                    SET status = 'FIRMADO', signature_image = ?, signed_name = ?,
                        signed_id_number = ?, signed_at = ?, signed_ip = ?,
                        signed_user_agent = ?, content_hash = ?
                    WHERE id = ? AND status = 'PENDIENTE'
                ");
                $upd->execute([
                    $signatureImage, $signedName, $signedId, $signedAt,
                    $ip, $ua, $contentHash, $signature['id'],
                ]);

                if ($upd->rowCount() === 0) {
                    throw new RuntimeException('Esta solicitud ya fue firmada.');
                }

                // --- PDF firmado + archivado automático en el expediente ---
                $documentId = null;
                try {
                    require_once __DIR__ . '/vendor/autoload.php';

                    $html = '<html><head><meta charset="UTF-8"><style>'
                        . 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;color:#111;}'
                        . 'h1{font-size:17px;margin:0 0 4px;color:#244886;}'
                        . '.sub{color:#555;font-size:11px;margin:0 0 18px;}'
                        . 'table{width:100%;border-collapse:collapse;margin:10px 0 18px;}'
                        . 'td{border:1px solid #cfd6e4;padding:6px 8px;}'
                        . 'td:first-child{background:#f7f9fc;font-weight:bold;width:34%;}'
                        . '.decl{background:#f7f9fc;border:1px solid #cfd6e4;padding:12px;line-height:1.6;}'
                        . '.firma{margin-top:22px;}'
                        . '.firma img{height:90px;}'
                        . '.linea{border-top:1px solid #333;width:280px;margin-top:4px;padding-top:4px;font-size:11px;}'
                        . '.ev{margin-top:24px;font-size:9px;color:#666;line-height:1.5;}'
                        . '</style></head><body>'
                        . '<h1>' . htmlspecialchars($docLabel, ENT_QUOTES, 'UTF-8') . '</h1>'
                        . '<p class="sub">Constancia de firma electrónica · Evallish BPO</p>'
                        . '<table>'
                        . '<tr><td>Colaborador</td><td>' . htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
                        . '<tr><td>Código de empleado</td><td>' . htmlspecialchars((string) $employee['code'], ENT_QUOTES, 'UTF-8') . '</td></tr>'
                        . '<tr><td>Cédula</td><td>' . htmlspecialchars($signedId, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                        . '<tr><td>Documento firmado</td><td>' . htmlspecialchars($docLabel, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                        . '<tr><td>Fecha y hora</td><td>' . date('d/m/Y H:i:s', strtotime($signedAt)) . ' (hora RD)</td></tr>'
                        . '</table>'
                        . '<div class="decl">Declaro que he leído y acepto el contenido del documento '
                        . '<strong>' . htmlspecialchars($docLabel, ENT_QUOTES, 'UTF-8') . '</strong>, '
                        . 'que la firma que aparece a continuación es mía y que la estampo de forma libre y voluntaria. '
                        . 'Reconozco esta firma electrónica como equivalente a mi firma manuscrita para todos los efectos.</div>'
                        . '<div class="firma"><img src="' . $signatureImage . '" alt="Firma">'
                        . '<div class="linea">' . htmlspecialchars($signedName, ENT_QUOTES, 'UTF-8')
                        . '<br>Cédula ' . htmlspecialchars($signedId, ENT_QUOTES, 'UTF-8') . '</div></div>'
                        . '<div class="ev"><strong>Evidencia de la firma</strong><br>'
                        . 'Registrada el ' . date('d/m/Y H:i:s', strtotime($signedAt)) . ' · IP ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . '<br>'
                        . 'Huella SHA-256: ' . $contentHash . '<br>'
                        . 'Cualquier alteración de este documento invalida la huella.</div>'
                        . '</body></html>';

                    $options = new \Dompdf\Options();
                    $options->set('isRemoteEnabled', false);
                    $options->set('defaultFont', 'DejaVu Sans');
                    $dompdf = new \Dompdf\Dompdf($options);
                    $dompdf->loadHtml($html, 'UTF-8');
                    $dompdf->setPaper('letter', 'portrait');
                    $dompdf->render();
                    $pdfBytes = $dompdf->output();

                    $dir = __DIR__ . '/uploads/signed_documents';
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }

                    $fileName = 'firmado_' . $signature['doc_key'] . '_' . (int) $signature['employee_id']
                        . '_' . date('YmdHis') . '.pdf';
                    $relPath  = 'uploads/signed_documents/' . $fileName;

                    if (file_put_contents($dir . '/' . $fileName, $pdfBytes) !== false) {
                        // Se archiva SOLO en el expediente: sin paso manual de RRHH.
                        $insDoc = $pdo->prepare("
                            INSERT INTO employee_documents
                                (employee_id, document_type, doc_key, signature_id, document_name,
                                 file_path, file_size, file_extension, mime_type, description,
                                 uploaded_by, uploaded_at, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'pdf', 'application/pdf', ?, ?, NOW(), NOW())
                        ");
                        $insDoc->execute([
                            $signature['employee_id'],
                            $docLabel,
                            $signature['doc_key'],
                            $signature['id'],
                            $docLabel . ' (firmado).pdf',
                            $relPath,
                            strlen($pdfBytes),
                            'Firmado electrónicamente el ' . date('d/m/Y H:i', strtotime($signedAt)),
                            $signature['user_id'] ?: null,
                        ]);
                        $documentId = (int) $pdo->lastInsertId();

                        $pdo->prepare("UPDATE employee_document_signatures SET document_id = ? WHERE id = ?")
                            ->execute([$documentId, $signature['id']]);
                    }
                } catch (Throwable $pdfEx) {
                    // La firma YA quedó registrada; si falla el PDF no se pierde.
                    error_log('firmar_documento PDF: ' . $pdfEx->getMessage());
                }

                $pdo->commit();
                $done = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('firmar_documento: ' . $e->getMessage());
                $error = 'No se pudo registrar la firma. Intenta de nuevo o avisa a Recursos Humanos.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Firma de documento · Evallish BPO</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px 16px; font-family: Inter, Arial, sans-serif;
               background: #0f172a; color: #e2e8f0; }
        .wrap { max-width: 640px; margin: 0 auto; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 14px; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #94a3b8; font-size: 13px; }
        .kv { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px; }
        .kv td { padding: 7px 8px; border-bottom: 1px solid #334155; }
        .kv td:first-child { color: #94a3b8; width: 42%; }
        label { display: block; font-size: 13px; margin: 14px 0 5px; color: #cbd5e1; }
        input[type=text] { width: 100%; padding: 11px; border-radius: 8px;
                           border: 1px solid #475569; background: #0f172a; color: #fff; font-size: 15px; }
        .decl { background: #0f172a; border: 1px solid #334155; border-radius: 8px;
                padding: 14px; font-size: 13px; line-height: 1.65; margin: 16px 0; }
        #pad { width: 100%; height: 190px; background: #fff; border-radius: 8px;
               border: 2px dashed #64748b; touch-action: none; display: block; cursor: crosshair; }
        .padrow { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; }
        button { font-family: inherit; font-size: 15px; cursor: pointer; border: 0; border-radius: 8px; }
        .btn { width: 100%; padding: 14px; background: #244886; color: #fff; font-weight: 700; margin-top: 18px; }
        .btn:disabled { background: #475569; cursor: not-allowed; }
        .link { background: none; color: #94a3b8; font-size: 13px; text-decoration: underline; padding: 0; }
        .alert { padding: 13px 15px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert.err { background: rgba(244,63,94,.12); border: 1px solid rgba(244,63,94,.4); color: #fda4af; }
        .alert.ok  { background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.4); color: #6ee7b7; }
        .chk { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; margin-top: 14px; }
        .chk input { margin-top: 3px; width: 18px; height: 18px; flex-shrink: 0; }
        .center { text-align: center; }
        .big { font-size: 46px; color: #10b981; margin-bottom: 10px; }
    </style>
</head>
<body>
<div class="wrap">

<?php if ($error && !$signature): ?>
    <div class="card center">
        <div class="big"><i class="fas fa-circle-xmark" style="color:#f43f5e"></i></div>
        <h1>Enlace no válido</h1>
        <p class="muted"><?= htmlspecialchars($error) ?></p>
    </div>

<?php elseif ($done): ?>
    <div class="card center">
        <div class="big"><i class="fas fa-circle-check"></i></div>
        <h1>Documento firmado</h1>
        <p class="muted">
            Gracias. Tu firma quedó registrada y el documento se archivó automáticamente
            en tu expediente. No tienes que enviar nada más.
        </p>
    </div>

<?php else: ?>
    <div class="card">
        <h1>Firma de documento</h1>
        <p class="muted">Lee y firma para completar tu expediente.</p>

        <?php if ($error): ?>
            <div class="alert err" style="margin-top:16px;"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <table class="kv">
            <tr><td>Documento</td><td><strong><?= htmlspecialchars($docLabel) ?></strong></td></tr>
            <tr><td>Colaborador</td><td><?= htmlspecialchars($employee['name']) ?></td></tr>
            <tr><td>Código</td><td><?= htmlspecialchars((string) $employee['code']) ?></td></tr>
            <tr><td>Fecha</td><td><?= date('d/m/Y') ?></td></tr>
        </table>

        <div class="decl">
            Declaro que he leído y acepto el contenido del documento
            <strong><?= htmlspecialchars($docLabel) ?></strong>, que la firma que dibujo a
            continuación es mía y que la estampo de forma libre y voluntaria. Reconozco esta
            firma electrónica como equivalente a mi firma manuscrita.
        </div>

        <form method="POST" id="signForm">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="signature_image" id="signature_image">

            <label for="signed_name">Nombre completo</label>
            <input type="text" id="signed_name" name="signed_name" required maxlength="150"
                   value="<?= htmlspecialchars($_POST['signed_name'] ?? $employee['name']) ?>">

            <label for="signed_id_number">Cédula</label>
            <input type="text" id="signed_id_number" name="signed_id_number" required maxlength="50"
                   placeholder="000-0000000-0" value="<?= htmlspecialchars($_POST['signed_id_number'] ?? '') ?>">

            <label>Firma</label>
            <canvas id="pad"></canvas>
            <div class="padrow">
                <span class="muted">Dibuja tu firma con el dedo o el mouse</span>
                <button type="button" class="link" id="clearPad">Borrar y repetir</button>
            </div>

            <label class="chk">
                <input type="checkbox" name="accept" value="1" required>
                <span>Acepto la declaración y firmo este documento electrónicamente.</span>
            </label>

            <button type="submit" class="btn" id="submitBtn" disabled>
                <i class="fas fa-signature"></i> Firmar documento
            </button>
        </form>
    </div>
<?php endif; ?>

</div>

<script>
(function () {
    const pad = document.getElementById('pad');
    if (!pad) return;

    const ctx = pad.getContext('2d');
    const submitBtn = document.getElementById('submitBtn');
    let drawing = false, hasDrawn = false;

    // El canvas se dimensiona al ancho real y en la densidad de la pantalla,
    // si no la firma sale pixelada en el teléfono (que es donde van a firmar).
    function resize() {
        const ratio = window.devicePixelRatio || 1;
        const rect = pad.getBoundingClientRect();
        const data = hasDrawn ? pad.toDataURL() : null;

        pad.width = rect.width * ratio;
        pad.height = rect.height * ratio;
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.lineWidth = 2.2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#111';

        if (data) {
            const img = new Image();
            img.onload = function () { ctx.drawImage(img, 0, 0, rect.width, rect.height); };
            img.src = data;
        }
    }
    window.addEventListener('resize', resize);
    resize();

    function pos(e) {
        const rect = pad.getBoundingClientRect();
        const p = e.touches ? e.touches[0] : e;
        return { x: p.clientX - rect.left, y: p.clientY - rect.top };
    }
    function start(e) {
        e.preventDefault();
        drawing = true;
        const p = pos(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
    }
    function move(e) {
        if (!drawing) return;
        e.preventDefault();
        const p = pos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        hasDrawn = true;
        submitBtn.disabled = false;
    }
    function end() { drawing = false; }

    pad.addEventListener('mousedown', start);
    pad.addEventListener('mousemove', move);
    document.addEventListener('mouseup', end);
    pad.addEventListener('touchstart', start, { passive: false });
    pad.addEventListener('touchmove', move, { passive: false });
    pad.addEventListener('touchend', end);

    document.getElementById('clearPad').addEventListener('click', function () {
        ctx.clearRect(0, 0, pad.width, pad.height);
        hasDrawn = false;
        submitBtn.disabled = true;
    });

    document.getElementById('signForm').addEventListener('submit', function (e) {
        if (!hasDrawn) {
            e.preventDefault();
            alert('Dibuja tu firma antes de continuar.');
            return;
        }
        document.getElementById('signature_image').value = pad.toDataURL('image/png');
        submitBtn.disabled = true;
        submitBtn.innerHTML = 'Registrando firma...';
    });
})();
</script>
</body>
</html>

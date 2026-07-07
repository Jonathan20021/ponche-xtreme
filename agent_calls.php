<?php
/**
 * Portal del agente — "Mis Llamadas": el agente escucha las grabaciones de sus
 * propias llamadas de Vicidial (metadato en vicidial_recordings; audio vía el
 * proxy agent_recording.php). Solo conversaciones reales (>= duración mínima).
 */
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login_agent.php');
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/vicidial_recordings.php';
ensureVicidialRecordingsTable($pdo);

$userId = (int) $_SESSION['user_id'];
$minSeconds = vicidialRecordingsMinSeconds($pdo);

$date = (string) ($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$recsEnabled = vicidialRecordingsEnabled($pdo);

$recs = [];
$days = [];
if ($recsEnabled) {
    $st = $pdo->prepare("
        SELECT id, call_datetime, length_seconds, customer_phone, lead_id
        FROM vicidial_recordings
        WHERE user_id = ? AND call_date = ? AND length_seconds >= ?
        ORDER BY call_datetime DESC
    ");
    $st->execute([$userId, $date, $minSeconds]);
    $recs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $daysStmt = $pdo->prepare("
        SELECT call_date, COUNT(*) n, SUM(length_seconds) secs
        FROM vicidial_recordings
        WHERE user_id = ? AND length_seconds >= ?
        GROUP BY call_date ORDER BY call_date DESC LIMIT 21
    ");
    $daysStmt->execute([$userId, $minSeconds]);
    $days = $daysStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$totalSecs = 0;
foreach ($recs as $r) {
    $totalSecs += (int) $r['length_seconds'];
}

if (!function_exists('recFmtDur')) {
    function recFmtDur($s): string
    {
        $s = max(0, (int) $s);
        $m = intdiv($s, 60);
        $r = $s % 60;
        return $m . ':' . str_pad((string) $r, 2, '0', STR_PAD_LEFT);
    }
}
if (!function_exists('recFmtDurLong')) {
    function recFmtDurLong(int $s): string
    {
        $s = max(0, $s);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        return ($h > 0 ? $h . 'h ' : '') . $m . 'm';
    }
}
if (!function_exists('recMaskPhone')) {
    function recMaskPhone($p): string
    {
        $p = preg_replace('/\D/', '', (string) $p);
        if ($p === null || strlen($p) < 7) {
            return $p !== '' ? $p : '—';
        }
        return substr($p, 0, 3) . str_repeat('•', max(1, strlen($p) - 6)) . substr($p, -3);
    }
}

$theme = $_SESSION['theme'] ?? 'light';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
?>
<?php include __DIR__ . '/header_agent.php'; ?>

<div class="agent-dashboard">
    <div class="ag-pagehead">
        <div>
            <h1><i class="fas fa-headphones" style="color:var(--ag-brand);"></i> Mis Llamadas</h1>
            <p>Escucha las grabaciones de tus llamadas de <?= (int) $minSeconds ?> segundos o más.</p>
        </div>
        <div class="ag-head-actions">
            <span class="ag-chip"><i class="fas fa-shield-halved" style="color:var(--ag-green)"></i> Solo tú puedes oír tus llamadas</span>
            <form method="get" style="margin:0;">
                <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" class="ag-input" style="padding:9px 12px;" onchange="this.form.submit()">
            </form>
        </div>
    </div>

    <?php if (!$recsEnabled): ?>
        <div class="ag-card ag-sec">
            <div class="ag-empty-state"><i class="fas fa-microphone-slash"></i><p>Las grabaciones están deshabilitadas. Contacta a tu supervisor.</p></div>
        </div>
    <?php else: ?>
        <div class="ag-grid ag-mt" style="grid-template-columns:1.7fr .9fr; align-items:start;">
            <!-- Lista de grabaciones del día -->
            <div class="ag-card ag-sec">
                <div class="ag-sec-head">
                    <div>
                        <div class="ttl"><i class="fas fa-phone-volume"></i> <?= htmlspecialchars(date('d \d\e M \d\e Y', strtotime($date))) ?></div>
                        <div class="sub"><?= count($recs) ?> grabación<?= count($recs) === 1 ? '' : 'es' ?> · <?= recFmtDurLong($totalSecs) ?> en total</div>
                    </div>
                    <span class="ag-chip"><i class="fas fa-tower-broadcast" style="color:var(--ag-teal)"></i> Vicidial</span>
                </div>

                <?php if (empty($recs)): ?>
                    <div class="ag-empty-state" style="min-height:160px;">
                        <i class="fas fa-phone-slash"></i>
                        <p>No hay grabaciones de conversaciones para este día. Prueba otra fecha en el calendario o en la lista de la derecha.</p>
                    </div>
                <?php else: ?>
                    <div class="ag-calls" id="agCalls">
                        <?php foreach ($recs as $rec): ?>
                            <div class="ag-call" data-call>
                                <div class="ag-call-head">
                                    <span class="ag-call-time"><i class="fas fa-clock"></i> <?= htmlspecialchars(date('g:i A', strtotime($rec['call_datetime']))) ?></span>
                                    <span class="ag-call-dur"><?= recFmtDur($rec['length_seconds']) ?></span>
                                    <span class="ag-call-phone"><i class="fas fa-user"></i> <?= htmlspecialchars(recMaskPhone($rec['customer_phone'])) ?></span>
                                </div>
                                <audio class="ag-call-audio" controls preload="none">
                                    <source src="agent_recording.php?id=<?= (int) $rec['id'] ?>" type="audio/mpeg">
                                    Tu navegador no soporta el reproductor de audio.
                                </audio>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="ag-pager" id="callsPager" style="display:none; margin-top:16px;">
                        <button type="button" class="ag-pager-btn" data-page="prev" aria-label="Anterior"><i class="fas fa-chevron-left"></i></button>
                        <span class="ag-pager-info">Página <b id="callsNow">1</b> de <span id="callsTot">1</span></span>
                        <button type="button" class="ag-pager-btn" data-page="next" aria-label="Siguiente"><i class="fas fa-chevron-right"></i></button>
                    </div>
                <?php endif; ?>
                <p class="ag-hint" style="margin-top:14px;"><i class="fas fa-circle-info" style="margin-right:5px;"></i>Escucha tus llamadas para mejorar. Estas grabaciones son privadas: solo tú y tu supervisor pueden oírlas.</p>
            </div>

            <!-- Días recientes con grabaciones -->
            <div class="ag-card ag-sec">
                <div class="ag-sec-head"><div class="ttl"><i class="fas fa-calendar-days"></i> Días recientes</div></div>
                <?php if (empty($days)): ?>
                    <div class="ag-empty-state" style="min-height:120px;"><i class="fas fa-calendar-xmark"></i><p>Aún no hay grabaciones registradas.</p></div>
                <?php else: ?>
                    <div class="ag-daylist">
                        <?php foreach ($days as $d): ?>
                            <?php $isSel = $d['call_date'] === $date; ?>
                            <a href="?date=<?= htmlspecialchars($d['call_date']) ?>" class="ag-day<?= $isSel ? ' active' : '' ?>">
                                <span class="ag-day-date"><?= htmlspecialchars(date('D d/m', strtotime($d['call_date']))) ?></span>
                                <span class="ag-day-meta"><?= (int) $d['n'] ?> llam · <?= recFmtDurLong((int) $d['secs']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Paginación de grabaciones (12 por página); pausa audios al cambiar de página.
(function () {
    var wrap = document.getElementById('agCalls');
    var pager = document.getElementById('callsPager');
    if (!wrap || !pager) { return; }
    var cards = Array.prototype.slice.call(wrap.querySelectorAll('[data-call]'));
    var per = 12, page = 1, pages = Math.ceil(cards.length / per);
    if (pages <= 1) { return; }
    var nowEl = document.getElementById('callsNow'), totEl = document.getElementById('callsTot');
    var prev = pager.querySelector('[data-page="prev"]'), next = pager.querySelector('[data-page="next"]');
    totEl.textContent = pages;
    pager.style.display = 'flex';
    function render() {
        cards.forEach(function (c, i) {
            var vis = (i >= (page - 1) * per && i < page * per);
            c.style.display = vis ? '' : 'none';
            if (!vis) { var a = c.querySelector('audio'); if (a && !a.paused) { a.pause(); } }
        });
        nowEl.textContent = page;
        prev.disabled = (page === 1);
        next.disabled = (page === pages);
    }
    prev.addEventListener('click', function () { if (page > 1) { page--; render(); } });
    next.addEventListener('click', function () { if (page < pages) { page++; render(); } });
    render();
})();
// Pausa las demás al reproducir una (solo una a la vez).
document.addEventListener('play', function (e) {
    document.querySelectorAll('audio').forEach(function (a) { if (a !== e.target) { a.pause(); } });
}, true);
</script>

</body>
</html>

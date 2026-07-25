<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/work_hours_calculator.php';

// Minimal self-contained assertions (no phpunit dependency)
function assertEqual($expected, $actual, $label) {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: $label\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "PASS: $label\n";
}

// Paid slugs (already normalized by system)
$paid = ['DISPONIBLE', 'WASAPI', 'DIGITACION'];

// Case 1: Paid -> Break -> Paid (deltas attributed to current state)
$punches = [
    ['type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:00:00'],
    ['type' => 'BREAK',      'timestamp' => '2025-12-01 09:30:00'],
    ['type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:45:00'],
    ['type' => 'EXIT',       'timestamp' => '2025-12-01 10:15:00'],
];
$res = calculateWorkSecondsFromPunches($punches, $paid);
// Paid time: 09:00->09:30 (1800) + 09:45->10:15 (1800) = 3600
assertEqual(3600, $res['work_seconds'], 'paid with break in-between');
assertEqual(900, $res['durations_all']['BREAK'] ?? 0, 'break duration in case 1');

// Case 2: Only paid punches, ensure we count between paid and next paid
$punches2 = [
    ['type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:00:00'],
    ['type' => 'WASAPI',     'timestamp' => '2025-12-01 10:00:00'],
    ['type' => 'EXIT',       'timestamp' => '2025-12-01 11:00:00'],
];
$res2 = calculateWorkSecondsFromPunches($punches2, $paid);
// 09:00->10:00 attributed to DISPONIBLE (paid) = 3600
// 10:00->11:00 attributed to WASAPI (paid) = 3600
assertEqual(7200, $res2['work_seconds'], 'consecutive paid types');

// Case 3: BREAK with intermediate BA_NO absorbed (Amberly 2026-05-20 pattern).
// Supervisor moved DISPONIBLE@13:19:12 -> 13:26:00 to extend the visible break,
// but a BA_NO at 13:15:47 was sitting between the BREAK and the moved DISPONIBLE.
// Expected: BREAK absorbs the BA_NO that's inside its pause window. The two
// DISPONIBLE punches at 13:19 and 13:26 have INVERTED ids (lower id has later
// timestamp = supervisor's edit), so dedup keeps the edited one (13:26:00).
$punches3 = [
    ['id' => 64575, 'type' => 'ENTRY',      'timestamp' => '2026-05-20 06:00:11'],
    ['id' => 64577, 'type' => 'DISPONIBLE', 'timestamp' => '2026-05-20 06:00:13'],
    ['id' => 64713, 'type' => 'BA_NO',      'timestamp' => '2026-05-20 11:21:51'],
    ['id' => 64717, 'type' => 'DISPONIBLE', 'timestamp' => '2026-05-20 11:25:31'],
    ['id' => 64759, 'type' => 'BREAK',      'timestamp' => '2026-05-20 12:32:13'],
    ['id' => 64787, 'type' => 'BA_NO',      'timestamp' => '2026-05-20 13:15:47'],
    ['id' => 64788, 'type' => 'DISPONIBLE', 'timestamp' => '2026-05-20 13:19:12'], // original
    ['id' => 64786, 'type' => 'DISPONIBLE', 'timestamp' => '2026-05-20 13:26:00'], // supervisor-edited (lower id, later ts)
    ['id' => 64834, 'type' => 'BA_NO',      'timestamp' => '2026-05-20 14:30:03'],
    ['id' => 64838, 'type' => 'DISPONIBLE', 'timestamp' => '2026-05-20 14:34:24'],
    ['id' => 64861, 'type' => 'EXIT',       'timestamp' => '2026-05-20 15:00:58'],
];
$res3 = calculateWorkSecondsFromPunches($punches3, $paid);
// BREAK: 12:32:13 -> 13:26:00 = 3227 sec (53m47s, the supervisor's intent)
assertEqual(3227, $res3['durations_all']['BREAK'] ?? 0, "amberly: BREAK absorbs interior BA_NO and dedup'd DISPONIBLE");
// BA_NO: only the two outside the BREAK window count (3m40s + 4m21s = 481 sec)
assertEqual(481, $res3['durations_all']['BA_NO'] ?? 0, 'amberly: BA_NO excludes interior baño');
// ENTRY pause window: 2 sec
assertEqual(2, $res3['durations_all']['ENTRY'] ?? 0, 'amberly: entry duration');
// DISPONIBLE paid time: 7h58m57s = 28737 sec
assertEqual(28737, $res3['durations_all']['DISPONIBLE'] ?? 0, 'amberly: total disponible paid time');
assertEqual(28737, $res3['work_seconds'], 'amberly: total work seconds');
// Sanity: total accounted time = full day span (06:00:11 -> 15:00:58 = 32447 sec)
$totalAccounted = array_sum($res3['durations_all']);
assertEqual(32447, $totalAccounted, 'amberly: durations sum equals entry-to-exit span');

// Case 4a: Consecutive same-paid WITH inverted ids (supervisor edit).
// Lower id has later timestamp → drop the higher-id "phantom".
$punches4a = [
    ['id' => 200, 'type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:00:00'], // phantom (higher id, earlier ts)
    ['id' => 100, 'type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:15:00'], // edited (lower id, later ts)
    ['id' => 300, 'type' => 'EXIT',       'timestamp' => '2025-12-01 10:00:00'],
];
$res4a = calculateWorkSecondsFromPunches($punches4a, $paid);
// After dedup: DISPONIBLE@09:15 -> EXIT@10:00. Paid = 45 min = 2700s.
assertEqual(2700, $res4a['work_seconds'], 'edit-induced same-paid dedup keeps later timestamp');

// Case 4b: Consecutive same-paid WITHOUT inverted ids (legitimate re-punch, no edit).
// Ids and timestamps both ascend → both punches kept, no dedup.
$punches4b = [
    ['id' => 100, 'type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:00:00'],
    ['id' => 101, 'type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:15:00'], // legit re-punch
    ['id' => 102, 'type' => 'EXIT',       'timestamp' => '2025-12-01 10:00:00'],
];
$res4b = calculateWorkSecondsFromPunches($punches4b, $paid);
// No dedup: 09:00->09:15 (900s) + 09:15->10:00 (2700s) = 3600s
assertEqual(3600, $res4b['work_seconds'], 'legitimate consecutive same-paid (natural id order) keeps both');

// Case 4c: Consecutive same-paid WITHOUT ids — defaults to "no dedup" for safety.
// (Callers that do not pass id keep the legacy summed behavior for paid duplicates.)
$punches4c = [
    ['type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:00:00'],
    ['type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:15:00'],
    ['type' => 'EXIT',       'timestamp' => '2025-12-01 10:00:00'],
];
$res4c = calculateWorkSecondsFromPunches($punches4c, $paid);
assertEqual(3600, $res4c['work_seconds'], 'no-id same-paid: legacy-compatible (no dedup)');

// Case 5: Agent went on break and never returned before EXIT.
// The trailing non-paid window must close against the LAST event (EXIT here),
// so BREAK gets credit for the full duration.
$punchesNoReturn = [
    ['type' => 'ENTRY',      'timestamp' => '2025-12-01 09:00:00'],
    ['type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:00:05'],
    ['type' => 'BREAK',      'timestamp' => '2025-12-01 12:00:00'],
    ['type' => 'EXIT',       'timestamp' => '2025-12-01 17:00:00'],
];
$resNoReturn = calculateWorkSecondsFromPunches($punchesNoReturn, $paid);
assertEqual(18000, $resNoReturn['durations_all']['BREAK'] ?? 0, 'trailing pause closes against EXIT (5h)');
assertEqual(10795, $resNoReturn['work_seconds'], 'work_seconds for incomplete-day case');

// Case 6: Normal flow (no edits, no sub-pauses) — should match the legacy algorithm.
$punches5 = [
    ['type' => 'ENTRY',      'timestamp' => '2025-12-01 09:00:00'],
    ['type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 09:00:05'],
    ['type' => 'BREAK',      'timestamp' => '2025-12-01 12:00:00'],
    ['type' => 'DISPONIBLE', 'timestamp' => '2025-12-01 12:15:00'],
    ['type' => 'EXIT',       'timestamp' => '2025-12-01 17:00:00'],
];
$res5 = calculateWorkSecondsFromPunches($punches5, $paid);
// DISPONIBLE: 09:00:05 -> 12:00:00 (10795s) + 12:15:00 -> 17:00:00 (17100s) = 27895s
assertEqual(27895, $res5['work_seconds'], 'normal flow unchanged');
assertEqual(900, $res5['durations_all']['BREAK'] ?? 0, 'normal flow break = 15min');

// Case 7: Weekly overtime threshold — a long day does not create overtime
// unless the ISO week exceeds 44 paid hours.
$weekly42 = splitWeeklyRegularOvertimeSeconds([
    '2026-06-08' => 8 * 3600,
    '2026-06-09' => 8 * 3600,
    '2026-06-10' => 8 * 3600,
    '2026-06-11' => 8 * 3600,
    '2026-06-12' => 10 * 3600,
], 44 * 3600);
assertEqual(42 * 3600, $weekly42['by_week']['2026-W24']['regular_seconds'], 'weekly threshold: 42h all regular');
assertEqual(0, $weekly42['by_week']['2026-W24']['overtime_seconds'], 'weekly threshold: 42h no overtime');

$weekly47 = splitWeeklyRegularOvertimeSeconds([
    '2026-06-08' => 8 * 3600,
    '2026-06-09' => 8 * 3600,
    '2026-06-10' => 8 * 3600,
    '2026-06-11' => 8 * 3600,
    '2026-06-12' => 15 * 3600,
], 44 * 3600);
assertEqual(44 * 3600, $weekly47['by_week']['2026-W24']['regular_seconds'], 'weekly threshold: 47h caps regular at 44h');
assertEqual(3 * 3600, $weekly47['by_week']['2026-W24']['overtime_seconds'], 'weekly threshold: 47h yields 3h overtime');

// Case 8: Payroll period starts mid-week. Prior days in the same ISO week
// consume the 44-hour regular bank, but only in-period days are paid.
$midWeek = splitWeeklyRegularOvertimeSeconds([
    '2026-06-08' => 20 * 3600,
    '2026-06-09' => 20 * 3600,
    '2026-06-10' => 8 * 3600,
], 44 * 3600);
assertEqual(4 * 3600, $midWeek['by_day']['2026-06-10']['regular_seconds'], 'mid-week period: first 4h remain regular');
assertEqual(4 * 3600, $midWeek['by_day']['2026-06-10']['overtime_seconds'], 'mid-week period: remaining 4h become overtime');

// ---------------------------------------------------------------------------
// computeStateSegments(): la línea de tiempo del histórico de disposiciones del
// monitor. INVARIANTE CLAVE: la suma de los segmentos pagados tiene que dar
// exactamente el mismo work_seconds que paga la nómina, y la suma por slug tiene
// que coincidir con durations_all. Si alguien toca una de las dos máquinas de
// estados sin la otra, esto falla aquí y no en el pago de alguien.
// ---------------------------------------------------------------------------
function assertSegmentsMatchCanonical(array $punches, array $paid, string $label): void
{
    $canon = calculateWorkSecondsFromPunches($punches, $paid);
    $segs  = computeStateSegments($punches, $paid);

    $segPaid = 0;
    $bySlug = [];
    foreach ($segs as $seg) {
        if ($seg['is_paid']) {
            $segPaid += $seg['seconds'];
        }
        $bySlug[$seg['slug']] = ($bySlug[$seg['slug']] ?? 0) + $seg['seconds'];
    }

    assertEqual((int) $canon['work_seconds'], $segPaid, "$label: total pagado de segmentos = nómina");
    foreach ($canon['durations_all'] as $slug => $seconds) {
        assertEqual((int) $seconds, (int) ($bySlug[$slug] ?? 0), "$label: duración de $slug coincide");
    }
}

// Jornada normal: ENTRY marcador + dos tramos con BREAK en medio
$segDay = [
    ['id' => 1, 'type' => 'ENTRY',      'timestamp' => '2026-07-24 08:01:11'],
    ['id' => 2, 'type' => 'DISPONIBLE', 'timestamp' => '2026-07-24 08:01:29'],
    ['id' => 3, 'type' => 'BA_NO',      'timestamp' => '2026-07-24 09:58:25'],
    ['id' => 4, 'type' => 'DISPONIBLE', 'timestamp' => '2026-07-24 10:01:48'],
    ['id' => 5, 'type' => 'DISPONIBLE', 'timestamp' => '2026-07-24 12:12:56'],
    ['id' => 6, 'type' => 'BREAK',      'timestamp' => '2026-07-24 12:13:34'],
    ['id' => 7, 'type' => 'DISPONIBLE', 'timestamp' => '2026-07-24 13:01:12'],
    ['id' => 8, 'type' => 'EXIT',       'timestamp' => '2026-07-24 17:02:11'],
];
assertSegmentsMatchCanonical($segDay, $paid, 'jornada normal');

$segs = computeStateSegments($segDay, $paid);
// Los tramos pagados consecutivos del mismo slug se unen: 08:01:29 -> 09:58:25,
// 10:01:48 -> 12:13:34 (la DISPONIBLE de 12:12:56 no parte el tramo) y
// 13:01:12 -> 17:02:11. Con el ENTRY inicial y las dos pausas: 6 tramos.
assertEqual(6, count($segs), 'jornada normal: 6 tramos');
assertEqual('ENTRY', $segs[0]['slug'], 'jornada normal: arranca en ENTRY');
assertEqual(false, $segs[0]['is_paid'], 'jornada normal: ENTRY no es pagado');
assertEqual('DISPONIBLE', $segs[1]['slug'], 'jornada normal: 2.º tramo es DISPONIBLE');
assertEqual(true, $segs[1]['is_paid'], 'jornada normal: DISPONIBLE es pagado');
assertEqual(2858, $segs[4]['seconds'], 'jornada normal: BREAK dura 47m 38s');
assertEqual(false, $segs[5]['is_open'], 'jornada normal: día cerrado, sin tramo en curso');

// Sub-pausa dentro de una pausa: la externa la absorbe (igual que la nómina)
$segNested = [
    ['id' => 1, 'type' => 'DISPONIBLE', 'timestamp' => '2026-07-24 09:00:00'],
    ['id' => 2, 'type' => 'BREAK',      'timestamp' => '2026-07-24 10:00:00'],
    ['id' => 3, 'type' => 'BA_NO',      'timestamp' => '2026-07-24 10:15:00'],
    ['id' => 4, 'type' => 'DISPONIBLE', 'timestamp' => '2026-07-24 10:45:00'],
    ['id' => 5, 'type' => 'EXIT',       'timestamp' => '2026-07-24 11:45:00'],
];
assertSegmentsMatchCanonical($segNested, $paid, 'sub-pausa anidada');
$segsNested = computeStateSegments($segNested, $paid);
assertEqual(3, count($segsNested), 'sub-pausa anidada: 3 tramos (el BA_NO no abre uno propio)');
assertEqual('BREAK', $segsNested[1]['slug'], 'sub-pausa anidada: el tramo del medio es BREAK');
assertEqual(2700, $segsNested[1]['seconds'], 'sub-pausa anidada: BREAK absorbe los 45 min completos');

// Día en curso: el último estado se extiende hasta "ahora" y se marca is_open
$openStart = strtotime('2026-07-24 08:00:00');
$segOpen = [
    ['id' => 1, 'type' => 'ENTRY',      'timestamp' => date('Y-m-d H:i:s', $openStart)],
    ['id' => 2, 'type' => 'DISPONIBLE', 'timestamp' => date('Y-m-d H:i:s', $openStart + 60)],
];
$nowTs = $openStart + 3660; // una hora después de ponerse DISPONIBLE
$segsOpen = computeStateSegments($segOpen, $paid, $nowTs);
assertEqual(2, count($segsOpen), 'día en curso: 2 tramos');
assertEqual(true, $segsOpen[1]['is_open'], 'día en curso: el último tramo queda abierto');
assertEqual(3600, $segsOpen[1]['seconds'], 'día en curso: DISPONIBLE lleva 1h hasta ahora');
assertEqual(true, $segsOpen[1]['is_paid'], 'día en curso: el tramo abierto es pagado');

// Tras EXIT no se extiende nada, aunque se pase $nowTs
$segsClosed = computeStateSegments($segDay, $paid, strtotime('2026-07-24 20:00:00'));
$lastClosed = $segsClosed[count($segsClosed) - 1];
assertEqual(false, $lastClosed['is_open'], 'tras EXIT no se abre tramo en vivo');

// normalizePaidTypeSlugs(): sin esto, unos slugs sin normalizar dan 0 horas
assertEqual(['DISPONIBLE', 'BA_NO'], normalizePaidTypeSlugs(['disponible', ' Ba-No ']), 'normalizePaidTypeSlugs normaliza y deduplica');
assertEqual(['DISPONIBLE'], normalizePaidTypeSlugs(['DISPONIBLE', 'disponible', '']), 'normalizePaidTypeSlugs quita duplicados y vacíos');

// resolvePaymentType(): decide si el colaborador cobra sueldo mensual ('fixed') o
// por hora ('hourly'), con la misma regla que aplica la nómina. Los reportes de
// horas lo usan para mostrar el valor mensual en vez de horas × tarifa.
assertEqual('fixed',  resolvePaymentType('fixed', 'HR', 28000.0), 'compensation_type fixed manda');
assertEqual('fixed',  resolvePaymentType('fixed', 'HR', 0.0), 'fixed se respeta aunque no haya monto cargado');
assertEqual('hourly', resolvePaymentType('hourly', 'QA', 0.0), 'hourly sin sueldo mensual es por hora');
// Caso real: varios administrativos quedaron marcados 'hourly' pero tienen sueldo
// mensual cargado; la nómina los trata como fijos y el reporte tiene que igualarla.
assertEqual('fixed',  resolvePaymentType('hourly', 'SUPERVISOR', 25000.0), 'no-agente con sueldo mensual es fijo aunque diga hourly');
assertEqual('fixed',  resolvePaymentType('', 'HR', 20000.0), 'compensation_type vacío + sueldo mensual = fijo');
// Un agente con sueldo mensual cargado sigue siendo por hora: se le paga por ponche.
assertEqual('hourly', resolvePaymentType('hourly', 'AGENT', 20000.0), 'el agente siempre es por hora');
assertEqual('hourly', resolvePaymentType(null, 'AGENT', 0.0), 'sin datos, por hora');

echo "All tests passed.\n";

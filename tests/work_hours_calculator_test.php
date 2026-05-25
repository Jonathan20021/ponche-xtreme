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

echo "All tests passed.\n";

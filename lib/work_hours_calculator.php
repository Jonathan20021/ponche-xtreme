<?php
/**
 * Work hours calculator (single source of truth)
 *
 * Goal: make Records (/records), Daily Attendance Report, and Payroll match.
 *
 * Contract:
 * - Input: ordered punches for a single user and single day (timestamp + type)
 * - Paid types: array of slugs (already sanitized to match attendance.type normalization)
 * - Output: associative array with seconds worked in paid states + durations per slug
 *
 * Algorithm:
 * 1. Sort events by timestamp.
 * 2. Deduplicate consecutive same-paid-type events: keep the LATER timestamp.
 *    This honors supervisor edits where a paid punch was moved forward to extend
 *    a preceding pause window (e.g., DISPONIBLE@13:19 -> DISPONIBLE@13:26 collapses
 *    to a single DISPONIBLE@13:26 — the "real" return-to-work after the edit).
 * 3. Walk events left-to-right. Track an open pause window:
 *    - When a non-paid event is seen with no pause open: open a window at that
 *      timestamp, tag it with that event's slug (first-wins).
 *    - When a non-paid event is seen with a pause already open: do nothing
 *      (the in-progress pause absorbs it — e.g., a BA_NO during a BREAK counts
 *      toward the BREAK total, not BA_NO).
 *    - When a paid event closes the pause: attribute (paid.ts - pauseStart) to
 *      the open pause's slug, then count (next.ts - paid.ts) toward the paid slug.
 */

/**
 * @param array<int, array{type:string,timestamp:string|int,id?:int}> $punches
 * @param string[] $paidTypeSlugs slugs should be normalized with sanitizeAttendanceTypeSlug()
 * @return array{work_seconds:int, durations_all:array<string,int>}
 */
function calculateWorkSecondsFromPunches(array $punches, array $paidTypeSlugs): array
{
    $events = [];
    foreach ($punches as $row) {
        $slug = sanitizeAttendanceTypeSlug($row['type'] ?? '');
        if ($slug === '') {
            continue;
        }
        $ts = is_int($row['timestamp'] ?? null) ? $row['timestamp'] : strtotime((string)($row['timestamp'] ?? ''));
        if ($ts === false || $ts === null) {
            continue;
        }
        $event = ['slug' => $slug, 'timestamp' => (int)$ts];
        if (isset($row['id'])) {
            $event['id'] = (int) $row['id'];
        }
        $events[] = $event;
    }

    usort($events, static fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

    $durationsAll = computeDurationsWithPauseWindows($events, $paidTypeSlugs);

    $workSeconds = 0;
    foreach ($paidTypeSlugs as $paidSlug) {
        if (isset($durationsAll[$paidSlug])) {
            $workSeconds += (int)$durationsAll[$paidSlug];
        }
    }

    return [
        'work_seconds' => max(0, (int)$workSeconds),
        'durations_all' => $durationsAll,
    ];
}

/**
 * @param array<int, array{type:string,timestamp:string|int,id?:int,work_date?:string}> $punches
 * @param string[] $paidTypeSlugs
 * @return array<string,int> date (Y-m-d) => paid work seconds
 */
function calculateDailyWorkSecondsFromPunchRows(array $punches, array $paidTypeSlugs): array
{
    $byDate = [];
    foreach ($punches as $row) {
        $date = $row['work_date'] ?? null;
        if (!$date) {
            $ts = is_int($row['timestamp'] ?? null) ? $row['timestamp'] : strtotime((string)($row['timestamp'] ?? ''));
            if ($ts === false || $ts === null) {
                continue;
            }
            $date = date('Y-m-d', (int)$ts);
        }
        $byDate[$date][] = $row;
    }

    $dailyWorkSeconds = [];
    foreach ($byDate as $date => $dayPunches) {
        $calc = calculateWorkSecondsFromPunches($dayPunches, $paidTypeSlugs);
        $workSeconds = (int)($calc['work_seconds'] ?? 0);
        if ($workSeconds > 0) {
            $dailyWorkSeconds[$date] = $workSeconds;
        }
    }

    ksort($dailyWorkSeconds);
    return $dailyWorkSeconds;
}

function getIsoWeekStartDate(string $date): string
{
    $dt = new DateTimeImmutable($date);
    $dayOfWeek = (int)$dt->format('N');
    return $dt->modify('-' . ($dayOfWeek - 1) . ' days')->format('Y-m-d');
}

function getIsoWeekEndDate(string $date): string
{
    return (new DateTimeImmutable(getIsoWeekStartDate($date)))->modify('+6 days')->format('Y-m-d');
}

/**
 * Splits actual paid work seconds into regular/overtime by ISO week.
 * Regular time resets every Monday and caps at 44 hours per week.
 *
 * @param array<string,int> $dailyWorkSeconds date (Y-m-d) => actual paid work seconds
 * @return array{by_day:array<string,array{work_seconds:int,regular_seconds:int,overtime_seconds:int,week_key:string}>,by_week:array<string,array{work_seconds:int,regular_seconds:int,overtime_seconds:int}>}
 */
function splitWeeklyRegularOvertimeSeconds(array $dailyWorkSeconds, int $weeklyThresholdSeconds = 158400): array
{
    ksort($dailyWorkSeconds);

    $byDay = [];
    $byWeek = [];
    $weekUsedSeconds = [];
    $weeklyThresholdSeconds = max(0, $weeklyThresholdSeconds);

    foreach ($dailyWorkSeconds as $date => $workSeconds) {
        $workSeconds = max(0, (int)$workSeconds);
        if ($workSeconds <= 0) {
            continue;
        }

        $dt = new DateTimeImmutable($date);
        $weekKey = $dt->format('o-\WW');
        $used = (int)($weekUsedSeconds[$weekKey] ?? 0);
        $regularRemaining = max(0, $weeklyThresholdSeconds - $used);
        $regularSeconds = min($workSeconds, $regularRemaining);
        $overtimeSeconds = $workSeconds - $regularSeconds;

        $weekUsedSeconds[$weekKey] = $used + $workSeconds;
        $byDay[$date] = [
            'work_seconds' => $workSeconds,
            'regular_seconds' => $regularSeconds,
            'overtime_seconds' => $overtimeSeconds,
            'week_key' => $weekKey,
        ];

        if (!isset($byWeek[$weekKey])) {
            $byWeek[$weekKey] = [
                'work_seconds' => 0,
                'regular_seconds' => 0,
                'overtime_seconds' => 0,
            ];
        }
        $byWeek[$weekKey]['work_seconds'] += $workSeconds;
        $byWeek[$weekKey]['regular_seconds'] += $regularSeconds;
        $byWeek[$weekKey]['overtime_seconds'] += $overtimeSeconds;
    }

    return [
        'by_day' => $byDay,
        'by_week' => $byWeek,
    ];
}

/**
 * Computes per-slug durations from a list of events already sorted by timestamp.
 *
 * Handles the two cases that the naïve "delta to next event" approach gets wrong:
 *  - Supervisor-edited "phantom" paid punches: when two consecutive same-paid
 *    events appear AND their ID order is inverted relative to timestamp order
 *    (lower ID has the LATER timestamp), the lower-ID record was edited forward,
 *    so the higher-ID original is a phantom and gets dropped. Without ID
 *    inversion the two punches are legitimate (e.g. agent re-affirmed status)
 *    and both are kept.
 *  - Sub-pauses (e.g. BA_NO) punched in the middle of an outer pause (e.g. BREAK).
 *    The outer-pause slug absorbs the sub-pause time, matching what supervisors
 *    expect when they extend a paid punch's timestamp past intermediate pauses.
 *
 * @param array<int, array{slug:string,timestamp:int,id?:int}> $sortedEvents timestamps ASC
 * @param string[] $paidTypeSlugs
 * @return array<string,int>
 */
function computeDurationsWithPauseWindows(array $sortedEvents, array $paidTypeSlugs): array
{
    $paidSet = [];
    foreach ($paidTypeSlugs as $slug) {
        $paidSet[$slug] = true;
    }

    // Pass 1: drop the phantom of any edit-induced same-paid duplicate.
    // Only trigger when both have an `id` AND the IDs are inverted vs the
    // timestamp order (lower ID has the later timestamp = edited forward).
    $events = [];
    foreach ($sortedEvents as $event) {
        $lastIdx = count($events) - 1;
        if ($lastIdx >= 0
            && isset($paidSet[$event['slug']])
            && $events[$lastIdx]['slug'] === $event['slug']
            && isset($event['id'], $events[$lastIdx]['id'])
        ) {
            $prevId = (int) $events[$lastIdx]['id'];
            $curId  = (int) $event['id'];
            // Inputs are sorted by timestamp ASC. If the LATER-timestamp event
            // has the LOWER id, its timestamp was edited forward and the OTHER
            // event is the phantom — keep only the edited one (lower id, later ts).
            if ($curId > $prevId) {
                // Natural order: lower id, earlier ts → higher id, later ts.
                // No inversion; both punches are legitimate, keep both.
                $events[] = $event;
            } else {
                // Inverted: keep the later-timestamp event (current), drop previous.
                $events[$lastIdx] = $event;
            }
        } else {
            $events[] = $event;
        }
    }

    $durationsAll = [];
    $count = count($events);
    if ($count < 2) {
        return $durationsAll;
    }

    // Pass 2: walk events, open/close pause windows on paid boundaries.
    $pauseStart = null;
    $pauseSlug = null;

    for ($i = 0; $i < $count; $i++) {
        $event = $events[$i];
        $isPaid = isset($paidSet[$event['slug']]);

        if ($isPaid) {
            if ($pauseStart !== null) {
                $delta = $event['timestamp'] - $pauseStart;
                if ($delta > 0) {
                    $durationsAll[$pauseSlug] = ($durationsAll[$pauseSlug] ?? 0) + $delta;
                }
                $pauseStart = null;
                $pauseSlug = null;
            }
            if ($i + 1 < $count) {
                $delta = $events[$i + 1]['timestamp'] - $event['timestamp'];
                if ($delta > 0) {
                    $durationsAll[$event['slug']] = ($durationsAll[$event['slug']] ?? 0) + $delta;
                }
            }
        } else {
            if ($pauseStart === null) {
                $pauseStart = $event['timestamp'];
                $pauseSlug = $event['slug'];
            }
            // else: in-progress pause window absorbs this event's time (no-op).
        }
    }

    // If the day ends inside a pause window (e.g. agent never punched back from
    // BREAK before EXIT), close it against the LAST event so the pause slug
    // still gets credit for the full trailing duration.
    if ($pauseStart !== null) {
        $lastTs = $events[$count - 1]['timestamp'];
        $delta = $lastTs - $pauseStart;
        if ($delta > 0) {
            $durationsAll[$pauseSlug] = ($durationsAll[$pauseSlug] ?? 0) + $delta;
        }
    }

    return $durationsAll;
}

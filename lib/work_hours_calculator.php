<?php
/**
 * Work hours calculator (single source of truth)
 *
 * Goal: make Records (/records), Daily Attendance Report, and Payroll match.
 *
 * Contract:
 * - Input: ordered punches for a single user and single day (timestamp + type)
 * - Paid types: array of slugs (already sanitized to match attendance.type normalization)
 * - Output: associative array with seconds worked in paid states (and optionally open-state handling)
 *
 * Notes:
 * - This matches the interval algorithm already used in records.php and download_daily_attendance_report.php:
 *   sum duration between consecutive punches by attributing delta to the *current* punch type.
 * - Payroll previously used a different algorithm (pairing consecutive paid punches). That causes mismatches.
 */

/**
 * @param array<int, array{type:string,timestamp:string|int}> $punches
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
        $events[] = ['slug' => $slug, 'timestamp' => (int)$ts];
    }

    usort($events, static fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

    $durationsAll = [];
    $count = count($events);
    if ($count >= 2) {
        for ($i = 0; $i < $count - 1; $i++) {
            $start = $events[$i]['timestamp'];
            $end = $events[$i + 1]['timestamp'];
            $delta = max(0, $end - $start);
            if ($delta <= 0) {
                continue;
            }
            $slug = $events[$i]['slug'];
            $durationsAll[$slug] = ($durationsAll[$slug] ?? 0) + $delta;
        }
    }

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

<?php
// Febrero 2026: Del 1 al 28
// Medio Tiempo Tarde: Lunes a Viernes, 4 horas

$start = new DateTime('2026-02-01');
$end = new DateTime('2026-02-28');
$interval = new DateInterval('P1D');
$period = new DatePeriod($start, $interval, $end->modify('+1 day'));

$totalHours = 0;
$weekdayCount = 0;

foreach ($period as $date) {
    $dayOfWeek = (int) $date->format('N'); // 1=Lun, 7=Dom
    if ($dayOfWeek >= 1 && $dayOfWeek <= 5) { // Lunes a Viernes
        $totalHours += 4;
        $weekdayCount++;
    }
}

echo "Días laborables (L-V) en Febrero 2026: $weekdayCount\n";
echo "Horas por día: 4\n";
echo "Total horas esperadas: $totalHours horas\n";
echo "En formato HH:MM:SS: " . sprintf('%d:%02d:%02d', $totalHours, 0, 0) . "\n";

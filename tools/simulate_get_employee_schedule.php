<?php
$_GET['employee_id'] = $argv[1] ?? null;
$_GET['include_all'] = $argv[2] ?? null;
chdir(__DIR__ . '/../hr');
ob_start();
require __DIR__ . '/../hr/get_employee_schedule.php';
$output = ob_get_clean();
echo $output;

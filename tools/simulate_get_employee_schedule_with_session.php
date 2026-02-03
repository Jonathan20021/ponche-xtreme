<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'ADMIN';

$_GET['employee_id'] = $argv[1] ?? null;
$_GET['include_all'] = $argv[2] ?? null;
$_GET['user_id'] = $argv[3] ?? null;

chdir(__DIR__ . '/../hr');
require __DIR__ . '/../hr/get_employee_schedule.php';

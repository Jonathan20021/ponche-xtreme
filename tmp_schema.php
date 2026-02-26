<?php
require 'db.php';
$stmt = $pdo->query('SHOW TABLES');
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
$schema = [];
foreach ($tables as $t) {
    if (in_array($t, ['campaign_staffing_forecast', 'vicidial_inbound_hourly', 'campaigns'])) {
        $st = $pdo->query("SHOW CREATE TABLE `$t`");
        $schema[$t] = $st->fetch(PDO::FETCH_NUM)[1];
    }
}
echo print_r($schema, true);

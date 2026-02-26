<?php
require __DIR__ . '/db.php';
$pdo->exec('DROP TABLE IF EXISTS `campaign_staffing_forecast`');
echo "Dropped.";

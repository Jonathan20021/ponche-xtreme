<?php
require 'db.php';
$pdo->exec('CREATE TABLE IF NOT EXISTS `vicidial_inbound_hourly` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` int(10) unsigned NOT NULL,
  `interval_start` datetime NOT NULL,
  `offered_calls` int(10) unsigned DEFAULT 0,
  `answered_calls` int(10) unsigned DEFAULT 0,
  `agents_answered` int(10) unsigned DEFAULT 0,
  `abandoned_calls` int(10) unsigned DEFAULT 0,
  `abandon_percent` decimal(5,2) DEFAULT 0.00,
  `avg_abandon_time_sec` int(10) unsigned DEFAULT 0,
  `avg_answer_speed_sec` int(10) unsigned DEFAULT 0,
  `avg_talk_time_sec` int(10) unsigned DEFAULT 0,
  `total_talk_sec` int(10) unsigned DEFAULT 0,
  `total_wrap_sec` int(10) unsigned DEFAULT 0,
  `total_call_sec` int(10) unsigned DEFAULT 0,
  `source_filename` varchar(255) DEFAULT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_campaign_interval` (`campaign_id`,`interval_start`),
  CONSTRAINT `fk_inbound_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
echo "OK\n";

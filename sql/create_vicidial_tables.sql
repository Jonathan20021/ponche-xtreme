-- Vicidial Reporting Module - Database Tables
-- Created: 2026-02-07
-- Description: Tables for storing Vicidial login statistics and upload history

-- Table: vicidial_login_stats
-- Stores login statistics data from Vicidial CSV exports
CREATE TABLE IF NOT EXISTS `vicidial_login_stats` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  
  -- User Information
  `user_name` VARCHAR(100) NOT NULL,
  `user_id` VARCHAR(50) NOT NULL,
  `current_user_group` VARCHAR(100) DEFAULT NULL,
  `most_recent_user_group` VARCHAR(100) DEFAULT NULL,
  
  -- Call Metrics
  `calls` INT(11) DEFAULT 0,
  `time_total` INT(11) DEFAULT 0 COMMENT 'Total time in seconds',
  
  -- Time Breakdown (in seconds)
  `pause_time` INT(11) DEFAULT 0,
  `pause_avg` INT(11) DEFAULT 0,
  `wait_time` INT(11) DEFAULT 0,
  `wait_avg` INT(11) DEFAULT 0,
  `talk_time` INT(11) DEFAULT 0,
  `talk_avg` INT(11) DEFAULT 0,
  `dispo_time` INT(11) DEFAULT 0,
  `dispo_avg` INT(11) DEFAULT 0,
  `dead_time` INT(11) DEFAULT 0,
  `dead_avg` INT(11) DEFAULT 0,
  `customer_time` INT(11) DEFAULT 0,
  `customer_avg` INT(11) DEFAULT 0,
  
  -- Status Columns (Disposition Codes)
  `a` INT(11) DEFAULT 0,
  `b` INT(11) DEFAULT 0,
  `callbk` INT(11) DEFAULT 0,
  `colgo` INT(11) DEFAULT 0,
  `dair` INT(11) DEFAULT 0,
  `dc` INT(11) DEFAULT 0,
  `dec` INT(11) DEFAULT 0,
  `dnc` INT(11) DEFAULT 0,
  `n` INT(11) DEFAULT 0,
  `ni` INT(11) DEFAULT 0,
  `nocal` INT(11) DEFAULT 0,
  `np` INT(11) DEFAULT 0,
  `orden` INT(11) DEFAULT 0,
  `otrod` INT(11) DEFAULT 0,
  `pedido` INT(11) DEFAULT 0,
  `pregun` INT(11) DEFAULT 0,
  `ptrans` INT(11) DEFAULT 0,
  `quejas` INT(11) DEFAULT 0,
  `reserv` INT(11) DEFAULT 0,
  `sale` INT(11) DEFAULT 0,
  `seguim` INT(11) DEFAULT 0,
  `silenc` INT(11) DEFAULT 0,
  `xfer` INT(11) DEFAULT 0,
  
  -- Metadata
  `upload_date` DATE NOT NULL COMMENT 'Date when this data was uploaded',
  `uploaded_by` INT(10) UNSIGNED DEFAULT NULL COMMENT 'User ID who uploaded this data',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_upload_date` (`upload_date`),
  KEY `idx_user_name` (`user_name`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Vicidial Login Statistics';

-- Table: vicidial_uploads
-- Tracks upload history for all Vicidial reports
CREATE TABLE IF NOT EXISTS `vicidial_uploads` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `report_type` VARCHAR(50) NOT NULL COMMENT 'Type of report: login_stats, etc.',
  `filename` VARCHAR(255) NOT NULL,
  `upload_date` DATE NOT NULL COMMENT 'Date of the data in the report',
  `uploaded_by` INT(10) UNSIGNED NOT NULL,
  `record_count` INT(11) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  KEY `idx_upload_date` (`upload_date`),
  KEY `idx_report_type` (`report_type`),
  KEY `idx_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Vicidial Upload History';

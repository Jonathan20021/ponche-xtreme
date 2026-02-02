-- Add days_of_week to employee_schedules for weekly patterns
ALTER TABLE employee_schedules
  ADD COLUMN days_of_week varchar(20) DEFAULT NULL COMMENT 'Días de la semana (1-7 Mon-Sun)' AFTER end_date;

CREATE INDEX idx_schedule_days ON employee_schedules(days_of_week);

-- Amplía la precisión de las columnas de horas de la nómina de DECIMAL(6,2) a
-- DECIMAL(8,4). Motivo: el bruto (gross_salary) se calcula con las horas EXACTAS
-- (full precision), pero las horas se guardaban redondeadas a 2 decimales. Eso hacía
-- que en el reporte "tarifa × horas" no cuadrara con el bruto por unos centavos.
-- Con 4 decimales, tarifa × horas reconcilia al centavo. Cambio aditivo/no destructivo
-- (los valores x.xx existentes quedan como x.xx00).
--
-- IMPORTANTE: para que las nóminas YA generadas tomen la nueva precisión hay que
-- REGENERARLAS (recalcular el período). Regenerar sobrescribe ediciones manuales
-- (p. ej. "Corregir Base" / incentivos), así que vuelve a aplicarlas después.

ALTER TABLE payroll_records
    MODIFY COLUMN regular_hours  DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    MODIFY COLUMN overtime_hours DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    MODIFY COLUMN total_hours    DECIMAL(8,4) NOT NULL DEFAULT 0.0000;

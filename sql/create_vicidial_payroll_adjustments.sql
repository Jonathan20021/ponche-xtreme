-- Tabla intermedia de ajustes de horas pagables de Vicidial.
--
-- Regla de oro: `vicidial_agent_timesheet` es la FUENTE CRUDA y NUNCA se edita.
-- Gestión de Desempeño corrige aquí las horas pagables de un día concreto
-- (p. ej. el agente quedó logueado después del break). El ajuste se aplica en un
-- solo punto del código -- vicidialGetPaidSecondsByDate() -- que consumen tanto
-- la nómina (hr/payroll.php) como el portal del agente (lib/agent_hours.php),
-- de modo que la corrección se refleja en ambos automáticamente.
--
-- `original_seconds` guarda lo que dijo Vicidial al momento del ajuste, para
-- poder auditar cuánto se movió y quién lo movió.

CREATE TABLE IF NOT EXISTS vicidial_payroll_adjustments (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(10) UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    adjusted_seconds INT(11) NOT NULL,
    original_seconds INT(11) NOT NULL DEFAULT 0,
    reason VARCHAR(255) NOT NULL,
    adjusted_by INT(10) UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_date (user_id, work_date),
    KEY idx_work_date (work_date),
    KEY idx_adjusted_by (adjusted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora inmutable: todo cambio queda registrado, incluidos los borrados.
CREATE TABLE IF NOT EXISTS vicidial_payroll_adjustment_log (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(10) UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    action VARCHAR(20) NOT NULL,
    old_seconds INT(11) DEFAULT NULL,
    new_seconds INT(11) DEFAULT NULL,
    original_seconds INT(11) NOT NULL DEFAULT 0,
    reason VARCHAR(255) DEFAULT NULL,
    performed_by INT(10) UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_date (user_id, work_date),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

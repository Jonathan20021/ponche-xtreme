-- Agrega la bandera visible_to_agents a payroll_periods.
-- Permite al admin/supervisor controlar qué quincenas ven los agentes
-- en su dashboard para consultar horas acumuladas.

ALTER TABLE payroll_periods
    ADD COLUMN IF NOT EXISTS visible_to_agents TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

CREATE INDEX IF NOT EXISTS idx_payroll_periods_visible_to_agents
    ON payroll_periods(visible_to_agents);

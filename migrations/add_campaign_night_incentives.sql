-- Incentivo nocturno automático por campaña.
--
-- La app crea la tabla sola (ensureCampaignNightIncentivesTable en
-- lib/night_incentive_calculator.php); este archivo queda como referencia y para
-- levantar el ambiente desde cero.

CREATE TABLE IF NOT EXISTS campaign_night_incentives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    -- Hora en que arranca la franja (hora local RD, GMT-4).
    start_time TIME NOT NULL DEFAULT '19:00:00',
    -- Fin de la franja. '00:00:00' (o <= start_time) = corre hasta la medianoche
    -- siguiente, para turnos que cruzan el día.
    end_time TIME NOT NULL DEFAULT '00:00:00',
    -- RD$ por cada hora pagable trabajada dentro de la franja.
    amount_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    -- Desde cuándo aplica. NULL = siempre.
    effective_from DATE NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- El monto calculado necesita columna propia: antes el incentivo nocturno solo
-- existía como captura manual y viajaba dentro de `bonuses`.
ALTER TABLE payroll_records
    ADD COLUMN night_incentive DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER bonuses,
    ADD COLUMN night_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER night_incentive,
    ADD COLUMN night_incentive_source VARCHAR(10) NOT NULL DEFAULT '' AFTER night_hours;

-- Delivery: RD$5.00 por hora a partir de las 7:00 PM.
INSERT INTO campaign_night_incentives (campaign_id, start_time, end_time, amount_per_hour, is_active, notes)
SELECT c.id, '19:00:00', '00:00:00', 5.00, 1, 'RD$5.00 por hora a partir de las 7:00 PM'
FROM campaigns c
WHERE c.name = 'Delivery'
ON DUPLICATE KEY UPDATE amount_per_hour = VALUES(amount_per_hour);

-- Incentivo (recargo) nocturno automático.
--
-- La app crea y actualiza la tabla sola (ensureCampaignNightIncentivesTable en
-- lib/night_incentive_calculator.php); este archivo queda como referencia y para
-- levantar el ambiente desde cero.

CREATE TABLE IF NOT EXISTS campaign_night_incentives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- 0 = regla GENERAL (aplica a todo el mundo). > 0 = excepción de esa campaña,
    -- que manda sobre la general.
    campaign_id INT UNSIGNED NOT NULL,
    -- 'percent' = % sobre la hora normal (lo de ley, art. 204 del Código de
    -- Trabajo). 'fixed' = RD$ por hora. 'none' = excluida a propósito.
    mode VARCHAR(10) NOT NULL DEFAULT 'fixed',
    -- Hora en que arranca la franja (hora local RD, GMT-4).
    start_time TIME NOT NULL DEFAULT '21:00:00',
    -- Fin de la franja. '00:00:00' (o <= start_time) = corre hasta la medianoche
    -- siguiente, para turnos que cruzan el día.
    end_time TIME NOT NULL DEFAULT '00:00:00',
    -- RD$ por cada hora pagable trabajada dentro de la franja (modo 'fixed').
    amount_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- % sobre la tarifa horaria normal (modo 'percent').
    percent_of_hourly DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    -- Ventana de vigencia. Las reglas se VERSIONAN: al cambiar una, la anterior
    -- se cierra con effective_to = (nueva effective_from - 1 día) en vez de
    -- pisarse, para que regenerar una quincena vieja siga pagando lo que
    -- aplicaba esos días. NULL en effective_to = regla vigente.
    effective_from DATE NULL,
    effective_to DATE NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_campaign (campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Instalaciones viejas: columnas nuevas y adiós al UNIQUE por campaña (ahora
-- conviven la regla vigente y las históricas).
-- ALTER TABLE campaign_night_incentives ADD COLUMN mode VARCHAR(10) NOT NULL DEFAULT 'fixed' AFTER campaign_id;
-- ALTER TABLE campaign_night_incentives ADD COLUMN percent_of_hourly DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER amount_per_hour;
-- ALTER TABLE campaign_night_incentives ADD COLUMN effective_to DATE NULL AFTER effective_from;
-- ALTER TABLE campaign_night_incentives DROP INDEX unique_campaign;
-- ALTER TABLE campaign_night_incentives ADD INDEX idx_campaign (campaign_id);

-- El monto calculado necesita columna propia: antes el incentivo nocturno solo
-- existía como captura manual y viajaba dentro de `bonuses`.
ALTER TABLE payroll_records
    ADD COLUMN night_incentive DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER bonuses,
    ADD COLUMN night_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER night_incentive,
    ADD COLUMN night_incentive_source VARCHAR(10) NOT NULL DEFAULT '' AFTER night_hours;

-- Regla general de ley: 15% sobre la hora normal, de 9:00 PM a 12:00 AM.
-- La app la siembra sola (seedGeneralNightIncentiveRule) desde la próxima
-- quincena y cierra ese mismo día las reglas de campaña que venían corriendo
-- (el caso real: Delivery, RD$5.00/h desde las 7:00 PM).
INSERT INTO campaign_night_incentives
    (campaign_id, mode, start_time, end_time, amount_per_hour, percent_of_hourly, is_active, effective_from, notes)
SELECT 0, 'percent', '21:00:00', '00:00:00', 0.00, 15.00, 1, CURDATE(),
       'Recargo nocturno de ley: 15% sobre la hora normal, 9:00 PM a 12:00 AM.'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM campaign_night_incentives WHERE campaign_id = 0);

-- Migration: AI-powered recruitment enrichment
-- MySQL 5.7 compatible (no IF NOT EXISTS on ADD COLUMN). Use the bundled PHP runner
-- below if any column already exists, or apply each ALTER once.

ALTER TABLE job_applications
    ADD COLUMN ai_summary           TEXT          NULL,
    ADD COLUMN ai_score             TINYINT       NULL,
    ADD COLUMN ai_strengths         TEXT          NULL,
    ADD COLUMN ai_concerns          TEXT          NULL,
    ADD COLUMN ai_extracted_data    LONGTEXT      NULL,
    ADD COLUMN ai_processed_at      TIMESTAMP     NULL,
    ADD COLUMN ai_model_used        VARCHAR(100)  NULL,
    ADD COLUMN ai_recommendation    VARCHAR(20)   NULL;

ALTER TABLE job_postings
    ADD COLUMN ai_generated         TINYINT(1)    NOT NULL DEFAULT 0;

-- Recruitment AI configuration defaults (settings.php UI editable)
INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_type, category, description) VALUES
('recruitment_ai_enabled',          '1',                 'boolean', 'ai', 'Activa el procesamiento automatico de candidatos con Claude AI al recibir solicitudes.'),
('recruitment_ai_model',            'claude-sonnet-4-6', 'string',  'ai', 'Modelo Claude usado para parseo de CV, screening y generacion de descripciones de puestos.'),
('recruitment_ai_min_score_shortlist', '75',             'number',  'ai', 'Score minimo (0-100) para sugerir auto-preselect (Shortlist) de un candidato.'),
('recruitment_ai_extract_prompt',
 'Eres un experto en RR.HH. analizando un CV. Extrae la informacion del candidato como JSON estricto. Devuelve SOLO JSON sin markdown ni texto extra.',
 'text', 'ai', 'Prompt base para extraccion estructurada del CV (parseo).'),
('recruitment_ai_screen_prompt',
 'Eres un reclutador senior. Evalua que tan bien el candidato encaja con la vacante. Considera: experiencia relevante, educacion, idiomas, habilidades. Devuelve un JSON con score (0-100), summary (1 parrafo), strengths (lista), concerns (lista) y recommendation (shortlist|review|reject).',
 'text', 'ai', 'Prompt usado para evaluar y puntuar candidatos contra una vacante.'),
('recruitment_ai_jobdesc_prompt',
 'Eres un experto en redaccion de descripciones de puestos. A partir del titulo, departamento y notas que te entregue, redacta: descripcion atractiva, lista de responsabilidades y lista de requisitos. Devuelve JSON con keys: description, responsibilities, requirements.',
 'text', 'ai', 'Prompt usado por el generador de descripciones de vacantes.');

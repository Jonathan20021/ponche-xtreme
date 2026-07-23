-- Migration: Campos adicionales del formulario de postulacion (solicitud RRHH 2026-07)
-- 1) role_interest: reemplaza la pregunta "por que deberiamos contratarle" por
--    "Cual es el rol de su interes" (Ingles / Espanol / APPOINT). Se guarda en
--    columna propia para poder filtrar, ordenar y exportar desde Reclutamiento.
-- 2) El resto de datos personales nuevos (nacionalidad, estado civil, tipo de
--    sangre, estatura, peso, con quien vive, dependientes, hijos, vivienda propia,
--    cursos e idiomas) viajan dentro del JSON de cover_letter, que es lo que
--    renderiza hr/view_application.php. Solo date_of_birth necesita columna y ya existe.

ALTER TABLE job_applications
    ADD COLUMN role_interest VARCHAR(50) NULL AFTER application_language;

CREATE INDEX idx_role_interest ON job_applications (role_interest);

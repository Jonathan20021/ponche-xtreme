-- Verificar los slugs en attendance_types
SELECT slug, label, is_paid FROM attendance_types WHERE slug LIKE '%disponible%' OR slug LIKE '%DISPONIBLE%';

-- Verificar los tipos en attendance de hoy para Ashley
SELECT DISTINCT type FROM attendance 
WHERE user_id = (SELECT id FROM users WHERE full_name LIKE '%Ashley%Estevez%')
AND DATE(timestamp) = CURDATE();

-- Si el slug en attendance_types es 'disponible' (minúsculas), actualízalo a mayúsculas
UPDATE attendance_types 
SET slug = 'DISPONIBLE' 
WHERE LOWER(slug) = 'disponible';

-- O si prefieres, actualiza todos los slugs a mayúsculas para consistencia
UPDATE attendance_types 
SET slug = UPPER(slug);

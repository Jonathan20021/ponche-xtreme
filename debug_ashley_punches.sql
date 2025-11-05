-- Consulta para ver los punches de Ashley Estevez Castillo hoy
-- Ejecuta cada consulta por separado en phpMyAdmin

-- CONSULTA 1: Ver todos los punches de hoy de Ashley
SELECT 
    a.id,
    a.type,
    a.timestamp,
    at.is_paid,
    at.label
FROM attendance a
LEFT JOIN attendance_types at ON BINARY at.slug = BINARY a.type
WHERE a.user_id = (SELECT id FROM users WHERE full_name LIKE '%Ashley%Estevez%' LIMIT 1)
AND DATE(a.timestamp) = CURDATE()
ORDER BY a.timestamp ASC;

-- CONSULTA 2: Ver el cálculo manual de duraciones
SELECT 
    a1.type,
    a1.timestamp as punch_time,
    (SELECT MIN(a2.timestamp) 
     FROM attendance a2 
     WHERE a2.user_id = a1.user_id 
     AND a2.timestamp > a1.timestamp 
     AND DATE(a2.timestamp) = DATE(a1.timestamp)) as next_punch_time,
    TIMESTAMPDIFF(SECOND, a1.timestamp, 
        (SELECT MIN(a2.timestamp) 
         FROM attendance a2 
         WHERE a2.user_id = a1.user_id 
         AND a2.timestamp > a1.timestamp 
         AND DATE(a2.timestamp) = DATE(a1.timestamp))
    ) as duration_seconds,
    at.is_paid,
    at.label
FROM attendance a1
LEFT JOIN attendance_types at ON BINARY at.slug = BINARY a1.type
WHERE a1.user_id = (SELECT id FROM users WHERE full_name LIKE '%Ashley%Estevez%' LIMIT 1)
AND DATE(a1.timestamp) = CURDATE()
ORDER BY a1.timestamp ASC;

-- CONSULTA 3: Ver resumen de tiempo por tipo
SELECT 
    a1.type,
    at.label,
    at.is_paid,
    COUNT(*) as veces,
    SUM(
        CASE 
            WHEN (SELECT MIN(a2.timestamp) 
                  FROM attendance a2 
                  WHERE a2.user_id = a1.user_id 
                  AND a2.timestamp > a1.timestamp 
                  AND DATE(a2.timestamp) = DATE(a1.timestamp)) IS NOT NULL
            THEN TIMESTAMPDIFF(SECOND, a1.timestamp, 
                (SELECT MIN(a2.timestamp) 
                 FROM attendance a2 
                 WHERE a2.user_id = a1.user_id 
                 AND a2.timestamp > a1.timestamp 
                 AND DATE(a2.timestamp) = DATE(a1.timestamp)))
            ELSE 0
        END
    ) as total_seconds
FROM attendance a1
LEFT JOIN attendance_types at ON BINARY at.slug = BINARY a1.type
WHERE a1.user_id = (SELECT id FROM users WHERE full_name LIKE '%Ashley%Estevez%' LIMIT 1)
AND DATE(a1.timestamp) = CURDATE()
GROUP BY a1.type, at.label, at.is_paid
ORDER BY at.is_paid DESC, total_seconds DESC;

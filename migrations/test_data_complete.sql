-- =====================================================
-- DATOS DE PRUEBA COMPLETOS PARA EL SISTEMA
-- Incluye: Departamentos, Usuarios, Empleados, Asistencia, Nómina
-- =====================================================

-- Limpiar datos de prueba existentes
DELETE FROM attendance WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'test_%');
DELETE FROM payroll_records WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'EMP-TEST-%');
DELETE FROM employee_deductions WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'EMP-TEST-%');
DELETE FROM vacation_requests WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'EMP-TEST-%');
DELETE FROM permission_requests WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'EMP-TEST-%');
DELETE FROM employees WHERE employee_code LIKE 'EMP-TEST-%';
DELETE FROM users WHERE username LIKE 'test_%';
DELETE FROM payroll_periods WHERE name LIKE '%Prueba%';
DELETE FROM departments WHERE name LIKE 'Test %';

-- =====================================================
-- 1. DEPARTAMENTOS DE PRUEBA
-- =====================================================
INSERT INTO departments (name, description) VALUES
('Test Ventas', 'Departamento de ventas y comercial'),
('Test Tecnología', 'Departamento de desarrollo y soporte técnico'),
('Test Recursos Humanos', 'Departamento de gestión de personal'),
('Test Operaciones', 'Departamento de operaciones y logística'),
('Test Finanzas', 'Departamento de contabilidad y finanzas');

-- Obtener IDs de departamentos
SET @dept_ventas = (SELECT id FROM departments WHERE name = 'Test Ventas');
SET @dept_tech = (SELECT id FROM departments WHERE name = 'Test Tecnología');
SET @dept_hr = (SELECT id FROM departments WHERE name = 'Test Recursos Humanos');
SET @dept_ops = (SELECT id FROM departments WHERE name = 'Test Operaciones');
SET @dept_fin = (SELECT id FROM departments WHERE name = 'Test Finanzas');

-- =====================================================
-- 2. USUARIOS DE PRUEBA
-- =====================================================
-- Contraseña para todos: "test123"
INSERT INTO users (username, employee_code, full_name, password, role, hourly_rate, monthly_salary, hourly_rate_dop, monthly_salary_dop, preferred_currency, department_id, overtime_multiplier, exit_time) VALUES
('test_manager', 'EMP-TEST-0001', 'Carlos Gerente', 'test123', 'ADMIN', 25.00, 4000.00, 1250.00, 200000.00, 'DOP', @dept_hr, 1.5, '18:00:00'),
('test_dev1', 'EMP-TEST-0002', 'Ana Desarrolladora', 'test123', 'USER', 20.00, 3200.00, 1000.00, 160000.00, 'DOP', @dept_tech, 1.5, '18:00:00'),
('test_dev2', 'EMP-TEST-0003', 'Luis Programador', 'test123', 'USER', 18.00, 2880.00, 900.00, 144000.00, 'DOP', @dept_tech, 1.5, '18:00:00'),
('test_sales1', 'EMP-TEST-0004', 'María Vendedora', 'test123', 'USER', 15.00, 2400.00, 750.00, 120000.00, 'DOP', @dept_ventas, 1.5, '18:00:00'),
('test_sales2', 'EMP-TEST-0005', 'Pedro Comercial', 'test123', 'USER', 16.00, 2560.00, 800.00, 128000.00, 'DOP', @dept_ventas, 1.5, '18:00:00'),
('test_ops1', 'EMP-TEST-0006', 'Sofia Operaciones', 'test123', 'USER', 14.00, 2240.00, 700.00, 112000.00, 'DOP', @dept_ops, 1.5, '18:00:00'),
('test_ops2', 'EMP-TEST-0007', 'Jorge Logística', 'test123', 'USER', 13.00, 2080.00, 650.00, 104000.00, 'DOP', @dept_ops, 1.5, '18:00:00'),
('test_fin1', 'EMP-TEST-0008', 'Laura Contadora', 'test123', 'USER', 22.00, 3520.00, 1100.00, 176000.00, 'DOP', @dept_fin, 1.5, '18:00:00'),
('test_agent1', 'EMP-TEST-0009', 'Roberto Agente', 'test123', 'AGENT', 12.00, 1920.00, 600.00, 96000.00, 'DOP', @dept_ops, 1.5, '18:00:00'),
('test_agent2', 'EMP-TEST-0010', 'Carmen Agente', 'test123', 'AGENT', 12.00, 1920.00, 600.00, 96000.00, 'DOP', @dept_ops, 1.5, '18:00:00');

-- =====================================================
-- 3. EMPLEADOS (VINCULADOS A USUARIOS)
-- =====================================================
INSERT INTO employees (
    user_id, employee_code, first_name, last_name, email, phone, mobile, 
    birth_date, hire_date, position, department_id, employment_status, employment_type,
    address, city, state, postal_code, identification_number, identification_type,
    blood_type, marital_status, gender, 
    emergency_contact_name, emergency_contact_phone, emergency_contact_relationship
) VALUES
-- Manager
((SELECT id FROM users WHERE username = 'test_manager'), 'EMP-TEST-0001', 'Carlos', 'Gerente', 'carlos.gerente@test.com', '809-555-0001', '829-555-0001', '1985-03-15', '2023-01-10', 'Gerente de RRHH', @dept_hr, 'ACTIVE', 'FULL_TIME', 'Calle Principal #123', 'Santo Domingo', 'Distrito Nacional', '10100', '001-0123456-7', 'Cédula', 'O+', 'Casado/a', 'Masculino', 'María Gerente', '809-555-1001', 'Esposo/a'),

-- Developers
((SELECT id FROM users WHERE username = 'test_dev1'), 'EMP-TEST-0002', 'Ana', 'Desarrolladora', 'ana.dev@test.com', '809-555-0002', '829-555-0002', '1992-07-20', '2023-06-15', 'Desarrolladora Senior', @dept_tech, 'ACTIVE', 'FULL_TIME', 'Av. Winston Churchill #456', 'Santo Domingo', 'Distrito Nacional', '10100', '001-0234567-8', 'Cédula', 'A+', 'Soltero/a', 'Femenino', 'Pedro Desarrollador', '809-555-1002', 'Padre'),

((SELECT id FROM users WHERE username = 'test_dev2'), 'EMP-TEST-0003', 'Luis', 'Programador', 'luis.prog@test.com', '809-555-0003', '829-555-0003', '1995-11-08', DATE_SUB(CURDATE(), INTERVAL 45 DAY), 'Programador Junior', @dept_tech, 'TRIAL', 'FULL_TIME', 'Calle El Conde #789', 'Santo Domingo', 'Distrito Nacional', '10100', '001-0345678-9', 'Cédula', 'B+', 'Soltero/a', 'Masculino', 'Rosa Programador', '809-555-1003', 'Madre'),

-- Sales
((SELECT id FROM users WHERE username = 'test_sales1'), 'EMP-TEST-0004', 'María', 'Vendedora', 'maria.ventas@test.com', '809-555-0004', '829-555-0004', '1990-05-12', '2022-09-01', 'Ejecutiva de Ventas', @dept_ventas, 'ACTIVE', 'FULL_TIME', 'Av. 27 de Febrero #321', 'Santo Domingo', 'Distrito Nacional', '10100', '001-0456789-0', 'Cédula', 'AB+', 'Casado/a', 'Femenino', 'Juan Vendedor', '809-555-1004', 'Esposo/a'),

((SELECT id FROM users WHERE username = 'test_sales2'), 'EMP-TEST-0005', 'Pedro', 'Comercial', 'pedro.comercial@test.com', '809-555-0005', '829-555-0005', '1988-12-25', '2023-03-20', 'Gerente Comercial', @dept_ventas, 'ACTIVE', 'FULL_TIME', 'Calle Duarte #654', 'Santiago', 'Santiago', '51000', '001-0567890-1', 'Cédula', 'O-', 'Divorciado/a', 'Masculino', 'Ana Comercial', '809-555-1005', 'Hermana'),

-- Operations
((SELECT id FROM users WHERE username = 'test_ops1'), 'EMP-TEST-0006', 'Sofia', 'Operaciones', 'sofia.ops@test.com', '809-555-0006', '829-555-0006', '1993-09-30', DATE_SUB(CURDATE(), INTERVAL 30 DAY), 'Coordinadora de Operaciones', @dept_ops, 'TRIAL', 'FULL_TIME', 'Av. Independencia #987', 'Santo Domingo', 'Distrito Nacional', '10100', '001-0678901-2', 'Cédula', 'A-', 'Soltero/a', 'Femenino', 'Carlos Operaciones', '809-555-1006', 'Padre'),

((SELECT id FROM users WHERE username = 'test_ops2'), 'EMP-TEST-0007', 'Jorge', 'Logística', 'jorge.log@test.com', '809-555-0007', '829-555-0007', '1991-04-18', '2022-11-10', 'Supervisor de Logística', @dept_ops, 'ACTIVE', 'FULL_TIME', 'Calle Mella #147', 'Santo Domingo', 'Distrito Nacional', '10100', '001-0789012-3', 'Cédula', 'B-', 'Casado/a', 'Masculino', 'Laura Logística', '809-555-1007', 'Esposo/a'),

-- Finance
((SELECT id FROM users WHERE username = 'test_fin1'), 'EMP-TEST-0008', 'Laura', 'Contadora', 'laura.conta@test.com', '809-555-0008', '829-555-0008', '1987-02-14', '2021-05-01', 'Contadora Senior', @dept_fin, 'ACTIVE', 'FULL_TIME', 'Av. Sarasota #258', 'Santo Domingo', 'Distrito Nacional', '10100', '001-0890123-4', 'Cédula', 'AB-', 'Casado/a', 'Femenino', 'Miguel Contador', '809-555-1008', 'Esposo/a'),

-- Agents
((SELECT id FROM users WHERE username = 'test_agent1'), 'EMP-TEST-0009', 'Roberto', 'Agente', 'roberto.agente@test.com', '809-555-0009', '829-555-0009', '1996-08-22', DATE_SUB(CURDATE(), INTERVAL 60 DAY), 'Agente de Soporte', @dept_ops, 'TRIAL', 'FULL_TIME', 'Calle Hostos #369', 'Santo Domingo', 'Distrito Nacional', '10100', '001-0901234-5', 'Cédula', 'O+', 'Soltero/a', 'Masculino', 'Elena Agente', '809-555-1009', 'Madre'),

((SELECT id FROM users WHERE username = 'test_agent2'), 'EMP-TEST-0010', 'Carmen', 'Agente', 'carmen.agente@test.com', '809-555-0010', '829-555-0010', '1994-06-05', '2023-02-15', 'Agente de Atención', @dept_ops, 'ACTIVE', 'FULL_TIME', 'Av. Bolívar #741', 'Santo Domingo', 'Distrito Nacional', '10100', '001-1012345-6', 'Cédula', 'A+', 'Soltero/a', 'Femenino', 'José Agente', '809-555-1010', 'Padre');

-- =====================================================
-- 4. REGISTROS DE ASISTENCIA (ÚLTIMOS 15 DÍAS)
-- =====================================================
-- Generar asistencia para los últimos 15 días laborales
INSERT INTO attendance (user_id, type, timestamp)
SELECT 
    u.id,
    'ENTRY',
    DATE_SUB(CURDATE(), INTERVAL d.day_offset DAY) + INTERVAL (8 + FLOOR(RAND() * 2)) HOUR + INTERVAL FLOOR(RAND() * 60) MINUTE
FROM users u
CROSS JOIN (
    SELECT 0 as day_offset UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION 
    SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION 
    SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
) d
WHERE u.username LIKE 'test_%'
AND DAYOFWEEK(DATE_SUB(CURDATE(), INTERVAL d.day_offset DAY)) NOT IN (1, 7); -- Excluir domingos y sábados

-- Salidas (EXIT)
INSERT INTO attendance (user_id, type, timestamp)
SELECT 
    u.id,
    'EXIT',
    DATE_SUB(CURDATE(), INTERVAL d.day_offset DAY) + INTERVAL (17 + FLOOR(RAND() * 3)) HOUR + INTERVAL FLOOR(RAND() * 60) MINUTE
FROM users u
CROSS JOIN (
    SELECT 0 as day_offset UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION 
    SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION 
    SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
) d
WHERE u.username LIKE 'test_%'
AND DAYOFWEEK(DATE_SUB(CURDATE(), INTERVAL d.day_offset DAY)) NOT IN (1, 7);

-- Algunos breaks y lunches
INSERT INTO attendance (user_id, type, timestamp)
SELECT 
    u.id,
    'PAUSA',
    DATE_SUB(CURDATE(), INTERVAL d.day_offset DAY) + INTERVAL 12 HOUR + INTERVAL FLOOR(RAND() * 30) MINUTE
FROM users u
CROSS JOIN (
    SELECT 0 as day_offset UNION SELECT 2 UNION SELECT 4 UNION SELECT 6 UNION SELECT 8 UNION SELECT 10
) d
WHERE u.username LIKE 'test_%'
AND DAYOFWEEK(DATE_SUB(CURDATE(), INTERVAL d.day_offset DAY)) NOT IN (1, 7);

-- =====================================================
-- 5. PERÍODO DE NÓMINA DE PRUEBA
-- =====================================================
INSERT INTO payroll_periods (name, period_type, start_date, end_date, payment_date, status, created_by) VALUES
('Quincena de Prueba - ' || DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 15 DAY), '%b %Y'), 
 'BIWEEKLY', 
 DATE_SUB(CURDATE(), INTERVAL 15 DAY), 
 DATE_SUB(CURDATE(), INTERVAL 1 DAY), 
 CURDATE() + INTERVAL 5 DAY,
 'DRAFT',
 (SELECT id FROM users WHERE username = 'test_manager'));

-- =====================================================
-- 6. SOLICITUDES DE PERMISOS
-- =====================================================
INSERT INTO permission_requests (employee_id, user_id, request_type, start_date, end_date, start_time, end_time, total_days, reason, status) VALUES
((SELECT id FROM employees WHERE employee_code = 'EMP-TEST-0002'), (SELECT id FROM users WHERE username = 'test_dev1'), 'MEDICAL', CURDATE() + INTERVAL 2 DAY, CURDATE() + INTERVAL 2 DAY, '09:00:00', '12:00:00', 0.5, 'Cita médica', 'PENDING'),
((SELECT id FROM employees WHERE employee_code = 'EMP-TEST-0004'), (SELECT id FROM users WHERE username = 'test_sales1'), 'PERSONAL', CURDATE() - INTERVAL 3 DAY, CURDATE() - INTERVAL 3 DAY, '14:00:00', '17:00:00', 0.5, 'Asunto personal', 'APPROVED'),
((SELECT id FROM employees WHERE employee_code = 'EMP-TEST-0006'), (SELECT id FROM users WHERE username = 'test_ops1'), 'PERMISSION', CURDATE() + INTERVAL 5 DAY, CURDATE() + INTERVAL 5 DAY, '08:00:00', '18:00:00', 1.0, 'Asunto familiar urgente', 'PENDING');

-- =====================================================
-- 7. SOLICITUDES DE VACACIONES
-- =====================================================
INSERT INTO vacation_requests (employee_id, user_id, start_date, end_date, total_days, vacation_type, reason, status) VALUES
((SELECT id FROM employees WHERE employee_code = 'EMP-TEST-0003'), (SELECT id FROM users WHERE username = 'test_dev2'), CURDATE() + INTERVAL 30 DAY, CURDATE() + INTERVAL 44 DAY, 10, 'ANNUAL', 'Vacaciones familiares', 'PENDING'),
((SELECT id FROM employees WHERE employee_code = 'EMP-TEST-0005'), (SELECT id FROM users WHERE username = 'test_sales2'), CURDATE() + INTERVAL 60 DAY, CURDATE() + INTERVAL 74 DAY, 10, 'ANNUAL', 'Descanso anual', 'PENDING'),
((SELECT id FROM employees WHERE employee_code = 'EMP-TEST-0008'), (SELECT id FROM users WHERE username = 'test_fin1'), CURDATE() - INTERVAL 20 DAY, CURDATE() - INTERVAL 6 DAY, 10, 'ANNUAL', 'Vacaciones de verano', 'APPROVED');

-- =====================================================
-- 8. DESCUENTOS PERSONALIZADOS (EJEMPLOS)
-- =====================================================
INSERT INTO employee_deductions (employee_id, name, description, type, amount, is_active) VALUES
((SELECT id FROM employees WHERE employee_code = 'EMP-TEST-0001'), 'Préstamo Personal', 'Préstamo a 12 meses', 'FIXED', 500.00, 1),
((SELECT id FROM employees WHERE employee_code = 'EMP-TEST-0002'), 'Seguro Médico Privado', 'Plan familiar', 'FIXED', 150.00, 1),
((SELECT id FROM employees WHERE employee_code = 'EMP-TEST-0008'), 'Aporte Voluntario AFP', 'Aporte adicional 2%', 'PERCENTAGE', 2.00, 1);

-- =====================================================
-- RESUMEN DE DATOS CREADOS
-- =====================================================
SELECT '=== RESUMEN DE DATOS DE PRUEBA ===' as '';
SELECT COUNT(*) as 'Departamentos Creados' FROM departments WHERE name LIKE 'Test %';
SELECT COUNT(*) as 'Usuarios Creados' FROM users WHERE username LIKE 'test_%';
SELECT COUNT(*) as 'Empleados Creados' FROM employees WHERE employee_code LIKE 'EMP-TEST-%';
SELECT COUNT(*) as 'Registros de Asistencia' FROM attendance WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'test_%');
SELECT COUNT(*) as 'Períodos de Nómina' FROM payroll_periods WHERE name LIKE '%Prueba%';
SELECT COUNT(*) as 'Solicitudes de Permisos' FROM permission_requests WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'EMP-TEST-%');
SELECT COUNT(*) as 'Solicitudes de Vacaciones' FROM vacation_requests WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'EMP-TEST-%');
SELECT COUNT(*) as 'Descuentos Personalizados' FROM employee_deductions WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'EMP-TEST-%');

SELECT '=== DATOS LISTOS PARA PROBAR ===' as '';
SELECT 'Usuario: test_manager | Contraseña: test123 | Rol: ADMIN' as 'Credenciales de Prueba';
SELECT 'Usuario: test_dev1 | Contraseña: test123 | Rol: USER' as '';
SELECT 'Usuario: test_agent1 | Contraseña: test123 | Rol: AGENT' as '';
SELECT '' as '';
SELECT 'Próximos pasos:' as '';
SELECT '1. Ve a /hr/payroll.php' as '';
SELECT '2. Haz clic en "Calcular" en el período de prueba' as '';
SELECT '3. Verás la nómina calculada con AFP, SFS, ISR' as '';
SELECT '4. Exporta a PDF, Excel, TSS o DGII' as '';

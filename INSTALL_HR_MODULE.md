# 🚀 Instalación Rápida del Módulo de Recursos Humanos

## ⚡ Instalación en 3 Pasos

### Paso 1: Ejecutar Script SQL

Abre phpMyAdmin y ejecuta el siguiente script:

```bash
# Opción 1: Desde phpMyAdmin
1. Abre phpMyAdmin
2. Selecciona la base de datos "ponche"
3. Ve a la pestaña "SQL"
4. Copia y pega el contenido de: migrations/create_hr_module.sql
5. Haz clic en "Continuar"
```

**O desde línea de comandos:**

```bash
mysql -u root -p ponche < migrations/create_hr_module.sql
```

### Paso 2: Verificar Instalación

Verifica que las siguientes tablas se hayan creado:

- ✅ `employees`
- ✅ `payroll_periods`
- ✅ `payroll_records`
- ✅ `permission_requests`
- ✅ `vacation_requests`
- ✅ `vacation_balances`
- ✅ `employee_documents`
- ✅ `hr_notifications`

### Paso 3: Acceder al Módulo

1. Inicia sesión con un usuario Admin, HR o IT
2. Navega a: `http://localhost/ponche-xtreme/hr/`
3. ¡Listo! 🎉

## 📋 Verificación Post-Instalación

### Verificar Permisos

Ejecuta esta consulta para verificar que los permisos se agregaron:

```sql
SELECT * FROM section_permissions WHERE section_key LIKE 'hr_%';
```

Deberías ver permisos para:
- hr_dashboard
- hr_employees
- hr_trial_period
- hr_payroll
- hr_birthdays
- hr_permissions
- hr_vacations
- hr_calendar

### Migrar Empleados Existentes

Si ya tienes usuarios en el sistema, ejecuta este script para crear sus registros de empleado:

```sql
INSERT INTO employees (user_id, employee_code, first_name, last_name, hire_date, employment_status, department_id)
SELECT 
    u.id,
    u.employee_code,
    SUBSTRING_INDEX(u.full_name, ' ', 1) as first_name,
    SUBSTRING_INDEX(u.full_name, ' ', -1) as last_name,
    COALESCE(u.created_at, NOW()) as hire_date,
    'ACTIVE' as employment_status,
    u.department_id
FROM users u
LEFT JOIN employees e ON e.user_id = u.id
WHERE e.id IS NULL;
```

## 🎯 Primeros Pasos

### 1. Registrar Empleados

Ve a `register.php` y registra empleados con toda la información:
- Datos personales
- Fecha de nacimiento
- Fecha de ingreso
- Posición y departamento
- Tarifa por hora

### 2. Configurar Departamentos

Si necesitas más departamentos:

```sql
INSERT INTO departments (name, description) VALUES
('Ventas', 'Equipo de ventas y comercial'),
('Marketing', 'Marketing y comunicaciones'),
('Finanzas', 'Contabilidad y finanzas');
```

### 3. Crear Primer Período de Nómina

1. Ve a `hr/payroll.php`
2. Clic en "Nuevo Período"
3. Completa:
   - Nombre: "Quincena 1 - Enero 2025"
   - Fecha inicio: 01/01/2025
   - Fecha fin: 15/01/2025
   - Fecha pago: 20/01/2025
4. Clic en "Crear Período"
5. Clic en "Calcular" para generar nómina

### 4. Configurar Balance de Vacaciones

Para empleados existentes, crea su balance inicial:

```sql
INSERT INTO vacation_balances (employee_id, year, total_days, used_days, remaining_days)
SELECT 
    id,
    YEAR(CURDATE()),
    14.00,
    0.00,
    14.00
FROM employees
WHERE employment_status IN ('ACTIVE', 'TRIAL');
```

## 🔧 Solución de Problemas

### Error: "Table doesn't exist"

**Solución:** Ejecuta nuevamente el script SQL de migración.

```bash
mysql -u root -p ponche < migrations/create_hr_module.sql
```

### Error: "Access denied"

**Solución:** Verifica que tu usuario tenga uno de estos roles:
- Admin
- HR
- IT

Actualiza el rol si es necesario:

```sql
UPDATE users SET role = 'HR' WHERE username = 'tu_usuario';
```

### No aparecen empleados en HR

**Solución:** Ejecuta el script de migración de empleados (ver arriba).

### Nómina no calcula horas

**Solución:** Verifica que:
1. Existan registros en la tabla `attendance`
2. Los empleados tengan `hourly_rate` configurado
3. El período de nómina tenga fechas correctas

## 📊 Datos de Prueba (Opcional)

Para probar el sistema, puedes insertar datos de ejemplo:

```sql
-- Empleado de prueba
INSERT INTO employees (user_id, employee_code, first_name, last_name, email, phone, birth_date, hire_date, position, department_id, employment_status)
VALUES (
    (SELECT id FROM users WHERE username = 'agentdemo'),
    'EMP-2025-0100',
    'Juan',
    'Pérez',
    'juan.perez@ejemplo.com',
    '809-555-0100',
    '1990-05-15',
    '2024-11-01',
    'Agente de Soporte',
    (SELECT id FROM departments WHERE name = 'Operations'),
    'TRIAL'
);

-- Balance de vacaciones
INSERT INTO vacation_balances (employee_id, year, total_days, used_days, remaining_days)
VALUES (
    (SELECT id FROM employees WHERE employee_code = 'EMP-2025-0100'),
    2025,
    14.00,
    0.00,
    14.00
);
```

## 📱 Acceso Rápido

Después de la instalación, accede a:

| Módulo | URL |
|--------|-----|
| Dashboard HR | `/hr/` |
| Empleados | `/hr/employees.php` |
| Período de Prueba | `/hr/trial_period.php` |
| Nómina | `/hr/payroll.php` |
| Cumpleaños | `/hr/birthdays.php` |
| Permisos | `/hr/permissions.php` |
| Vacaciones | `/hr/vacations.php` |
| Calendario | `/hr/calendar.php` |

## ✅ Checklist de Instalación

- [ ] Script SQL ejecutado correctamente
- [ ] Tablas creadas en la base de datos
- [ ] Permisos verificados en `section_permissions`
- [ ] Empleados existentes migrados (si aplica)
- [ ] Departamentos configurados
- [ ] Balance de vacaciones inicializado
- [ ] Acceso al módulo verificado
- [ ] Primer empleado registrado con éxito
- [ ] Primer período de nómina creado

## 🎓 Capacitación

Para capacitar a tu equipo:

1. **Administradores:** Leer `HR_MODULE_README.md` completo
2. **Personal de HR:** Enfocarse en secciones de uso diario
3. **Empleados:** Portal de autoservicio (próximamente)

## 📞 Soporte

Si encuentras problemas durante la instalación:

1. Verifica los logs de PHP en `xampp/php/logs/`
2. Revisa errores de MySQL en phpMyAdmin
3. Consulta la documentación completa en `HR_MODULE_README.md`

---

**¡Felicidades! Tu módulo de Recursos Humanos está listo para usar.** 🎉

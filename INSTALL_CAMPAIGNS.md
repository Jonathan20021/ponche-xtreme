# Instalación Rápida - Sistema de Campañas

## Pasos de Instalación

### 1. Ejecutar Script SQL

Abrir **phpMyAdmin** o conectarse a MySQL y ejecutar:

```bash
mysql -u tu_usuario -p tu_base_de_datos < migrations/add_campaigns_system.sql
```

O copiar y ejecutar el contenido del archivo:
`c:\xampp\htdocs\ponche-xtreme\migrations\add_campaigns_system.sql`

### 2. Verificar Instalación

Ejecutar en MySQL para verificar que las tablas se crearon:

```sql
-- Verificar tabla campaigns
SHOW TABLES LIKE 'campaigns';
DESC campaigns;

-- Verificar tabla supervisor_campaigns
SHOW TABLES LIKE 'supervisor_campaigns';
DESC supervisor_campaigns;

-- Verificar que employees tiene el campo campaign_id
DESC employees;

-- Ver campañas de ejemplo
SELECT * FROM campaigns;

-- Verificar permisos
SELECT * FROM role_permissions WHERE permission_name = 'manage_campaigns';
```

### 3. Acceder al Sistema

#### Administrar Campañas:
```
URL: http://localhost/ponche-xtreme/hr/campaigns.php
Roles permitidos: Admin, HR
```

#### Crear Empleado con Campaña:
```
URL: http://localhost/ponche-xtreme/hr/new_employee.php
Roles permitidos: Admin, HR
```

#### Monitor en Tiempo Real:
```
URL: http://localhost/ponche-xtreme/supervisor_dashboard.php
Roles permitidos: Admin, HR, Supervisor
Nota: Los supervisores solo verán agentes de sus campañas asignadas
```

### 4. Configuración Inicial

1. **Crear Campañas:**
   - Ir a: `hr/campaigns.php`
   - Hacer clic en "Nueva Campaña"
   - Llenar el formulario:
     - Nombre: ej. "Soporte Técnico"
     - Código: ej. "TECH-SUPPORT"
     - Descripción: opcional
     - Color: elegir un color
     - Marcar como activa
   - Guardar

2. **Asignar Supervisores a Campañas:**
   - En la tarjeta de la campaña, clic en "Gestionar Supervisores"
   - Seleccionar supervisor del dropdown
   - Clic en "Asignar Supervisor"

3. **Asignar Agentes a Campañas:**
   - Al crear/editar un empleado en `hr/new_employee.php`
   - Seleccionar la campaña en el campo "Campaña"
   - Guardar

### 5. Probar el Sistema

1. **Como Administrador:**
   - Ir a `supervisor_dashboard.php`
   - Deberías ver TODOS los agentes (independiente de campaña)

2. **Como Supervisor:**
   - Asegurarse de que el supervisor tenga campañas asignadas
   - Ir a `supervisor_dashboard.php`
   - Solo deberías ver agentes de tus campañas asignadas
   - Las tarjetas mostrarán el código de la campaña con su color

## Estructura de Archivos

```
ponche-xtreme/
├── migrations/
│   └── add_campaigns_system.sql          # Script de instalación
├── api/
│   └── campaigns.php                      # API REST para campañas
├── hr/
│   ├── campaigns.php                      # Gestión de campañas
│   └── new_employee.php                   # Modificado para incluir campañas
├── supervisor_dashboard.php               # Modificado para filtrar por campaña
├── supervisor_realtime_api.php            # Modificado para filtrar por campaña
├── CAMPAIGNS_SYSTEM.md                    # Documentación completa
└── INSTALL_CAMPAIGNS.md                   # Este archivo
```

## Troubleshooting

### Error: "Table 'campaigns' doesn't exist"
**Solución:** Ejecutar el script SQL de migración

### Error: "Unknown column 'campaign_id' in 'field list'"
**Solución:** El campo no se agregó a la tabla employees. Ejecutar:
```sql
ALTER TABLE employees ADD COLUMN campaign_id INT UNSIGNED;
```

### Los supervisores ven todos los agentes
**Verificar:**
1. Que el supervisor tenga campañas asignadas:
   ```sql
   SELECT * FROM supervisor_campaigns WHERE supervisor_id = [ID_SUPERVISOR];
   ```
2. Que los agentes tengan campaña asignada:
   ```sql
   SELECT user_id, campaign_id FROM employees WHERE campaign_id IS NOT NULL;
   ```

### No aparece el menú de campañas
**Verificar permisos:**
```sql
SELECT * FROM role_permissions 
WHERE permission_name = 'manage_campaigns' 
AND role_name = 'Admin';
```

## Datos de Prueba

Ejecutar estos comandos para crear datos de prueba:

```sql
-- Crear campaña de prueba
INSERT INTO campaigns (name, code, description, color, is_active, created_by)
VALUES ('Campaña de Prueba', 'TEST', 'Para pruebas del sistema', '#10b981', 1, 1);

-- Obtener el ID de la campaña recién creada
SET @campaign_id = LAST_INSERT_ID();

-- Asignar un supervisor (cambiar 5 por el ID de tu supervisor)
INSERT INTO supervisor_campaigns (supervisor_id, campaign_id, assigned_by)
VALUES (5, @campaign_id, 1);

-- Asignar un agente a la campaña (cambiar 1 por el employee_id de tu agente)
UPDATE employees SET campaign_id = @campaign_id WHERE id = 1;
```

## Verificación Final

Ejecutar este query para ver el resumen:

```sql
SELECT 
    c.name as campaign_name,
    c.code as campaign_code,
    c.is_active,
    (SELECT COUNT(*) FROM supervisor_campaigns WHERE campaign_id = c.id) as supervisors_count,
    (SELECT COUNT(*) FROM employees WHERE campaign_id = c.id) as agents_count
FROM campaigns c
ORDER BY c.name;
```

## Próximos Pasos

1. ✅ Crear campañas necesarias
2. ✅ Asignar supervisores a campañas
3. ✅ Asignar agentes a campañas
4. ✅ Probar el monitor en tiempo real
5. ✅ Configurar permisos adicionales si es necesario

---

**¡Sistema listo para usar!**

Para documentación completa, ver: `CAMPAIGNS_SYSTEM.md`

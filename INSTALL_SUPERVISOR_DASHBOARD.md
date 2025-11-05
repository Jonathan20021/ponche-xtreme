# Instalación del Sistema de Monitor en Tiempo Real para Supervisores

## Guía Rápida de Instalación

### Paso 1: Verificar Archivos

Asegúrate de que estos archivos existen en tu proyecto:
- ✅ `supervisor_dashboard.php` - Página principal del monitor
- ✅ `supervisor_realtime_api.php` - API para datos en tiempo real
- ✅ `SUPERVISOR_REALTIME_SYSTEM.md` - Documentación completa

### Paso 2: Configurar Permisos

#### Opción A: Desde la Interfaz Web (Recomendado)

1. Inicia sesión como administrador
2. Ve a **Configuración** (settings.php)
3. Desplázate hasta **"Permisos por sección"**
4. Busca la sección **"Monitor en Tiempo Real"** en la categoría **"Supervisión"**
5. Marca los roles que deben tener acceso:
   - ✅ `supervisor`
   - ✅ `admin`
   - ✅ `superadmin`
6. Haz clic en **"Guardar todos los permisos"**

#### Opción B: Desde SQL

```sql
-- Dar permiso al rol 'supervisor'
INSERT INTO section_permissions (section_key, role) 
VALUES ('supervisor_dashboard', 'supervisor')
ON DUPLICATE KEY UPDATE role = role;

-- Dar permiso al rol 'admin'
INSERT INTO section_permissions (section_key, role) 
VALUES ('supervisor_dashboard', 'admin')
ON DUPLICATE KEY UPDATE role = role;

-- Dar permiso al rol 'superadmin'
INSERT INTO section_permissions (section_key, role) 
VALUES ('supervisor_dashboard', 'superadmin')
ON DUPLICATE KEY UPDATE role = role;
```

### Paso 3: Crear Rol de Supervisor (Si no existe)

Si aún no tienes un rol llamado `supervisor`, créalo:

```sql
-- Crear rol de supervisor
INSERT INTO roles (name, label, description) 
VALUES ('supervisor', 'Supervisor', 'Supervisor de agentes con acceso al monitor en tiempo real')
ON DUPLICATE KEY UPDATE label = 'Supervisor';
```

### Paso 4: Asignar Rol a Usuarios

Asigna el rol de supervisor a los usuarios que deben tener acceso:

```sql
-- Ejemplo: Asignar rol supervisor al usuario 'jsmith'
UPDATE users 
SET role = 'supervisor' 
WHERE username = 'jsmith';
```

O desde la interfaz:
1. Ve a **Configuración** → **Gestionar usuarios existentes**
2. Encuentra el usuario
3. En la columna "Rol", escribe `supervisor`
4. Haz clic en **"Guardar cambios"**

### Paso 5: Verificar Instalación

1. Cierra sesión y vuelve a iniciar sesión con un usuario supervisor
2. Deberías ver **"Monitor en Tiempo Real"** en el menú lateral
3. Haz clic en el enlace
4. Deberías ver el dashboard con todos los agentes

## Verificación de Funcionamiento

### Test 1: Acceso a la Página
```
URL: http://tu-dominio.com/supervisor_dashboard.php
Resultado esperado: Dashboard con tarjetas de agentes
```

### Test 2: API Funcionando
```
URL: http://tu-dominio.com/supervisor_realtime_api.php
Resultado esperado: JSON con datos de agentes
```

### Test 3: Actualización en Tiempo Real
1. Abre el dashboard
2. Espera 5 segundos
3. Verifica que el timestamp de "Última actualización" cambie
4. El indicador "EN VIVO" debe tener un punto pulsante verde

## Configuración Adicional

### Cambiar Intervalo de Actualización

Por defecto, el sistema actualiza cada **5 segundos**. Para cambiar:

Edita `supervisor_dashboard.php`, busca:
```javascript
refreshInterval = setInterval(refreshData, 5000);
```

Cambia `5000` por el valor deseado en milisegundos:
- 3 segundos = `3000`
- 10 segundos = `10000`
- 30 segundos = `30000`

### Filtrar Roles Mostrados

Por defecto, el sistema muestra todos los usuarios activos excepto admin y superadmin.

Para cambiar esto, edita `supervisor_realtime_api.php`, línea ~50:
```php
WHERE u.is_active = 1
AND u.role NOT IN ('admin', 'superadmin')
```

Agrega o quita roles según necesites.

## Permisos Recomendados para Supervisores

Un supervisor típicamente necesita estos permisos:

```sql
-- Monitor en tiempo real
INSERT INTO section_permissions (section_key, role) VALUES ('supervisor_dashboard', 'supervisor');

-- Dashboard principal
INSERT INTO section_permissions (section_key, role) VALUES ('dashboard', 'supervisor');

-- Ver registros
INSERT INTO section_permissions (section_key, role) VALUES ('records', 'supervisor');

-- Dashboard de operaciones
INSERT INTO section_permissions (section_key, role) VALUES ('operations_dashboard', 'supervisor');

-- Reportes
INSERT INTO section_permissions (section_key, role) VALUES ('hr_report', 'supervisor');
INSERT INTO section_permissions (section_key, role) VALUES ('adherence_report', 'supervisor');
```

## Solución de Problemas Comunes

### Error: "No autorizado"
**Causa**: El usuario no tiene permisos
**Solución**: Verifica que el rol del usuario tenga el permiso `supervisor_dashboard`

```sql
-- Verificar permisos del rol
SELECT * FROM section_permissions WHERE section_key = 'supervisor_dashboard';

-- Verificar rol del usuario
SELECT username, role FROM users WHERE username = 'tu_usuario';
```

### Error: "No hay agentes para mostrar"
**Causa**: No hay usuarios activos o todos son admin
**Solución**: Verifica que hay usuarios con `is_active = 1` y roles diferentes a admin/superadmin

```sql
-- Ver usuarios activos
SELECT username, role, is_active FROM users WHERE is_active = 1;
```

### La página no se actualiza automáticamente
**Causa**: JavaScript bloqueado o error en consola
**Solución**: 
1. Presiona F12 para abrir la consola del navegador
2. Ve a la pestaña "Console"
3. Busca errores en rojo
4. Verifica que `supervisor_realtime_api.php` responda correctamente

### Los colores no se muestran
**Causa**: Tipos de punch sin colores configurados
**Solución**: 
1. Ve a **Configuración** → **Tipos de punch**
2. Asigna colores a cada tipo
3. Guarda los cambios

## Características del Sistema

### ✅ Lo que YA funciona:
- ✅ Actualización automática cada 5 segundos
- ✅ Tipos de punch dinámicos (se agregan automáticamente)
- ✅ Colores personalizados por tipo
- ✅ Filtros por estado (activo, pagado, pausas, offline)
- ✅ Estadísticas en tiempo real
- ✅ Responsive (funciona en móviles)
- ✅ Indicador de tiempo en estado actual
- ✅ Badge de punch pagado/no pagado

### 🎯 Próximas mejoras sugeridas:
- Notificaciones cuando un agente lleva mucho tiempo en pausa
- Click en tarjeta para ver historial del día
- Búsqueda por nombre o departamento
- Exportar reporte del estado actual
- Gráficos de distribución por tipo de punch

## Mantenimiento

### Limpiar Caché
Si haces cambios y no se reflejan:
```bash
# Limpiar caché del navegador
Ctrl + Shift + Delete (Chrome/Edge)
Cmd + Shift + Delete (Mac)

# O forzar recarga
Ctrl + F5 (Windows)
Cmd + Shift + R (Mac)
```

### Verificar Rendimiento
```sql
-- Ver cuántos agentes hay en el sistema
SELECT COUNT(*) as total_agentes FROM users WHERE is_active = 1;

-- Ver distribución por tipo de punch actual
SELECT 
    a.type,
    COUNT(*) as cantidad
FROM users u
LEFT JOIN attendance a ON a.id = (
    SELECT a2.id FROM attendance a2 
    WHERE a2.user_id = u.id 
    ORDER BY a2.timestamp DESC LIMIT 1
)
WHERE u.is_active = 1
GROUP BY a.type;
```

## Seguridad

- ✅ Requiere autenticación
- ✅ Verifica permisos en cada request
- ✅ Usa consultas preparadas (PDO)
- ✅ Sanitiza todos los datos de salida
- ✅ No expone información sensible

## Soporte

Para más información:
- `SUPERVISOR_REALTIME_SYSTEM.md` - Documentación completa
- `PAID_PUNCH_TYPES_SYSTEM.md` - Sistema de tipos pagados/no pagados
- `settings.php` - Configuración de permisos

## Resumen de Comandos SQL Útiles

```sql
-- Ver todos los permisos configurados
SELECT sp.section_key, sp.role, r.label as role_label
FROM section_permissions sp
LEFT JOIN roles r ON r.name = sp.role
ORDER BY sp.section_key, sp.role;

-- Ver usuarios con rol supervisor
SELECT username, full_name, role, is_active
FROM users 
WHERE role = 'supervisor';

-- Ver último punch de cada usuario
SELECT 
    u.username,
    u.full_name,
    a.type,
    a.timestamp,
    TIMESTAMPDIFF(MINUTE, a.timestamp, NOW()) as minutos_en_estado
FROM users u
LEFT JOIN attendance a ON a.id = (
    SELECT a2.id FROM attendance a2 
    WHERE a2.user_id = u.id 
    ORDER BY a2.timestamp DESC LIMIT 1
)
WHERE u.is_active = 1
ORDER BY u.full_name;
```

¡El sistema está listo para usar! 🚀

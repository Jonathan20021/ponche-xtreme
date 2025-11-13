# Sistema de Campañas para Supervisores y Agentes

## Descripción General

Este sistema permite organizar a los agentes en campañas específicas y asignar supervisores a cada campaña, de modo que cada supervisor solo vea los agentes de las campañas que tiene asignadas en el monitor en tiempo real.

## Características Principales

### 1. Gestión de Campañas
- Crear, editar y eliminar campañas
- Asignar nombre, código único y descripción
- Personalizar color de identificación
- Activar/desactivar campañas

### 2. Asignación de Supervisores
- Un supervisor puede tener múltiples campañas asignadas
- Múltiples supervisores pueden estar asignados a una misma campaña
- Interfaz visual para gestionar asignaciones

### 3. Asignación de Agentes
- Cada agente puede pertenecer a una campaña
- Se asigna desde el formulario de nuevo empleado
- Se puede cambiar posteriormente editando el empleado

### 4. Filtrado en Monitor en Tiempo Real
- Los supervisores solo ven agentes de sus campañas asignadas
- Los roles Admin y HR ven todos los agentes
- Los agentes sin campaña también son visibles

## Archivos Creados/Modificados

### Nuevos Archivos

1. **`migrations/add_campaigns_system.sql`**
   - Script de migración para crear las tablas necesarias
   - Crea tabla `campaigns`
   - Crea tabla `supervisor_campaigns` (relación muchos a muchos)
   - Agrega campo `campaign_id` a tabla `employees`
   - Inserta campañas de ejemplo
   - Configura permisos

2. **`api/campaigns.php`**
   - API REST para gestión de campañas
   - Endpoints:
     - `GET ?action=list` - Listar todas las campañas
     - `GET ?action=active` - Listar campañas activas
     - `GET ?action=get&id=X` - Obtener campaña específica
     - `GET ?action=supervisors` - Listar supervisores disponibles
     - `GET ?action=my_campaigns` - Campañas del supervisor actual
     - `POST ?action=create` - Crear nueva campaña
     - `POST ?action=assign_supervisor` - Asignar supervisor a campaña
     - `POST ?action=unassign_supervisor` - Desasignar supervisor
     - `PUT` - Actualizar campaña existente
     - `DELETE ?id=X` - Eliminar campaña

3. **`hr/campaigns.php`**
   - Interfaz de administración de campañas
   - Dashboard con estadísticas
   - Gestión de campañas (CRUD completo)
   - Asignación de supervisores
   - Solo accesible para Admin y HR

### Archivos Modificados

1. **`hr/new_employee.php`**
   - Agregado campo "Supervisor Asignado"
   - Agregado campo "Campaña" con botón para crear nueva
   - Modal para crear campañas desde el formulario
   - Los campos se guardan en la tabla employees

2. **`supervisor_realtime_api.php`**
   - Modificada consulta para incluir información de campañas
   - Filtrado por campañas asignadas si el usuario es Supervisor
   - Admin y HR ven todos los agentes
   - Incluye información de campaña en la respuesta JSON

3. **`supervisor_dashboard.php`**
   - Actualizada función `createAgentCard()` para mostrar badge de campaña
   - El badge muestra el código de la campaña con su color personalizado

## Estructura de Base de Datos

### Tabla `campaigns`
```sql
CREATE TABLE campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(50) UNIQUE NOT NULL,
  description TEXT,
  is_active TINYINT(1) DEFAULT 1,
  color VARCHAR(7) DEFAULT '#6366f1',
  created_by INT UNSIGNED,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Tabla `supervisor_campaigns`
```sql
CREATE TABLE supervisor_campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supervisor_id INT UNSIGNED NOT NULL,
  campaign_id INT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  assigned_by INT UNSIGNED,
  UNIQUE KEY (supervisor_id, campaign_id),
  FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
);
```

### Modificación en `employees`
```sql
ALTER TABLE employees
ADD COLUMN campaign_id INT UNSIGNED,
ADD COLUMN supervisor_id INT UNSIGNED,
ADD FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL;
```

## Instalación

### Paso 1: Ejecutar Migración
```bash
# En phpMyAdmin o línea de comandos MySQL
mysql -u usuario -p nombre_base_datos < migrations/add_campaigns_system.sql
```

O ejecutar manualmente el contenido del archivo SQL en phpMyAdmin.

### Paso 2: Verificar Permisos
Asegurarse de que los roles Admin y HR tengan el permiso `manage_campaigns`:

```sql
SELECT * FROM role_permissions WHERE permission_name = 'manage_campaigns';
```

### Paso 3: Crear Campañas
1. Acceder a `hr/campaigns.php`
2. Crear las campañas necesarias
3. Asignar supervisores a cada campaña

### Paso 4: Asignar Agentes
1. Al crear un nuevo empleado en `hr/new_employee.php`
2. Seleccionar supervisor y campaña
3. O editar empleados existentes para asignarles campaña

## Uso del Sistema

### Para Administradores/HR

1. **Crear Campaña:**
   - Ir a HR → Gestión de Campañas
   - Clic en "Nueva Campaña"
   - Completar formulario (nombre, código, descripción, color)
   - Guardar

2. **Asignar Supervisores:**
   - En la tarjeta de la campaña, clic en "Gestionar Supervisores"
   - Seleccionar supervisor del dropdown
   - Clic en "Asignar Supervisor"
   - Repetir para asignar múltiples supervisores

3. **Asignar Agentes a Campañas:**
   - Al crear/editar empleado
   - Seleccionar campaña en el formulario
   - El agente quedará asignado a esa campaña

### Para Supervisores

1. **Ver Agentes de sus Campañas:**
   - Acceder al Monitor en Tiempo Real
   - Solo verán agentes de las campañas asignadas
   - Las tarjetas mostrarán el código de la campaña con su color

2. **Consultar sus Campañas:**
   ```javascript
   // Desde JavaScript, llamar a:
   fetch('../api/campaigns.php?action=my_campaigns')
   ```

## Validaciones y Restricciones

### Seguridad
- Solo Admin y HR pueden gestionar campañas
- Los supervisores solo ven agentes de sus campañas
- Las validaciones se hacen en el backend

### Integridad de Datos
- No se puede eliminar una campaña si tiene agentes asignados
- Los códigos de campaña deben ser únicos
- Al eliminar una campaña, se eliminan automáticamente las asignaciones de supervisores (CASCADE)
- Si se elimina un supervisor, se eliminan sus asignaciones a campañas (CASCADE)

### Reglas de Negocio
- Un agente solo puede estar en una campaña a la vez
- Un supervisor puede tener múltiples campañas
- Una campaña puede tener múltiples supervisores
- Los agentes sin campaña son visibles para todos los supervisores

## Ejemplos de Código

### Crear Campaña desde JavaScript
```javascript
async function createCampaign() {
  const response = await fetch('../api/campaigns.php?action=create', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      name: 'Soporte Técnico',
      code: 'TECH-SUPPORT',
      description: 'Campaña de soporte técnico',
      color: '#3b82f6',
      is_active: 1
    })
  });
  
  const data = await response.json();
  console.log(data);
}
```

### Asignar Supervisor a Campaña
```javascript
async function assignSupervisor(supervisorId, campaignId) {
  const response = await fetch('../api/campaigns.php?action=assign_supervisor', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      supervisor_id: supervisorId,
      campaign_id: campaignId
    })
  });
  
  const data = await response.json();
  console.log(data);
}
```

### Obtener Agentes de una Campaña (PHP)
```php
$stmt = $pdo->prepare("
  SELECT e.*, u.username, u.full_name
  FROM employees e
  JOIN users u ON e.user_id = u.id
  WHERE e.campaign_id = ?
  ORDER BY e.first_name, e.last_name
");
$stmt->execute([$campaignId]);
$agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

## Troubleshooting

### Los supervisores ven todos los agentes
- Verificar que el supervisor tenga campañas asignadas en `supervisor_campaigns`
- Verificar que los agentes tengan `campaign_id` en la tabla `employees`

### No se puede crear campaña
- Verificar que el código sea único
- Verificar permisos del usuario
- Revisar logs de errores de PHP

### Los agentes no aparecen en el monitor
- Verificar que el usuario sea role='agent' y is_active=1
- Verificar que el agente tenga un registro en la tabla `employees`
- Verificar la asignación de campaña

## Mejoras Futuras

1. **Reportes por Campaña**
   - Estadísticas de asistencia por campaña
   - Comparativas entre campañas
   - Exportación de datos

2. **Notificaciones**
   - Alertar al supervisor cuando un agente de su campaña marca entrada/salida
   - Resúmenes diarios por campaña

3. **Dashboard de Campaña**
   - Vista específica por campaña
   - Métricas de rendimiento
   - Gráficas de actividad

4. **Historial de Asignaciones**
   - Registro de cambios de campaña
   - Auditoría de supervisores

## Soporte

Para preguntas o problemas, contactar al equipo de desarrollo.

---

**Versión:** 1.0  
**Fecha:** Noviembre 12, 2025  
**Autor:** Sistema de Gestión de Recursos Humanos

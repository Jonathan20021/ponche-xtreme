# Sistema de Logs de Actividad - Documentación

## Descripción General

Sistema completo de registro de actividades (Activity Logs) que rastrea todas las acciones importantes realizadas en la aplicación. Este sistema proporciona una auditoría completa de todas las operaciones realizadas por los usuarios en todos los módulos.

## Características Principales

### 📋 Registro Completo de Actividades
- **Gestión de Empleados**: Creación, edición y eliminación de empleados
- **Horarios y Turnos**: Cambios en horarios de trabajo de agentes
- **Nómina**: Generación de períodos de nómina
- **Reclutamiento**: Cambios de estado en solicitudes de empleo
- **Permisos Médicos**: Creación, aprobación y rechazo de licencias médicas
- **Tiempo Extra**: Registro y aprobación de horas extras
- **Información Bancaria**: Actualizaciones de datos bancarios
- **Tarifas**: Cambios en tarifas por hora
- **Calendario**: Creación, edición y eliminación de eventos
- **Usuarios**: Activación/desactivación de usuarios
- **Permisos**: Cambios en permisos de usuarios
- **Asistencia**: Modificaciones en registros de asistencia

### 🔍 Visor de Logs Avanzado
- **Filtros Múltiples**: Por módulo, acción, usuario, rango de fechas
- **Búsqueda**: Búsqueda en tiempo real por descripción o usuario
- **Paginación**: Navegación eficiente con 50 registros por página
- **Detalles Expandibles**: Ver valores anteriores y nuevos de cada cambio
- **Estadísticas**: Resumen de total de registros, módulos activos y usuarios
- **Badges Visuales**: Identificación rápida de módulos y acciones con colores

### 📊 Información Capturada
Para cada acción se registra:
- Usuario que realizó la acción (ID, nombre, rol)
- Módulo afectado
- Tipo de acción (create, update, delete, approve, etc.)
- Descripción legible de la acción
- Tipo y ID de la entidad afectada
- Valores anteriores (para actualizaciones)
- Valores nuevos (para creaciones/actualizaciones)
- Dirección IP del usuario
- User Agent (navegador)
- Fecha y hora exacta

## Instalación

### Paso 1: Ejecutar Migraciones de Base de Datos

Ejecuta los siguientes scripts SQL en tu base de datos:

**1. Crear tabla de logs:**
```bash
mysql -u tu_usuario -p tu_base_de_datos < migrations/add_activity_logs.sql
```

**2. Crear permiso de acceso:**
```bash
mysql -u tu_usuario -p tu_base_de_datos < migrations/add_activity_logs_permission.sql
```

O ejecuta manualmente:

```sql
-- Crear tabla de logs
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_name VARCHAR(255) NOT NULL,
    user_role VARCHAR(50) NOT NULL,
    module VARCHAR(100) NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    entity_type VARCHAR(100),
    entity_id INT,
    old_values TEXT,
    new_values TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_module (module),
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Crear permiso para admin
INSERT INTO section_permissions (section_key, role) 
VALUES ('activity_logs', 'admin')
ON DUPLICATE KEY UPDATE role = 'admin';

-- Crear permiso para HR (opcional)
INSERT INTO section_permissions (section_key, role) 
VALUES ('activity_logs', 'hr')
ON DUPLICATE KEY UPDATE role = 'hr';
```

### Paso 2: Verificar Archivos Creados

Asegúrate de que los siguientes archivos existan:

1. **Librería de Funciones**: `lib/logging_functions.php`
2. **Visor de Logs**: `hr/activity_logs.php`
3. **Migración SQL**: `migrations/add_activity_logs.sql`

### Paso 3: Verificar Integraciones

Los siguientes archivos ya tienen integrado el sistema de logging:

- `hr/new_employee.php` - Creación de empleados
- `hr/employees.php` - Edición de empleados y cambios de horario
- `hr/payroll.php` - Generación de nómina
- `hr/medical_leaves.php` - Gestión de licencias médicas
- `hr/recruitment.php` - Módulo de reclutamiento
- `hr/update_application_status.php` - Cambios de estado en solicitudes

### Paso 4: Configurar Permisos

El sistema de logs está disponible en el **menú principal** (fuera de Recursos Humanos). 

**Control de Acceso:**
- Por defecto, solo usuarios con rol `admin` tienen acceso
- Puedes otorgar el permiso `activity_logs` a otros roles desde el módulo de configuración de permisos
- El permiso se llama: **"Logs de Actividad"** (activity_logs)

## Uso del Sistema

### Acceder al Visor de Logs

1. Inicia sesión con una cuenta que tenga el permiso `activity_logs`
2. En el menú principal, haz clic en **Logs de Actividad**
3. El visor se abrirá mostrando todos los registros de actividad

### Filtrar Logs

**Por Módulo:**
- Selecciona un módulo específico del dropdown (employees, payroll, recruitment, etc.)

**Por Acción:**
- Filtra por tipo de acción (create, update, delete, approve, reject, etc.)

**Por Usuario:**
- Selecciona un usuario específico para ver solo sus acciones

**Por Fecha:**
- Define un rango de fechas usando "Fecha Desde" y "Fecha Hasta"

**Por Búsqueda:**
- Escribe palabras clave en el campo de búsqueda para filtrar por descripción o nombre de usuario

### Ver Detalles de un Log

Para logs que tienen valores anteriores o nuevos:
1. Haz clic en "Ver detalles" en la columna de Detalles
2. Se expandirá una sección mostrando:
   - Valores anteriores (en formato JSON)
   - Valores nuevos (en formato JSON)
   - User Agent del navegador utilizado

## Módulos Registrados

### 🧑‍💼 employees
- **create**: Nuevo empleado creado
- **update**: Empleado actualizado
- **delete**: Empleado eliminado

### 📅 schedules
- **update**: Horario modificado para un empleado

### 💰 payroll
- **generate**: Nómina generada para un período

### 👥 recruitment
- **application_received**: Nueva solicitud recibida
- **status_changed**: Estado de solicitud cambiado
- **interview_scheduled**: Entrevista programada
- **hired**: Candidato contratado
- **rejected**: Candidato rechazado

### 🏥 medical_leaves
- **create**: Permiso médico creado
- **update**: Permiso médico actualizado
- **approve**: Permiso médico aprobado
- **reject**: Permiso médico rechazado
- **delete**: Permiso médico eliminado

### ⏰ overtime
- **create**: Tiempo extra registrado
- **update**: Tiempo extra actualizado
- **approve**: Tiempo extra aprobado
- **reject**: Tiempo extra rechazado
- **delete**: Tiempo extra eliminado

### 📝 attendance
- **update**: Registro de asistencia modificado

### 📆 calendar
- **create**: Evento creado
- **update**: Evento actualizado
- **delete**: Evento eliminado

### 🏦 banking
- **update**: Información bancaria actualizada

### 💵 rates
- **update**: Tarifa modificada

### 👤 users
- **activate**: Usuario activado
- **deactivate**: Usuario desactivado

### 🔐 permissions
- **update**: Permisos modificados

## API de Funciones de Logging

### Función Principal

```php
log_activity($pdo, $user_id, $user_name, $user_role, $module, $action, 
             $description, $entity_type = null, $entity_id = null, 
             $old_values = null, $new_values = null)
```

### Funciones Especializadas

#### Empleados
```php
// Creación
log_employee_created($pdo, $user_id, $user_name, $user_role, $employee_id, $employee_data);

// Actualización
log_employee_updated($pdo, $user_id, $user_name, $user_role, $employee_id, $old_data, $new_data);

// Eliminación
log_employee_deleted($pdo, $user_id, $user_name, $user_role, $employee_id, $employee_data);
```

#### Horarios
```php
log_schedule_changed($pdo, $user_id, $user_name, $user_role, $employee_id, 
                     $employee_name, $old_schedule, $new_schedule);
```

#### Nómina
```php
log_payroll_generated($pdo, $user_id, $user_name, $user_role, 
                      $period_start, $period_end, $employee_count);
```

#### Reclutamiento
```php
log_recruitment_action($pdo, $user_id, $user_name, $user_role, $action, 
                       $candidate_id, $candidate_name, $details = []);
```

#### Permisos Médicos
```php
log_medical_leave_action($pdo, $user_id, $user_name, $user_role, $action, 
                         $leave_id, $employee_name, $details = []);
```

#### Tiempo Extra
```php
log_overtime_action($pdo, $user_id, $user_name, $user_role, $action, 
                    $overtime_id, $employee_name, $details = []);
```

#### Usuarios
```php
log_user_activation($pdo, $user_id, $user_name, $user_role, 
                    $target_user_id, $target_user_name, $is_active);
```

#### Permisos
```php
log_permission_changed($pdo, $user_id, $user_name, $user_role, 
                       $target_user_id, $target_user_name, 
                       $old_permissions, $new_permissions);
```

#### Asistencia
```php
log_attendance_modified($pdo, $user_id, $user_name, $user_role, 
                        $record_id, $employee_name, $old_data, $new_data);
```

#### Calendario
```php
log_calendar_event($pdo, $user_id, $user_name, $user_role, $action, 
                   $event_id, $event_title, $details = []);
```

#### Información Bancaria
```php
log_bank_info_changed($pdo, $user_id, $user_name, $user_role, 
                      $employee_id, $employee_name, $old_data, $new_data);
```

#### Tarifas
```php
log_rate_changed($pdo, $user_id, $user_name, $user_role, $employee_id, 
                 $employee_name, $old_rate, $new_rate, $effective_date);
```

#### Acción Personalizada
```php
log_custom_action($pdo, $user_id, $user_name, $user_role, $module, $action, 
                  $description, $entity_type = null, $entity_id = null, $details = []);
```

## Ejemplo de Integración

Para agregar logging a una nueva funcionalidad:

```php
<?php
// 1. Incluir la librería
require_once '../lib/logging_functions.php';

// 2. Realizar la operación
$stmt = $pdo->prepare("UPDATE employees SET position =  WHERE id = ?");
$stmt->execute(['Senior Developer', 123]);

// 3. Registrar el log
log_employee_updated(
    $pdo,
    $_SESSION['user_id'],
    $_SESSION['full_name'],
    $_SESSION['role'],
    123, // employee_id
    ['position' => 'Developer'], // old values
    ['position' => 'Senior Developer'] // new values
);
?>
```

## Consideraciones de Rendimiento

- Los logs se almacenan en una tabla optimizada con índices en campos clave
- La paginación limita la carga de datos a 50 registros por página
- Los filtros utilizan índices para búsquedas rápidas
- Los valores JSON se almacenan de forma eficiente

## Seguridad

- Solo usuarios con roles `admin` o `hr` pueden acceder a los logs
- Se registra la IP y User Agent para auditoría adicional
- Los logs son de solo lectura desde la interfaz web
- No se pueden eliminar logs desde la interfaz (integridad de auditoría)

## Mantenimiento

### Limpieza de Logs Antiguos

Para mantener el rendimiento, considera implementar una política de retención:

```sql
-- Eliminar logs mayores a 1 año
DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

-- O archivar en otra tabla
INSERT INTO activity_logs_archive SELECT * FROM activity_logs 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

### Respaldo

Incluye la tabla `activity_logs` en tus respaldos regulares de base de datos.

## Soporte

Para agregar logging a módulos adicionales:
1. Incluye `require_once '../lib/logging_functions.php'` en el archivo
2. Llama a la función de logging apropiada después de cada operación importante
3. Proporciona información descriptiva y valores relevantes

## Changelog

### Versión 1.0 (2025-11-03)
- ✅ Sistema de logs completo implementado
- ✅ Integración en módulos principales (empleados, nómina, reclutamiento, permisos médicos)
- ✅ Visor de logs con filtros avanzados
- ✅ Librería de funciones de logging
- ✅ Documentación completa
- ✅ Enlace en menú de navegación

## Próximas Mejoras

- [ ] Exportación de logs a Excel/PDF
- [ ] Dashboard de estadísticas de actividad
- [ ] Alertas por actividades sospechosas
- [ ] Integración con más módulos (vacaciones, permisos, etc.)
- [ ] API REST para consulta de logs

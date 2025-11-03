# Sistema de Horarios/Turnos de Empleados

## Descripción General

Este sistema permite asignar horarios de trabajo personalizados a cada empleado, los cuales se integran automáticamente con el sistema de ponche para calcular métricas de asistencia, adherencia y horas extras de manera precisa.

## Características Principales

- **Horarios Personalizados**: Cada empleado puede tener su propio horario de trabajo
- **Templates de Horarios**: Plantillas predefinidas para turnos comunes (mañana, tarde, noche)
- **Integración Automática**: El sistema de ponche usa automáticamente el horario del empleado
- **Flexibilidad**: Los empleados sin horario personalizado usan el horario global del sistema
- **Historial**: Soporte para cambios de horario con fechas efectivas

## Instalación

### Paso 1: Ejecutar la Migración SQL

Ejecuta el archivo de migración en tu base de datos:

```bash
mysql -u usuario -p nombre_base_datos < migrations/add_employee_schedules.sql
```

O desde phpMyAdmin:
1. Abre phpMyAdmin
2. Selecciona tu base de datos
3. Ve a la pestaña "SQL"
4. Copia y pega el contenido de `migrations/add_employee_schedules.sql`
5. Haz clic en "Ejecutar"

### Paso 2: Verificar las Tablas Creadas

La migración crea las siguientes tablas:

- **`employee_schedules`**: Almacena los horarios personalizados de cada empleado
- **`schedule_templates`**: Plantillas de horarios predefinidas

También inserta 6 templates de horarios predefinidos:
- Turno Regular (10am-7pm)
- Turno Mañana (7am-4pm)
- Turno Tarde (2pm-11pm)
- Turno Noche (10pm-7am)
- Medio Tiempo Mañana (8am-12pm)
- Medio Tiempo Tarde (1pm-5pm)

## Uso del Sistema

### Asignar Horario al Registrar un Nuevo Empleado

1. Ve a **Registro de Empleados** (`register.php`)
2. Llena los datos del empleado
3. En la sección **"Horario de Trabajo"**, selecciona el turno apropiado
4. Si no seleccionas ningún horario, el empleado usará el horario global del sistema
5. Haz clic en "Registrar empleado"

### Modificar Horario de un Empleado Existente

1. Ve a **HR > Empleados** (`hr/employees.php`)
2. Busca el empleado y haz clic en "Editar"
3. Desplázate hasta la sección **"Horario de Trabajo"**
4. Verás el horario actual del empleado (si tiene uno asignado)
5. Selecciona un nuevo horario del menú desplegable
6. Haz clic en "Guardar Cambios"

### Cómo Funciona la Integración con el Ponche

El sistema de ponche utiliza automáticamente el horario del empleado para:

1. **Calcular Adherencia**: Compara las horas trabajadas vs. las horas programadas del empleado
2. **Detectar Tardanzas**: Usa la hora de entrada del horario del empleado
3. **Calcular Horas Extras**: Usa la hora de salida del horario del empleado
4. **Reportes**: Todos los reportes usan el horario individual del empleado

### Función Principal: `getScheduleConfigForUser()`

Esta función es el corazón del sistema. Cuando necesites obtener el horario de un empleado:

```php
// Obtener horario para un usuario específico
$userId = $_SESSION['user_id'];
$schedule = getScheduleConfigForUser($pdo, $userId);

// El array $schedule contiene:
// - entry_time: Hora de entrada
// - exit_time: Hora de salida
// - lunch_time: Hora de almuerzo
// - break_time: Hora de descanso
// - lunch_minutes: Minutos de almuerzo
// - break_minutes: Minutos de descanso
// - scheduled_hours: Horas programadas por día
// - schedule_name: Nombre del horario
// - is_custom: true si es personalizado, false si es global
```

## Funciones Disponibles

### `getAllScheduleTemplates($pdo, $activeOnly = true)`
Obtiene todas las plantillas de horarios disponibles.

### `getEmployeeSchedule($pdo, $employeeId, $date = null)`
Obtiene el horario activo de un empleado para una fecha específica.

### `getEmployeeScheduleByUserId($pdo, $userId, $date = null)`
Obtiene el horario activo de un empleado por su user_id.

### `createEmployeeSchedule($pdo, $employeeId, $userId, $scheduleData)`
Crea un horario personalizado para un empleado.

### `createEmployeeScheduleFromTemplate($pdo, $employeeId, $userId, $templateId, $effectiveDate = null)`
Crea un horario para un empleado basado en una plantilla.

### `updateEmployeeSchedule($pdo, $scheduleId, $scheduleData)`
Actualiza un horario existente.

### `deactivateEmployeeSchedules($pdo, $employeeId)`
Desactiva todos los horarios de un empleado.

### `getScheduleConfigForUser($pdo, $userId, $date = null)`
**FUNCIÓN PRINCIPAL**: Obtiene el horario de un usuario (personalizado o global).

## Estructura de Datos

### Tabla `employee_schedules`

```sql
- id: ID único del horario
- employee_id: ID del empleado
- user_id: ID del usuario
- schedule_name: Nombre del turno (ej: "Turno Mañana")
- entry_time: Hora de entrada (TIME)
- exit_time: Hora de salida (TIME)
- lunch_time: Hora de almuerzo (TIME)
- break_time: Hora de descanso (TIME)
- lunch_minutes: Minutos de almuerzo (INT)
- break_minutes: Minutos de descanso (INT)
- scheduled_hours: Horas programadas (DECIMAL)
- is_active: Si está activo (BOOLEAN)
- effective_date: Fecha desde cuando aplica (DATE)
- end_date: Fecha hasta cuando aplica (DATE, NULL = indefinido)
- notes: Notas adicionales (TEXT)
```

### Tabla `schedule_templates`

```sql
- id: ID único de la plantilla
- name: Nombre de la plantilla
- description: Descripción
- entry_time: Hora de entrada
- exit_time: Hora de salida
- lunch_time: Hora de almuerzo
- break_time: Hora de descanso
- lunch_minutes: Minutos de almuerzo
- break_minutes: Minutos de descanso
- scheduled_hours: Horas programadas
- is_active: Si está activa
```

## Migración de Sistemas Existentes

Si ya tienes empleados en el sistema:

1. **Opción 1 - Asignación Manual**: 
   - Edita cada empleado y asigna su horario correspondiente

2. **Opción 2 - Asignación Masiva**:
   - Todos los empleados sin horario personalizado usarán automáticamente el horario global
   - No requiere acción adicional

3. **Opción 3 - Script SQL**:
   ```sql
   -- Asignar "Turno Regular" a todos los empleados activos
   INSERT INTO employee_schedules (employee_id, user_id, schedule_name, entry_time, exit_time, lunch_time, break_time, lunch_minutes, break_minutes, scheduled_hours, is_active, effective_date)
   SELECT 
       e.id,
       e.user_id,
       'Turno Regular (10am-7pm)',
       '10:00:00',
       '19:00:00',
       '14:00:00',
       '17:00:00',
       45,
       15,
       8.00,
       1,
       CURDATE()
   FROM employees e
   WHERE e.employment_status = 'ACTIVE'
   AND NOT EXISTS (
       SELECT 1 FROM employee_schedules es 
       WHERE es.employee_id = e.id AND es.is_active = 1
   );
   ```

## Ejemplos de Uso

### Ejemplo 1: Crear un Horario Personalizado

```php
$scheduleData = [
    'schedule_name' => 'Turno Especial',
    'entry_time' => '08:00:00',
    'exit_time' => '17:00:00',
    'lunch_time' => '12:00:00',
    'break_time' => '15:00:00',
    'lunch_minutes' => 60,
    'break_minutes' => 15,
    'scheduled_hours' => 8.00,
    'is_active' => 1,
    'effective_date' => '2025-11-01',
    'notes' => 'Horario especial para proyecto X'
];

$scheduleId = createEmployeeSchedule($pdo, $employeeId, $userId, $scheduleData);
```

### Ejemplo 2: Usar Horario en Cálculos de Ponche

```php
// En lugar de usar getScheduleConfig($pdo)
$userId = $attendance['user_id'];
$schedule = getScheduleConfigForUser($pdo, $userId, $attendanceDate);

// Ahora $schedule contiene el horario específico del empleado
$entryTime = $schedule['entry_time'];
$exitTime = $schedule['exit_time'];
$scheduledHours = $schedule['scheduled_hours'];
```

### Ejemplo 3: Cambiar Horario de un Empleado

```php
// Desactivar horarios anteriores
deactivateEmployeeSchedules($pdo, $employeeId);

// Asignar nuevo horario desde template
$templateId = 2; // Turno Mañana
createEmployeeScheduleFromTemplate($pdo, $employeeId, $userId, $templateId);
```

## Preguntas Frecuentes

**P: ¿Qué pasa si un empleado no tiene horario asignado?**  
R: Automáticamente usará el horario global del sistema configurado en `schedule_config`.

**P: ¿Puedo tener diferentes horarios para diferentes fechas?**  
R: Sí, usa los campos `effective_date` y `end_date` para programar cambios de horario.

**P: ¿Los horarios afectan el cálculo de nómina?**  
R: Sí, el sistema de nómina usa `getScheduleConfigForUser()` para calcular horas trabajadas correctamente.

**P: ¿Puedo crear mis propias plantillas de horarios?**  
R: Sí, inserta nuevos registros en la tabla `schedule_templates`.

**P: ¿Cómo elimino el horario personalizado de un empleado?**  
R: Edita el empleado y selecciona "Usar horario global del sistema" en el menú de horarios.

## Soporte y Mantenimiento

- Los horarios se almacenan con timestamps de creación y actualización
- Los cambios de horario se pueden rastrear mediante el campo `updated_at`
- Se recomienda revisar periódicamente los horarios activos para mantener la precisión

## Integración con Otros Módulos

Este sistema se integra automáticamente con:
- ✅ Sistema de Ponche (attendance)
- ✅ Reportes de Adherencia
- ✅ Cálculo de Horas Extras
- ✅ Sistema de Nómina
- ✅ Reportes HR

No se requiere configuración adicional en estos módulos.

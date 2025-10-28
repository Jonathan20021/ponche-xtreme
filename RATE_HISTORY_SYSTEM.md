# Sistema de Historial de Tarifas por Hora - Ponche Xtreme

## Descripción General

El sistema de historial de tarifas permite registrar aumentos de pago por hora con fechas efectivas específicas. Esto garantiza que los registros históricos mantengan su tarifa original mientras los nuevos registros usan la tarifa actualizada.

## Características Principales

### 1. Historial de Tarifas con Fechas Efectivas
- **Fecha Efectiva**: Cada cambio de tarifa tiene una fecha desde la cual es válida
- **Preservación Histórica**: Los cálculos de horas extras y pagos usan la tarifa vigente en la fecha del registro
- **Múltiples Monedas**: Soporta USD y DOP simultáneamente

### 2. Gestión desde Settings
- **Interfaz Intuitiva**: Pestaña dedicada "Historial de tarifas" en configuración
- **Registro de Cambios**: Formulario simple para agregar nuevas tarifas
- **Visualización por Usuario**: Historial completo de cada empleado
- **Notas y Auditoría**: Registra quién hizo el cambio y notas opcionales

## Cómo Funciona

### Lógica de Aplicación de Tarifas

```
1. Cuando se calcula el pago de un registro:
   - Se obtiene la fecha del registro (ej: 2025-01-15)
   
2. El sistema busca en hourly_rate_history:
   - La tarifa más reciente con effective_date <= fecha del registro
   - Si hay múltiples tarifas en esa fecha, usa la más reciente por ID
   
3. Si no encuentra historial:
   - Usa la tarifa actual del usuario en la tabla users
   
4. Calcula el pago:
   - Pago regular = (Horas trabajadas × Tarifa histórica)
   - Pago horas extras = (Horas extras × Tarifa histórica × Multiplicador)
```

### Ejemplo Práctico

**Escenario:**
- Empleado: Juan Pérez
- Tarifa inicial: $110/hora (desde 2024-01-01)
- Aumento: $130/hora (desde 2025-02-01)

**Registros:**
- 2025-01-28: Trabajó 9 horas → Usa $110/hora
- 2025-02-03: Trabajó 9 horas → Usa $130/hora

**Cálculos:**
```
Registro del 28 de enero:
- Horas regulares: 8 × $110 = $880
- Horas extras: 1 × $110 × 1.5 = $165
- Total: $1,045

Registro del 3 de febrero:
- Horas regulares: 8 × $130 = $1,040
- Horas extras: 1 × $130 × 1.5 = $195
- Total: $1,235
```

## Uso del Sistema

### Paso 1: Registrar un Cambio de Tarifa

1. Ir a **Settings** → **Historial de tarifas**
2. En el formulario "Registrar cambio de tarifa":
   - **Usuario**: Seleccionar el empleado
   - **Nueva tarifa USD**: Ingresar la nueva tarifa en dólares
   - **Nueva tarifa DOP**: Ingresar la nueva tarifa en pesos
   - **Fecha efectiva**: Fecha desde la cual aplica (puede ser futura)
   - **Notas**: Opcional - Razón del cambio (ej: "Aumento anual 2025")
3. Clic en **Registrar cambio de tarifa**

### Paso 2: Visualizar Historial

En la misma página, verás tarjetas para cada usuario con historial:
- **Tarifa actual**: Muestra la tarifa vigente hoy
- **Tabla de historial**: Todas las tarifas anteriores con:
  - Fecha efectiva
  - Tarifas USD y DOP
  - Notas del cambio
  - Quién lo registró
  - Fecha de registro
  - Opción para eliminar

### Paso 3: Verificar Cálculos

1. Ir a **Records** → **Resumen de tiempo trabajado**
2. Los pagos se calcularán automáticamente usando la tarifa histórica correcta
3. Columnas relevantes:
   - **Pago HE (USD)**: Usa la tarifa vigente en esa fecha
   - **Pago Total (USD)**: Suma de pago regular + horas extras

## Base de Datos

### Tabla: hourly_rate_history

```sql
CREATE TABLE `hourly_rate_history` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `hourly_rate_usd` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `hourly_rate_dop` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `effective_date` DATE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
);
```

### Índices Optimizados

- `idx_user_effective_date`: Para búsquedas rápidas por usuario y fecha
- `idx_effective_date`: Para consultas por rango de fechas
- `idx_user_date_lookup`: Índice compuesto para máximo rendimiento

## Migración

### Para Bases de Datos Existentes

Ejecuta el script de migración:

```bash
# En phpMyAdmin o línea de comandos MySQL
source migrations/add_hourly_rate_history.sql;
```

O ejecuta manualmente:

```sql
-- Crear la tabla
CREATE TABLE IF NOT EXISTS `hourly_rate_history` (
  -- ... (ver estructura completa en el archivo de migración)
);

-- Poblar con tarifas iniciales
INSERT INTO `hourly_rate_history` 
  (`user_id`, `hourly_rate_usd`, `hourly_rate_dop`, `effective_date`, `notes`)
SELECT 
    id,
    hourly_rate,
    hourly_rate_dop,
    DATE(created_at),
    'Tarifa inicial del sistema'
FROM users;
```

## Funciones PHP Disponibles

### getUserHourlyRateForDate()
```php
// Obtiene la tarifa vigente en una fecha específica
$rate = getUserHourlyRateForDate($pdo, $userId, '2025-01-15', 'USD');
```

### addHourlyRateHistory()
```php
// Registra un nuevo cambio de tarifa
addHourlyRateHistory($pdo, $userId, $rateUsd, $rateDop, $effectiveDate, $createdBy, $notes);
```

### getUserRateHistory()
```php
// Obtiene todo el historial de un usuario
$history = getUserRateHistory($pdo, $userId);
```

### deleteRateHistoryEntry()
```php
// Elimina una entrada del historial
deleteRateHistoryEntry($pdo, $historyId);
```

## Casos de Uso

### Caso 1: Aumento Anual Programado
```
Fecha actual: 2025-01-15
Acción: Registrar aumento con fecha efectiva 2025-02-01
Resultado: Los registros de enero usan tarifa antigua, febrero usa nueva
```

### Caso 2: Corrección Retroactiva
```
Situación: Se olvidó registrar un aumento que debió aplicar desde hace 2 meses
Acción: Registrar con fecha efectiva en el pasado
Resultado: Al recalcular reportes, los registros desde esa fecha usan la nueva tarifa
```

### Caso 3: Promoción de Empleado
```
Empleado promovido de agente a supervisor
Acción: Registrar nueva tarifa con fecha de promoción y nota "Promoción a Supervisor"
Resultado: Historial documenta el cambio y aplica automáticamente
```

### Caso 4: Ajuste por Inflación
```
Ajuste general del 10% a todos los empleados
Acción: Registrar cambio para cada usuario con misma fecha efectiva
Resultado: Todos los empleados reciben el ajuste desde la fecha especificada
```

## Mejores Prácticas

### 1. Fechas Efectivas
- ✅ Usar la fecha real del cambio (no la fecha de registro)
- ✅ Planificar aumentos futuros con fechas efectivas adelantadas
- ❌ Evitar fechas efectivas muy antiguas sin justificación

### 2. Notas Descriptivas
- ✅ "Aumento anual 2025 - 8%"
- ✅ "Promoción a Team Lead"
- ✅ "Ajuste por inflación Q1 2025"
- ❌ Dejar notas vacías o genéricas

### 3. Auditoría
- El sistema registra automáticamente quién hizo el cambio
- Revisar historial antes de eliminar entradas
- Mantener al menos una entrada por usuario

### 4. Sincronización
- Al registrar un cambio, la tabla `users` se actualiza automáticamente
- La tarifa en `users` siempre refleja la más reciente
- El historial preserva todas las tarifas anteriores

## Preguntas Frecuentes

**P: ¿Qué pasa si registro una tarifa con fecha futura?**
R: Los registros actuales seguirán usando la tarifa vigente. Cuando llegue la fecha efectiva, los nuevos registros usarán la nueva tarifa automáticamente.

**P: ¿Puedo tener múltiples cambios en el mismo día?**
R: Sí, el sistema usa el ID más reciente si hay múltiples entradas con la misma fecha efectiva.

**P: ¿Afecta a los reportes ya generados?**
R: Los reportes se calculan dinámicamente, por lo que reflejarán las tarifas históricas correctas al regenerarse.

**P: ¿Puedo eliminar una entrada del historial?**
R: Sí, pero ten cuidado: eliminar una entrada puede afectar los cálculos de registros en ese rango de fechas.

**P: ¿Qué pasa si no hay historial para un usuario?**
R: El sistema usa la tarifa actual de la tabla `users` como respaldo.

**P: ¿Funciona con ambas monedas (USD y DOP)?**
R: Sí, cada entrada del historial almacena ambas tarifas y el sistema usa la moneda preferida del usuario.

## Soporte Técnico

Para problemas o preguntas adicionales sobre el sistema de historial de tarifas, contacta al administrador del sistema.

---

**Versión:** 1.0  
**Última actualización:** Octubre 2025  
**Desarrollado por:** Ponche Xtreme Team

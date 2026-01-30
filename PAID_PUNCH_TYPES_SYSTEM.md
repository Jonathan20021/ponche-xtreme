# Sistema de Tipos de Punch Pagados/No Pagados

## Descripción General

Este sistema permite configurar qué tipos de punch (asistencia) se cuentan para el cálculo de nómina y cuáles no. Esto es esencial para diferenciar entre tiempo productivo pagado y tiempo no productivo (como pausas, baños, etc.).

## Tipos de Punch Configurados

### Tipos Pagados (cuentan para nómina)
- **Disponible**: Tiempo disponible para trabajar
- **Wasapi**: Tiempo en Wasapi
- **Digitación**: Tiempo en digitación

### Tipos No Pagados (NO cuentan para nómina)
- **Entry**: Entrada (marca de inicio de jornada)
- **Exit**: Salida (marca de fin de jornada)
- **Baño**: Tiempo en baño
- **Pausa**: Pausas
- **Lunch**: Almuerzo
- **Break**: Descansos

## Configuración

### Desde la Interfaz (settings.php)

1. Navega a **Configuración** → **Tipos de punch**
2. En la tabla de tipos de asistencia, encontrarás una columna **"Pagado"** con un checkbox
3. Marca el checkbox para los tipos que deben contar para pago de nómina
4. Desmarca el checkbox para los tipos que NO deben contar
5. Haz clic en **"Actualizar tipos"** para guardar los cambios

### Desde la Base de Datos

La columna `is_paid` en la tabla `attendance_types` controla este comportamiento:
- `is_paid = 1`: El tipo de punch cuenta para nómina
- `is_paid = 0`: El tipo de punch NO cuenta para nómina

```sql
-- Ver configuración actual
SELECT slug, label, is_paid, is_active 
FROM attendance_types 
ORDER BY sort_order;

-- Marcar un tipo como pagado
UPDATE attendance_types SET is_paid = 1 WHERE slug = 'DISPONIBLE';

-- Marcar un tipo como no pagado
UPDATE attendance_types SET is_paid = 0 WHERE slug = 'BANO';
```

## Migración de Base de Datos

Para aplicar este sistema a una base de datos existente, ejecuta:

```bash
mysql -u usuario -p nombre_bd < migrations/add_is_paid_to_attendance_types.sql
```

O desde phpMyAdmin/MySQL Workbench, ejecuta el contenido del archivo:
`migrations/add_is_paid_to_attendance_types.sql`

## Impacto en el Sistema

### Módulos Afectados

1. **Nómina (hr/payroll.php)**
   - Solo cuenta horas de tipos de punch marcados como pagados
   - Calcula automáticamente horas regulares y extras basándose en tipos pagados

2. **Reportes de HR (hr_report.php)**
   - Los reportes de horas productivas consideran solo tipos pagados

3. **Exportación Excel (download_excel.php, download_excel_daily.php)**
   - Las exportaciones muestran solo horas de tipos pagados

4. **Dashboard de Operaciones**
   - Las métricas de tiempo productivo usan solo tipos pagados

## Funciones Disponibles

### `getPaidAttendanceTypeSlugs(PDO $pdo): array`

Retorna un array con los slugs de los tipos de punch que están marcados como pagados y activos.

```php
$paidTypes = getPaidAttendanceTypeSlugs($pdo);
// Resultado: ['DISPONIBLE', 'WASAPI', 'DIGITACION']
```

### `getAttendanceTypes(PDO $pdo, bool $activeOnly = false): array`

Retorna todos los tipos de asistencia, incluyendo el campo `is_paid`.

```php
$allTypes = getAttendanceTypes($pdo);
foreach ($allTypes as $type) {
    echo $type['label'] . ': ' . ($type['is_paid']  'Pagado' : 'No pagado');
}
```

## Ejemplo de Uso en Cálculo de Nómina

```php
// Obtener tipos pagados
$paidTypes = getPaidAttendanceTypeSlugs($pdo);

// Construir consulta SQL para calcular horas
$paidTypesPlaceholders = implode(',', array_fill(0, count($paidTypes), '?'));

$stmt = $pdo->prepare("
    SELECT 
        DATE(timestamp) as work_date,
        SUM(TIMESTAMPDIFF(SECOND, timestamp, 
            LEAD(timestamp) OVER (PARTITION BY DATE(timestamp) ORDER BY timestamp)
        )) as total_seconds
    FROM attendance
    WHERE user_id = ?
    AND DATE(timestamp) BETWEEN  AND ?
    AND UPPER(type) IN ($paidTypesPlaceholders)
    GROUP BY DATE(timestamp)
");

$params = array_merge([$userId, $startDate, $endDate], array_map('strtoupper', $paidTypes));
$stmt->execute($params);
```

## Ventajas del Sistema

1. **Flexibilidad**: Permite configurar fácilmente qué tipos cuentan para pago
2. **Precisión**: Evita pagar por tiempo no productivo
3. **Transparencia**: Los empleados y administradores pueden ver claramente qué se cuenta
4. **Escalabilidad**: Fácil agregar nuevos tipos de punch con su configuración de pago
5. **Auditoría**: Todos los cambios quedan registrados en la base de datos

## Notas Importantes

- Los cambios en la configuración de tipos pagados afectan **inmediatamente** a todos los cálculos de nómina futuros
- Los períodos de nómina ya calculados NO se recalculan automáticamente
- Si necesitas recalcular un período después de cambiar la configuración, debes eliminar el período y volver a calcularlo
- Asegúrate de tener al menos un tipo de punch marcado como pagado, de lo contrario no se contarán horas

## Soporte y Mantenimiento

Para agregar un nuevo tipo de punch pagado:

1. Ve a **Configuración** → **Tipos de punch**
2. Completa el formulario de "Crear nuevo tipo"
3. Marca el checkbox **"Pagado"** si debe contar para nómina
4. Guarda el nuevo tipo

El sistema automáticamente lo incluirá en todos los cálculos de nómina.

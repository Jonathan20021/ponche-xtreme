# Reporte de Asistencia Diaria

## Descripción General

El **Reporte de Asistencia Diaria** es una funcionalidad completa integrada en el módulo de Records que permite generar reportes detallados de asistencia en formato Excel (.xlsx) con diseño profesional y múltiples métricas de análisis.

## Características Principales

### 📊 Contenido del Reporte

El reporte incluye dos hojas de cálculo:

#### 1. **Hoja de Resumen**
- Periodo del reporte
- Total de registros procesados
- Total de agentes únicos
- Total de horas trabajadas
- Total de horas de pausa
- Total de horas extra
- Pagos totales por moneda (USD/DOP)
- Notas explicativas del cálculo

#### 2. **Hoja de Detalle**
Por cada agente y fecha, el reporte muestra:

- **Información del empleado**: Nombre completo, usuario, fecha
- **Duraciones por tipo de punch**: Tiempo en cada tipo de asistencia configurado
- **Total Tiempo Pago**: Suma de todos los tipos de punch marcados como pagados
- **Total Tiempo Pausa**: Suma de todos los tipos de punch no pagados (breaks, lunch, etc.)
- **Horas Extra**: Calculadas automáticamente según configuración
- **Tarifa/Hora**: Tarifa histórica aplicable a la fecha específica
- **Pago Regular**: Calculado sobre el tiempo pagado
- **Pago HE**: Pago de horas extra con multiplicador aplicado
- **Pago Total**: Suma de pago regular + pago HE

### 🎨 Diseño Profesional

- **Encabezados con gradientes de color** para fácil identificación
- **Filas alternadas** para mejor legibilidad
- **Fila de totales destacada** con formato especial
- **Anchos de columna automáticos** para visualización óptima
- **Alineación centrada** para datos numéricos
- **Formato de moneda** con símbolos ($ USD, RD$ DOP)

### 🔧 Filtros Disponibles

El reporte respeta los filtros aplicados en la página de Records:

1. **Filtro de Fechas**: 
   - Fecha única
   - Rango de fechas
   - Presets (Hoy, Ayer, Últimos 7 días, etc.)

2. **Filtro de Usuario**: 
   - Todos los usuarios
   - Usuario específico

## Cómo Usar

### Desde la Página de Records

1. Navega a la página **Records** (`records.php`)
2. Aplica los filtros deseados:
   - Selecciona el rango de fechas usando el selector de fechas
   - Opcionalmente, filtra por un usuario específico
3. Localiza la sección **"Resumen de tiempo trabajado"**
4. Haz clic en el botón **"Reporte de Asistencia Diaria"** (botón verde con icono de descarga)
5. El reporte se generará y descargará automáticamente

### Nombre del Archivo

El archivo descargado tendrá el formato:
```
Reporte_Asistencia_Diaria_[FECHAS]_[TIMESTAMP].xlsx
```

Ejemplo: `Reporte_Asistencia_Diaria_2025-11-05_20251105143022.xlsx`

## Cálculos y Lógica

### Tiempo de Trabajo Pagado

El tiempo de trabajo se calcula **SOLO** con los tipos de punch marcados como `is_paid = 1` en la tabla `attendance_types`. Esto incluye típicamente:

- DISPONIBLE
- WASAPI
- DIGITACION
- DISPONIBLE_CALL
- Otros tipos configurados como pagados

### Tiempo de Pausa

El tiempo de pausa incluye todos los tipos de punch **NO** marcados como pagados:

- BREAK
- LUNCH
- Otros tipos no pagados

### Horas Extra

Las horas extra se calculan automáticamente cuando:

1. El empleado tiene un **punch de EXIT** registrado
2. La hora de salida es **posterior** a la hora de salida configurada
3. Se aplica el **offset de inicio de horas extra** configurado (`overtime_start_minutes`)
4. Se usa el **multiplicador de horas extra** del empleado (o el predeterminado del sistema)

Fórmula:
```
Horas Extra = (Hora de Exit Real - Hora de Salida Configurada - Offset)
Pago HE = (Horas Extra / 3600) × Tarifa/Hora × Multiplicador
```

### Pagos

Los pagos se calculan usando las **tarifas históricas** para cada fecha específica, obtenidas de la tabla `rate_history`. Esto asegura que los cambios de tarifa se apliquen correctamente según las fechas.

## Requisitos Técnicos

### Dependencias

- **PHP 7.4+**
- **PhpSpreadsheet**: Librería para generación de archivos Excel
  ```bash
  composer require phpoffice/phpspreadsheet
  ```

### Base de Datos

El reporte utiliza las siguientes tablas:

- `attendance`: Registros de asistencia
- `users`: Información de usuarios
- `attendance_types`: Tipos de punch y configuración
- `rate_history`: Tarifas históricas por usuario
- `schedule_config`: Configuración de horarios y horas extra

## Archivos Involucrados

### Archivos PHP

1. **`download_daily_attendance_report.php`**: Archivo principal que genera el reporte Excel
   - Procesa filtros
   - Calcula métricas
   - Genera el archivo Excel con formato

2. **`records.php`**: Página principal de registros
   - Incluye el botón de descarga
   - Maneja los filtros
   - JavaScript para activar descarga

### Funciones Utilizadas

Desde `db.php`:

- `getAttendanceTypes()`: Obtiene tipos de asistencia
- `getPaidAttendanceTypeSlugs()`: Obtiene slugs de tipos pagados
- `getUserHourlyRates()`: Obtiene tarifas por usuario
- `getUserHourlyRateForDate()`: Obtiene tarifa histórica para fecha específica
- `getScheduleConfig()`: Obtiene configuración de horarios
- `getUserExitTimes()`: Obtiene horas de salida personalizadas
- `getUserOvertimeMultipliers()`: Obtiene multiplicadores de HE

## Permisos

El reporte está disponible para los siguientes roles:

- IT
- HR
- Admin
- Operations

## Ejemplos de Uso

### Caso 1: Reporte Diario para Todos los Agentes

```
1. Ir a Records
2. Seleccionar fecha de hoy en el selector de fechas
3. No aplicar filtro de usuario (dejar en "Todos los usuarios")
4. Click en "Reporte de Asistencia Diaria"
```

### Caso 2: Reporte Semanal para un Agente Específico

```
1. Ir a Records
2. Seleccionar "Últimos 7 días" en el selector de fechas
3. Seleccionar el agente en el filtro de usuario
4. Click en "Reporte de Asistencia Diaria"
```

### Caso 3: Reporte Mensual Completo

```
1. Ir a Records
2. Seleccionar "Este mes" en el selector de fechas
3. No aplicar filtro de usuario
4. Click en "Reporte de Asistencia Diaria"
```

## Solución de Problemas

### El reporte no se descarga

1. Verificar que PhpSpreadsheet esté instalado:
   ```bash
   composer install
   ```

2. Verificar permisos de escritura en el directorio temporal

3. Revisar logs de PHP para errores

### Los totales no coinciden

1. Verificar que los tipos de punch estén correctamente configurados en `attendance_types`
2. Confirmar que `is_paid` esté establecido correctamente
3. Revisar que existan registros de `rate_history` para los usuarios

### Las horas extra no aparecen

1. Verificar que `overtime_enabled = 1` en `schedule_config`
2. Confirmar que los usuarios tengan hora de salida configurada
3. Verificar que existan punches de tipo EXIT en las fechas

## Mejoras Futuras

- [ ] Agregar gráficos de tendencias
- [ ] Incluir comparativas mes a mes
- [ ] Agregar desglose por departamento
- [ ] Exportar también en formato PDF
- [ ] Envío automático por email
- [ ] Programación de reportes periódicos

## Notas Importantes

1. **Rendimiento**: Para rangos de fechas muy amplios (>90 días) con muchos usuarios, el reporte puede tardar varios segundos en generarse.

2. **Memoria**: La librería PhpSpreadsheet requiere memoria suficiente. Para reportes muy grandes, considerar aumentar `memory_limit` en PHP.

3. **Zona Horaria**: Todos los cálculos usan la zona horaria `America/Santo_Domingo` configurada en el sistema.

4. **Monedas**: El sistema soporta USD y DOP. Los totales se agrupan por moneda según la preferencia de cada usuario.

## Soporte

Para problemas o preguntas sobre el reporte de asistencia diaria, contactar al equipo de IT o revisar la documentación adicional:

- `PAID_PUNCH_TYPES_SYSTEM.md`: Sistema de tipos pagados/no pagados
- `OVERTIME_SYSTEM.md`: Sistema de horas extra
- `RATE_HISTORY_SYSTEM.md`: Sistema de tarifas históricas

---

**Última actualización**: Noviembre 5, 2025
**Versión**: 1.0.0

# Sistema de Horas Extras - Ponche Xtreme

## Descripción General

El sistema de horas extras calcula automáticamente las horas trabajadas después de la hora de salida configurada para cada empleado. El cálculo incluye un multiplicador personalizable para determinar el pago adicional por horas extras.

## Características Principales

### 1. Configuración Global (Settings)
- **Activar/Desactivar**: Habilita o deshabilita el cálculo de horas extras en todo el sistema
- **Multiplicador de Pago**: Factor por el cual se multiplica la tarifa por hora (ej: 1.5 = tiempo y medio, 2.0 = tiempo doble)
- **Minutos de Gracia**: Tiempo después de la hora de salida antes de comenzar a contar horas extras (ej: 15 minutos)

### 2. Configuración por Empleado
- **Hora de Salida Personalizada**: Cada empleado puede tener su propia hora de salida (si está vacío, usa la hora global)
- **Multiplicador Personalizado**: Cada empleado puede tener su propio multiplicador de horas extras (si está vacío, usa el multiplicador global)

## Cómo se Calculan las Horas Extras

### Fórmula de Cálculo

```
1. Detectar hora de salida configurada:
   - Si el empleado tiene hora de salida personalizada → usar esa hora
   - Si no → usar hora de salida global del sistema

2. Aplicar minutos de gracia:
   Hora inicio HE = Hora salida + Minutos de gracia

3. Detectar hora de salida real:
   - Buscar el último evento "EXIT" del día
   - Si no hay EXIT → usar el último evento registrado

4. Calcular horas extras:
   Si (Hora salida real > Hora inicio HE):
       Horas extras = Hora salida real - Hora inicio HE

5. Calcular pago de horas extras:
   Multiplicador = Multiplicador personalizado del empleado O Multiplicador global
   Pago HE = (Horas extras / 3600) × Tarifa por hora × Multiplicador

6. Pago total:
   Pago Total = Pago regular + Pago de horas extras
```

### Ejemplo Práctico

**Configuración:**
- Hora de salida global: 19:00
- Multiplicador global: 1.5 (tiempo y medio)
- Minutos de gracia: 15 minutos
- Tarifa por hora del empleado: $10.00 USD

**Escenario:**
- Empleado marca EXIT a las 20:30

**Cálculo:**
1. Hora inicio HE = 19:00 + 15 min = 19:15
2. Hora salida real = 20:30
3. Horas extras = 20:30 - 19:15 = 1 hora 15 minutos = 1.25 horas
4. Pago HE = 1.25 × $10.00 × 1.5 = $18.75
5. Si trabajó 8 horas regulares: Pago regular = 8 × $10.00 = $80.00
6. **Pago Total = $80.00 + $18.75 = $98.75**

## Configuración en el Sistema

### Paso 1: Configuración Global
1. Ir a **Settings** → **Horario objetivo**
2. Desplazarse a la sección **Configuración de Horas Extras**
3. Configurar:
   - ☑ Activar cálculo de horas extras
   - Multiplicador de pago (ej: 1.50)
   - Minutos de gracia (ej: 0 o 15)
4. Guardar cambios

### Paso 2: Configuración por Empleado (Opcional)
1. Ir a **Settings** → **Gestionar usuarios existentes**
2. Para cada empleado, configurar:
   - **Hora Salida**: Hora de salida personalizada (ej: 18:00)
   - **Mult. HE**: Multiplicador personalizado (ej: 2.00 para tiempo doble)
3. Dejar vacío para usar valores globales
4. Guardar usuarios

### Paso 3: Visualizar Horas Extras
1. Ir a **Records** → **Resumen de tiempo trabajado**
2. Columnas disponibles:
   - **Horas trabajadas**: Horas regulares de trabajo
   - **Horas extra**: Tiempo trabajado después de la hora de salida
   - **Pago HE (USD)**: Pago calculado por horas extras con multiplicador
   - **Pago Total (USD)**: Pago regular + Pago de horas extras

## Casos de Uso

### Caso 1: Todos los empleados con mismo multiplicador
- Configurar multiplicador global en 1.5
- No configurar multiplicadores personalizados
- Todos usarán tiempo y medio

### Caso 2: Diferentes multiplicadores por empleado
- Configurar multiplicador global en 1.5 (por defecto)
- Empleados especiales con multiplicador 2.0 (tiempo doble)
- Empleados sin configuración personalizada usan el global

### Caso 3: Diferentes horarios de salida
- Turno matutino: Hora salida 14:00
- Turno tarde: Hora salida 19:00
- Turno noche: Hora salida 02:00
- Configurar hora de salida personalizada para cada empleado

### Caso 4: Minutos de gracia
- Configurar 15 minutos de gracia
- Empleado sale a las 19:10 → No cuenta como hora extra
- Empleado sale a las 19:20 → 5 minutos de hora extra (19:20 - 19:15)

## Migración de Base de Datos

Si tu base de datos ya existe, ejecuta el script de migración:

```sql
-- Ejecutar en phpMyAdmin o línea de comandos MySQL
source migrations/add_overtime_fields.sql;
```

O ejecuta manualmente:

```sql
ALTER TABLE `schedule_config`
ADD COLUMN `overtime_enabled` TINYINT(1) NOT NULL DEFAULT 1,
ADD COLUMN `overtime_multiplier` DECIMAL(4,2) NOT NULL DEFAULT 1.50,
ADD COLUMN `overtime_start_minutes` INT NOT NULL DEFAULT 0;

ALTER TABLE `users`
ADD COLUMN `exit_time` TIME DEFAULT NULL,
ADD COLUMN `overtime_multiplier` DECIMAL(4,2) DEFAULT NULL;
```

## Preguntas Frecuentes

**P: ¿Qué pasa si un empleado no marca EXIT?**
R: El sistema usa el último evento registrado del día como hora de salida.

**P: ¿Se pueden desactivar las horas extras?**
R: Sí, desmarca "Activar cálculo de horas extras" en Settings.

**P: ¿El multiplicador puede ser menor a 1.0?**
R: No, el sistema requiere un mínimo de 1.0 para evitar errores de cálculo.

**P: ¿Las horas extras afectan el cálculo de horas trabajadas?**
R: No, las horas trabajadas y las horas extras son independientes. Las horas extras son adicionales.

**P: ¿Puedo tener diferentes multiplicadores por día de la semana?**
R: Actualmente no, pero puedes configurar multiplicadores personalizados por empleado.

## Soporte Técnico

Para problemas o preguntas adicionales sobre el sistema de horas extras, contacta al administrador del sistema.

---

**Versión:** 1.0  
**Última actualización:** Octubre 2025  
**Desarrollado por:** Ponche Xtreme Team

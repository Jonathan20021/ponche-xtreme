# Sistema de Horas Extras - Ponche Xtreme

## Descripción General

El sistema de horas extras de nómina calcula automáticamente el excedente semanal sobre 44 horas efectivas pagadas. El cálculo incluye un multiplicador personalizable para determinar el pago adicional por horas extras.

## Características Principales

### 1. Configuración Global (Settings)
- **Activar/Desactivar**: Habilita o deshabilita el cálculo de horas extras en todo el sistema
- **Multiplicador de Pago**: Factor por el cual se multiplica la tarifa por hora (ej: 1.5 = tiempo y medio, 2.0 = tiempo doble)
- **Minutos de Gracia**: Se usa en reportes diarios y alertas operativas de salida; no define el corte de horas extra de nómina.

### 2. Configuración por Empleado
- **Hora de Salida Personalizada**: Se usa para reportes diarios y alertas operativas de salida
- **Multiplicador Personalizado**: Cada empleado puede tener su propio multiplicador de horas extras (si está vacío, usa el multiplicador global)

## Cómo se Calculan las Horas Extras

### Fórmula de Cálculo de Nómina

```
1. Sumar las horas efectivas pagadas de cada día:
   - Solo cuentan tipos de asistencia marcados como pagados.
   - Pausas, lunch, baño, ENTRY y EXIT no cuentan como horas pagadas.

2. Agrupar por semana ISO:
   - Cada semana corre de lunes a domingo.

3. Separar horas regulares y extra:
   - Las primeras 44 horas semanales son regulares.
   - Solo el excedente sobre 44 horas semanales es hora extra.

4. Calcular pago de horas extras:
   Multiplicador = Multiplicador personalizado del empleado O Multiplicador global
   Pago HE = Horas extra semanales × Tarifa por hora × Multiplicador

5. Pago total:
   Pago Total = Pago regular + Pago de horas extras
```

### Ejemplo Práctico

**Configuración:**
- Multiplicador global: 1.5 (tiempo y medio)
- Tarifa por hora del empleado: $10.00 USD

**Escenario:**
- El empleado acumula 47 horas efectivas pagadas de lunes a domingo.

**Cálculo:**
1. Horas regulares = 44
2. Horas extras = 47 - 44 = 3
3. Pago HE = 3 × $10.00 × 1.5 = $45.00
4. Pago regular = 44 × $10.00 = $440.00
5. **Pago Total = $440.00 + $45.00 = $485.00**

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
   - **Horas extra**: Excedente semanal sobre 44 horas efectivas pagadas
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

### Caso 3: Semana sin horas extra aunque haya un día largo
- Lunes a jueves: 8 horas por día
- Viernes: 10 horas
- Total semanal: 42 horas
- Resultado: 0 horas extra, porque no supera 44 horas semanales

### Caso 4: Semana con horas extra
- Lunes a jueves: 8 horas por día
- Viernes: 15 horas
- Total semanal: 47 horas
- Resultado: 44 horas regulares y 3 horas extra

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
R: Para nómina, solo se calculan intervalos cerrados por los punches registrados. Si faltan marcas, RRHH debe corregir el registro antes de recalcular.

**P: ¿Se pueden desactivar las horas extras?**
R: Sí, desmarca "Activar cálculo de horas extras" en Settings.

**P: ¿El multiplicador puede ser menor a 1.0?**
R: No, el sistema requiere un mínimo de 1.0 para evitar errores de cálculo.

**P: ¿Las horas extras afectan el cálculo de horas trabajadas?**
R: No duplican las horas. El sistema separa el total semanal entre horas regulares y horas extra.

**P: ¿Puedo tener diferentes multiplicadores por día de la semana?**
R: Actualmente no, pero puedes configurar multiplicadores personalizados por empleado.

## Soporte Técnico

Para problemas o preguntas adicionales sobre el sistema de horas extras, contacta al administrador del sistema.

---

**Versión:** 1.0  
**Última actualización:** Octubre 2025  
**Desarrollado por:** Ponche Xtreme Team

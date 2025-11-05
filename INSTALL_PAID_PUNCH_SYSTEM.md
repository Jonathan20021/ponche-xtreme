# Instalación del Sistema de Tipos de Punch Pagados

## Pasos de Instalación

### 1. Ejecutar la Migración de Base de Datos

Ejecuta el siguiente archivo SQL en tu base de datos:

```bash
mysql -u hhempeos_ponche -p hhempeos_ponche < migrations/add_is_paid_to_attendance_types.sql
```

O desde phpMyAdmin:
1. Abre phpMyAdmin
2. Selecciona la base de datos `hhempeos_ponche`
3. Ve a la pestaña "SQL"
4. Copia y pega el contenido de `migrations/add_is_paid_to_attendance_types.sql`
5. Haz clic en "Continuar"

### 2. Verificar la Instalación

Ejecuta esta consulta para verificar que la columna se agregó correctamente:

```sql
DESCRIBE attendance_types;
```

Deberías ver la columna `is_paid` en la lista.

### 3. Verificar los Tipos Configurados

```sql
SELECT slug, label, is_paid, is_active 
FROM attendance_types 
ORDER BY sort_order;
```

Resultado esperado:
- **DISPONIBLE**: is_paid = 1 (Pagado)
- **WASAPI**: is_paid = 1 (Pagado)
- **DIGITACION**: is_paid = 1 (Pagado)
- **ENTRY**: is_paid = 0 (No pagado)
- **BA_NO** (Baño): is_paid = 0 (No pagado)
- **PAUSA**: is_paid = 0 (No pagado)
- **LUNCH**: is_paid = 0 (No pagado)
- **BREAK**: is_paid = 0 (No pagado)
- **EXIT**: is_paid = 0 (No pagado)

### 4. Configurar desde la Interfaz

1. Inicia sesión en el sistema
2. Ve a **Configuración** (settings.php)
3. Desplázate hasta la sección **"Tipos de asistencia"**
4. Verás una nueva columna **"Pagado"** con checkboxes
5. Ajusta según tus necesidades
6. Haz clic en **"Actualizar tipos"**

## Archivos Modificados

### Archivos Nuevos
- `migrations/add_is_paid_to_attendance_types.sql` - Migración de base de datos
- `PAID_PUNCH_TYPES_SYSTEM.md` - Documentación del sistema
- `INSTALL_PAID_PUNCH_SYSTEM.md` - Este archivo

### Archivos Modificados
- `db.php` - Agregada función `getPaidAttendanceTypeSlugs()` y actualizada `getAttendanceTypes()`
- `settings.php` - Agregado soporte para campo `is_paid` en gestión de tipos de punch
- `hr/payroll.php` - Modificado cálculo de nómina para usar solo tipos pagados

## Impacto en el Sistema

### ✅ Cambios Automáticos
- El cálculo de nómina ahora usa solo tipos de punch marcados como pagados
- Los nuevos períodos de nómina se calcularán correctamente
- La configuración es visible y editable desde la interfaz

### ⚠️ Acciones Requeridas
- **Períodos de nómina existentes**: Si tienes períodos ya calculados, deberás:
  1. Eliminar el período
  2. Volver a calcularlo para que use la nueva configuración

### 📊 Reportes Afectados
Los siguientes módulos ahora consideran solo tipos pagados:
- Módulo de Nómina (hr/payroll.php)
- Futuros reportes de horas productivas

## Pruebas Recomendadas

### 1. Verificar Configuración de Tipos
```sql
-- Ver todos los tipos y su configuración
SELECT 
    slug, 
    label, 
    is_paid,
    is_active,
    CASE WHEN is_paid = 1 THEN 'PAGADO' ELSE 'NO PAGADO' END as estado_pago
FROM attendance_types 
ORDER BY sort_order;
```

### 2. Probar Cálculo de Nómina
1. Crea un período de nómina de prueba
2. Calcula la nómina
3. Verifica que solo se cuenten horas de tipos pagados

### 3. Verificar Interfaz
1. Ve a settings.php
2. Verifica que la columna "Pagado" aparece
3. Prueba marcar/desmarcar checkboxes
4. Guarda y verifica que los cambios persisten

## Solución de Problemas

### Error: "Unknown column 'is_paid'"
**Causa**: La migración no se ejecutó correctamente.
**Solución**: Ejecuta manualmente la migración SQL.

### Los checkboxes no aparecen en settings.php
**Causa**: Caché del navegador.
**Solución**: Presiona Ctrl+F5 para recargar la página sin caché.

### Las horas no se calculan correctamente
**Causa**: Ningún tipo está marcado como pagado.
**Solución**: 
```sql
-- Marcar al menos un tipo como pagado
UPDATE attendance_types SET is_paid = 1 WHERE slug IN ('DISPONIBLE', 'WASAPI', 'DIGITACION');
```

## Configuración Personalizada

Si necesitas agregar más tipos pagados:

```sql
-- Ejemplo: Marcar "COACHING" como pagado
UPDATE attendance_types SET is_paid = 1 WHERE slug = 'COACHING';

-- Ejemplo: Marcar "MEETING" como no pagado
UPDATE attendance_types SET is_paid = 0 WHERE slug = 'MEETING';
```

## Soporte

Para más información, consulta:
- `PAID_PUNCH_TYPES_SYSTEM.md` - Documentación completa del sistema
- `migrations/add_is_paid_to_attendance_types.sql` - Script de migración

## Notas Importantes

⚠️ **IMPORTANTE**: 
- Asegúrate de tener al menos un tipo marcado como pagado
- Los cambios afectan inmediatamente a nuevos cálculos de nómina
- Los períodos ya calculados NO se recalculan automáticamente
- Haz un respaldo de la base de datos antes de ejecutar la migración

# Sistema de Monitor en Tiempo Real para Supervisores

## Descripción General

El sistema de monitor en tiempo real permite a los supervisores ver el estado actual de todos los agentes en tiempo real, mostrando en qué tipo de punch se encuentra cada uno, cuánto tiempo llevan en ese estado, y si el punch actual es pagado o no pagado.

## Características Principales

### ✨ Actualización en Tiempo Real
- **Auto-refresh cada 5 segundos**: La página se actualiza automáticamente sin necesidad de recargar
- **Indicador visual "EN VIVO"**: Muestra que los datos están actualizándose constantemente
- **Timestamp de última actualización**: Indica cuándo fue la última actualización

### 🎨 Tipos de Punch Dinámicos
- **Colores personalizados**: Cada tipo de punch muestra sus colores configurados en `attendance_types`
- **Iconos dinámicos**: Los iconos se cargan automáticamente desde la configuración
- **Nuevos tipos automáticos**: Al agregar un nuevo tipo de punch en settings.php, aparece automáticamente en el monitor

### 📊 Estadísticas en Tiempo Real
- **Total de Agentes**: Cuenta total de agentes activos en el sistema
- **Activos Hoy**: Agentes que han registrado al menos un punch hoy
- **En Punch Pagado**: Agentes actualmente en un tipo de punch que cuenta para nómina
- **En Pausa/Break**: Agentes en tipos de punch no pagados

### 🔍 Filtros Inteligentes
- **Todos**: Muestra todos los agentes
- **Activos**: Solo agentes que han marcado punch hoy
- **Punch Pagado**: Solo agentes en tipos pagados (Disponible, Wasapi, Digitación)
- **Pausas/Breaks**: Solo agentes en tipos no pagados (Baño, Pausa, Lunch, Break)
- **Sin Registro Hoy**: Agentes que no han marcado punch hoy

### 📱 Información por Agente
Cada tarjeta de agente muestra:
- **Nombre completo** con iniciales en avatar
- **Departamento** al que pertenece
- **Tipo de punch actual** con icono y color
- **Duración en estado actual** (formato: Xh Ym o Xm Ys)
- **Total de punches hoy**
- **Badge de estado**: Indica si el punch actual es pagado o no pagado

## Archivos del Sistema

### 1. `supervisor_dashboard.php`
Página principal del monitor con interfaz en tiempo real.

**Características:**
- Grid responsivo que se adapta a cualquier tamaño de pantalla
- Tarjetas de agentes con colores dinámicos según tipo de punch
- Estadísticas en tiempo real en la parte superior
- Sistema de filtros para búsqueda rápida
- Auto-refresh con JavaScript

### 2. `supervisor_realtime_api.php`
API REST que retorna datos en formato JSON.

**Endpoint:** `GET /supervisor_realtime_api.php`

**Respuesta:**
```json
{
  "success": true,
  "timestamp": "2025-11-05 09:30:00",
  "total_agents": 25,
  "agents": [
    {
      "user_id": 123,
      "username": "jdoe",
      "full_name": "John Doe",
      "department": "Ventas",
      "current_punch": {
        "type": "DISPONIBLE",
        "label": "Disponible",
        "icon": "fas fa-check-circle",
        "color_start": "#10B981",
        "color_end": "#059669",
        "is_paid": 1,
        "timestamp": "2025-11-05 09:15:00",
        "duration_seconds": 900,
        "duration_formatted": "15m 0s"
      },
      "punches_today": 8,
      "status": "active"
    }
  ],
  "types_available": [...]
}
```

## Configuración de Permisos

### Agregar Permiso a un Rol

1. Ve a **Configuración** → **Permisos por sección**
2. Busca la sección **"Monitor en Tiempo Real"** en la categoría **"Supervisión"**
3. Marca los roles que deben tener acceso (ej: `supervisor`, `admin`, `superadmin`)
4. Haz clic en **"Guardar todos los permisos"**

### Desde SQL
```sql
-- Dar permiso al rol 'supervisor'
INSERT INTO section_permissions (section_key, role) 
VALUES ('supervisor_dashboard', 'supervisor');

-- Dar permiso al rol 'admin'
INSERT INTO section_permissions (section_key, role) 
VALUES ('supervisor_dashboard', 'admin');
```

## Uso del Sistema

### Acceso
1. Inicia sesión con un usuario que tenga permisos de supervisor
2. En el menú lateral, haz clic en **"Monitor en Tiempo Real"**
3. La página se cargará mostrando todos los agentes

### Interpretación de Estados

#### 🟢 Activo (Borde Verde/Azul)
- El agente ha registrado punch hoy
- Muestra el tipo de punch actual
- Duración actualizada en tiempo real

#### 🟠 Sin Registro Hoy (Borde Naranja)
- El agente no ha marcado punch hoy
- Muestra el último punch registrado (puede ser de días anteriores)

#### ⚫ Nunca ha Marcado (Borde Gris)
- El agente nunca ha registrado un punch en el sistema

### Filtros

**Ejemplo de uso:**
1. Haz clic en **"Punch Pagado"** para ver solo agentes productivos
2. Haz clic en **"Pausas/Breaks"** para ver quién está en descanso
3. Haz clic en **"Sin Registro Hoy"** para identificar ausencias

### Actualización Manual
- Haz clic en el botón **"Actualizar"** para forzar una actualización inmediata
- El icono girará mientras se obtienen los datos

## Integración con Tipos de Punch

### Agregar Nuevo Tipo de Punch

1. Ve a **Configuración** → **Tipos de punch**
2. Completa el formulario:
   - **Nombre**: Ej. "Capacitación"
   - **Identificador**: Ej. "CAPACITACION"
   - **Icono**: Ej. "fas fa-graduation-cap"
   - **Color inicio/fin**: Selecciona colores
   - **Pagado**: Marca si cuenta para nómina
3. Guarda el nuevo tipo

**El nuevo tipo aparecerá automáticamente en el monitor** sin necesidad de modificar código.

### Ejemplo de Nuevo Tipo
```sql
INSERT INTO attendance_types 
(slug, label, icon_class, color_start, color_end, is_paid, is_active) 
VALUES 
('CAPACITACION', 'Capacitación', 'fas fa-graduation-cap', '#8B5CF6', '#6D28D9', 1, 1);
```

## Personalización

### Cambiar Intervalo de Actualización

Edita `supervisor_dashboard.php`, línea ~380:
```javascript
// Cambiar de 5000ms (5 segundos) a 10000ms (10 segundos)
refreshInterval = setInterval(refreshData, 10000);
```

### Modificar Colores de Estado

Edita el CSS en `supervisor_dashboard.php`:
```css
.status-offline .agent-card::before {
    background: linear-gradient(90deg, #tu-color-1, #tu-color-2);
}
```

### Agregar Más Estadísticas

En `supervisor_realtime_api.php`, agrega cálculos adicionales:
```php
$customStat = count(array_filter($result, function($a) {
    return $a['department'] === 'Ventas' && $a['status'] === 'active';
}));
```

## Solución de Problemas

### Los datos no se actualizan
**Causa**: JavaScript bloqueado o error en la API
**Solución**: 
1. Abre la consola del navegador (F12)
2. Verifica errores en la pestaña "Console"
3. Verifica que `supervisor_realtime_api.php` responda correctamente

### No aparecen todos los agentes
**Causa**: Filtro de usuarios en la consulta SQL
**Solución**: Verifica en `supervisor_realtime_api.php` la línea:
```php
WHERE u.is_active = 1
AND u.role NOT IN ('admin', 'superadmin')
```

### Colores no se muestran correctamente
**Causa**: Tipos de punch sin colores configurados
**Solución**: Ve a **Configuración** → **Tipos de punch** y asigna colores

### Error 401 (No autorizado)
**Causa**: Usuario sin permisos
**Solución**: Asigna el permiso `supervisor_dashboard` al rol del usuario

## Seguridad

- ✅ Requiere autenticación de sesión
- ✅ Verifica permisos con `ensurePermission()`
- ✅ Sanitiza todos los datos antes de mostrarlos
- ✅ Usa consultas preparadas (PDO) para prevenir SQL injection
- ✅ Headers de caché deshabilitados en la API

## Rendimiento

- **Optimizado para 100+ agentes**: La consulta SQL usa índices eficientemente
- **Carga mínima**: Solo transfiere datos JSON, no HTML completo
- **Actualización inteligente**: Solo actualiza el DOM cuando hay cambios
- **Sin bloqueo**: Las actualizaciones son asíncronas

## Compatibilidad

- ✅ Chrome/Edge (recomendado)
- ✅ Firefox
- ✅ Safari
- ✅ Móviles (responsive design)

## Próximas Mejoras (Opcionales)

1. **Notificaciones**: Alertas cuando un agente lleva mucho tiempo en pausa
2. **Historial**: Ver el historial de punches del día de un agente
3. **Exportar**: Descargar reporte del estado actual
4. **WebSockets**: Actualización instantánea sin polling
5. **Búsqueda**: Buscar agente por nombre o departamento

## Soporte

Para más información sobre el sistema de tipos de punch, consulta:
- `PAID_PUNCH_TYPES_SYSTEM.md` - Sistema de tipos pagados/no pagados
- `settings.php` - Configuración de tipos de punch y permisos

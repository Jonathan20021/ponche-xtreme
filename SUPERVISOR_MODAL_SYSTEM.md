# Sistema de Modal con Detalles del Agente en Tiempo Real

## Descripción

El modal de detalles del agente muestra información completa y en tiempo real sobre la actividad de un agente específico durante el día actual. Se actualiza automáticamente cada 3 segundos mientras está abierto.

## Características Principales

### 📊 Estadísticas en Tiempo Real
- **Total de Punches**: Cantidad total de registros del día
- **Tiempo Pagado**: Suma de tiempo en tipos de punch pagados (Disponible, Wasapi, Digitación)
- **Tiempo No Pagado**: Suma de tiempo en tipos no pagados (Baño, Pausa, Lunch, Break)

### 📜 Historial Cronológico
- Lista completa de todos los punches del día
- Ordenados del más reciente al más antiguo
- Cada punch muestra:
  - Icono y color del tipo
  - Nombre del tipo de punch
  - Hora exacta del registro
  - Badge indicando si es pagado o no pagado

### 📈 Gráfica de Distribución
- Gráfica tipo "doughnut" (dona) con Chart.js
- Muestra la distribución de tiempo por tipo de punch
- Colores dinámicos según configuración de cada tipo
- Tooltip con información detallada (minutos y si es pagado)
- Se actualiza en tiempo real

### 📋 Desglose por Tipo
- Lista detallada de cada tipo de punch utilizado
- Muestra:
  - Nombre del tipo
  - Cantidad de veces registrado
  - Tiempo total en ese tipo
  - Porcentaje del tiempo total
  - Badge de pagado/no pagado

## Cómo Usar

### Abrir el Modal
1. En el dashboard de supervisor, haz clic en cualquier tarjeta de agente
2. El modal se abrirá automáticamente mostrando los detalles

### Navegación
- **Cerrar**: Haz clic en el botón X o presiona la tecla `ESC`
- **Actualización**: El modal se actualiza automáticamente cada 3 segundos

### Interpretación de Datos

#### Tiempo Pagado vs No Pagado
- **Verde**: Tiempo que cuenta para nómina
- **Naranja**: Tiempo que NO cuenta para nómina

#### Porcentajes
Los porcentajes muestran qué proporción del tiempo total del día ha estado el agente en cada tipo de punch.

## Archivos del Sistema

### 1. `supervisor_agent_details_api.php`
API que retorna los detalles completos de un agente.

**Endpoint**: `GET /supervisor_agent_details_api.php?user_id={id}`

**Respuesta**:
```json
{
  "success": true,
  "timestamp": "2025-11-05 09:45:00",
  "user": {
    "id": 123,
    "username": "jdoe",
    "full_name": "John Doe",
    "role": "agent",
    "department_name": "Ventas"
  },
  "punches": [
    {
      "id": 456,
      "type": "DISPONIBLE",
      "type_label": "Disponible",
      "icon": "fas fa-check-circle",
      "color_start": "#10B981",
      "color_end": "#059669",
      "is_paid": 1,
      "timestamp": "2025-11-05 09:30:00",
      "time": "09:30 AM",
      "seconds_ago": 900
    }
  ],
  "stats": {
    "total_punches": 8,
    "paid_punches": 5,
    "unpaid_punches": 3,
    "total_paid_time": 14400,
    "total_unpaid_time": 3600,
    "total_paid_time_formatted": "4h 0m",
    "total_unpaid_time_formatted": "1h 0m",
    "total_time": 18000,
    "total_time_formatted": "5h 0m",
    "by_type": {
      "DISPONIBLE": {
        "label": "Disponible",
        "count": 3,
        "total_seconds": 10800,
        "is_paid": 1,
        "total_time_formatted": "3h 0m",
        "percentage": 60.0
      }
    }
  },
  "chart_data": {
    "labels": ["Disponible", "Lunch", "Break"],
    "data": [180, 30, 15],
    "colors": ["#10B981", "#F59E0B", "#EF4444"],
    "isPaid": [1, 0, 0]
  }
}
```

### 2. Modal en `supervisor_dashboard.php`
El modal está integrado en el dashboard principal con:
- HTML del modal
- Estilos CSS adaptados a modo claro/oscuro
- JavaScript para funcionalidad en tiempo real

## Actualización en Tiempo Real

### Dashboard Principal
- Se actualiza cada **5 segundos**
- Actualiza todas las tarjetas de agentes

### Modal de Detalles
- Se actualiza cada **3 segundos** (más frecuente)
- Solo actualiza cuando el modal está abierto
- Se detiene automáticamente al cerrar el modal

### Gráfica
- Se destruye y recrea en cada actualización
- Mantiene animaciones suaves
- Colores adaptados al tema actual

## Adaptación a Temas

### Modo Oscuro
- Fondos oscuros semi-transparentes
- Texto en tonos claros
- Bordes sutiles
- Gráfica con colores vibrantes

### Modo Claro
- Fondos blancos/claros
- Texto en tonos oscuros
- Bordes más definidos
- Gráfica con colores ajustados

## Cálculo de Tiempos

El sistema calcula el tiempo en cada tipo de punch usando la función SQL `LEAD()`:

```sql
SELECT 
    type,
    timestamp,
    LEAD(timestamp) OVER (ORDER BY timestamp) as next_timestamp,
    TIMESTAMPDIFF(SECOND, timestamp, LEAD(timestamp) OVER (ORDER BY timestamp)) as duration_seconds
FROM attendance
WHERE user_id = ?
AND DATE(timestamp) = CURDATE()
```

### Punch Activo
Si el agente está actualmente en un punch (sin siguiente registro), el tiempo se calcula hasta el momento actual:
```php
$currentDuration = time() - strtotime($lastEntry['timestamp']);
```

## Ejemplos de Uso

### Ver Detalles de un Agente
```javascript
// Abrir modal programáticamente
openAgentModal(123, 'John Doe');
```

### Cerrar Modal
```javascript
// Cerrar modal programáticamente
closeAgentModal();
```

### Obtener Datos Manualmente
```javascript
// Cargar datos sin abrir modal
const response = await fetch('supervisor_agent_details_api.php?user_id=123');
const data = await response.json();
console.log(data.stats);
```

## Personalización

### Cambiar Intervalo de Actualización del Modal

Edita `supervisor_dashboard.php`, línea ~907:
```javascript
// Cambiar de 3000ms (3 segundos) a 5000ms (5 segundos)
modalRefreshInterval = setInterval(() => {
    if (currentAgentId) {
        loadAgentDetails(currentAgentId);
    }
}, 5000);
```

### Cambiar Tipo de Gráfica

Edita `supervisor_dashboard.php`, línea ~1040:
```javascript
// Cambiar de 'doughnut' a 'pie' o 'bar'
agentChart = new Chart(ctx, {
    type: 'pie', // o 'bar', 'line', etc.
    // ...
});
```

### Agregar Más Estadísticas

En `supervisor_agent_details_api.php`, agrega cálculos adicionales:
```php
$stats['average_punch_duration'] = $stats['total_time'] / $stats['total_punches'];
$stats['first_punch_time'] = $punches[0]['timestamp'];
$stats['last_punch_time'] = end($punches)['timestamp'];
```

## Solución de Problemas

### El modal no se abre
**Causa**: Error de JavaScript
**Solución**: Abre la consola (F12) y verifica errores

### Los datos no se actualizan
**Causa**: API no responde
**Solución**: Verifica que `supervisor_agent_details_api.php` sea accesible

### La gráfica no se muestra
**Causa**: Chart.js no cargado
**Solución**: Verifica que Chart.js esté incluido en `header.php`

### Colores incorrectos en modo claro
**Causa**: Variables CSS no definidas
**Solución**: Verifica que las variables de tema estén en el CSS

## Rendimiento

### Optimizaciones Implementadas
- ✅ Consultas SQL optimizadas con índices
- ✅ Actualización solo cuando el modal está abierto
- ✅ Destrucción de gráfica al cerrar modal (libera memoria)
- ✅ Uso de `LEAD()` para cálculos eficientes
- ✅ Cache de tipos de punch

### Recomendaciones
- No abrir múltiples modales simultáneamente
- Cerrar el modal cuando no se necesite
- El sistema maneja automáticamente la limpieza de recursos

## Seguridad

- ✅ Requiere autenticación de sesión
- ✅ Verifica permisos de supervisor
- ✅ Valida ID de usuario
- ✅ Usa consultas preparadas (PDO)
- ✅ Sanitiza datos de salida

## Compatibilidad

- ✅ Chrome/Edge (recomendado)
- ✅ Firefox
- ✅ Safari
- ✅ Responsive (funciona en tablets)
- ⚠️ Móviles pequeños: modal ocupa pantalla completa

## Próximas Mejoras Sugeridas

1. **Exportar Datos**: Botón para descargar reporte del agente en PDF/Excel
2. **Comparación**: Comparar rendimiento con otros agentes
3. **Alertas**: Notificar si el agente lleva mucho tiempo en pausa
4. **Historial Extendido**: Ver datos de días anteriores
5. **Notas**: Agregar notas del supervisor sobre el agente
6. **Gráfica de Línea**: Mostrar evolución del tiempo durante el día

## Integración con Otros Módulos

El modal puede integrarse fácilmente con:
- **Sistema de Nómina**: Ver cuánto se pagará por el día actual
- **Sistema de Reportes**: Exportar datos del modal
- **Sistema de Notificaciones**: Enviar alertas basadas en comportamiento
- **Sistema de Evaluación**: Usar datos para evaluaciones de desempeño

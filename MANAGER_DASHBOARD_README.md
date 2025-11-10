# Dashboard de Gerente - Monitor de Personal Administrativo

## Descripción
Dashboard en tiempo real para que los gerentes puedan monitorear el estado de asistencia de todo el personal administrativo (todos los roles excepto AGENT).

## Características

### 🎯 Funcionalidades Principales

1. **Monitor en Tiempo Real**
   - Actualización automática cada 5 segundos
   - Vista del estado actual de todos los empleados administrativos
   - Indicador visual "EN VIVO" con animación de pulso

2. **Filtros Avanzados**
   - **Todos**: Muestra todo el personal administrativo
   - **Activos**: Solo personal que ha marcado entrada hoy
   - **Punch Pagado**: Personal en actividad remunerada
   - **Pausas/Breaks**: Personal en descanso o actividad no remunerada
   - **Sin Registro Hoy**: Personal que no ha marcado entrada
   - **Por Rol**: Filtros específicos para Supervisores, HR, Gerentes

3. **Estadísticas en Tiempo Real**
   - Total de personal administrativo
   - Personal activo hoy
   - Personal en punch pagado
   - Personal en pausa/break
   - Cantidad de supervisores

4. **Información por Empleado**
   - Nombre completo y avatar
   - Rol y departamento
   - Estado de punch actual con icono y color
   - Duración en el estado actual
   - Cantidad de punches del día
   - Indicador de si el punch es pagado o no pagado

5. **Registro Rápido**
   - El gerente puede marcar su propia asistencia
   - Botones de acceso rápido para: Entrada, Break, Almuerzo, Baño, Salida

## Instalación

### 1. Los Permisos se Asignan desde la UI

**IMPORTANTE**: Este sistema NO requiere ejecutar scripts SQL para permisos. Todo se maneja desde la interfaz de usuario.

#### Pasos para asignar permisos:

1. **Accede a Configuración**
   - Inicia sesión con un usuario administrador
   - Ve a **Configuración** (⚙️ en el menú lateral)

2. **Ve a Roles y Permisos**
   - Click en la pestaña **"Roles y Permisos"**
   - Busca la sección **"Asignar Permisos por Rol"**

3. **Asigna el Permiso**
   - En la categoría **"Gerencia"** encontrarás:
     - **Monitor Administrativos** - Monitor en tiempo real del personal administrativo
   - Marca el checkbox para los roles que necesiten acceso:
     - ✅ **manager** (Gerente) - Recomendado
     - ✅ **hr** (Recursos Humanos) - Recomendado
     - ✅ **developer** (Desarrollador) - Opcional

4. **Guarda los Cambios**
   - Click en **"Guardar Permisos"**
   - Los usuarios con estos roles verán automáticamente el menú "Monitor Administrativos"

### 2. Verificar Archivos
Asegúrate de que los siguientes archivos existan:
- `manager_dashboard.php` - Interfaz principal
- `manager_realtime_api.php` - API de datos en tiempo real
- `settings.php` - Actualizado con la sección 'manager_dashboard' en la categoría 'Gerencia'
- `header.php` - Debe incluir la entrada de menú

### 3. Verificar que Aparezca en el Menú
Una vez asignados los permisos:
- Cierra sesión y vuelve a iniciar con un usuario del rol asignado
- Deberías ver **"Monitor Administrativos"** en el menú lateral
- El icono será una corbata (👔) de color rosa

## Uso

### Acceso al Dashboard
1. Inicia sesión con un usuario que tenga rol de `manager`, `hr` o `developer`
2. En el menú lateral, busca **"Monitor Administrativos"** con el icono de corbata
3. El dashboard se abrirá mostrando todos los empleados administrativos

### Interpretación de Estados

#### Estados de Punch
- **Verde**: Personal activo en punch pagado (ENTRY, etc.)
- **Naranja**: Personal en pausa o break no pagado
- **Rojo**: Personal que ya marcó salida (EXIT)
- **Gris**: Personal sin registro hoy

#### Indicadores Visuales
- **Barra superior de color**: Representa el tipo de punch actual
- **Icono del punch**: Muestra el tipo de actividad actual
- **Duración**: Tiempo transcurrido en el estado actual
- **Badges**: Muestran cantidad de punches y si es pagado/no pagado

### Filtros Disponibles

#### Por Estado
- **Todos**: Vista completa sin filtros
- **Activos**: Solo quien ha marcado entrada hoy
- **Punch Pagado**: Empleados en actividad remunerada
- **Pausas/Breaks**: Empleados en descanso
- **Sin Registro Hoy**: Empleados sin punch del día

#### Por Rol
- **Supervisores**: Solo usuarios con rol `supervisor`
- **HR**: Solo usuarios con rol `hr`
- **Gerentes**: Solo usuarios con rol `manager`

### Actualización de Datos
- **Automática**: Cada 5 segundos
- **Manual**: Click en botón "Actualizar" (🔄)
- El indicador "EN VIVO" confirma que el dashboard está activo

## Diferencias con Supervisor Dashboard

| Característica | Supervisor Dashboard | Manager Dashboard |
|---------------|---------------------|-------------------|
| Usuarios monitoreados | Solo AGENTS | Todos excepto AGENTS |
| Roles visibles | agent | supervisor, manager, hr, developer, operations |
| Filtro por rol | ❌ No | ✅ Sí |
| Acceso | supervisor, hr, developer | manager, hr, developer |
| Estadística de supervisores | ❌ No | ✅ Sí |
| Modal de detalles | ✅ Sí | ❌ No (por ahora) |

## Estructura de Datos

### API Response (`manager_realtime_api.php`)
```json
{
  "success": true,
  "timestamp": "2025-11-10 14:30:45",
  "administrative_staff": [
    {
      "user_id": 123,
      "username": "jdoe",
      "full_name": "John Doe",
      "role": "supervisor",
      "department": "Operations",
      "current_punch": {
        "slug": "ENTRY",
        "type": "ENTRY",
        "label": "Entrada",
        "icon": "fas fa-sign-in-alt",
        "color_start": "#10b981",
        "color_end": "#059669",
        "is_paid": 1,
        "timestamp": "2025-11-10 08:00:00",
        "duration_seconds": 23445,
        "duration_formatted": "6h 30m"
      },
      "punches_today": 3,
      "status": "active"
    }
  ],
  "total_administrative": 25
}
```

## Temas (Theme Support)

El dashboard soporta tanto tema oscuro como claro:
- Variables CSS adaptables
- Colores optimizados para cada tema
- Badges y etiquetas con contraste adecuado

## Seguridad

1. **Validación de Sesión**: Verifica que el usuario esté autenticado
2. **Permisos por Rol**: Solo roles autorizados pueden acceder
3. **Cache Control**: Headers HTTP previenen cache de datos sensibles
4. **SQL Preparado**: Todas las consultas usan prepared statements

## Personalización

### Agregar Más Roles al Filtro
Edita `manager_dashboard.php` y agrega botones de filtro adicionales:

```php
<button class="filter-btn" data-filter="operations" onclick="filterAdmins('operations')">
    <i class="fas fa-cogs"></i> Operaciones
</button>
```

Y en el JavaScript:
```javascript
case 'operations':
    filtered = adminsData.filter(a => a.role === 'operations');
    break;
```

### Modificar Intervalo de Actualización
Edita `manager_dashboard.php`, busca `startAutoRefresh()`:

```javascript
function startAutoRefresh() {
    // Cambiar de 5000 (5 segundos) al valor deseado en milisegundos
    refreshInterval = setInterval(refreshData, 10000); // 10 segundos
}
```

## Troubleshooting

### El dashboard no aparece en el menú
1. Verifica que hayas asignado el permiso desde **Configuración > Roles y Permisos**
2. Cierra sesión e inicia nuevamente
3. Confirma que tu usuario tenga uno de los roles autorizados
4. Revisa la consola del navegador para errores

### No puedo asignar permisos desde Settings
1. Verifica que tu usuario tenga acceso a "Configuración"
2. Asegúrate de que `settings.php` incluya la entrada `manager_dashboard` en el array `$sections`
3. Verifica que la categoría sea "Gerencia"

### El dashboard no carga datos
1. Verifica que `manager_realtime_api.php` sea accesible
2. Revisa la consola del navegador para errores JavaScript
3. Verifica los permisos en la tabla `section_permissions`

### No veo empleados administrativos
1. Verifica que existan usuarios con roles diferentes a `agent`
2. Confirma que los usuarios tengan `is_active = 1`
3. Revisa la consulta SQL en `manager_realtime_api.php`

### Los filtros no funcionan
1. Verifica que el JavaScript se esté cargando correctamente
2. Revisa la consola para errores
3. Confirma que `adminsData` se esté poblando correctamente

### Los permisos no funcionan
1. Verifica que los permisos estén asignados correctamente desde la UI
2. Ejecuta esta consulta para verificar:
```sql
SELECT sp.*, r.label 
FROM section_permissions sp
LEFT JOIN roles r ON r.name = sp.role
WHERE sp.section_key = 'manager_dashboard';
```
3. Si no hay resultados, asigna los permisos desde **Configuración > Roles y Permisos**
4. NO ejecutes INSERT manual - siempre usa la UI

## Mejoras Futuras

- [ ] Modal de detalles por empleado (similar a supervisor_dashboard)
- [ ] Gráficas de productividad
- [ ] Exportación de reportes
- [ ] Notificaciones push para eventos importantes
- [ ] Modo Ninja para edición de punches (requiere permisos adicionales)
- [ ] Historial de estados del día
- [ ] Filtro por departamento
- [ ] Vista de calendario

## Soporte

Para reportar problemas o sugerir mejoras, contacta al equipo de desarrollo.

---

**Versión**: 1.0.0  
**Fecha**: Noviembre 2025  
**Autor**: Equipo de Desarrollo Ponche-Xtreme

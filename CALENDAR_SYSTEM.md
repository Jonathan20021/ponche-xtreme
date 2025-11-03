# Sistema de Calendario Mejorado - Recursos Humanos

## Descripción General

El calendario de Recursos Humanos ha sido completamente mejorado con funcionalidad tipo Google Calendar, permitiendo crear, editar y gestionar eventos personalizados además de visualizar cumpleaños, permisos y vacaciones.

## Características Principales

### 1. **Eventos Personalizados**
- Crear eventos con título, descripción, fecha y hora
- Múltiples tipos de eventos:
  - 🤝 Reuniones
  - 🔔 Recordatorios
  - 🚩 Fechas límite
  - ⭐ Feriados
  - 🎓 Capacitaciones
  - 📅 Otros

### 2. **Interfaz Intuitiva**
- **Botón "Crear Evento"**: En la parte superior derecha del calendario
- **Botón "+" en cada día**: Aparece al pasar el mouse sobre cualquier día del mes
- **Click en eventos**: Ver detalles completos de eventos personalizados
- **Colores personalizables**: 8 colores predefinidos para categorizar eventos

### 3. **Gestión Completa de Eventos**
- ✅ Crear nuevos eventos
- ✏️ Editar eventos existentes
- 🗑️ Eliminar eventos
- 👁️ Ver detalles completos

### 4. **Opciones de Eventos**
- **Todo el día**: Para eventos sin hora específica
- **Horario específico**: Hora de inicio y fin
- **Ubicación**: Agregar lugar del evento
- **Descripción**: Detalles adicionales del evento

## Instalación

### Paso 1: Ejecutar Migración de Base de Datos

Ejecuta el siguiente archivo SQL en tu base de datos:

```bash
mysql -u root -p ponche < migrations/add_calendar_events.sql
```

O desde phpMyAdmin:
1. Abre phpMyAdmin
2. Selecciona la base de datos `ponche`
3. Ve a la pestaña "SQL"
4. Copia y pega el contenido de `migrations/add_calendar_events.sql`
5. Haz click en "Ejecutar"

### Paso 2: Verificar Archivos

Asegúrate de que los siguientes archivos estén presentes:

```
hr/
├── calendar.php (actualizado)
├── calendar_events_api.php (nuevo)
assets/
├── css/
│   └── calendar.css (nuevo)
├── js/
│   └── calendar.js (nuevo)
migrations/
└── add_calendar_events.sql (nuevo)
```

## Uso del Sistema

### Crear un Evento

**Opción 1: Botón Principal**
1. Haz click en el botón "Crear Evento" en la parte superior
2. Completa el formulario:
   - Título (requerido)
   - Descripción (opcional)
   - Fecha (requerido)
   - Tipo de evento
   - Horario o "Todo el día"
   - Ubicación (opcional)
   - Color
3. Haz click en "Guardar"

**Opción 2: Desde el Calendario**
1. Pasa el mouse sobre cualquier día del mes
2. Haz click en el botón "+" que aparece
3. El formulario se abrirá con la fecha preseleccionada
4. Completa los detalles y guarda

**Opción 3: Atajo de Teclado**
- Presiona `Ctrl + N` (o `Cmd + N` en Mac) para abrir el formulario rápidamente

### Ver Detalles de un Evento

1. Haz click en cualquier evento personalizado (color morado/azul)
2. Se abrirá un modal con todos los detalles:
   - Tipo de evento
   - Fecha completa
   - Horario
   - Ubicación
   - Descripción
   - Creador del evento

### Editar un Evento

1. Haz click en el evento para ver sus detalles
2. Haz click en el botón "Editar"
3. Modifica los campos necesarios
4. Guarda los cambios

**Nota**: Solo puedes editar eventos que tú creaste o si tienes permisos de administrador.

### Eliminar un Evento

1. Haz click en el evento para ver sus detalles
2. Haz click en el botón "Eliminar"
3. Confirma la eliminación

**Nota**: Solo puedes eliminar eventos que tú creaste o si tienes permisos de administrador.

## Tipos de Eventos en el Calendario

El calendario muestra diferentes tipos de eventos con colores distintivos:

| Tipo | Color | Icono | Descripción |
|------|-------|-------|-------------|
| Cumpleaños | Rosa (#ec4899) | 🎂 | Cumpleaños de empleados |
| Permisos | Morado (#8b5cf6) | 📋 | Permisos aprobados |
| Vacaciones | Cyan (#06b6d4) | 🏖️ | Vacaciones aprobadas |
| Eventos Personalizados | Variable | Variable | Eventos creados por usuarios |

## Colores Disponibles para Eventos

- 🔵 Azul Índigo (#6366f1) - Por defecto
- 🔴 Rosa (#ec4899)
- 🟣 Morado (#8b5cf6)
- 🔵 Cyan (#06b6d4)
- 🟢 Verde (#10b981)
- 🟠 Naranja (#f59e0b)
- 🔴 Rojo (#ef4444)
- ⚫ Gris (#64748b)

## Navegación del Calendario

- **Mes Anterior/Siguiente**: Usa los botones con flechas en la parte superior
- **Mes Actual**: El calendario se carga automáticamente en el mes actual
- **Día Actual**: Resaltado con borde azul

## Características Técnicas

### Base de Datos

**Tabla: `calendar_events`**
- Almacena todos los eventos personalizados
- Campos: título, descripción, fecha, hora, tipo, color, ubicación, etc.

**Tabla: `calendar_event_attendees`**
- Sistema de asistentes (preparado para futuras expansiones)
- Permite agregar empleados a eventos

**Tabla: `calendar_event_reminders`**
- Sistema de recordatorios (preparado para futuras expansiones)
- Permite configurar alertas antes de eventos

### API Endpoints

**`calendar_events_api.php`**

- `?action=create` - Crear nuevo evento
- `?action=update` - Actualizar evento existente
- `?action=delete` - Eliminar evento
- `?action=get` - Obtener detalles de un evento
- `?action=list` - Listar eventos en un rango de fechas
- `?action=add_attendee` - Agregar asistente a evento
- `?action=remove_attendee` - Remover asistente de evento

### Seguridad

- ✅ Validación de permisos: Solo usuarios con permiso `hr_calendar` pueden acceder
- ✅ Protección CSRF: Sesiones validadas
- ✅ Validación de propietario: Solo el creador o admin puede editar/eliminar
- ✅ Sanitización de datos: Todos los inputs son validados

## Atajos de Teclado

- `Ctrl + N` / `Cmd + N`: Crear nuevo evento
- `ESC`: Cerrar modales abiertos

## Responsive Design

El calendario está completamente optimizado para:
- 💻 Desktop
- 📱 Tablet
- 📱 Móvil

## Futuras Mejoras Posibles

1. **Notificaciones por Email**: Enviar recordatorios automáticos
2. **Eventos Recurrentes**: Crear eventos que se repiten
3. **Vista Semanal/Diaria**: Diferentes vistas del calendario
4. **Exportar a iCal**: Sincronizar con otros calendarios
5. **Asistentes a Eventos**: Invitar empleados específicos
6. **Integración con Permisos**: Crear eventos automáticamente desde solicitudes

## Soporte

Para problemas o sugerencias, contacta al equipo de desarrollo.

---

**Versión**: 1.0  
**Fecha**: Noviembre 2025  
**Módulo**: Recursos Humanos

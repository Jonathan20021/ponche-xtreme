# Módulo de Licencias Médicas

## 📋 Descripción General

El **Módulo de Licencias Médicas** es un sistema completo para gestionar licencias médicas, maternidad, paternidad, accidentes laborales y más dentro del sistema de Recursos Humanos de Ponche Xtreme.

## ✨ Características Principales

### 1. Tipos de Licencias
- **Médica**: Licencias por enfermedad general
- **Maternidad**: Licencias de maternidad
- **Paternidad**: Licencias de paternidad
- **Accidente**: Licencias por accidentes (laborales o no)
- **Cirugía**: Licencias por procedimientos quirúrgicos
- **Crónica**: Licencias por enfermedades crónicas

### 2. Gestión Completa
- ✅ Creación de licencias médicas con información detallada
- ✅ Aprobación/rechazo de solicitudes
- ✅ Extensión de licencias existentes
- ✅ Seguimientos médicos programados
- ✅ Estadísticas de salud por empleado
- ✅ Integración con ARS (Administradora de Riesgos de Salud)

### 3. Información Detallada
Cada licencia médica incluye:
- Empleado y datos personales
- Tipo de licencia
- Fechas de inicio y fin
- Diagnóstico médico
- Nombre del médico y centro médico
- Número de certificado médico
- Información de pago (pagada/no pagada, porcentaje)
- Indicador de accidente laboral
- Notas y razones detalladas

### 4. Extensiones de Licencias
- Registro histórico de todas las extensiones
- Razón de cada extensión
- Fechas anteriores y nuevas
- Días adicionales otorgados

### 5. Seguimientos Médicos
- Programación de citas de seguimiento
- Tipos de seguimiento (chequeo, tratamiento, terapia, examen)
- Historial completo de seguimientos
- Notas detalladas por seguimiento

### 6. Estadísticas de Salud
El sistema mantiene estadísticas por empleado:
- Total de licencias médicas por año
- Total de días en licencia
- Incidentes relacionados con el trabajo
- Última fecha de licencia médica

## 🗄️ Estructura de Base de Datos

### Tabla: `medical_leaves`
Tabla principal que almacena todas las licencias médicas.

**Campos principales:**
- `id`: Identificador único
- `employee_id`: Referencia al empleado
- `user_id`: Referencia al usuario
- `leave_type`: Tipo de licencia (MEDICAL, MATERNITY, PATERNITY, etc.)
- `diagnosis`: Diagnóstico médico
- `start_date`: Fecha de inicio
- `end_date`: Fecha de fin
- `total_days`: Total de días de licencia
- `is_paid`: Si la licencia es pagada
- `payment_percentage`: Porcentaje de pago (0-100%)
- `doctor_name`: Nombre del médico
- `medical_center`: Centro médico
- `medical_certificate_number`: Número de certificado
- `is_work_related`: Si es accidente laboral
- `status`: Estado (PENDING, APPROVED, REJECTED, EXTENDED, COMPLETED)
- `reviewed_by`: Usuario que revisó la solicitud
- `ars_claim_number`: Número de reclamación ARS

### Tabla: `medical_leave_extensions`
Registro de extensiones de licencias médicas.

**Campos principales:**
- `id`: Identificador único
- `medical_leave_id`: Referencia a la licencia médica
- `previous_end_date`: Fecha de fin anterior
- `new_end_date`: Nueva fecha de fin
- `extension_days`: Días de extensión
- `reason`: Razón de la extensión
- `status`: Estado de la extensión

### Tabla: `medical_leave_followups`
Seguimientos médicos programados.

**Campos principales:**
- `id`: Identificador único
- `medical_leave_id`: Referencia a la licencia médica
- `followup_date`: Fecha del seguimiento
- `followup_type`: Tipo (CHECKUP, TREATMENT, THERAPY, EXAM, OTHER)
- `notes`: Notas del seguimiento
- `status`: Estado (SCHEDULED, COMPLETED, CANCELLED)

### Tabla: `employee_health_stats`
Estadísticas de salud por empleado y año.

**Campos principales:**
- `id`: Identificador único
- `employee_id`: Referencia al empleado
- `year`: Año de las estadísticas
- `total_medical_leaves`: Total de licencias médicas
- `total_days_on_leave`: Total de días en licencia
- `total_work_related_incidents`: Total de incidentes laborales
- `last_medical_leave_date`: Última fecha de licencia

### Vista: `vw_medical_leaves_report`
Vista optimizada para reportes que combina información de empleados, departamentos y licencias médicas.

## 📁 Archivos del Módulo

### Backend (PHP)
- **`hr/medical_leaves.php`**: Controlador principal con toda la lógica de negocio
- **`hr/medical_leaves_view.php`**: Vista HTML principal
- **`hr/medical_leaves_modals.php`**: Modales para crear, ver, revisar y extender licencias

### Migración
- **`migrations/add_medical_leaves.sql`**: Script SQL completo para crear todas las tablas, índices y permisos

## 🚀 Instalación

### Paso 1: Ejecutar la Migración
Ejecuta el script SQL en tu base de datos:

```bash
mysql -u usuario -p nombre_base_datos < migrations/add_medical_leaves.sql
```

O desde phpMyAdmin:
1. Abre phpMyAdmin
2. Selecciona tu base de datos
3. Ve a la pestaña "SQL"
4. Copia y pega el contenido de `migrations/add_medical_leaves.sql`
5. Haz clic en "Ejecutar"

### Paso 2: Verificar Permisos
El script de migración automáticamente crea los permisos para:
- Admin
- HR
- IT

Si necesitas agregar más roles, ejecuta:

```sql
INSERT INTO section_permissions (section_key, role) VALUES
('hr_medical_leaves', 'TU_ROL_AQUI');
```

### Paso 3: Acceder al Módulo
1. Inicia sesión con un usuario que tenga permisos (Admin, HR o IT)
2. Ve al Dashboard de Recursos Humanos
3. Haz clic en "Licencias Médicas"

## 📖 Guía de Uso

### Crear una Nueva Licencia Médica

1. **Acceder al módulo**: Dashboard HR → Licencias Médicas
2. **Hacer clic en "Nueva Licencia"**
3. **Completar el formulario**:
   - Seleccionar empleado
   - Elegir tipo de licencia
   - Ingresar diagnóstico (opcional)
   - Establecer fechas de inicio y fin
   - Agregar información del médico y centro médico
   - Indicar si es pagada y el porcentaje
   - Marcar si es accidente laboral
   - Escribir la razón detallada
4. **Guardar**: El sistema calculará automáticamente los días totales

### Revisar una Solicitud

1. **Localizar la licencia pendiente** (estado PENDING)
2. **Hacer clic en el botón de revisar** (✓)
3. **Seleccionar acción**:
   - Aprobar
   - Rechazar
   - Cancelar
4. **Agregar notas de revisión** (opcional)
5. **Guardar**: La licencia cambiará de estado

### Extender una Licencia

1. **Localizar una licencia aprobada o extendida**
2. **Hacer clic en el botón de extender** (+)
3. **Ingresar nueva fecha de fin** (debe ser posterior a la actual)
4. **Explicar la razón de la extensión**
5. **Guardar**: El sistema:
   - Creará un registro de extensión
   - Actualizará los días totales
   - Cambiará el estado a EXTENDED

### Agregar Seguimiento Médico

1. **Localizar una licencia activa**
2. **Hacer clic en el botón de seguimiento** (🩺)
3. **Completar información**:
   - Fecha del seguimiento
   - Tipo de seguimiento
   - Notas detalladas
4. **Guardar**: El seguimiento quedará registrado

### Filtrar y Buscar

El módulo incluye filtros avanzados:
- **Año**: Filtrar por año específico
- **Estado**: Pendientes, Aprobadas, Extendidas, etc.
- **Tipo**: Médica, Maternidad, Paternidad, etc.
- **Empleado**: Buscar por nombre o código

## 📊 Estadísticas

El dashboard muestra:
- **Total**: Todas las licencias del año
- **Pendientes**: Licencias esperando aprobación
- **Aprobadas**: Licencias aprobadas
- **Activas**: Licencias actualmente en curso
- **Total Días**: Suma de días de todas las licencias

## 🔔 Notificaciones Automáticas

El sistema genera notificaciones automáticas para:
- Licencias médicas próximas a finalizar (2 días antes)
- Empleados que deben regresar al trabajo

## 🎨 Interfaz de Usuario

### Características Visuales
- **Tema oscuro/claro**: Compatible con el sistema de temas
- **Códigos de color por tipo**:
  - 🔴 Médica: Rojo
  - 💗 Maternidad: Rosa
  - 🔵 Paternidad: Azul
  - 🟠 Accidente: Naranja
  - 🟣 Cirugía: Púrpura
  - 🟡 Crónica: Amarillo

- **Estados visuales**:
  - 🟡 PENDING: Amarillo
  - 🟢 APPROVED: Verde
  - 🔵 EXTENDED: Azul
  - ⚫ COMPLETED: Gris
  - 🔴 REJECTED: Rojo
  - 🟠 CANCELLED: Naranja

### Indicadores Especiales
- **Licencia Activa**: Badge verde pulsante
- **Accidente Laboral**: Icono de advertencia naranja
- **Extensiones**: Contador de extensiones
- **Pago**: Indicadores de licencia no pagada o pago parcial

## 🔒 Seguridad y Permisos

### Control de Acceso
El módulo utiliza el sistema de permisos existente:
- Solo usuarios con permiso `hr_medical_leaves` pueden acceder
- Los roles Admin, HR e IT tienen acceso por defecto
- Cada acción verifica la sesión del usuario

### Auditoría
El sistema registra:
- Quién creó cada licencia
- Quién revisó/aprobó cada licencia
- Quién realizó extensiones
- Quién registró seguimientos
- Fechas y horas de todas las acciones

## 🔗 Integraciones

### Con Otros Módulos
- **Empleados**: Vinculación directa con perfiles de empleados
- **Departamentos**: Filtrado por departamento
- **Usuarios**: Control de acceso y auditoría
- **Notificaciones HR**: Alertas automáticas

### Con Sistemas Externos
- **ARS**: Campo para número de reclamación
- **Certificados Médicos**: Campos para documentación
- **Nómina**: Información de pago para integración futura

## 📈 Reportes y Análisis

### Datos Disponibles
- Historial completo de licencias por empleado
- Estadísticas anuales de salud
- Tendencias de licencias médicas
- Incidentes laborales
- Días totales de ausencia

### Vista de Reportes
La vista `vw_medical_leaves_report` proporciona:
- Información consolidada de empleados
- Datos de departamento
- Detalles completos de licencias
- Información de revisión
- Fechas calculadas de retorno

## 🛠️ Mantenimiento

### Limpieza de Datos
Se recomienda:
- Archivar licencias antiguas (más de 5 años)
- Revisar y actualizar estadísticas anualmente
- Verificar integridad de datos periódicamente

### Respaldos
Asegúrate de respaldar:
- Tabla `medical_leaves`
- Tabla `medical_leave_extensions`
- Tabla `medical_leave_followups`
- Tabla `employee_health_stats`

## 🆘 Solución de Problemas

### Error: "No se encontró el empleado"
- Verifica que el empleado existe en la tabla `employees`
- Confirma que tiene un `user_id` válido

### Error: "Permiso denegado"
- Verifica que el usuario tiene el permiso `hr_medical_leaves`
- Revisa la tabla `section_permissions`

### Las extensiones no funcionan
- Verifica que la fecha nueva es posterior a la actual
- Confirma que la licencia está en estado APPROVED o EXTENDED

## 📞 Soporte

Para soporte técnico o preguntas:
- Revisa la documentación del sistema principal
- Consulta los logs de PHP para errores
- Verifica la consola del navegador para errores JavaScript

## 🔄 Actualizaciones Futuras

Posibles mejoras:
- Carga de archivos (certificados médicos, recetas)
- Integración directa con sistema de nómina
- Reportes PDF exportables
- Dashboard de salud ocupacional
- Integración con calendario de Google/Outlook
- Notificaciones por email/SMS
- App móvil para solicitudes

## 📝 Notas Importantes

1. **Días Laborables**: El sistema cuenta días calendario, no días laborables
2. **Permisos**: Los permisos se crean automáticamente en la migración
3. **Estadísticas**: Se actualizan automáticamente al crear/aprobar licencias
4. **Extensiones**: Pueden ser múltiples para una misma licencia
5. **Seguimientos**: Son opcionales pero recomendados para casos complejos

## ✅ Checklist de Implementación

- [x] Migración de base de datos ejecutada
- [x] Permisos configurados
- [x] Módulo accesible desde Dashboard HR
- [x] Prueba de creación de licencia
- [x] Prueba de aprobación/rechazo
- [x] Prueba de extensión
- [x] Prueba de seguimientos
- [x] Verificación de estadísticas
- [x] Prueba de filtros y búsqueda

---

**Versión**: 1.0  
**Fecha**: Octubre 2025  
**Desarrollado para**: Ponche Xtreme - Sistema de Recursos Humanos

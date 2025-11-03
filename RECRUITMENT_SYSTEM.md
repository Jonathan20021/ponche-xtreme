# Sistema de Reclutamiento - Ponche Xtreme

## 📋 Descripción General

Sistema completo de reclutamiento y gestión de candidatos que permite a las empresas publicar vacantes, recibir solicitudes de empleo, gestionar el proceso de reclutamiento y mantener comunicación con los candidatos.

## 🎯 Características Principales

### Para Candidatos (Público)
- ✅ Visualización de vacantes activas
- ✅ Formulario de solicitud de empleo completo
- ✅ Carga de CV (PDF, DOC, DOCX)
- ✅ Seguimiento del estado de solicitud con código único
- ✅ Visualización de entrevistas programadas
- ✅ Mensajes del equipo de RRHH
- ✅ Historial de estados de la solicitud

### Para Recursos Humanos
- ✅ Dashboard con estadísticas de reclutamiento
- ✅ Gestión completa de vacantes (CRUD)
- ✅ Visualización y filtrado de solicitudes
- ✅ Sistema de comentarios internos y públicos
- ✅ Agendamiento de entrevistas
- ✅ Calificación de candidatos
- ✅ Cambio de estados del proceso
- ✅ Asignación de responsables
- ✅ Exportación a Excel
- ✅ Descarga de CVs
- ✅ Historial completo de actividades

## 📁 Estructura de Archivos

```
ponche-xtreme/
├── migrations/
│   └── add_recruitment_system.sql       # Migración de base de datos
├── hr/
│   ├── recruitment.php                  # Dashboard principal de RRHH
│   ├── view_application.php             # Vista detallada de solicitud
│   ├── job_postings.php                 # Gestión de vacantes
│   ├── save_job_posting.php             # Guardar nueva vacante
│   ├── toggle_job_status.php            # Activar/desactivar vacante
│   ├── update_application_status.php    # Actualizar estado de solicitud
│   ├── add_comment.php                  # Agregar comentario
│   ├── schedule_interview.php           # Agendar entrevista
│   └── export_applications.php          # Exportar a Excel
├── uploads/
│   └── cvs/                             # Directorio para CVs
├── careers.php                          # Página pública de vacantes
├── submit_application.php               # Procesar solicitud de empleo
└── track_application.php                # Rastrear estado de solicitud
```

## 🗄️ Estructura de Base de Datos

### Tablas Principales

#### `job_postings`
Almacena las vacantes publicadas por la empresa.

**Campos principales:**
- `id`: ID único
- `title`: Título del puesto
- `department`: Departamento
- `location`: Ubicación
- `employment_type`: Tipo de empleo (full_time, part_time, contract, internship)
- `description`: Descripción del puesto
- `requirements`: Requisitos
- `responsibilities`: Responsabilidades
- `salary_range`: Rango salarial
- `status`: Estado (active, inactive, closed)
- `posted_date`: Fecha de publicación
- `closing_date`: Fecha de cierre

#### `job_applications`
Almacena todas las solicitudes de empleo.

**Campos principales:**
- `id`: ID único
- `application_code`: Código único para rastreo (ej: APP-XXXXXXXX-2025)
- `job_posting_id`: ID de la vacante
- `first_name`, `last_name`: Nombre del candidato
- `email`, `phone`: Información de contacto
- `education_level`: Nivel educativo
- `years_of_experience`: Años de experiencia
- `cv_filename`, `cv_path`: Información del CV
- `status`: Estado actual (new, reviewing, shortlisted, interview_scheduled, interviewed, offer_extended, hired, rejected, withdrawn)
- `overall_rating`: Calificación del candidato (1-5)
- `assigned_to`: Usuario de RRHH asignado
- `applied_date`: Fecha de aplicación

#### `application_comments`
Comentarios sobre las solicitudes (internos y públicos).

**Campos principales:**
- `id`: ID único
- `application_id`: ID de la solicitud
- `user_id`: Usuario que comentó
- `comment`: Texto del comentario
- `is_internal`: Si es interno (no visible para candidato)

#### `recruitment_interviews`
Entrevistas programadas.

**Campos principales:**
- `id`: ID único
- `application_id`: ID de la solicitud
- `interview_type`: Tipo (phone_screening, technical, hr, manager, final, other)
- `interview_date`: Fecha y hora
- `duration_minutes`: Duración
- `location`: Ubicación o link de reunión
- `status`: Estado (scheduled, completed, cancelled, rescheduled, no_show)
- `notes`: Notas para el candidato
- `feedback`: Retroalimentación post-entrevista
- `rating`: Calificación de la entrevista (1-5)

#### `application_status_history`
Historial de cambios de estado.

**Campos principales:**
- `id`: ID único
- `application_id`: ID de la solicitud
- `old_status`: Estado anterior
- `new_status`: Nuevo estado
- `changed_by`: Usuario que hizo el cambio
- `notes`: Notas del cambio
- `changed_at`: Fecha y hora del cambio

## 🚀 Instalación

### 1. Ejecutar Migración de Base de Datos

```sql
-- Ejecutar el archivo:
migrations/add_recruitment_system.sql
```

Este script creará todas las tablas necesarias e insertará 3 vacantes de ejemplo.

### 2. Crear Directorio para CVs

```bash
mkdir -p uploads/cvs
chmod 755 uploads/cvs
```

### 3. Configurar Permisos

Asegúrate de que el usuario de RRHH tenga el rol `hr` o `admin` en la tabla `users`.

```sql
UPDATE users SET role = 'hr' WHERE id = [ID_DEL_USUARIO];
```

## 📖 Guía de Uso

### Para Candidatos

#### 1. Ver Vacantes Disponibles
- Acceder a: `careers.php`
- Ver todas las vacantes activas con detalles completos

#### 2. Aplicar a una Vacante
1. Hacer clic en "Aplicar Ahora"
2. Llenar el formulario con información personal y profesional
3. Subir CV (PDF, DOC, DOCX - máx 5MB)
4. Opcionalmente agregar carta de presentación y enlaces
5. Enviar solicitud
6. Guardar el código de seguimiento proporcionado

#### 3. Rastrear Solicitud
- Acceder a: `track_application.php`
- Ingresar código de solicitud y email
- Ver estado actual, entrevistas programadas y mensajes de RRHH

### Para Recursos Humanos

#### 1. Gestionar Vacantes
**Acceder a:** `hr/job_postings.php`

- **Crear nueva vacante:** Clic en "Nueva Vacante"
- **Editar vacante:** Clic en "Editar" en la vacante deseada
- **Activar/Desactivar:** Cambiar estado de la vacante
- **Ver solicitudes:** Clic en "Ver Solicitudes"

#### 2. Revisar Solicitudes
**Acceder a:** `hr/recruitment.php`

**Funcionalidades:**
- Dashboard con estadísticas en tiempo real
- Filtros por estado, vacante y búsqueda
- Ordenamiento por diferentes campos
- Vista rápida de información clave
- Indicadores de comentarios y entrevistas

#### 3. Gestionar Solicitud Individual
**Acceder a:** `hr/view_application.php?id=[ID]`

**Acciones disponibles:**

##### Cambiar Estado
Estados disponibles:
- **Nueva:** Solicitud recién recibida
- **En Revisión:** RRHH está revisando
- **Preseleccionado:** Candidato cumple requisitos
- **Entrevista Agendada:** Se programó entrevista
- **Entrevistado:** Entrevista completada
- **Oferta Extendida:** Se hizo oferta de trabajo
- **Contratado:** Candidato aceptó y fue contratado
- **Rechazado:** No cumple requisitos
- **Retirado:** Candidato retiró su solicitud

##### Agregar Comentarios
- **Comentarios internos:** Solo visibles para el equipo de RRHH
- **Comentarios públicos:** Visibles para el candidato en su portal de seguimiento

##### Agendar Entrevistas
Tipos de entrevista:
- Llamada de Filtro
- Entrevista Técnica
- Entrevista de RRHH
- Entrevista con Gerente
- Entrevista Final

Información a capturar:
- Fecha y hora
- Duración
- Ubicación o link de reunión
- Notas para el candidato

##### Calificar Candidato
- Calificación general de 1 a 5 estrellas
- Ayuda a comparar candidatos

##### Asignar Responsable
- Asignar la solicitud a un miembro específico del equipo de RRHH

#### 4. Exportar Datos
- Clic en "Exportar Excel" en el dashboard
- Se descargará un archivo Excel con todas las solicitudes filtradas
- Incluye información completa de candidatos

## 🔄 Flujo de Trabajo Recomendado

### Proceso Estándar de Reclutamiento

```
1. Nueva Solicitud (new)
   ↓
2. En Revisión (reviewing)
   - RRHH revisa CV y experiencia
   - Agrega comentarios internos
   ↓
3. Preseleccionado (shortlisted)
   - Candidato cumple requisitos básicos
   - Se prepara para entrevista
   ↓
4. Entrevista Agendada (interview_scheduled)
   - Se programa primera entrevista
   - Se envía notificación al candidato
   ↓
5. Entrevistado (interviewed)
   - Se completan todas las entrevistas
   - Se agrega feedback y calificación
   ↓
6. Oferta Extendida (offer_extended)
   - Se hace oferta formal al candidato
   - Se espera respuesta
   ↓
7. Contratado (hired)
   - Candidato acepta oferta
   - Proceso de onboarding
```

### Alternativas

- **Rechazado (rejected):** En cualquier etapa si no cumple requisitos
- **Retirado (withdrawn):** Si el candidato retira su solicitud

## 🎨 Características de Diseño

### Interfaz Pública (Candidatos)
- Diseño moderno con gradientes
- Totalmente responsive
- Formulario intuitivo con validación
- Drag & drop para subir CV
- Indicadores visuales de progreso
- Timeline de estados

### Interfaz de RRHH
- Dashboard con estadísticas visuales
- Tarjetas de información organizadas
- Sistema de badges para estados
- Filtros y búsqueda avanzada
- Modales para acciones rápidas
- Exportación a Excel

## 🔒 Seguridad

### Validaciones Implementadas
- ✅ Autenticación requerida para panel de RRHH
- ✅ Verificación de roles (admin, hr)
- ✅ Validación de tipos de archivo (solo PDF, DOC, DOCX)
- ✅ Límite de tamaño de archivo (5MB)
- ✅ Sanitización de inputs
- ✅ Prepared statements para prevenir SQL injection
- ✅ Códigos únicos para rastreo de solicitudes

### Recomendaciones Adicionales
- Implementar HTTPS en producción
- Configurar límites de rate limiting
- Agregar CAPTCHA al formulario público
- Implementar sistema de notificaciones por email
- Backup regular de CVs y base de datos

## 📧 Extensiones Futuras

### Funcionalidades Sugeridas
1. **Sistema de Notificaciones por Email**
   - Confirmación de solicitud
   - Recordatorios de entrevista
   - Cambios de estado

2. **Portal del Candidato**
   - Login para candidatos
   - Actualizar información
   - Subir documentos adicionales

3. **Evaluaciones en Línea**
   - Pruebas técnicas
   - Cuestionarios de personalidad
   - Evaluaciones de habilidades

4. **Integración con Calendario**
   - Sincronización con Google Calendar
   - Outlook integration
   - Recordatorios automáticos

5. **Análisis y Reportes**
   - Métricas de reclutamiento
   - Tiempo promedio de contratación
   - Fuentes de candidatos
   - Tasas de conversión

6. **Sistema de Referencias**
   - Verificación de referencias
   - Contacto automático
   - Seguimiento de respuestas

7. **Video Entrevistas**
   - Integración con Zoom/Teams
   - Grabación de entrevistas
   - Notas colaborativas

## 🛠️ Mantenimiento

### Tareas Regulares
- Limpiar solicitudes antiguas (>2 años)
- Archivar vacantes cerradas
- Backup de CVs
- Revisar y actualizar estados
- Monitorear espacio en disco

### Logs y Auditoría
Todos los cambios importantes se registran en:
- `application_status_history`: Cambios de estado
- `application_comments`: Comentarios y notas
- Timestamps automáticos en todas las tablas

## 📞 Soporte

Para soporte técnico o preguntas sobre el sistema:
- Revisar esta documentación
- Consultar los comentarios en el código
- Contactar al equipo de desarrollo

## 📝 Notas Importantes

1. **Códigos de Aplicación:** Son únicos y se generan automáticamente. Formato: `APP-XXXXXXXX-YYYY`

2. **Estados de Solicitud:** Mantener consistencia en el flujo de estados para mejor seguimiento

3. **Comentarios Internos vs Públicos:** Usar comentarios internos para discusiones del equipo y públicos para comunicación con candidatos

4. **Entrevistas:** Actualizar el estado de las entrevistas después de completarlas

5. **CVs:** Se almacenan en `uploads/cvs/` con nombres únicos para evitar conflictos

## 🎉 Conclusión

Este sistema de reclutamiento proporciona una solución completa para gestionar el proceso de contratación desde la publicación de vacantes hasta la contratación final. Con una interfaz intuitiva para candidatos y herramientas poderosas para RRHH, facilita todo el ciclo de reclutamiento.

---

**Versión:** 1.0  
**Fecha:** 2025  
**Desarrollado para:** Ponche Xtreme

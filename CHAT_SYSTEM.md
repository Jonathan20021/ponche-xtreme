# Sistema de Chat en Tiempo Real

## Descripción General

Sistema de chat en tiempo real completo integrado en la aplicación Evallish BPO Control. Permite comunicación instantánea entre todos los usuarios del sistema (admins, supervisores, agentes, HR, etc.) con soporte para mensajes de texto, archivos adjuntos (imágenes, videos, documentos), reacciones y gestión de permisos granular.

## Características Principales

### 🚀 Funcionalidades del Chat

- ✅ **Mensajes en tiempo real** - Sistema de polling cada 2 segundos para actualizaciones instantáneas
- ✅ **Conversaciones directas** - Chat 1 a 1 entre usuarios
- ✅ **Grupos de chat** - Conversaciones grupales con múltiples participantes
- ✅ **Archivos adjuntos** - Soporte para imágenes, videos y documentos
- ✅ **Reacciones a mensajes** - Emojis y reacciones
- ✅ **Edición y eliminación** - Los usuarios pueden editar/eliminar sus propios mensajes
- ✅ **Indicador de escritura** - Muestra cuando alguien está escribiendo
- ✅ **Estado de usuarios** - Online, offline, away, busy
- ✅ **Notificaciones** - Contador de mensajes no leídos
- ✅ **Recibos de lectura** - Seguimiento de mensajes leídos/no leídos
- ✅ **Búsqueda de usuarios** - Búsqueda rápida para iniciar conversaciones
- ✅ **Widget flotante** - Chat accesible desde cualquier página

### 🔒 Sistema de Permisos

- ✅ **Control granular por usuario**
- ✅ **Restricción de acceso al chat**
- ✅ **Límites de tamaño de archivos**
- ✅ **Permisos para crear grupos**
- ✅ **Permisos para enviar videos/documentos**
- ✅ **Sistema de restricciones temporales o permanentes**
- ✅ **Panel de administración completo**

## Instalación

### 1. Ejecutar Script SQL

Ejecuta el archivo SQL para crear todas las tablas necesarias:

```bash
mysql -u [usuario] -p [base_de_datos] < INSTALL_CHAT_SYSTEM.sql
```

O desde phpMyAdmin, importa el archivo `INSTALL_CHAT_SYSTEM.sql`

### 2. Verificar Permisos de Directorios

Asegúrate de que el directorio de uploads tenga permisos de escritura:

```bash
mkdir -p chat/uploads/{images,videos,documents,audio,thumbnails}
chmod -R 755 chat/uploads
```

En Windows (XAMPP):
```
md chat\uploads\images
md chat\uploads\videos
md chat\uploads\documents
md chat\uploads\audio
md chat\uploads\thumbnails
```

### 3. Configuración

Revisa y ajusta la configuración en `chat/config.php`:

- `CHAT_UPLOAD_MAX_SIZE` - Tamaño máximo de archivos (por defecto 100MB)
- `CHAT_POLL_INTERVAL` - Intervalo de actualización (por defecto 2000ms)
- `CHAT_MAX_MESSAGE_LENGTH` - Longitud máxima de mensajes (por defecto 10000 caracteres)
- Tipos de archivos permitidos

### 4. Verificar Integración

El chat ya está integrado en:
- `header.php` - Para usuarios admin, supervisor, HR, developer
- `header_agent.php` - Para agentes

No se requiere configuración adicional.

## Estructura de Archivos

```
ponche-xtreme/
├── chat/
│   ├── config.php           # Configuración del sistema
│   ├── api.php              # API REST para operaciones del chat
│   ├── upload.php           # Manejo de subida de archivos
│   ├── admin.php            # Panel de administración
│   └── uploads/             # Directorio de archivos adjuntos
│       ├── images/
│       ├── videos/
│       ├── documents/
│       ├── audio/
│       └── thumbnails/
├── assets/
│   ├── css/
│   │   └── chat.css         # Estilos del chat
│   └── js/
│       └── chat.js          # Cliente JavaScript
└── INSTALL_CHAT_SYSTEM.sql  # Script de instalación
```

## Tablas de Base de Datos

### Tablas Principales

1. **chat_conversations** - Conversaciones
2. **chat_participants** - Participantes de conversaciones
3. **chat_messages** - Mensajes
4. **chat_attachments** - Archivos adjuntos
5. **chat_reactions** - Reacciones a mensajes
6. **chat_read_receipts** - Recibos de lectura
7. **chat_notifications** - Notificaciones
8. **chat_permissions** - Permisos de usuario
9. **chat_user_status** - Estado de usuarios
10. **chat_scheduled_messages** - Mensajes programados (futuro)

## Uso del Sistema

### Para Usuarios

#### Iniciar el Chat

1. Busca el botón flotante del chat en la esquina inferior derecha
2. Haz clic para abrir la ventana del chat
3. Verás tus conversaciones existentes

#### Crear Nueva Conversación

1. Haz clic en el botón "+" (nueva conversación)
2. Busca usuarios por nombre o username
3. Selecciona uno o más usuarios
4. Haz clic en "Iniciar Chat"

#### Enviar Mensajes

- Escribe tu mensaje en el campo de texto
- Presiona Enter o haz clic en el botón de enviar
- Shift + Enter para nueva línea

#### Enviar Archivos

1. Haz clic en el icono de clip 📎
2. Selecciona el archivo
3. El archivo se subirá automáticamente
4. Soporta: imágenes, videos, PDFs, Office, comprimidos

#### Ver Usuarios en Línea

1. Haz clic en la pestaña "En línea"
2. Verás todos los usuarios conectados
3. Haz clic en un usuario para iniciar chat

### Para Administradores

#### Acceder al Panel de Administración

Navega a: `http://tu-dominio/chat/admin.php`

#### Gestionar Permisos

1. En el panel verás todos los usuarios
2. Haz clic en el icono de editar (lápiz) junto a un usuario
3. Configura los permisos:
   - **Puede usar el chat** - Acceso general al chat
   - **Puede crear grupos** - Permiso para crear grupos
   - **Puede subir archivos** - Permiso para adjuntar archivos
   - **Tamaño máximo** - Límite de MB por archivo
   - **Puede enviar videos** - Permiso específico para videos
   - **Puede enviar documentos** - Permiso específico para documentos

#### Restringir Usuarios

1. Haz clic en el icono de prohibición (🚫) junto a un usuario
2. Ingresa la razón de la restricción
3. Define la duración (0 = permanente)
4. El usuario no podrá usar el chat hasta que se remueva la restricción

#### Ver Estadísticas

El panel muestra:
- Mensajes enviados hoy
- Conversaciones activas
- Usuarios en línea
- Archivos compartidos hoy

## API Endpoints

### GET Endpoints

```
/chat/api.php?action=get_conversations
/chat/api.php?action=get_messages&conversation_id={id}&last_message_id={id}
/chat/api.php?action=get_unread_count
/chat/api.php?action=get_online_users
/chat/api.php?action=search_users&q={query}
/chat/api.php?action=get_typing&conversation_id={id}
```

### POST Endpoints

```
/chat/api.php?action=send_message
/chat/api.php?action=create_conversation
/chat/api.php?action=mark_as_read
/chat/api.php?action=update_status
/chat/api.php?action=edit_message
/chat/api.php?action=delete_message
/chat/api.php?action=add_reaction
/chat/api.php?action=typing
```

### Upload Endpoint

```
POST /chat/upload.php
```

## Configuración de Permisos por Rol

Los permisos por defecto se asignan así en la instalación:

| Rol | Usar Chat | Crear Grupos | Tamaño Máx. | Videos | Documentos |
|-----|-----------|--------------|-------------|--------|------------|
| Admin | ✅ | ✅ | 100MB | ✅ | ✅ |
| Supervisor | ✅ | ✅ | 100MB | ✅ | ✅ |
| HR | ✅ | ✅ | 100MB | ✅ | ✅ |
| Developer | ✅ | ✅ | 100MB | ✅ | ✅ |
| Agent | ✅ | ❌ | 50MB | ✅ | ✅ |

Estos permisos pueden ser modificados individualmente desde el panel de administración.

## Personalización

### Cambiar Colores del Chat

Edita `assets/css/chat.css` y modifica los gradientes:

```css
.chat-toggle-btn {
    background: linear-gradient(135deg, #tu-color-1 0%, #tu-color-2 100%);
}
```

### Ajustar Intervalo de Polling

Edita `chat/config.php`:

```php
define('CHAT_POLL_INTERVAL', 3000); // 3 segundos en lugar de 2
```

### Cambiar Tamaños de Archivo

Edita `chat/config.php`:

```php
define('CHAT_UPLOAD_MAX_SIZE', 200 * 1024 * 1024); // 200MB
```

### Agregar Nuevos Tipos de Archivo

Edita `chat/config.php` y agrega a los arrays:

```php
define('CHAT_ALLOWED_DOCUMENT_TYPES', [
    'application/pdf',
    // ... agregar más tipos MIME
]);
```

## Resolución de Problemas

### El chat no aparece

1. Verifica que el usuario tenga permiso `chat` en `section_permissions`
2. Revisa la consola del navegador para errores JavaScript
3. Verifica que los archivos CSS y JS se carguen correctamente

### No se pueden enviar archivos

1. Verifica permisos del directorio `chat/uploads/`
2. Revisa el tamaño máximo en `php.ini`: `upload_max_filesize` y `post_max_size`
3. Verifica que el usuario tenga permisos de subida de archivos

### Mensajes no se actualizan en tiempo real

1. Verifica que el intervalo de polling esté activo
2. Revisa errores en la consola del navegador
3. Verifica que la API responda correctamente: `/chat/api.php?action=get_messages&conversation_id=1`

### Usuarios no pueden acceder al chat

1. Verifica en la tabla `chat_permissions` que `can_use_chat = 1`
2. Verifica que `is_restricted = 0`
3. Revisa si hay fecha en `restricted_until`

## Mantenimiento

### Limpiar Archivos Antiguos

Puedes crear un cron job para limpiar archivos de más de X días:

```php
// cleanup_old_files.php
$days = 90;
$path = __DIR__ . '/chat/uploads/';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path)
);

foreach ($files as $file) {
    if ($file->isFile() && time() - $file->getMTime() > $days * 86400) {
        unlink($file->getRealPath());
    }
}
```

### Optimizar Base de Datos

Ejecuta periódicamente:

```sql
-- Eliminar mensajes eliminados antiguos (más de 30 días)
DELETE FROM chat_messages 
WHERE is_deleted = 1 
AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Limpiar notificaciones leídas antiguas
DELETE FROM chat_notifications 
WHERE is_read = 1 
AND read_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

## Mejoras Futuras

### En Desarrollo

- [ ] WebSocket real en lugar de polling
- [ ] Videollamadas integradas
- [ ] Mensajes de voz
- [ ] Búsqueda de mensajes
- [ ] Exportar conversaciones
- [ ] Mensajes programados
- [ ] Cifrado end-to-end
- [ ] Bots y automatización
- [ ] Integración con notificaciones push

## Soporte

Para preguntas o problemas:
1. Revisa esta documentación
2. Verifica los logs de PHP y JavaScript
3. Consulta el código en los archivos comentados

## Créditos

Sistema desarrollado para Evallish BPO Control
- Versión: 1.0
- Fecha: Noviembre 2025
- Framework: PHP + Vanilla JavaScript
- Base de datos: MySQL 8.0+

---

## Licencia

Uso interno exclusivo para Evallish BPO.

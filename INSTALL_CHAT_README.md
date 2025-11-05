# 🚀 Instalación Rápida del Sistema de Chat

## Pasos de Instalación

### 1️⃣ Ejecutar SQL de Instalación

Abre phpMyAdmin o tu cliente MySQL favorito y ejecuta:

```bash
mysql -u [usuario] -p hhempeos_ponche < INSTALL_CHAT_SYSTEM.sql
```

O importa el archivo `INSTALL_CHAT_SYSTEM.sql` desde phpMyAdmin.

### 2️⃣ Crear Directorios de Uploads

En Windows (XAMPP):
```cmd
cd C:\xampp\htdocs\ponche-xtreme
md chat\uploads\images
md chat\uploads\videos
md chat\uploads\documents
md chat\uploads\audio
md chat\uploads\thumbnails
```

En Linux/Mac:
```bash
cd /ruta/a/ponche-xtreme
mkdir -p chat/uploads/{images,videos,documents,audio,thumbnails}
chmod -R 755 chat/uploads
```

### 3️⃣ Verificar Instalación

1. Inicia sesión como administrador
2. Visita: `http://tu-dominio/test_chat_system.php`
3. Revisa que todos los checks estén en verde ✅

### 4️⃣ Configurar Permisos (Opcional)

Visita el panel de administración:
```
http://tu-dominio/chat/admin.php
```

Aquí puedes:
- ✅ Activar/desactivar chat para usuarios
- ✅ Establecer límites de tamaño de archivos
- ✅ Restringir usuarios temporalmente
- ✅ Ver estadísticas del chat

### 5️⃣ ¡Listo! 🎉

El widget del chat aparecerá automáticamente en la esquina inferior derecha para todos los usuarios con permisos.

## 📱 Uso Básico

### Para Usuarios

1. **Abrir Chat**: Clic en el botón flotante 💬 en la esquina inferior derecha
2. **Nueva Conversación**: Clic en el botón ➕ y busca usuarios
3. **Enviar Mensaje**: Escribe y presiona Enter
4. **Enviar Archivos**: Clic en 📎 para adjuntar
5. **Ver En Línea**: Pestaña "En línea" para ver usuarios conectados

### Para Administradores

1. **Panel Admin**: Menú → "Administración de Chat"
2. **Editar Permisos**: Clic en ✏️ junto al usuario
3. **Restringir**: Clic en 🚫 para restringir temporalmente
4. **Estadísticas**: Panel muestra métricas en tiempo real

## 🔧 Configuración Avanzada

Edita `chat/config.php` para ajustar:

```php
// Tamaño máximo de archivos (100MB por defecto)
define('CHAT_UPLOAD_MAX_SIZE', 100 * 1024 * 1024);

// Intervalo de actualización (2 segundos)
define('CHAT_POLL_INTERVAL', 2000);

// Longitud máxima de mensajes
define('CHAT_MAX_MESSAGE_LENGTH', 10000);
```

## ⚠️ Resolución de Problemas

### El chat no aparece
- ✅ Verifica que el usuario tenga permiso `chat` en la base de datos
- ✅ Revisa la consola del navegador (F12) para errores
- ✅ Confirma que los archivos CSS y JS se carguen

### No se pueden subir archivos
- ✅ Verifica permisos de escritura en `chat/uploads/`
- ✅ Revisa `php.ini`: `upload_max_filesize` y `post_max_size`
- ✅ Confirma permisos del usuario en el panel admin

### Mensajes no se actualizan
- ✅ Verifica que no haya errores en la consola
- ✅ Prueba la API: `/chat/api.php?action=get_unread_count`
- ✅ Revisa los logs de PHP por errores

## 📚 Documentación Completa

Lee `CHAT_SYSTEM.md` para documentación detallada.

## 🎯 Características Principales

✅ Mensajes en tiempo real (polling cada 2 segundos)  
✅ Conversaciones directas y grupales  
✅ Archivos adjuntos (imágenes, videos, documentos)  
✅ Indicador de escritura  
✅ Estado online/offline  
✅ Notificaciones de mensajes no leídos  
✅ Sistema de permisos granular  
✅ Panel de administración completo  
✅ Widget flotante en todas las páginas  
✅ Responsive (móvil y escritorio)

## 🔐 Seguridad

- ✅ Verificación de sesión en todas las APIs
- ✅ Validación de permisos por conversación
- ✅ Sanitización de nombres de archivo
- ✅ Protección contra directory traversal
- ✅ Archivos servidos a través de PHP (no acceso directo)
- ✅ Control granular de permisos por usuario

## 🆘 Soporte

Si encuentras problemas:
1. Ejecuta `test_chat_system.php` para diagnóstico
2. Revisa los logs de PHP
3. Verifica la consola del navegador
4. Consulta `CHAT_SYSTEM.md`

---

**Desarrollado para Evallish BPO Control**  
Versión 1.0 • Noviembre 2025

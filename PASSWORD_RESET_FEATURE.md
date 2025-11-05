# Sistema de Reseteo de Contraseña desde Settings

## Descripción General

Se ha implementado un botón de **"Reset Password"** en la sección de **Gestionar usuarios existentes** de `settings.php`. Este botón permite a los administradores enviar un correo electrónico de reseteo de contraseña a cualquier usuario del sistema.

## Características

### 1. **Botón de Reset Password**
- Ubicado en la columna de **Acciones** de cada usuario en la tabla de gestión
- Diseño consistente con el resto de la interfaz (botón azul con icono de sobre)
- Solo visible para usuarios que no sean la cuenta actual del administrador

### 2. **Proceso de Envío**
El sistema realiza las siguientes validaciones y acciones:

1. **Validación de usuario**: Verifica que el ID de usuario sea válido
2. **Obtención de email**: Consulta el email del usuario desde la tabla `employees`
3. **Validación de email**: Si el usuario no tiene email registrado, muestra un mensaje de error indicando que debe registrar el email en el módulo HR primero
4. **Generación de token**: Crea un token único de 64 caracteres usando `random_bytes(32)`
5. **Expiración**: El token expira automáticamente en 1 hora
6. **Almacenamiento opcional**: Si existe la tabla `password_reset_tokens`, guarda el token para seguimiento
7. **Envío de email**: Utiliza la función `sendPasswordResetEmail()` existente en `lib/email_functions.php`

### 3. **Seguridad**
- Token único generado criptográficamente
- Expiración automática de 1 hora
- Confirmación antes de enviar el email
- No permite resetear la contraseña del propio administrador

## Ubicación del Código

### Archivo Principal: `settings.php`

#### 1. Case Switch (línea ~728)
```php
case 'send_password_reset':
    // Validación y envío de email
```

#### 2. Botón en la Tabla (línea ~1423)
```html
<!-- Send Password Reset Email -->
<form method="POST" class="inline" onsubmit="return confirm('¿Enviar email de reseteo de contraseña a este usuario?');">
    <input type="hidden" name="action" value="send_password_reset">
    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-blue-500/15 text-blue-400 border border-blue-500/20 hover:bg-blue-500/25 transition-colors w-full justify-center">
        <i class="fas fa-envelope"></i>
        Reset Password
    </button>
</form>
```

## Uso

### Paso 1: Acceder a Settings
1. Navega a **Settings** desde el menú principal
2. Ve a la sección **"Gestionar usuarios existentes"**

### Paso 2: Seleccionar Usuario
1. Encuentra el usuario al que deseas enviar el reseteo
2. En la columna de **Acciones**, verás el botón azul **"Reset Password"**

### Paso 3: Enviar Email
1. Haz clic en el botón **"Reset Password"**
2. Confirma la acción en el diálogo que aparece
3. El sistema enviará el email y mostrará un mensaje de confirmación

### Paso 4: Mensajes de Respuesta
- ✅ **Éxito**: "Se ha enviado un correo de reseteo de contraseña a [email]"
- ❌ **Error - Sin email**: "El usuario no tiene un email registrado. Registra un email en el módulo HR primero."
- ❌ **Error - Envío fallido**: "No se pudo enviar el correo: [mensaje de error]"

## Requisitos Previos

### 1. Email Registrado
El usuario debe tener un email registrado en la tabla `employees`. Para registrarlo:
1. Ve al módulo **HR** → **Empleados**
2. Edita el empleado correspondiente
3. Agrega su email en el campo correspondiente

### 2. Configuración SMTP
Asegúrate de que el archivo `config/email_config.php` esté correctamente configurado con:
- Host SMTP
- Puerto
- Credenciales
- URL de la aplicación

### 3. Tabla Opcional (Recomendado)
Ejecuta el script SQL proporcionado para crear la tabla de seguimiento:
```bash
mysql -u username -p database_name < CREATE_PASSWORD_RESET_TABLE.sql
```

## Contenido del Email

El email enviado incluye:

### Asunto
```
Recuperación de Contraseña - [Nombre de la App]
```

### Contenido
- Saludo personalizado
- Botón con enlace de reseteo
- URL completa del enlace (por si el botón no funciona)
- Aviso de expiración (1 hora)
- Nota de seguridad si no solicitó el cambio

### Ejemplo de URL
```
https://tu-dominio.com/reset_password.php?token=abc123...xyz789
```

## Flujo de Usuario Final

1. **Administrador** envía el email de reset desde settings.php
2. **Usuario** recibe el email en su bandeja de entrada
3. **Usuario** hace clic en el enlace o botón del email
4. Sistema valida el token y su expiración
5. **Usuario** ingresa su nueva contraseña
6. Sistema actualiza la contraseña y marca el token como usado
7. **Usuario** puede iniciar sesión con la nueva contraseña

## Tabla de Base de Datos (Opcional)

### `password_reset_tokens`
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT | ID único auto-incremental |
| `user_id` | INT | ID del usuario (FK a `users`) |
| `token` | VARCHAR(64) | Token único de reseteo |
| `expires_at` | DATETIME | Fecha y hora de expiración |
| `used_at` | DATETIME | Fecha y hora en que se usó (NULL si no se ha usado) |
| `created_at` | TIMESTAMP | Fecha de creación del token |

### Índices
- Primary Key: `id`
- Unique Key: `user_id` (un token por usuario)
- Index: `token` (búsqueda rápida)
- Index: `expires_at` (limpieza de tokens expirados)
- Foreign Key: `user_id` → `users.id` (CASCADE DELETE)

## Mantenimiento

### Limpieza de Tokens Expirados
Ejecuta periódicamente (ej: cron job diario):
```sql
DELETE FROM password_reset_tokens 
WHERE expires_at < NOW() 
OR used_at IS NOT NULL;
```

### Verificar Emails Registrados
Para ver qué usuarios tienen email:
```sql
SELECT 
    u.id,
    u.username,
    u.full_name,
    e.email,
    CASE WHEN e.email IS NOT NULL THEN 'Sí' ELSE 'No' END as tiene_email
FROM users u
LEFT JOIN employees e ON e.user_id = u.id
ORDER BY e.email IS NULL DESC, u.username;
```

## Mensajes de Error Comunes

### "El usuario no tiene un email registrado"
**Solución**: Registra el email del usuario en el módulo HR antes de intentar enviar el reset.

### "No se pudo enviar el correo: Connection refused"
**Solución**: Verifica la configuración SMTP en `config/email_config.php`

### "No se pudo enviar el correo: Invalid address"
**Solución**: Verifica que el email registrado sea válido

## Seguridad y Mejores Prácticas

1. ✅ Token generado criptográficamente (`random_bytes`)
2. ✅ Expiración automática en 1 hora
3. ✅ Token de 64 caracteres (256 bits de entropía)
4. ✅ Validación de email antes del envío
5. ✅ Confirmación del administrador antes de enviar
6. ✅ Mensajes claros de éxito/error
7. ✅ No permite resetear la propia cuenta del admin

## Archivos Modificados

1. **settings.php**
   - Añadido caso `send_password_reset` en el switch
   - Añadido botón "Reset Password" en la tabla de usuarios

2. **CREATE_PASSWORD_RESET_TABLE.sql** (nuevo)
   - Script para crear tabla de seguimiento de tokens

3. **PASSWORD_RESET_FEATURE.md** (este archivo)
   - Documentación completa del feature

## Funciones Utilizadas

### `sendPasswordResetEmail($userData)`
Ubicación: `lib/email_functions.php`

**Parámetros esperados:**
```php
[
    'email' => 'usuario@ejemplo.com',
    'full_name' => 'Nombre Completo',
    'username' => 'username',
    'reset_token' => 'abc123...xyz789'
]
```

**Retorna:**
```php
[
    'success' => true/false,
    'message' => 'Mensaje de éxito o error'
]
```

## Próximas Mejoras (Opcional)

1. ⚡ Dashboard de tokens activos/expirados
2. 📊 Estadísticas de reseteos de contraseña
3. 🔔 Notificación al administrador cuando se usa un token
4. 📝 Log de auditoría de reseteos
5. ⏰ Configuración personalizable del tiempo de expiración
6. 📧 Recordatorio al usuario si no usa el token

## Soporte

Para dudas o problemas con esta funcionalidad, verifica:
1. Configuración SMTP en `config/email_config.php`
2. Emails registrados en la tabla `employees`
3. Logs de PHP para errores de envío
4. Estado del servidor SMTP

---

**Fecha de Implementación**: Noviembre 2025  
**Versión**: 1.0  
**Desarrollador**: Sistema Ponche Xtreme

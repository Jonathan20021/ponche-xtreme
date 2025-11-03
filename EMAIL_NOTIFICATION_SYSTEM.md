# Sistema de Notificaciones por Email

## Descripción General

Este sistema envía automáticamente un correo electrónico de bienvenida a los nuevos empleados cuando son registrados en el sistema, incluyendo sus credenciales de acceso e instrucciones detalladas sobre cómo usar el sistema de marcaciones.

## Características

✅ **Correo de Bienvenida Profesional**: Plantilla HTML moderna y responsive con toda la información necesaria
✅ **Credenciales de Acceso**: Usuario, contraseña y código de empleado
✅ **Instrucciones Paso a Paso**: Guía completa sobre cómo usar el sistema de ponche
✅ **Enlaces Directos**: Acceso rápido al login y dashboard
✅ **Soporte cPanel SMTP**: Compatible con servidores de correo cPanel
✅ **Validación de Email**: Verifica formato antes de enviar
✅ **Manejo de Errores**: Notifica si el correo no se pudo enviar

## Archivos Creados

### 1. Configuración
- **`config/email_config.php`**: Configuración SMTP de cPanel

### 2. Funciones de Email
- **`lib/email_functions.php`**: Funciones para enviar correos (welcome, password reset, test)

### 3. Plantillas
- **`templates/welcome_email.php`**: Plantilla HTML del correo de bienvenida

### 4. Integración
- **`hr/new_employee.php`**: Formulario actualizado con envío automático de email

## Configuración Inicial

### Paso 1: Instalar PHPMailer

Ejecuta el siguiente comando en la raíz del proyecto:

```bash
composer install
```

O si ya tienes composer instalado:

```bash
composer require phpmailer/phpmailer
```

### Paso 2: Configurar cPanel Email

Edita el archivo `config/email_config.php` con tus credenciales de cPanel:

```php
return [
    'smtp_host' => 'mail.tudominio.com',        // Tu servidor de correo cPanel
    'smtp_port' => 465,                          // 465 para SSL, 587 para TLS
    'smtp_secure' => 'ssl',                      // 'ssl' o 'tls'
    'smtp_username' => 'noreply@tudominio.com',  // Tu email de cPanel
    'smtp_password' => 'tu_password_aqui',       // Tu contraseña
    'from_email' => 'noreply@tudominio.com',
    'from_name' => 'Ponche Xtreme - Sistema de RH',
    'app_url' => 'https://tudominio.com/ponche-xtreme',  // URL de tu aplicación
    'support_email' => 'soporte@tudominio.com',
];
```

### Paso 3: Crear Cuenta de Email en cPanel

1. Accede a tu cPanel
2. Ve a "Cuentas de Correo Electrónico"
3. Crea una nueva cuenta (ej: `noreply@tudominio.com`)
4. Usa estas credenciales en `email_config.php`

### Paso 4: Configuración de Puertos

**Para SSL (Recomendado):**
- Puerto: `465`
- smtp_secure: `'ssl'`

**Para TLS:**
- Puerto: `587`
- smtp_secure: `'tls'`

## Uso

### Registro de Nuevo Empleado

Cuando registras un nuevo empleado en `hr/new_employee.php`:

1. El campo **Email** es ahora **obligatorio**
2. Al guardar exitosamente, el sistema automáticamente:
   - Crea el usuario y empleado en la base de datos
   - Envía un correo de bienvenida con:
     - Credenciales de acceso (usuario y contraseña)
     - Código de empleado
     - Posición y departamento
     - Instrucciones detalladas sobre cómo ponchar
     - Enlaces directos al sistema
     - Consejos importantes

### Contenido del Email

El correo incluye:

#### 🔐 Credenciales de Acceso
- Código de Empleado
- Usuario
- Contraseña
- Posición
- Departamento
- Fecha de Ingreso

#### 📋 Instrucciones de Uso
1. Cómo acceder al sistema
2. Cómo marcar entrada
3. Cómo registrar descansos
4. Cómo marcar salida
5. Cómo consultar registros

#### 💡 Consejos Importantes
- Cambiar contraseña
- Puntualidad en marcaciones
- Acceso móvil
- Información de soporte

#### Enlaces Rápidos
- Dashboard del Agente
- Portal de Login
- Email de Soporte

## Funciones Disponibles

### `sendWelcomeEmail($employeeData)`

Envía correo de bienvenida a nuevo empleado.

**Parámetros requeridos:**
```php
$employeeData = [
    'email' => 'empleado@ejemplo.com',
    'employee_name' => 'Juan Pérez',
    'username' => 'jperez',
    'password' => 'defaultpassword',
    'employee_code' => 'EMP-2025-0001',
    'position' => 'Agente de Soporte',      // Opcional
    'department' => 'Operaciones',          // Opcional
    'hire_date' => '2025-11-03'            // Opcional
];

$result = sendWelcomeEmail($employeeData);
```

**Retorna:**
```php
[
    'success' => true/false,
    'message' => 'Mensaje descriptivo'
]
```

### `testEmailConfiguration($testEmail)`

Prueba la configuración de email enviando un correo de prueba.

```php
$result = testEmailConfiguration('test@ejemplo.com');
```

### `sendPasswordResetEmail($userData)`

Envía correo de recuperación de contraseña.

```php
$userData = [
    'email' => 'empleado@ejemplo.com',
    'full_name' => 'Juan Pérez',
    'reset_token' => 'token_generado'
];

$result = sendPasswordResetEmail($userData);
```

## Pruebas

### Crear Script de Prueba

Crea el archivo `test_email.php` en la raíz:

```php
<?php
require_once 'lib/email_functions.php';

// Prueba de configuración
$result = testEmailConfiguration('tu_email@ejemplo.com');

if ($result['success']) {
    echo "✅ " . $result['message'];
} else {
    echo "❌ " . $result['message'];
}
```

Ejecuta: `php test_email.php`

## Solución de Problemas

### Error: "SMTP connect() failed"

**Causas comunes:**
1. Credenciales incorrectas
2. Puerto bloqueado por firewall
3. SSL/TLS mal configurado

**Solución:**
- Verifica usuario y contraseña en cPanel
- Prueba con puerto 587 (TLS) si 465 (SSL) no funciona
- Contacta a tu proveedor de hosting

### Error: "Could not instantiate mail function"

**Solución:**
- Asegúrate de que PHPMailer esté instalado: `composer install`
- Verifica que `vendor/autoload.php` existe

### Email no llega

**Verifica:**
1. Carpeta de spam/correo no deseado
2. Email válido del destinatario
3. Límites de envío de tu hosting
4. Logs del servidor de correo en cPanel

### Modo Debug

Activa el modo debug en `config/email_config.php`:

```php
'debug_mode' => true,
```

Esto mostrará información detallada de la conexión SMTP.

## Seguridad

### Mejores Prácticas

1. **No subas credenciales a Git:**
   ```bash
   # Añade a .gitignore
   config/email_config.php
   ```

2. **Usa contraseñas fuertes** para la cuenta de email

3. **Limita permisos** de la cuenta de email (solo envío)

4. **Cambia contraseñas** periódicamente

5. **Monitorea el uso** para detectar abusos

## Personalización

### Modificar Plantilla de Email

Edita `templates/welcome_email.php` para personalizar:
- Colores y estilos
- Contenido del mensaje
- Estructura del email
- Logos e imágenes

### Añadir Nuevos Tipos de Email

1. Crea nueva función en `lib/email_functions.php`
2. Crea nueva plantilla en `templates/`
3. Llama la función donde sea necesario

## Mantenimiento

### Logs

Los errores de email se registran en:
- Mensajes de error en pantalla (modo desarrollo)
- Logs de PHP del servidor
- Logs de cPanel Mail

### Monitoreo

Revisa periódicamente:
- Tasa de entrega de emails
- Emails rebotados
- Quejas de spam
- Límites de envío del hosting

## Soporte

Para problemas o preguntas:
1. Revisa esta documentación
2. Verifica la configuración de cPanel
3. Consulta logs del servidor
4. Contacta al administrador del sistema

---

**Versión:** 1.0  
**Fecha:** Noviembre 2025  
**Sistema:** Ponche Xtreme - HR Module

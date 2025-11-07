# Sistema de Email para Reporte de Ausencias - Actualización

## Cambios Realizados

El sistema de reporte diario de ausencias ha sido actualizado para usar el sistema de email centralizado basado en PHPMailer en lugar de la función nativa `mail()` de PHP.

### Archivos Modificados

1. **`lib/email_functions.php`**
   - ✅ Agregada función `sendDailyAbsenceReport()`
   - Usa PHPMailer con configuración SMTP de cPanel
   - Genera versión plain text automáticamente
   - Validación de emails
   - Manejo de errores mejorado

2. **`lib/daily_absence_report.php`**
   - ✅ Actualizada función `sendReportByEmail()`
   - Ahora usa `email_functions.php` en lugar de `mail()`
   - Más robusto y confiable

### Ventajas del Nuevo Sistema

✅ **SMTP Autenticado**
- Usa el servidor SMTP de cPanel configurado
- Mayor tasa de entrega (evita ser marcado como spam)
- Autenticación segura

✅ **Mejor Manejo de Errores**
- Mensajes de error detallados
- Logging mejorado
- Debug mode disponible

✅ **Formato Profesional**
- HTML y plain text automáticos
- Headers correctos
- Codificación UTF-8 garantizada

✅ **Consistencia**
- Usa el mismo sistema que otros emails del sistema (bienvenida, recuperación, aplicaciones)
- Misma configuración centralizada
- Fácil mantenimiento

## Configuración

### 1. Verificar Configuración SMTP

El sistema usa la configuración en `config/email_config.php`:

```php
return [
    'smtp_host' => 'mail.yourdomain.com',
    'smtp_port' => 587,
    'smtp_username' => 'noreply@yourdomain.com',
    'smtp_password' => 'your_password',
    'smtp_secure' => 'tls',  // or 'ssl'
    'from_email' => 'noreply@yourdomain.com',
    'from_name' => 'Sistema de Asistencia',
    'app_url' => 'https://yourdomain.com/ponche-xtreme',
    'support_email' => 'rrhh@yourdomain.com',
    'app_name' => 'Ponche Xtreme',
    'debug_mode' => false
];
```

### 2. Configurar Destinatarios

Desde **Settings > Reporte de Ausencias**:

1. Ingrese los correos separados por comas:
   ```
   rrhh@empresa.com, gerencia@empresa.com, operaciones@empresa.com
   ```

2. Configure la hora de envío (GMT-4)

3. Active el sistema

4. Pruebe con "Enviar Reporte Ahora"

### 3. Solución de Problemas

#### Si no llegan los correos:

1. **Verificar credenciales SMTP:**
   ```bash
   php test_email.php  # Si existe un script de prueba
   ```

2. **Ver logs de PHP:**
   ```bash
   tail -f /path/to/php_error.log
   ```

3. **Activar debug mode:**
   En `config/email_config.php` cambie:
   ```php
   'debug_mode' => true
   ```

4. **Verificar firewall/puertos:**
   - Puerto 587 (TLS) debe estar abierto
   - Puerto 465 (SSL) como alternativa
   - Puerto 25 generalmente bloqueado

#### Si los correos van a spam:

1. Configure SPF record en su dominio
2. Configure DKIM si está disponible
3. Use `reply_to_email` válido
4. Evite palabras spam en el asunto

## Testing

### Test Manual

1. Ir a Settings > Reporte de Ausencias
2. Click en "Enviar Reporte Ahora"
3. Verificar inbox y spam

### Test Programático

```bash
php test_absence_report.php
```

Esto genera el HTML pero NO envía correos (útil para preview).

### Test con Email Real

Edite `configure_test_email.php` con su email:

```php
$testEmail = 'tucorreo@example.com';
```

Ejecute:
```bash
php configure_test_email.php
```

Luego pruebe el envío manual desde Settings.

## Migración de mail() a PHPMailer

### Antes (mail() nativo):
```php
$headers = [
    'MIME-Version: 1.0',
    'Content-type: text/html; charset=UTF-8',
    'From: Sistema <noreply@domain.com>'
];

mail($recipient, $subject, $html, implode("\r\n", $headers));
```

**Problemas:**
- ❌ Requiere sendmail/postfix configurado
- ❌ Muchos servidores tienen mail() deshabilitado
- ❌ Fácilmente marcado como spam
- ❌ Sin autenticación SMTP
- ❌ Difícil debuggear

### Después (PHPMailer con SMTP):
```php
require_once 'lib/email_functions.php';

$result = sendDailyAbsenceReport($html, $recipients, $reportData);

if ($result['success']) {
    echo $result['message'];
} else {
    error_log($result['message']);
}
```

**Ventajas:**
- ✅ SMTP autenticado
- ✅ Compatible con cPanel
- ✅ Mejor entregabilidad
- ✅ Debug detallado
- ✅ Manejo de errores robusto

## Compatibilidad

- ✅ PHP 7.4+
- ✅ PHP 8.0+
- ✅ PHP 8.1+
- ✅ Requiere composer (ya instalado con PHPMailer)
- ✅ Compatible con cPanel
- ✅ Compatible con servidores Linux
- ✅ Compatible con Windows (XAMPP en desarrollo)

## Dependencias

El sistema requiere PHPMailer, que ya está instalado:

```bash
composer require phpmailer/phpmailer
```

Si necesita reinstalar:
```bash
cd /path/to/ponche-xtreme
composer install
```

## Logs y Monitoreo

### Logs del Sistema

Todos los envíos se registran en:
- `error_log` de PHP (ver configuración)
- Tabla `activity_logs` en la base de datos

### Ver Logs de Email

```bash
# Linux
tail -f /var/log/php_error.log | grep "Absence report"

# O desde PHP
tail -f error_log | grep "send"
```

### Estadísticas

El sistema registra:
- ✅ Intentos de envío
- ✅ Éxitos/Fallos
- ✅ Destinatarios
- ✅ Timestamp
- ✅ Usuario que disparó el envío (manual vs automático)

## Próximos Pasos

1. Configure SMTP en `config/email_config.php`
2. Agregue destinatarios en Settings
3. Pruebe el envío manual
4. Configure el cron job para envío automático
5. Monitoree los primeros días

## Soporte

Para problemas con el envío de emails:

1. Revisar `config/email_config.php`
2. Activar debug_mode
3. Ver logs de PHP
4. Contactar al proveedor de hosting si persisten problemas SMTP

---

**Versión:** 2.0  
**Fecha:** Noviembre 2025  
**Mejora:** Migración de mail() a PHPMailer con SMTP

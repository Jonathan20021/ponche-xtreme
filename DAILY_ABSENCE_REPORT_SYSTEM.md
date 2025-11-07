# Sistema de Reporte Diario de Ausencias

## Descripción General

Sistema automatizado que genera y envía reportes diarios por correo electrónico de todos los empleados que no han marcado asistencia. El sistema valida automáticamente si tienen permisos, vacaciones o licencias médicas aprobadas.

## Características

✅ **Generación Automática**
- Se ejecuta diariamente a la hora configurada (por defecto 8:00 AM GMT-4)
- Puede enviarse manualmente desde la configuración

✅ **Validación Inteligente**
- Verifica permisos aprobados (medical, personal, study)
- Valida vacaciones activas
- Valida licencias médicas vigentes
- Separa ausencias justificadas de no justificadas

✅ **Reporte Detallado**
- Estadísticas generales (total empleados, ausencias sin/con justificación)
- Información completa de cada empleado ausente
- Detalles de justificaciones cuando existen
- Diseño profesional y responsive para correo electrónico

## Archivos del Sistema

### Core
- `lib/daily_absence_report.php` - Funciones principales del sistema
- `cron_daily_absence_report.php` - Script para ejecución automática via cron
- `send_absence_report.php` - Endpoint para envío manual desde settings

### Configuración
- Settings > "Reporte de Ausencias" - Interfaz de configuración
- System Settings tabla: `absence_report_recipients`, `absence_report_enabled`, `absence_report_time`

### Testing
- `test_absence_report.php` - Script de prueba (genera HTML sin enviar)

## Configuración

### 1. Configurar Destinatarios

Vaya a **Settings > Reporte de Ausencias**:

1. Ingrese los correos electrónicos separados por comas:
   ```
   rrhh@empresa.com, gerencia@empresa.com, operaciones@empresa.com
   ```

2. Configure la hora de envío (formato 24hrs, GMT-4):
   ```
   08:00
   ```

3. Active el envío automático marcando el checkbox

4. Haga clic en "Guardar Configuración"

### 2. Configurar Cron Job

Para envío automático, configure un cron job en su servidor:

#### Opción A: Ejecución PHP Directa (Recomendado)
```bash
0 8 * * * /usr/bin/php /path/to/ponche-xtreme/cron_daily_absence_report.php
```

#### Opción B: Via wget
```bash
0 8 * * * wget -q -O - https://yourdomain.com/ponche-xtreme/cron_daily_absence_report.php?cron_key=ponche_xtreme_2025
```

#### Opción C: Via curl
```bash
0 8 * * * curl -s https://yourdomain.com/ponche-xtreme/cron_daily_absence_report.php?cron_key=ponche_xtreme_2025
```

**Nota de Seguridad:** Si usa wget/curl, cambie la `cron_key` en `cron_daily_absence_report.php` línea 19.

### 3. Configuración de cPanel (si aplica)

1. Vaya a **cPanel > Cron Jobs**
2. Seleccione **Common Settings**: Once Per Day (0:00)
3. O configure manual: `0 8 * * *`
4. En Command:
   ```
   /usr/bin/php /home/username/public_html/ponche-xtreme/cron_daily_absence_report.php
   ```
5. Guarde

## Uso

### Envío Manual

Desde **Settings > Reporte de Ausencias**:

1. Haga clic en el botón "Enviar Reporte Ahora"
2. El sistema generará el reporte del día actual
3. Se enviará a todos los destinatarios configurados
4. Recibirá confirmación con estadísticas

### Prueba del Sistema

Ejecute el script de prueba:

```bash
php test_absence_report.php
```

Esto generará un archivo HTML que puede abrir en el navegador para ver cómo se verá el correo electrónico.

## Contenido del Reporte

### Sección 1: Estadísticas Generales
- Total de empleados activos
- Número de ausencias sin justificar
- Número de ausencias justificadas

### Sección 2: Ausencias Sin Justificación ⚠️
Para cada empleado:
- Nombre completo
- Código de empleado
- Puesto
- Departamento

### Sección 3: Ausencias Justificadas ✓
Para cada empleado:
- Información básica (igual que arriba)
- **Justificaciones activas:**
  - Permisos: Tipo, fechas, razón
  - Vacaciones: Tipo, fechas
  - Licencias Médicas: Tipo, diagnóstico, fechas, doctor

## Tablas Consultadas

El sistema consulta las siguientes tablas:

- `employees` - Empleados activos
- `users` - Usuarios del sistema
- `attendance` - Registros de asistencia del día
- `permission_requests` - Permisos aprobados
- `vacation_requests` - Vacaciones aprobadas
- `medical_leaves` - Licencias médicas activas
- `departments` - Departamentos

## Casos de Uso

### Escenario 1: Empleado con Permiso Médico
El empleado Juan Pérez no marcó asistencia hoy, pero tiene un permiso médico aprobado del 5-7 de noviembre.

**Resultado:** Aparece en "Ausencias Justificadas" con detalles del permiso.

### Escenario 2: Empleado de Vacaciones
La empleada María López está de vacaciones del 1-15 de noviembre.

**Resultado:** Aparece en "Ausencias Justificadas" con detalles de las vacaciones.

### Escenario 3: Ausencia Injustificada
El empleado Carlos Díaz no marcó asistencia y no tiene permisos, vacaciones ni licencias.

**Resultado:** Aparece en "Ausencias Sin Justificación" ⚠️

### Escenario 4: Todos Presentes
Todos los empleados marcaron asistencia.

**Resultado:** El reporte muestra "¡Excelente! Todos los empleados han registrado su asistencia hoy."

## Troubleshooting

### El reporte no se envía automáticamente

1. Verifique que el cron job esté configurado correctamente
2. Revise los logs del servidor: `/var/log/cron` o logs de PHP
3. Ejecute manualmente: `php cron_daily_absence_report.php`
4. Verifique que `absence_report_enabled = 1` en system_settings

### No se reciben correos

1. Verifique que los emails estén correctamente configurados
2. Revise la configuración de PHP mail()
3. Considere usar un servicio SMTP externo (se puede modificar `sendReportByEmail()`)
4. Revise spam/junk folder

### Empleados no aparecen en el reporte

1. Verifique que `employment_status = 'active'`
2. Verifique que tengan `user_id` asignado
3. Ejecute `test_absence_report.php` para debug

## Personalización

### Cambiar el diseño del email

Edite la función `generateReportHTML()` en `lib/daily_absence_report.php`.

### Agregar más validaciones

Agregue funciones similares a `getApprovedPermissionsForToday()` para consultar otras tablas.

### Cambiar la hora de envío

Vaya a Settings > Reporte de Ausencias y cambie el campo "Hora de envío".

## Seguridad

- ✅ Solo usuarios con roles admin/IT/HR pueden enviar reportes manualmente
- ✅ El cron script valida clave secreta si se ejecuta vía web
- ✅ Los correos se validan antes de enviar
- ✅ Todas las acciones se registran en activity_logs

## Mantenimiento

### Logs

El sistema registra todas las operaciones en:
- Error logs de PHP: errores y warnings
- Activity logs: envíos exitosos con estadísticas

### Actualizaciones Futuras Sugeridas

1. Integración con servicio SMTP (SendGrid, Mailgun)
2. Plantillas de correo personalizables
3. Reportes semanales/mensuales
4. Notificaciones push adicionales
5. Dashboard con histórico de ausencias

## Soporte

Para problemas o consultas, contacte al equipo de desarrollo o revise la documentación técnica en el código fuente.

---

**Versión:** 1.0  
**Fecha:** Noviembre 2025  
**Autor:** Sistema Ponche Xtreme

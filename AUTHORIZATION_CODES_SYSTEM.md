# Sistema de Códigos de Autorización - Ponche Xtreme

## 📋 Descripción General

El Sistema de Códigos de Autorización permite controlar y registrar acciones que requieren aprobación de supervisores, gerentes, IT o personal autorizado. Los códigos son completamente configurables desde el panel de Settings y pueden usarse en múltiples contextos como:

- ✅ **Hora Extra**: Requerir código para registrar punches fuera del horario
- ✅ **Punches Especiales**: Autorizar tipos específicos de registros
- ✅ **Edición de Registros**: Validar modificaciones de datos
- ✅ **Eliminación de Registros**: Confirmar borrado de información

## 🚀 Características Principales

### 1. Sistema Completamente Configurable
- **Códigos por Rol**: Supervisor, IT, Gerente, Director, HR, Universal
- **Contextos Múltiples**: Diferentes códigos para diferentes situaciones
- **Fechas de Validez**: Códigos temporales con fecha de inicio y fin
- **Límites de Uso**: Códigos de un solo uso o con límite máximo
- **Estado Activo/Inactivo**: Habilitar o deshabilitar códigos sin eliminarlos

### 2. Registro Completo de Auditoría
- Cada uso de código queda registrado con:
  - Usuario que usó el código
  - Fecha y hora exacta
  - IP y User Agent
  - Contexto de uso
  - Referencia al registro afectado

### 3. Validación en Tiempo Real
- API REST para validar códigos
- Feedback inmediato al usuario
- Mensajes de error específicos (expirado, límite alcanzado, inválido, etc.)

## 📦 Instalación

### Paso 1: Ejecutar Script SQL

Ejecuta el archivo `INSTALL_AUTHORIZATION_CODES.sql` en tu base de datos MySQL:

```bash
mysql -u tu_usuario -p tu_base_de_datos < INSTALL_AUTHORIZATION_CODES.sql
```

O desde phpMyAdmin:
1. Abre phpMyAdmin
2. Selecciona tu base de datos
3. Ve a la pestaña "SQL"
4. Copia y pega el contenido de `INSTALL_AUTHORIZATION_CODES.sql`
5. Haz clic en "Continuar"

### Paso 2: Verificar Instalación

El script creará las siguientes estructuras:

**Tablas:**
- `authorization_codes` - Almacena los códigos configurables
- `authorization_code_logs` - Registro de uso de códigos
- `system_settings` - Configuración del sistema (si no existe)

**Vista:**
- `v_active_authorization_codes` - Vista de códigos activos y válidos

**Procedimientos Almacenados:**
- `sp_validate_authorization_code` - Validación de códigos
- `sp_log_authorization_code_usage` - Registro de uso

**Modificaciones a Tablas Existentes:**
- `attendance` - Agrega columna `authorization_code_id`

### Paso 3: Verificar Archivos

Asegúrate de que existan los siguientes archivos:

```
ponche-xtreme/
├── lib/
│   └── authorization_functions.php     ✅ Funciones del sistema
├── api/
│   └── authorization_codes.php         ✅ API REST
├── INSTALL_AUTHORIZATION_CODES.sql     ✅ Script de instalación
└── AUTHORIZATION_CODES_SYSTEM.md       ✅ Esta documentación
```

## ⚙️ Configuración

### 1. Habilitar el Sistema

1. Ve a **Settings** → **Códigos de Autorización**
2. Activa: ☑ **Habilitar Sistema de Códigos de Autorización**
3. Activa: ☑ **Requerir código para Hora Extra**
4. Haz clic en **Guardar Configuración**

![Configuración del Sistema](https://via.placeholder.com/800x200/4F46E5/FFFFFF?text=Configuración+del+Sistema)

### 2. Crear Códigos de Autorización

#### Opción A: Usar Códigos de Ejemplo

El sistema viene con 6 códigos de ejemplo instalados:

| Nombre | Código | Tipo | Contexto |
|--------|--------|------|----------|
| Supervisor Principal | `SUP2025` | supervisor | overtime |
| IT Administrator | `IT2025` | it | overtime |
| Gerente General | `MGR2025` | manager | overtime |
| Director de Operaciones | `DIR2025` | director | overtime |
| Recursos Humanos | `HR2025` | hr | overtime |
| Código Universal | `UNIVERSAL2025` | universal | overtime |

#### Opción B: Crear Códigos Personalizados

1. Ve a **Settings** → **Códigos de Autorización**
2. En la sección **Crear Código de Autorización**:

**Campos Obligatorios:**
- **Nombre del Código**: Descripción del código (ej: "Supervisor Turno Noche")
- **Código**: El código alfanumérico (ej: "SUPNOC2025")
  - Usa el botón **Generar** para crear uno aleatorio
- **Tipo de Rol**: Categoría del código
  - Supervisor
  - IT
  - Gerente
  - Director
  - Recursos Humanos
  - Universal (Todos)
  - Personalizado

**Campos Opcionales:**
- **Contexto de Uso**: Limita dónde se puede usar
  - Todos los contextos (predeterminado)
  - Hora Extra
  - Punch Especial
  - Editar Registros
  - Eliminar Registros

- **Válido Desde**: Fecha y hora de inicio de validez
- **Válido Hasta**: Fecha y hora de expiración
- **Máximo de Usos**: Número de veces que se puede usar (vacío = ilimitado)

3. Haz clic en **Crear Código**

### 3. Gestionar Códigos Existentes

En la tabla de códigos puedes:

- **Ver**: Icono 👁️ para ver detalles y estadísticas
- **Desactivar**: Icono 🚫 para desactivar el código
- **Ver Estado**: 
  - ✅ Activo (verde)
  - ❌ Expirado (rojo)
  - ❌ Límite alcanzado (rojo)

## 🔧 Uso del Sistema

### Para Empleados

#### Registrar Hora Extra con Código

1. Ve a `punch.php`
2. Ingresa tu username
3. Si intentas registrar hora extra fuera de tu horario:
   - Aparecerá automáticamente el campo **Código de Autorización**
   - El campo será obligatorio y tendrá borde amarillo
4. Solicita el código a tu supervisor
5. Ingresa el código en el campo
6. Selecciona el tipo de punch (ENTRY, EXIT, etc.)
7. El sistema validará el código automáticamente

**Mensajes Posibles:**
- ✅ "Attendance recorded successfully. (Código de autorización validado)"
- ❌ "Código de autorización inválido: Código no encontrado o inactivo"
- ❌ "Código de autorización inválido: Código expirado"
- ❌ "Código de autorización inválido: Código ha alcanzado el límite de usos"

### Para Supervisores/Gerentes

#### Compartir Códigos

1. Ve a **Settings** → **Códigos de Autorización**
2. Encuentra tu código en la tabla
3. Comparte el código con tus empleados autorizados
4. Cambia el código periódicamente por seguridad

#### Monitorear Uso

1. Haz clic en el icono 👁️ del código
2. Verás:
   - Número total de usos
   - Usuarios únicos que lo han usado
   - Historial completo con fechas y horas
   - IPs desde donde se usó

### Para Administradores

#### Crear Códigos Temporales

Ejemplo: Código para un proyecto específico de 1 mes

```
Nombre: Proyecto X - Horas Extra
Código: PROJX2025
Tipo: manager
Contexto: overtime
Válido Desde: 2025-11-01 00:00
Válido Hasta: 2025-11-30 23:59
Máximo de Usos: [vacío = ilimitado]
```

#### Crear Códigos de Un Solo Uso

Ejemplo: Código para una excepción puntual

```
Nombre: Excepción John Doe - 15 Nov
Código: EXC15NOV
Tipo: supervisor
Contexto: overtime
Válido Desde: 2025-11-15 00:00
Válido Hasta: 2025-11-15 23:59
Máximo de Usos: 1
```

#### Auditar Uso de Códigos

Consulta SQL para ver todos los usos:

```sql
SELECT 
    ac.code_name,
    ac.code,
    u.username,
    u.full_name,
    acl.usage_context,
    acl.ip_address,
    acl.used_at
FROM authorization_code_logs acl
JOIN authorization_codes ac ON ac.id = acl.authorization_code_id
JOIN users u ON u.id = acl.user_id
WHERE DATE(acl.used_at) = CURDATE()
ORDER BY acl.used_at DESC;
```

## 🔌 API REST

### Endpoints Disponibles

#### 1. Validar Código

```
POST /api/authorization_codes.php?action=validate
Content-Type: application/json

{
  "code": "SUP2025",
  "context": "overtime"
}
```

**Respuesta Exitosa:**
```json
{
  "success": true,
  "message": "Código válido",
  "data": {
    "code_id": 1,
    "code_name": "Supervisor Principal",
    "role_type": "supervisor"
  },
  "timestamp": "2025-11-06 14:30:00"
}
```

**Respuesta de Error:**
```json
{
  "success": false,
  "message": "Código expirado. Válido hasta: 2025-10-31 23:59",
  "data": null,
  "timestamp": "2025-11-06 14:30:00"
}
```

#### 2. Verificar Requerimiento

```
GET /api/authorization_codes.php?action=check_requirement&context=overtime
```

**Respuesta:**
```json
{
  "success": true,
  "message": "Configuración obtenida",
  "data": {
    "system_enabled": true,
    "required": true
  }
}
```

#### 3. Listar Códigos (Requiere Autenticación)

```
GET /api/authorization_codes.php?action=list&context=overtime
```

#### 4. Obtener Estadísticas (Requiere Autenticación)

```
GET /api/authorization_codes.php?action=stats&code_id=1&days=30
```

#### 5. Generar Código Aleatorio (Requiere Autenticación)

```
GET /api/authorization_codes.php?action=generate_code&length=8
```

**Respuesta:**
```json
{
  "success": true,
  "message": "Código generado",
  "data": {
    "code": "X7K9M2P4"
  }
}
```

## 📊 Estructura de Base de Datos

### Tabla: authorization_codes

```sql
CREATE TABLE `authorization_codes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code_name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(50) NOT NULL,
  `role_type` VARCHAR(50) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `usage_context` VARCHAR(100) DEFAULT NULL,
  `valid_from` DATETIME DEFAULT NULL,
  `valid_until` DATETIME DEFAULT NULL,
  `max_uses` INT DEFAULT NULL,
  `current_uses` INT NOT NULL DEFAULT 0,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Tabla: authorization_code_logs

```sql
CREATE TABLE `authorization_code_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `authorization_code_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `usage_context` VARCHAR(100) NOT NULL,
  `reference_id` INT DEFAULT NULL,
  `reference_table` VARCHAR(50) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `additional_data` JSON DEFAULT NULL,
  `used_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## 🔒 Seguridad

### Mejores Prácticas

1. **Cambio Regular de Códigos**
   - Cambia los códigos cada 1-3 meses
   - Desactiva códigos antiguos en lugar de eliminarlos

2. **Códigos Únicos por Contexto**
   - No reutilices el mismo código para diferentes propósitos
   - Usa códigos específicos por departamento o turno

3. **Límites de Uso**
   - Establece límites para códigos sensibles
   - Monitorea códigos con alto número de usos

4. **Auditoría Regular**
   - Revisa los logs semanalmente
   - Investiga patrones sospechosos de uso

5. **Acceso Controlado**
   - Solo admin y developer pueden crear/editar códigos
   - Los supervisores solo obtienen códigos, no los gestionan

### Permisos Requeridos

| Acción | Roles Permitidos |
|--------|------------------|
| Usar código | Todos los empleados |
| Ver códigos activos | admin, developer, hr_manager |
| Crear código | admin, developer |
| Editar código | admin, developer |
| Desactivar código | admin, developer |
| Ver estadísticas | admin, developer, hr_manager |

## 🆘 Solución de Problemas

### Problema: "Código no encontrado o inactivo"

**Causas Posibles:**
1. El código fue escrito incorrectamente (sensible a mayúsculas)
2. El código fue desactivado
3. El código no existe en el sistema

**Solución:**
- Verifica que el código esté escrito exactamente como aparece en Settings
- Contacta al administrador para verificar si el código está activo

### Problema: "Código expirado"

**Causa:**
- El código tenía fecha de expiración y ya pasó

**Solución:**
- Contacta al administrador para obtener un nuevo código
- El administrador debe crear un código nuevo o extender la fecha

### Problema: "Código ha alcanzado el límite de usos"

**Causa:**
- El código tenía un límite máximo de usos y se agotó

**Solución:**
- El administrador debe crear un nuevo código
- O aumentar el límite de usos del código existente

### Problema: El campo de código no aparece

**Causas Posibles:**
1. El sistema de autorización está deshabilitado
2. No se requiere código para hora extra
3. No estás intentando registrar hora extra

**Solución:**
1. Verificar en Settings que el sistema esté habilitado
2. Verificar que "Requerir código para Hora Extra" esté activo
3. Verificar que estés fuera de tu horario normal

### Problema: API retorna error 401 (No autenticado)

**Causa:**
- La sesión expiró o no estás autenticado

**Solución:**
- Inicia sesión nuevamente
- Para endpoints públicos (validate, check_requirement) no se requiere autenticación

## 📈 Estadísticas y Reportes

### Consultas SQL Útiles

#### Top 10 Códigos Más Usados

```sql
SELECT 
    ac.code_name,
    ac.code,
    ac.current_uses,
    COUNT(DISTINCT acl.user_id) as unique_users,
    MAX(acl.used_at) as last_use
FROM authorization_codes ac
LEFT JOIN authorization_code_logs acl ON ac.id = acl.authorization_code_id
WHERE ac.is_active = 1
GROUP BY ac.id
ORDER BY ac.current_uses DESC
LIMIT 10;
```

#### Uso de Códigos por Usuario

```sql
SELECT 
    u.full_name,
    u.username,
    COUNT(*) as times_used,
    GROUP_CONCAT(DISTINCT ac.code_name) as codes_used
FROM authorization_code_logs acl
JOIN users u ON u.id = acl.user_id
JOIN authorization_codes ac ON ac.id = acl.authorization_code_id
WHERE DATE(acl.used_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY u.id
ORDER BY times_used DESC;
```

#### Códigos Próximos a Expirar

```sql
SELECT 
    code_name,
    code,
    valid_until,
    DATEDIFF(valid_until, NOW()) as days_remaining
FROM authorization_codes
WHERE is_active = 1
  AND valid_until IS NOT NULL
  AND valid_until > NOW()
  AND DATEDIFF(valid_until, NOW()) <= 7
ORDER BY days_remaining ASC;
```

## 🔮 Próximas Funcionalidades

- [ ] Modal detallado para ver historial de uso de cada código
- [ ] Notificaciones por email cuando un código está por expirar
- [ ] Gráficos de uso en el dashboard
- [ ] Exportar logs a Excel
- [ ] Códigos temporales con generación automática
- [ ] Integración con sistema de notificaciones Slack
- [ ] Códigos QR para escanear en lugar de escribir

## 📞 Soporte

Para problemas técnicos o preguntas:
- Contacta al administrador del sistema
- Revisa los logs en `authorization_code_logs`
- Consulta esta documentación

## 📝 Changelog

### Versión 1.0.0 (Noviembre 2025)
- ✅ Sistema completo de códigos de autorización
- ✅ Integración con punch.php para hora extra
- ✅ API REST completa
- ✅ Interfaz de gestión en Settings
- ✅ Sistema de auditoría y logs
- ✅ Validación en tiempo real
- ✅ Códigos temporales y con límites

---

**Desarrollado para Ponche Xtreme**  
**Versión:** 1.0.0  
**Última actualización:** Noviembre 2025  
**Autor:** Ponche Xtreme Development Team

# Sistema de Activación/Desactivación de Usuarios

## Descripción General

Este sistema permite a los administradores habilitar, desactivar o eliminar usuarios desde la página de configuración. Los usuarios inactivos no pueden acceder al sistema.

## Características Implementadas

### 1. Campo de Estado en Base de Datos
- **Campo**: `is_active` (TINYINT)
- **Valores**: 
  - `1` = Usuario activo (puede iniciar sesión)
  - `0` = Usuario inactivo (no puede iniciar sesión)
- **Ubicación**: Tabla `users`
- **Migración**: `migrations/add_user_active_status.sql`

### 2. Validación en Login

#### Login Administrativo (`index.php`)
- Verifica el estado del usuario antes de permitir el acceso
- Muestra mensaje: "Tu cuenta ha sido desactivada. Contacta al administrador."

#### Login de Agentes (`login_agent.php`)
- Verifica el estado del usuario antes de permitir el acceso
- Muestra mensaje: "Tu cuenta ha sido desactivada. Contacta al administrador."

### 3. Gestión desde Settings

#### Visualización
- **Columna de Estado**: Muestra badge verde (Activo) o rojo (Inactivo)
- **Indicador Visual**: Las filas de usuarios inactivos se muestran con opacidad reducida
- **Ordenamiento**: Los usuarios activos aparecen primero en la lista

#### Acciones Disponibles

##### Activar/Desactivar Usuario
- **Botón**: "Desactivar" (naranja) para usuarios activos
- **Botón**: "Activar" (verde) para usuarios inactivos
- **Confirmación**: Requiere confirmación antes de ejecutar
- **Protección**: No puedes desactivar tu propia cuenta

##### Eliminar Usuario
- **Botón**: "Eliminar" (rojo)
- **Confirmación**: Requiere confirmación con advertencia
- **Protección**: No puedes eliminar tu propia cuenta
- **Cascada**: Elimina automáticamente el registro de empleado asociado

## Instalación

### 1. Ejecutar Migración SQL

```sql
-- Ejecutar en tu base de datos MySQL
source migrations/add_user_active_status.sql;
```

O ejecutar manualmente:

```sql
ALTER TABLE `users` 
ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 
COMMENT 'Estado del usuario: 1 = activo, 0 = inactivo' 
AFTER `overtime_multiplier`;

UPDATE `users` SET `is_active` = 1 WHERE `is_active` IS NULL;

ALTER TABLE `users` ADD INDEX `idx_is_active` (`is_active`);
```

### 2. Verificar Archivos Actualizados

Los siguientes archivos han sido modificados:
- `index.php` - Validación de login admin
- `login_agent.php` - Validación de login agentes
- `settings.php` - Interfaz de gestión y lógica de backend

## Uso

### Para Administradores

1. **Acceder a Configuración**
   - Ir a `Settings` → Pestaña `Usuarios`

2. **Desactivar un Usuario**
   - Localizar el usuario en la tabla
   - Hacer clic en el botón "Desactivar" (naranja)
   - Confirmar la acción
   - El usuario ya no podrá iniciar sesión

3. **Activar un Usuario**
   - Localizar el usuario inactivo (badge rojo)
   - Hacer clic en el botón "Activar" (verde)
   - Confirmar la acción
   - El usuario podrá iniciar sesión nuevamente

4. **Eliminar un Usuario**
   - Localizar el usuario en la tabla
   - Hacer clic en el botón "Eliminar" (rojo)
   - Confirmar la acción con advertencia
   - El usuario y su registro de empleado serán eliminados permanentemente

### Protecciones de Seguridad

- ✅ No puedes desactivar tu propia cuenta
- ✅ No puedes eliminar tu propia cuenta
- ✅ Confirmación requerida para todas las acciones
- ✅ Los usuarios inactivos son bloqueados en ambos portales (admin y agentes)
- ✅ Transacciones de base de datos para eliminar usuarios (rollback en caso de error)

## Mensajes de Error

| Acción | Error | Mensaje |
|--------|-------|---------|
| Login | Usuario inactivo | "Tu cuenta ha sido desactivada. Contacta al administrador." |
| Desactivar | Cuenta propia | "No puedes desactivar tu propia cuenta." |
| Eliminar | Cuenta propia | "No puedes eliminar tu propia cuenta." |

## Notas Técnicas

### Compatibilidad con Versiones Anteriores
- Los usuarios existentes se establecen como activos (`is_active = 1`) por defecto
- Si el campo `is_active` no existe, se asume que el usuario está activo

### Base de Datos
- Se agregó índice en `is_active` para mejorar el rendimiento de consultas
- La eliminación de usuarios es en cascada con la tabla `employees`

### Interfaz
- Los usuarios se ordenan por estado (activos primero) y luego por nombre de usuario
- Los usuarios inactivos se muestran con opacidad reducida para fácil identificación
- Badges de colores para identificación rápida del estado

## Soporte

Para cualquier problema o pregunta sobre este sistema, contacta al equipo de desarrollo.

# Instalación del Sistema de Activación de Usuarios

## Paso 1: Ejecutar la Migración SQL

Ejecuta el siguiente comando SQL en tu base de datos:

```bash
mysql -u hhempeos_ponche -p hhempeos_ponche < migrations/add_user_active_status.sql
```

O desde phpMyAdmin o tu cliente MySQL preferido, ejecuta:

```sql
-- Agregar campo is_active a la tabla users
ALTER TABLE `users` 
ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 
COMMENT 'Estado del usuario: 1 = activo, 0 = inactivo' 
AFTER `overtime_multiplier`;

-- Establecer todos los usuarios existentes como activos
UPDATE `users` SET `is_active` = 1 WHERE `is_active` IS NULL;

-- Agregar índice para mejor rendimiento
ALTER TABLE `users` ADD INDEX `idx_is_active` (`is_active`);
```

## Paso 2: Verificar la Instalación

1. Accede a la página de **Settings**
2. Ve a la pestaña **Usuarios**
3. Verifica que aparezca:
   - Una columna "Estado" con badges verde/rojo
   - Una columna "Acciones" con botones Activar/Desactivar/Eliminar

## Paso 3: Probar el Sistema

### Prueba de Desactivación
1. Selecciona un usuario de prueba (NO tu cuenta)
2. Haz clic en "Desactivar"
3. Confirma la acción
4. Intenta iniciar sesión con ese usuario
5. Deberías ver: "Tu cuenta ha sido desactivada. Contacta al administrador."

### Prueba de Activación
1. Desde Settings, localiza el usuario desactivado
2. Haz clic en "Activar"
3. Confirma la acción
4. El usuario debería poder iniciar sesión nuevamente

### Prueba de Eliminación
1. Crea un usuario de prueba temporal
2. Haz clic en "Eliminar"
3. Confirma la acción
4. El usuario debería desaparecer de la lista

## ¡Listo!

El sistema de activación/desactivación de usuarios está completamente instalado y funcional.

Para más información, consulta: `USER_ACTIVATION_SYSTEM.md`

# Sistema de Códigos de Autorización - Actualización de Registros

## 📋 Descripción General

Se ha extendido el sistema de códigos de autorización para requerir validación al **editar** y **eliminar** registros de asistencia, además del control existente para hora extra.

## 🎯 Características Implementadas

### 1. **Edición de Registros con Autorización**
- Los usuarios ahora necesitan un código de autorización válido para editar registros de asistencia
- El formulario de edición (`edit_record.php`) muestra dinámicamente el campo de código cuando está habilitado
- Se valida el código antes de aplicar cualquier cambio
- Se registra el uso del código en el log de auditoría

### 2. **Eliminación de Registros con Autorización**
- Se implementó un modal elegante para confirmar la eliminación
- El modal incluye un campo para el código de autorización cuando está habilitado
- El código se valida antes de proceder con la eliminación
- Todas las eliminaciones quedan registradas en el log

### 3. **Interfaz de Configuración**
- Nuevos toggles en Settings > Códigos de Autorización:
  - ✅ Requerir código para Editar Registros
  - ✅ Requerir código para Eliminar Registros
- Se pueden habilitar/deshabilitar independientemente

### 4. **Contextos de Uso Extendidos**
Los códigos de autorización ahora soportan los siguientes contextos:
- `overtime_punch` - Hora Extra
- `edit_record` - Editar Registros
- `delete_record` - Eliminar Registros
- `special_punch` - Punch Especial (futuro)

## 📁 Archivos Modificados

### 1. **INSTALL_AUTHORIZATION_CODES.sql**
```sql
-- Nuevas configuraciones agregadas
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`, `category`) VALUES
('authorization_require_for_edit_records', '1', 'Requerir código de autorización para editar registros de asistencia', 'authorization_codes'),
('authorization_require_for_delete_records', '1', 'Requerir código de autorización para eliminar registros de asistencia', 'authorization_codes');
```

### 2. **delete_record.php**
**Cambios principales:**
- ✅ Se agregó `require_once 'lib/authorization_functions.php'`
- ✅ Se agregó verificación de permisos con `ensurePermission('records')`
- ✅ Validación del código de autorización antes de eliminar
- ✅ Registro del uso del código con contexto `delete_record`
- ✅ Manejo de errores mejorado con mensajes de sesión

**Flujo de validación:**
```php
if (isAuthorizationRequiredForContext($pdo, 'delete_record')) {
    $validation = validateAuthorizationCode($pdo, $authorizationCode, 'delete_record', $_SESSION['user_id']);
    if (!$validation['valid']) {
        $_SESSION['error'] = "Código inválido: " . $validation['error'];
        redirect();
    }
}
```

### 3. **edit_record.php**
**Cambios principales:**
- ✅ Se agregó campo de código de autorización al formulario
- ✅ Validación del código antes de actualizar el registro
- ✅ El campo solo aparece si la configuración está habilitada
- ✅ Registro del uso con datos de cambio (old_values, new_values)

**UI Condicional:**
```php
<?php if (isAuthorizationRequiredForContext($pdo, 'edit_record')): ?>
<div class="mb-4 bg-yellow-50 border border-yellow-300 p-4 rounded">
    <label for="authorization_code">
        <i class="fas fa-lock"></i> Código de Autorización *
    </label>
    <input type="text" name="authorization_code" required>
</div>
<?php endif; ?>
```

### 4. **records.php**
**Cambios principales:**
- ✅ Modal elegante para confirmar eliminación con código
- ✅ Botón de eliminar reemplazado con llamada al modal
- ✅ JavaScript para gestionar el modal (abrir, cerrar, submit)
- ✅ Soporte para cerrar con ESC y click fuera del modal
- ✅ Diseño responsive y animado

**Estructura del Modal:**
```html
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">...</div>
        <div class="modal-body">
            <!-- Campo de código (condicional) -->
            <form id="deleteForm">...</form>
        </div>
        <div class="modal-footer">
            <button onclick="closeDeleteModal()">Cancelar</button>
            <button onclick="submitDelete()">Eliminar</button>
        </div>
    </div>
</div>
```

**JavaScript Functions:**
```javascript
function openDeleteModal(recordId) { ... }
function closeDeleteModal() { ... }
function submitDelete() { ... }
```

### 5. **settings.php**
**Cambios en el backend:**
```php
case 'toggle_auth_system':
    $requireForEdit = isset($_POST['authorization_require_for_edit_records'])  1 : 0;
    $requireForDelete = isset($_POST['authorization_require_for_delete_records'])  1 : 0;
    $stmt->execute(['authorization_require_for_edit_records', $requireForEdit]);
    $stmt->execute(['authorization_require_for_delete_records', $requireForDelete]);
```

**Cambios en el UI:**
- 2 nuevos checkboxes en la sección de configuración
- Dropdown de contextos actualizado con las nuevas opciones
- Descripción de ayuda para cada opción

## 🔧 Configuración del Sistema

### Paso 1: Ejecutar el SQL actualizado
```bash
mysql -u root ponche < INSTALL_AUTHORIZATION_CODES.sql
```

### Paso 2: Configurar en Settings
1. Ve a **Settings > Códigos de Autorización**
2. Habilita "Sistema de Códigos de Autorización"
3. Marca las opciones que necesites:
   - ☑️ Requerir código para Hora Extra
   - ☑️ Requerir código para Editar Registros
   - ☑️ Requerir código para Eliminar Registros
4. Clic en "Guardar Configuración"

### Paso 3: Crear códigos para cada contexto
1. En la misma página, crea códigos específicos:
   ```
   Nombre: Supervisor - Editar
   Código: EDIT2025
   Rol: supervisor
   Contexto: edit_record
   ```
   ```
   Nombre: Manager - Eliminar
   Código: DEL2025
   Rol: manager
   Contexto: delete_record
   ```

## 📊 Tabla de Contextos

| Contexto | Setting Key | Descripción | Implementado en |
|----------|-------------|-------------|-----------------|
| `overtime_punch` | `authorization_require_for_overtime` | Registro de hora extra | `punch.php` |
| `edit_record` | `authorization_require_for_edit_records` | Editar registros de asistencia | `edit_record.php` |
| `delete_record` | `authorization_require_for_delete_records` | Eliminar registros de asistencia | `delete_record.php` |
| `special_punch` | (futuro) | Punches especiales | (futuro) |

## 🔐 Validación de Códigos

### Criterios de validación:
1. ✅ El código existe y está activo
2. ✅ El contexto coincide (o el código es universal)
3. ✅ Está dentro del rango de fechas válidas
4. ✅ No ha excedido el límite de usos
5. ✅ El usuario tiene permiso para usarlo

### Ejemplo de código universal:
```php
code: UNIVERSAL2025
role_type: admin
usage_context: NULL  // Funciona en TODOS los contextos
max_uses: NULL       // Usos ilimitados
```

## 📝 Log de Auditoría

Cada uso de código queda registrado en `authorization_code_logs`:

```sql
SELECT 
    acl.id,
    ac.code,
    ac.code_name,
    u.full_name as used_by,
    acl.usage_context,
    acl.reference_table,
    acl.reference_id,
    acl.used_at
FROM authorization_code_logs acl
JOIN authorization_codes ac ON acl.authorization_code_id = ac.id
JOIN users u ON acl.user_id = u.id
WHERE acl.usage_context IN ('edit_record', 'delete_record')
ORDER BY acl.used_at DESC;
```

## 🎨 Diseño del Modal

El modal de eliminación incluye:
- **Animación de entrada** (slide in desde arriba)
- **Backdrop blur** para mejor enfoque
- **Diseño glassmorphism** consistente con el sistema
- **Cierre con ESC** o click fuera
- **Responsive** para móviles
- **Estados hover** animados

## 🧪 Pruebas Recomendadas

### Test 1: Editar con autorización habilitada
```
1. Habilitar "Requerir código para Editar Registros"
2. Ir a records.php
3. Clic en editar un registro
4. Verificar que aparece campo de código
5. Intentar guardar sin código → Error
6. Ingresar código válido → Éxito
```

### Test 2: Eliminar con autorización habilitada
```
1. Habilitar "Requerir código para Eliminar Registros"
2. Ir a records.php
3. Clic en eliminar un registro
4. Verificar que abre modal con campo de código
5. Clic en "Eliminar" sin código → Alert
6. Ingresar código válido y eliminar → Éxito
```

### Test 3: Autorización deshabilitada
```
1. Deshabilitar ambas opciones
2. Editar un registro → No pide código
3. Eliminar un registro → No pide código (pero sí muestra modal)
```

### Test 4: Código inválido/expirado
```
1. Usar código expirado → Error con mensaje claro
2. Usar código de otro contexto → Error de contexto
3. Usar código con límite alcanzado → Error de límite
```

## 🚀 Próximas Mejoras

- [ ] Modal para ver detalles del código en settings
- [ ] Historial de uso por código
- [ ] Notificaciones cuando un código está próximo a expirar
- [ ] Dashboard de estadísticas de uso
- [ ] Exportar logs de autorización a Excel
- [ ] Agregar contexto `special_punch` para punches especiales
- [ ] API endpoint para validar desde aplicaciones externas

## 📞 Soporte

Si tienes problemas:
1. Verifica que el SQL se ejecutó correctamente
2. Revisa los logs de PHP en `xampp/logs/php_error_log`
3. Verifica la consola del navegador para errores JS
4. Confirma que `lib/authorization_functions.php` está incluido

## 📄 Archivos Relacionados

- `AUTHORIZATION_CODES_SYSTEM.md` - Documentación completa del sistema
- `INSTALL_AUTHORIZATION_CODES.sql` - Script de instalación
- `INSTALL_AUTHORIZATION_CODES_README.md` - Guía rápida
- `lib/authorization_functions.php` - Librería de funciones
- `api/authorization_codes.php` - API REST

---

**Última actualización:** 2025
**Versión:** 2.0
**Estado:** ✅ Implementado y probado

# 🔐 Sistema de Códigos de Autorización - Guía Rápida de Instalación

## ⚡ Instalación Rápida (5 minutos)

### 1️⃣ Ejecutar Script SQL

```bash
# Opción A: Línea de comandos
mysql -u root -p ponche_xtreme < INSTALL_AUTHORIZATION_CODES.sql

# Opción B: phpMyAdmin
# 1. Abre phpMyAdmin
# 2. Selecciona tu base de datos
# 3. Ve a SQL → Importar archivo
# 4. Selecciona INSTALL_AUTHORIZATION_CODES.sql
```

### 2️⃣ Verificar Instalación

El script creará:
- ✅ Tabla `authorization_codes`
- ✅ Tabla `authorization_code_logs`  
- ✅ Tabla `system_settings` (si no existe)
- ✅ 6 códigos de ejemplo listos para usar
- ✅ Vista `v_active_authorization_codes`
- ✅ Procedimientos almacenados de validación

### 3️⃣ Habilitar el Sistema

1. Inicia sesión como **admin** o **developer**
2. Ve a **Settings** → **Códigos de Autorización** (nueva pestaña)
3. Activa: ☑️ **Habilitar Sistema de Códigos de Autorización**
4. Activa: ☑️ **Requerir código para Hora Extra**
5. Clic en **Guardar Configuración**

### 4️⃣ Probar el Sistema

1. Ve a `punch.php`
2. Ingresa un username
3. Si es hora extra (después de las 7 PM), aparecerá el campo de código
4. Ingresa uno de los códigos de ejemplo:
   - `SUP2025` (Supervisor)
   - `IT2025` (IT)
   - `MGR2025` (Gerente)
   - `DIR2025` (Director)
   - `HR2025` (Recursos Humanos)
   - `UNIVERSAL2025` (Universal)
5. Registra el punch

## 📋 Códigos de Ejemplo Incluidos

| Código | Nombre | Tipo | Uso |
|--------|--------|------|-----|
| `SUP2025` | Supervisor Principal | supervisor | Hora Extra |
| `IT2025` | IT Administrator | it | Hora Extra |
| `MGR2025` | Gerente General | manager | Hora Extra |
| `DIR2025` | Director de Operaciones | director | Hora Extra |
| `HR2025` | Recursos Humanos | hr | Hora Extra |
| `UNIVERSAL2025` | Código Universal | universal | Hora Extra |

## 🎯 Primeros Pasos

### Crear tu Primer Código Personalizado

1. Ve a **Settings** → **Códigos de Autorización**
2. En "Crear Código de Autorización":
   - **Nombre**: "Supervisor Turno A"
   - **Código**: Clic en "Generar" o escribe uno (ej: `SUPA2025`)
   - **Tipo de Rol**: Supervisor
   - **Contexto**: Hora Extra
3. Clic en **Crear Código**

### Compartir Código con Empleados

1. Encuentra el código en la tabla
2. Comparte el código con tus empleados autorizados
3. Los empleados lo usarán cuando registren hora extra

### Monitorear Uso

1. En la tabla de códigos, verás:
   - Número de usos actuales
   - Estado (Activo/Expirado)
   - Límite de usos (si aplica)
2. Clic en 👁️ para ver detalles (próximamente)

## 🔧 Archivos Modificados

El sistema agregó/modificó estos archivos:

```
ponche-xtreme/
├── lib/
│   └── authorization_functions.php     [NUEVO] Funciones del sistema
├── api/
│   └── authorization_codes.php         [NUEVO] API REST
├── settings.php                        [MODIFICADO] Nueva pestaña
├── punch.php                           [MODIFICADO] Validación de códigos
├── INSTALL_AUTHORIZATION_CODES.sql    [NUEVO] Script de instalación
├── AUTHORIZATION_CODES_SYSTEM.md      [NUEVO] Documentación completa
└── INSTALL_AUTHORIZATION_CODES_README.md [NUEVO] Esta guía
```

## ❓ FAQ

### ¿Dónde gestiono los códigos?
**Settings** → **Códigos de Autorización** (nueva pestaña con icono 🔑)

### ¿Cuándo se solicita el código?
Cuando un empleado intenta registrar un punch fuera de su horario normal (hora extra).

### ¿Puedo crear códigos temporales?
Sí, al crear un código especifica "Válido Desde" y "Válido Hasta".

### ¿Puedo limitar los usos?
Sí, especifica "Máximo de Usos" al crear el código.

### ¿Cómo desactivo un código?
En la tabla de códigos, clic en el botón 🚫 "Desactivar".

### ¿Se puede usar el mismo código en otros contextos?
Sí! El sistema está preparado para usar códigos en:
- Hora Extra (activo)
- Punch Especial (configurable)
- Editar Registros (configurable)
- Eliminar Registros (configurable)

## 🐛 Solución de Problemas

### Error: "Table 'authorization_codes' doesn't exist"
**Solución**: Ejecuta el script SQL de instalación.

### Error: "Missing required fields"
**Solución**: Asegúrate de completar Nombre, Código y Tipo de Rol.

### No aparece la pestaña "Códigos de Autorización"
**Solución**: 
1. Limpia caché del navegador (Ctrl+F5)
2. Verifica que tengas rol admin o developer
3. Verifica que los archivos se hayan actualizado correctamente

### El campo de código no aparece en punch.php
**Solución**:
1. Verifica que el sistema esté habilitado en Settings
2. Verifica que "Requerir código para Hora Extra" esté activo
3. Intenta registrar un punch fuera del horario normal (después de 7 PM)

## 📚 Documentación Completa

Para información detallada, consulta:
- **AUTHORIZATION_CODES_SYSTEM.md** - Documentación completa del sistema
- Estructura de base de datos
- API REST endpoints
- Consultas SQL útiles
- Mejores prácticas de seguridad

## 🎉 ¡Listo!

Tu sistema de códigos de autorización está funcionando. Ahora puedes:
- ✅ Controlar quién registra hora extra
- ✅ Auditar todos los usos de códigos
- ✅ Crear códigos temporales o limitados
- ✅ Expandir el sistema a otros contextos

---

**¿Necesitas ayuda?**
Consulta `AUTHORIZATION_CODES_SYSTEM.md` para documentación completa.

**Versión:** 1.0.0  
**Fecha:** Noviembre 2025

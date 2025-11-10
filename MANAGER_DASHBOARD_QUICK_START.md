# ⚡ Guía Rápida - Manager Dashboard

## 🎯 ¿Qué es?
Dashboard en tiempo real para que gerentes monitoreen el estado de asistencia del personal administrativo (todos los roles excepto agents).

## 📦 ¿Qué se instaló?

### Nuevos Archivos
- `manager_dashboard.php` - Interfaz del dashboard
- `manager_realtime_api.php` - API de datos en tiempo real

### Archivos Actualizados
- `settings.php` - Nueva sección en categoría "Gerencia"
- `header.php` - Nuevo enlace en el menú

## 🚀 Activación (3 clicks)

### DESDE LA INTERFAZ DE USUARIO:

1. **Configuración** → Click en ⚙️
2. **Roles y Permisos** → Pestaña
3. **Gerencia** → Buscar "Monitor Administrativos"
4. ☑️ Marcar: `manager`, `hr`, `developer`
5. **Guardar Permisos** → Click

¡Listo! 🎉

## 📍 ¿Dónde está?

Después de asignar permisos:
- Menú lateral → **"Monitor Administrativos"** 👔
- URL: `manager_dashboard.php`

## 👥 ¿Quién puede verlo?

Roles configurables desde Settings:
- ✅ **manager** (Gerente)
- ✅ **hr** (Recursos Humanos)
- ✅ **developer** (Desarrollador)
- ✅ Cualquier otro rol que asignes desde la UI

## 🎨 Características

- ⚡ Actualización cada 5 segundos
- 📊 Estadísticas en vivo
- 🎯 Filtros por rol y estado
- 👔 Solo personal administrativo
- 🌓 Tema claro/oscuro
- 📱 Responsive

## 🔍 Muestra

- Supervisores
- Gerentes
- HR
- Desarrolladores
- Operations
- Cualquier rol excepto "agent"

## ⚠️ IMPORTANTE

**NO ejecutar scripts SQL**  
Los permisos se asignan SOLO desde:
```
Configuración > Roles y Permisos > Asignar Permisos
```

## 🆘 Ayuda Rápida

**No veo el menú:**
1. ¿Asignaste el permiso desde Settings?
2. ¿Cerraste sesión y volviste a entrar?
3. ¿Tu usuario tiene el rol correcto?

**No carga datos:**
1. Abre consola (F12) → ¿Hay errores?
2. Verifica `manager_realtime_api.php` en el navegador
3. ¿Hay usuarios con roles diferentes a 'agent'?

---

**Documentación completa:** `MANAGER_DASHBOARD_README.md`  
**Guía de instalación:** `MANAGER_DASHBOARD_SETUP.md`

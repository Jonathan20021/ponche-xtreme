# 🎯 Acceso a la Calculadora - Configuración Completada

## ✅ Cambios Realizados

Se ha agregado el acceso a la Calculadora de Nivel de Servicio en **DOS ubicaciones** para facilitar el acceso:

---

## 📍 Ubicación 1: Menú de Navegación Principal

### Ruta en el Menú
```
Recursos Humanos → Calculadora SL
```

### Detalles
- **Archivo modificado**: `header.php`
- **Ubicación**: Submenú de Recursos Humanos
- **Posición**: Justo después de "WFM Planificacion"
- **Icono**: <i class="fas fa-calculator"></i> Calculadora
- **Etiqueta**: "Calculadora SL"

### Cómo Acceder
1. Click en el menú "Recursos Humanos" en la barra superior
2. Se despliega el submenú
3. Click en "Calculadora SL"
4. Se abre la calculadora

---

## 📍 Ubicación 2: Dashboard de Recursos Humanos

### Ruta Visual
```
Recursos Humanos → Panel RH → Card "Calculadora de Nivel de Servicio"
```

### Detalles
- **Archivo modificado**: `hr/index.php`
- **Ubicación**: Grid de módulos principales
- **Posición**: Después de la card de "Vacantes"
- **Card visual**: Con gradiente cyan-azul
- **Icono**: <i class="fas fa-calculator"></i>
- **Título**: "Calculadora de Nivel de Servicio"
- **Descripción**: "Calcula agentes requeridos con fórmula de Erlang C"

### Cómo Acceder
1. Ir al Dashboard Principal
2. Click en "Recursos Humanos"
3. En el panel de módulos, localizar la card "Calculadora de Nivel de Servicio"
4. Click en la card
5. Se abre la calculadora

---

## 🎨 Diseño Visual

### Card en Dashboard
```css
Gradiente: #06b6d4 → #3b82f6 (Cyan a Azul)
Icono: fas fa-calculator
Estilo: Hover effect con elevación
Responsive: Adaptable a todas las pantallas
```

### Ubicación en Grid
```
| Reclutamiento | Vacantes | WFM Planning |
| Calculadora SL | ... | ... |
```

---

## 🔐 Permisos Requeridos

Para acceder a la calculadora, el usuario debe tener:
```
Permission: wfm_planning
```

Si un usuario sin permisos intenta acceder:
- ❌ Verá error 403 Forbidden
- 🔒 Será redirigido a página de no autorizado

### Asignar Permisos
```sql
-- Para asignar permisos a un usuario
UPDATE users 
SET role = 'ADMIN' 
WHERE id = X;

-- O crear permiso específico en system_settings
```

---

## 🚀 URLs de Acceso Directo

### Desarrollo (Local)
```
http://localhost/ponche-xtreme/hr/service_level_calculator.php
```

### Producción
```
https://tu-dominio.com/hr/service_level_calculator.php
```

### Desde Subdirectorios
```
Desde /hr/: service_level_calculator.php
Desde raíz: hr/service_level_calculator.php
Desde /agents/: ../hr/service_level_calculator.php
```

---

## 📸 Vista Previa

### En el Menú de Navegación
```
[≡] Recursos Humanos ▼
    ├── Panel RH
    ├── Empleados
    ├── Gestión de Campañas
    ├── Productividad
    ├── WFM Planificacion
    ├── 🆕 Calculadora SL ← NUEVO
    ├── Período de Prueba
    └── ...
```

### En el Dashboard de HR
```
┌──────────────┬──────────────┬──────────────┐
│ Reclutamiento│   Vacantes   │ WFM Planning │
├──────────────┼──────────────┼──────────────┤
│ 🆕 Calculadora│              │              │
│ de Nivel de  │              │              │
│ Servicio     │              │              │
└──────────────┴──────────────┴──────────────┘
```

---

## ✅ Verificación

### Pruebas Realizadas
- ✅ Header.php modificado correctamente
- ✅ hr/index.php modificado correctamente
- ✅ Sin errores de sintaxis
- ✅ Enlaces funcionando
- ✅ Responsive design mantenido

### Para Verificar Visualmente
1. **Refrescar el navegador** (Ctrl + F5)
2. Ir a Dashboard → Recursos Humanos
3. **Verificar menú superior**: Debe aparecer "Calculadora SL" en submenú
4. **Verificar dashboard**: Debe aparecer card visual de calculadora

---

## 🛠️ Troubleshooting

### No veo el menú nuevo
**Solución**: Limpiar caché del navegador
```javascript
// En consola del navegador
localStorage.clear();
sessionStorage.clear();
location.reload(true);
```

### No veo la card en dashboard
**Solución**: Verificar que estás en hr/index.php
```
URL correcta: /hr/index.php o /hr/
```

### Error 403 al acceder
**Solución**: Verificar permisos del usuario
```sql
SELECT role FROM users WHERE id = TU_USER_ID;
-- Debe ser: ADMIN, HR, o tener permiso wfm_planning
```

---

## 📝 Archivos Modificados

1. **header.php** (Línea ~59)
   - Agregado enlace en submenú de Recursos Humanos

2. **hr/index.php** (Línea ~545)
   - Agregada card visual en grid de módulos

---

## 🎉 ¡Listo Para Usar!

La calculadora ahora es **fácilmente accesible** desde:
- ✅ Menú de navegación principal
- ✅ Dashboard visual de Recursos Humanos
- ✅ URL directa

**¡Ya puedes empezar a calcular dimensionamiento de agentes!** 🚀

---

*Configurado: 13 de Marzo, 2026*

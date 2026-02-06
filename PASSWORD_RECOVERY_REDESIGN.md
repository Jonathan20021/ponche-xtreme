# Rediseño de Página de Recuperación de Contraseña

## 📋 Resumen de Cambios

Se ha actualizado completamente la página de recuperación de contraseña para agentes (`password_recovery_agent.php`) para que coincida con el nuevo diseño de dos columnas implementado en las páginas de login.

## 🎨 Características del Nuevo Diseño

### Estructura de Dos Columnas (Split-Panel)

#### Panel Izquierdo - Marca (Brand Panel)
- **Logo de Evallish BPO** centrado y prominente
- **Título principal**: "Recuperación de Contraseña"
- **Descripción**: "Sigue los pasos para restablecer tu contraseña de forma segura"
- **Lista de características** con iconos:
  - ✓ Verificación de identidad segura
  - ✓ Proceso protegido y confidencial
  - ✓ Rápido y fácil de completar
- **Fondo degradado azul** con los colores de la marca (#4A90E2 → #5B9BD5)

#### Panel Derecho - Formulario (Form Panel)
- **Icono de llave** en la parte superior
- **Indicador de paso**: "Paso 1 de 2" o "Paso 2 de 2"
- **Formularios adaptados** al diseño split-panel
- **Fondo claro** que contrasta con el panel de marca

### Proceso de Dos Pasos

#### Paso 1: Verificación de Identidad
- Campo de **Usuario**
- Campo de **Número de Cédula** (11 dígitos sin guiones)
- Botón "Verificar Identidad"
- Link para volver al inicio de sesión

#### Paso 2: Nueva Contraseña
- Banner de confirmación mostrando el usuario verificado
- Campo de **Nueva Contraseña** (mínimo 6 caracteres)
- Campo de **Confirmar Contraseña**
- Caja informativa con requisitos de contraseña
- Botón "Cambiar Contraseña"
- Link para cancelar y volver al login

## 🌓 Funcionalidad de Modo Oscuro

### Botón de Cambio de Tema
- **Posición**: Fijo en la esquina inferior derecha
- **Diseño**: Botón circular flotante con degradado azul
- **Iconos dinámicos**: 
  - 🌙 Luna en modo oscuro
  - ☀️ Sol en modo claro
- **Animaciones**: 
  - Efecto hover con elevación
  - Rotación del icono al pasar el mouse
  - Transición suave entre estados

### Implementación Técnica
```javascript
// AJAX request para cambiar el tema sin recargar
fetch('theme_toggle.php', {
    method: 'POST',
    body: 'ajax=1'
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        location.reload(); // Recarga para aplicar el nuevo tema
    }
});
```

### Backend (theme_toggle.php)
```php
// Detecta solicitudes AJAX
$isAjax = isset($_POST['ajax']) || 
          (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($isAjax) {
    // Respuesta JSON para AJAX
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'theme' => $_SESSION['theme']
    ]);
    exit;
}
```

## 🎨 Estilos CSS Agregados

### Botón de Tema (theme-toggle-btn)
```css
.theme-toggle-btn {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #4A90E2 0%, #5B9BD5 100%);
    z-index: 9999;
    /* Efectos hover, active y animaciones */
}

/* Modo claro - colores naranja/amarillo */
body.theme-light .theme-toggle-btn {
    background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
}

/* Responsive */
@media (max-width: 640px) {
    .theme-toggle-btn {
        width: 48px;
        height: 48px;
        bottom: 1.5rem;
        right: 1.5rem;
    }
}
```

## 📱 Diseño Responsive

- **Desktop**: Dos columnas lado a lado (50% cada una)
- **Tablet** (< 968px): Columnas apiladas, panel de marca arriba
- **Móvil** (< 640px): 
  - Botón de tema más pequeño (48px)
  - Padding reducido
  - Texto optimizado para pantallas pequeñas

## 🔧 Archivos Modificados

### 1. password_recovery_agent.php
- ✅ Estructura HTML actualizada a split-panel
- ✅ Formularios adaptados con clases `form-field-split`
- ✅ Botones con clase `split-submit-btn`
- ✅ Links con clase `split-forgot-link`
- ✅ Botón de cambio de tema agregado
- ✅ Script JavaScript para toggle AJAX

### 2. theme_toggle.php
- ✅ Soporte para solicitudes AJAX agregado
- ✅ Respuesta JSON cuando se detecta AJAX
- ✅ Mantiene compatibilidad con redirecciones tradicionales

### 3. assets/css/theme.css
- ✅ Estilos del botón de tema agregados al final
- ✅ Variantes para modo claro y oscuro
- ✅ Animaciones hover y active
- ✅ Media queries responsive

## ✨ Mejoras de UX

1. **Feedback Visual Claro**: Indicadores de paso (1 de 2, 2 de 2)
2. **Mensajes de Estado**: Alertas visuales para errores y éxitos
3. **Validación de Campos**: Hints y patrones de validación
4. **Navegación Intuitiva**: Links claros para volver o cancelar
5. **Tema Persistente**: El tema seleccionado se mantiene entre páginas
6. **Accesibilidad**: Labels descriptivos y aria-labels en botones

## 🔒 Seguridad Mantenida

- ✅ Validación de entrada en el backend
- ✅ Preparación de consultas SQL (PDO prepared statements)
- ✅ Sanitización de output con htmlspecialchars()
- ✅ Verificación de identidad en dos pasos
- ✅ Requisitos de contraseña aplicados

## 🎯 Próximos Pasos Sugeridos

1. ⏭️ Adaptar `password_recovery.php` (versión administrativa) al mismo diseño
2. ⏭️ Implementar animación de transición suave entre temas (sin reload)
3. ⏭️ Agregar feedback visual durante el cambio de tema (spinner/loader)
4. ⏭️ Considerar guardar preferencia de tema en base de datos por usuario

## 📸 Elementos Visuales Clave

### Colores de Marca
- **Primario**: #4A90E2 (Azul Evallish)
- **Secundario**: #5B9BD5 (Azul claro)
- **Acento**: #22d3ee (Cyan brillante)
- **Tema Oscuro**: #0f172a (Fondo base)
- **Tema Claro**: #f8fafc (Fondo base)

### Tipografía
- **Familia**: Inter (Google Fonts)
- **Pesos**: 300, 400, 500, 600, 700

### Iconos
- **Librería**: Font Awesome 6.0.0
- **Estilo**: Solid (fas)

---

**Fecha de Actualización**: <?= date('Y-m-d H:i:s') ?>
**Estado**: ✅ Completado y funcional

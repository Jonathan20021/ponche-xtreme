# 🎨 Guía de Personalización - Calculadora de Nivel de Servicio

## Índice
1. [Colores y Gradientes](#colores-y-gradientes)
2. [Tipografía y Tamaños](#tipografía-y-tamaños)
3. [Animaciones](#animaciones)
4. [Badges y Indicadores](#badges-y-indicadores)
5. [Presets Personalizados](#presets-personalizados)
6. [Campos Adicionales](#campos-adicionales)
7. [Integración con Sistema](#integración-con-sistema)

---

## Colores y Gradientes

### Cambiar el Color Principal de la Card

**Ubicación**: `hr/service_level_calculator.php` - Sección `<style>`

```css
/* ANTES */
.calculator-card {
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.9) 0%, rgba(30, 41, 59, 0.7) 100%);
    border: 1px solid rgba(100, 200, 255, 0.2);
}

/* DESPUÉS - Color Púrpura */
.calculator-card {
    background: linear-gradient(135deg, rgba(88, 28, 135, 0.9) 0%, rgba(124, 58, 237, 0.7) 100%);
    border: 1px solid rgba(168, 85, 247, 0.3);
}

/* DESPUÉS - Color Verde */
.calculator-card {
    background: linear-gradient(135deg, rgba(6, 78, 59, 0.9) 0%, rgba(16, 185, 129, 0.7) 100%);
    border: 1px solid rgba(52, 211, 153, 0.3);
}
```

### Cambiar Color del Botón Calcular

```css
/* ANTES */
.btn-calculate {
    background: linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%);
    box-shadow: 0 4px 15px rgba(6, 182, 212, 0.4);
}

/* DESPUÉS - Verde Esmeralda */
.btn-calculate {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
}

/* DESPUÉS - Naranja */
.btn-calculate {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
}
```

### Paleta de Colores Completa

```css
/* CYAN (Original) */
--primary-color: #06b6d4;
--primary-dark: #0891b2;
--primary-light: #22d3ee;

/* PURPLE (Alternativa 1) */
--primary-color: #a855f7;
--primary-dark: #9333ea;
--primary-light: #c084fc;

/* GREEN (Alternativa 2) */
--primary-color: #10b981;
--primary-dark: #059669;
--primary-light: #34d399;

/* ORANGE (Alternativa 3) */
--primary-color: #f59e0b;
--primary-dark: #d97706;
--primary-light: #fbbf24;
```

### Cambiar Fondo de Inputs

```css
/* ANTES */
.input-field {
    background: rgba(15, 23, 42, 0.8);
    border: 1px solid rgba(100, 200, 255, 0.3);
}

/* DESPUÉS - Más oscuro */
.input-field {
    background: rgba(0, 0, 0, 0.6);
    border: 1px solid rgba(100, 200, 255, 0.4);
}

/* DESPUÉS - Más claro */
.input-field {
    background: rgba(241, 245, 249, 0.1);
    border: 1px solid rgba(148, 163, 184, 0.3);
}
```

---

## Tipografía y Tamaños

### Cambiar Tamaño de Títulos

```css
/* Título Principal */
h1 {
    font-size: 2.5rem; /* Aumentar */
    font-weight: 800;
}

/* Subtítulos de Cards */
.calculator-card h2 {
    font-size: 1.5rem; /* Aumentar */
    font-weight: 700;
}
```

### Cambiar Fuente

**En `header.php`** o al inicio del archivo:

```html
<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">

<style>
body {
    font-family: 'Inter', sans-serif;
}

/* O usar una fuente más display para títulos */
h1, h2, h3 {
    font-family: 'Poppins', sans-serif;
}
</style>
```

### Espaciado y Padding

```css
/* Más espacioso */
.calculator-card {
    padding: 2rem; /* Aumentar de 1.5rem */
}

.input-group {
    margin-bottom: 2rem; /* Aumentar de 1.5rem */
}

/* Más compacto  */
.calculator-card {
    padding: 1rem;
}

.input-group {
    margin-bottom: 1rem;
}
```

---

## Animaciones

### Hacer la Animación más Suave

```css
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(30px); /* Aumentar para más efecto */
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-slide-in {
    animation: slideIn 0.8s ease-out; /* Más lento */
}
```

### Agregar Hover Effect a Cards

```css
.calculator-card {
    /* ...estilos existentes... */
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.calculator-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 40px 0 rgba(0, 0, 0, 0.5);
}
```

### Desactivar Animaciones

```css
/* Remover o comentar */
.animate-slide-in {
    /* animation: slideIn 0.5s ease-out; */
}
```

---

## Badges y Indicadores

### Cambiar Colores de Service Level Badge

```javascript
// En hr/service_level_calculator.php - función displayResults()

// ANTES
if (slPercent >= 90) {
    slBadge.textContent = 'Excelente';
    slBadge.className = 'metric-badge metric-excellent';
}

// DESPUÉS - Personalizar umbrales
if (slPercent >= 95) {
    slBadge.textContent = '⭐ Excepcional';
    slBadge.className = 'metric-badge metric-excellent';
} else if (slPercent >= 85) {
    slBadge.textContent = '✓ Excelente';
    slBadge.className = 'metric-badge metric-excellent';
}
```

### Personalizar Estilos de Badges

```css
/* Badge Excelente - Verde más vibrante */
.metric-excellent {
    background: rgba(16, 185, 129, 0.3);
    border: 2px solid rgba(16, 185, 129, 0.6);
    color: #6ee7b7;
    font-weight: 700;
}

/* Badge Bueno - Azul brillante */
.metric-good {
    background: rgba(59, 130, 246, 0.3);
    border: 2px solid rgba(59, 130, 246, 0.6);
    color: #60a5fa;
}

/* Badge con sombra */
.metric-badge {
    /* ...estilos existentes... */
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
}
```

### Agregar Iconos a Badges

```javascript
// Modificar displayResults()
if (slPercent >= 90) {
    slBadge.innerHTML = '<i class="fas fa-star mr-1"></i>Excelente';
    // ...
}
```

---

## Presets Personalizados

### Agregar Nuevo Preset

**En `hr/service_level_calculator.php` - función `loadPreset()`:**

```javascript
function loadPreset(type) {
    const presets = {
        // ...presets existentes...
        
        // NUEVO: Contact Center Nocturno
        nocturno: {
            targetSl: 85,
            targetAns: 25,
            intervalMinutes: 60,
            calls: 40,
            ahtSeconds: 360,
            occupancyTarget: 75,
            shrinkage: 35
        },
        
        // NUEVO: Campaña de Cobranzas
        cobranzas: {
            targetSl: 70,
            targetAns: 30,
            intervalMinutes: 30,
            calls: 120,
            ahtSeconds: 180,
            occupancyTarget: 88,
            shrinkage: 32
        },
        
        // NUEVO: Soporte VIP
        vip: {
            targetSl: 95,
            targetAns: 10,
            intervalMinutes: 30,
            calls: 50,
            ahtSeconds: 480,
            occupancyTarget: 80,
            shrinkage: 25
        }
    };
    
    // ...resto del código...
}
```

### Agregar Botón de Preset

**En la sección HTML de Presets:**

```html
<!-- Agregar después de los presets existentes -->
<button onclick="loadPreset('nocturno')" class="p-4 bg-slate-800/50 hover:bg-slate-700/50 border border-slate-600 rounded-lg transition-all text-left">
    <div class="text-indigo-400 text-2xl mb-2"><i class="fas fa-moon"></i></div>
    <div class="font-semibold text-white">Nocturno</div>
    <div class="text-xs text-slate-400 mt-1">40 calls, 60 min, AHT 360s</div>
</button>

<button onclick="loadPreset('cobranzas')" class="p-4 bg-slate-800/50 hover:bg-slate-700/50 border border-slate-600 rounded-lg transition-all text-left">
    <div class="text-red-400 text-2xl mb-2"><i class="fas fa-dollar-sign"></i></div>
    <div class="font-semibold text-white">Cobranzas</div>
    <div class="text-xs text-slate-400 mt-1">120 calls, 30 min, AHT 180s</div>
</button>
```

---

## Campos Adicionales

### Agregar Campo "Target Abandonment Rate"

**1. Agregar HTML del campo:**

```html
<!-- Después del campo Shrinkage -->
<div class="input-group mb-0">
    <label class="input-label">
        Target Abandonment Rate
        <span class="info-badge">% máximo de abandonadas</span>
    </label>
    <input type="number" id="targetAbandon" name="targetAbandon" class="input-field" 
           value="5" min="0" max="20" step="1">
    <span class="input-addon">%</span>
</div>
```

**2. Agregar al JavaScript:**

```javascript
// En el submit del formulario
const data = {
    // ...campos existentes...
    targetAbandon: parseFloat(formData.get('targetAbandon'))
};
```

**3. Actualizar API:**

```php
// En api/service_level_calculator.php
$targetAbandon = (float) ($params['targetAbandon'] ?? 5);
if ($targetAbandon > 1) {
    $targetAbandon = $targetAbandon / 100;
}
```

### Agregar Selector de Campaña

```html
<div class="input-group">
    <label class="input-label">Campaña</label>
    <select id="campaign" name="campaign" class="input-field">
        <option value="">Seleccionar...</option>
        <?php
        $campaigns = $pdo->query("SELECT id, name FROM campaigns ORDER BY name")->fetchAll();
        foreach ($campaigns as $camp) {
            echo '<option value="' . $camp['id'] . '">' . htmlspecialchars($camp['name']) . '</option>';
        }
        ?>
    </select>
</div>
```

---

## Integración con Sistema

### Guardar Cálculos Favoritos

**Agregar botón:**

```html
<button onclick="saveFavorite()" class="w-full px-4 py-2.5 bg-yellow-600 hover:bg-yellow-500 text-white rounded-lg transition-colors font-semibold mt-3">
    <i class="fas fa-star mr-2"></i>
    Guardar como Favorito
</button>
```

**Agregar JavaScript:**

```javascript
async function saveFavorite() {
    if (!lastCalculation) {
        showToast('No hay cálculo para guardar', 'warning');
        return;
    }
    
    const name = prompt('Nombre del favorito:');
    if (!name) return;
    
    const response = await fetch('../api/service_level_calculator.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'save_favorite',
            name: name,
            params: lastCalculation.params,
            result: lastCalculation.result
        })
    });
    
    const result = await response.json();
    if (result.success) {
        showToast('Favorito guardado', 'success');
    }
}
```

### Integrar con Calendario/Scheduling

```javascript
function scheduleStaff() {
    if (!lastCalculation) return;
    
    // Enviar a sistema de scheduling
    window.location.href = '/hr/schedule_builder.php?agents=' + 
        lastCalculation.result.required_staff + 
        '&date=' + document.getElementById('scheduleDate').value;
}
```

---

## Ejemplos de Temas Completos

### Tema Oscuro Intenso

```css
.calculator-card {
    background: linear-gradient(135deg, rgba(0, 0, 0, 0.95) 0%, rgba(17, 24, 39, 0.9) 100%);
    border: 1px solid rgba(75, 85, 99, 0.5);
}

.input-field {
    background: rgba(0, 0, 0, 0.8);
    border: 1px solid rgba(55, 65, 81, 0.5);
    color: #f9fafb;
}

.btn-calculate {
    background: linear-gradient(135deg, #1f2937 0%, #374151 100%);
    border: 1px solid rgba(75, 85, 99, 0.5);
}
```

### Tema Claro

```css
.calculator-card {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.95) 0%, rgba(241, 245, 249, 0.9) 100%);
    border: 1px solid rgba(203, 213, 225, 0.8);
    color: #1e293b;
}

.input-field {
    background: rgba(248, 250, 252, 0.9);
    border: 1px solid rgba(203, 213, 225, 0.6);
    color: #0f172a;
}

.input-label {
    color: #475569;
}

.result-value {
    background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
```

### Tema Corporativo (Azul Marino)

```css
:root {
    --corp-primary: #1e40af;
    --corp-secondary: #3b82f6;
    --corp-accent: #60a5fa;
}

.calculator-card {
    background: linear-gradient(135deg, rgba(30, 64, 175, 0.15) 0%, rgba(59, 130, 246, 0.1) 100%);
    border: 1px solid var(--corp-accent);
}

.btn-calculate {
    background: linear-gradient(135deg, var(--corp-primary) 0%, var(--corp-secondary) 100%);
}

.result-value {
    color: var(--corp-primary);
    text-shadow: 0 0 20px rgba(30, 64, 175, 0.3);
}
```

---

## Tips de Personalización

### 1. Mantener Consistencia
- Usa las mismas esquinas redondeadas en todos los elementos
- Mantén la familia tipográfica consistente
- Usa la misma escala de espaciado

### 2. Accesibilidad
- Contraste mínimo de 4.5:1 para texto
- Botones con área de click mínima de 44x44px
- Etiquetas claras en inputs

### 3. Responsive
- Prueba en móvil, tablet y desktop
- Usa grid y flexbox
- max-width para contenedores

### 4. Performance
- Minimiza animaciones pesadas
- Optimiza imágenes/iconos
- Lazy load si hay muchos elementos

---

## Herramientas Útiles

- **Gradientes**: https://cssgradient.io/
- **Colores**: https://coolors.co/
- **Iconos**: https://fontawesome.com/icons
- **Fuentes**: https://fonts.google.com/
- **Shadows**: https://shadows.brumm.af/

---

¿Necesitas más personalizaciones específicas? Consulta la documentación completa en `SERVICE_LEVEL_CALCULATOR.md`

# Sistema de Análisis de Reclutamiento con IA

## 📋 Resumen

Se ha implementado un sistema completo de análisis de reclutamiento potenciado por Inteligencia Artificial (Google Gemini), que permite realizar consultas en lenguaje natural sobre los datos de reclutamiento y obtener insights accionables.

## 🚀 Características Principales

### 1. **Consultas en Lenguaje Natural**
- Escribe preguntas en español simple
- El sistema las convierte automáticamente a SQL
- Ejemplos:
  - "¿Cuántas personas aplicaron a Desarrollador con salario mayor a 25,000 pesos?"
  - "Mostrar candidatos con más de 5 años de experiencia"
  - "Listar aplicaciones nuevas de los últimos 7 días"

### 2. **Filtros Rápidos Predefinidos**
- 6 filtros rápidos listos para usar
- Un clic para consultas comunes
- Totalmente personalizables

### 3. **Insights Automáticos con IA**
- Gemini AI analiza los resultados
- Genera recomendaciones accionables
- Identifica patrones y tendencias

### 4. **Interfaz Moderna y Responsiva**
- Diseño glassmorphism
- Animaciones suaves
- Compatible con tema claro/oscuro
- Optimizado para móviles

### 5. **Seguridad y Validación**
- Solo consultas SELECT permitidas
- Validación de SQL para prevenir inyecciones
- Control de permisos por rol

## 📁 Archivos Creados

### Frontend
- **`hr/recruitment_ai_analysis.php`** - Página principal del sistema
  - Estadísticas de reclutamiento
  - Interfaz de consulta con IA
  - Filtros rápidos
  - Visualización de resultados

### Backend
- **`hr/recruitment_ai_api.php`** - API para procesamiento de consultas
  - Integración con Gemini AI
  - Generación de SQL desde lenguaje natural
  - Validación de seguridad
  - Generación de insights

### Base de Datos
- **`migrations/add_recruitment_ai_permission.sql`** - SQL para permisos
- **`migrations/run_recruitment_ai_migration.php`** - Script de migración PHP

### Configuración
- **`settings.php`** - Agregado permiso `hr_recruitment_ai`
- **`header.php`** - Agregada entrada en menú de navegación
- **`hr/index.php`** - Agregada tarjeta de módulo en dashboard

## 🔧 Configuración

### Paso 1: Ejecutar Migración

Visita en tu navegador:
```
http://tu-dominio/ponche-xtreme/migrations/run_recruitment_ai_migration.php
```

Esto agregará los permisos necesarios a la base de datos.

### Paso 2: Verificar Permisos

Los siguientes roles tienen acceso por defecto:
- **Admin**
- **HR**
- **IT**

Para agregar más roles, modifica en `settings.php` o usa la interfaz de configuración.

### Paso 3: Acceder al Sistema

**Desde el Dashboard de HR:**
- Ve a `hr/index.php`
- Haz clic en la tarjeta "Análisis IA"

**Desde el Menú:**
- Recursos Humanos → Análisis Reclutamiento IA

## 🎯 Cómo Usar

### Consultas Básicas

1. **Filtrar por salario:**
   ```
   Mostrar candidatos con expectativa salarial entre 20,000 y 30,000 pesos
   ```

2. **Filtrar por experiencia:**
   ```
   Listar personas con más de 3 años de experiencia en desarrollo
   ```

3. **Filtrar por fecha:**
   ```
   Aplicaciones nuevas de los últimos 30 días
   ```

4. **Filtrar por vacante:**
   ```
   Cuántos candidatos aplicaron a Desarrollador Full Stack
   ```

5. **Filtrar por estado:**
   ```
   Mostrar candidatos en proceso de entrevista
   ```

### Consultas Avanzadas

1. **Análisis estadístico:**
   ```
   Distribución de nivel educativo de todos los candidatos
   ```

2. **Comparativas:**
   ```
   Comparar aplicaciones por departamento
   ```

3. **Tendencias:**
   ```
   Vacantes con más de 10 aplicaciones en el último mes
   ```

## 🔐 API de Gemini

### Configuración Actual

```php
API Key: AIzaSyDJMoBOmGPa5wQUck3OKiUMlenHP5oyJ5o
Model: gemini-2.0-flash-exp
```

**Nota:** Esta API key está hardcodeada en `recruitment_ai_api.php`. Para mayor seguridad, considera:
1. Moverla a un archivo de configuración `.env`
2. Guardarla en la base de datos
3. Usar variables de entorno

### Cambiar la API Key

Edita `hr/recruitment_ai_api.php`, línea 12:
```php
define('GEMINI_API_KEY', 'TU_API_KEY_AQUI');
```

## 📊 Tablas Disponibles para Consultas

El sistema tiene acceso a las siguientes tablas:

1. **job_applications** - Aplicaciones de candidatos
   - Datos personales
   - Experiencia laboral
   - Expectativas salariales
   - Estado de la aplicación

2. **job_postings** - Vacantes publicadas
   - Título y descripción
   - Departamento
   - Rango salarial
   - Estado (activa/inactiva)

3. **applicant_skills** - Habilidades de candidatos
   - Nombre de habilidad
   - Nivel de dominio
   - Años de experiencia

4. **recruitment_interviews** - Entrevistas
   - Tipo de entrevista
   - Fecha programada
   - Calificación
   - Retroalimentación

## 🎨 Personalización

### Agregar Filtros Rápidos

Edita `hr/recruitment_ai_analysis.php` en la sección de "Filtros Rápidos":

```html
<button class="quick-filter-btn" data-query="TU_CONSULTA_AQUI">
    <div class="flex items-center gap-3">
        <i class="fas fa-icon-name text-color-400"></i>
        <div>
            <div class="font-semibold">Título del Filtro</div>
            <div class="text-xs text-slate-500">Descripción</div>
        </div>
    </div>
</button>
```

### Modificar Colores

Los colores principales están en línea en el archivo PHP. Para cambiar el tema:

```css
/* Gradiente principal */
background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);

/* Bordes */
border: 1px solid rgba(139, 92, 246, 0.3);
```

## 🐛 Solución de Problemas

### Error: "Acceso Denegado"
**Causa:** No tienes el permiso `hr_recruitment_ai`
**Solución:** Ejecuta la migración o pide a un admin que te otorgue el permiso

### Error: "No se pudo generar SQL"
**Causa:** La consulta es muy ambigua o compleja
**Solución:** Intenta reformular la consulta de forma más específica

### Error: "Consulta SQL no permitida"
**Causa:** La IA generó SQL con operaciones prohibidas (UPDATE, DELETE, etc.)
**Solución:** Esto es una protección de seguridad. Reformula tu consulta

### No aparece en el menú
**Causa:** Permisos no aplicados correctamente
**Solución:** 
1. Verifica que ejecutaste la migración
2. Cierra sesión y vuelve a iniciar
3. Verifica en `settings.php` que exista el permiso

## 📈 Próximas Mejoras

Funcionalidades planeadas para futuras versiones:

1. **Exportación de Datos**
   - Excel con formato
   - PDF con gráficas
   - CSV para análisis externo

2. **Visualizaciones**
   - Gráficas de barras
   - Gráficas de pastel
   - Líneas de tendencia

3. **Filtros Guardados**
   - Guardar consultas favoritas
   - Compartir filtros con el equipo
   - Historial de consultas

4. **Reportes Automatizados**
   - Reportes programados por email
   - Dashboards personalizados
   - Alertas inteligentes

5. **Análisis Predictivo**
   - Predicción de tiempo de contratación
   - Análisis de éxito de candidatos
   - Recomendaciones de mejora

## 🤝 Soporte

Para preguntas o problemas:
1. Revisa esta documentación
2. Consulta los logs de error en el servidor
3. Contacta al equipo de desarrollo

## 📝 Notas Técnicas

- **Motor de IA:** Google Gemini 2.0 Flash Exp
- **Framework CSS:** Tailwind CSS 2.2.19
- **Iconos:** Font Awesome 6.0.0
- **Base de datos:** MySQL con PDO
- **Formato de respuesta:** JSON

## ⚠️ Seguridad

El sistema implementa múltiples capas de seguridad:

1. Validación de permisos de usuario
2. Solo consultas SELECT permitidas
3. Preparación de statements (PDO)
4. Validación de SQL generado
5. Límite de 100 registros por consulta
6. Timeout de 30 segundos en API calls

**Importante:** Nunca expongas la API key de Gemini en el frontend o en repositorios públicos.

---

**Versión:** 1.0.0  
**Fecha de creación:** Marzo 2026  
**Autor:** Sistema de IA Ponche Xtreme

# Sistema de Análisis de Reclutamiento con IA - Guía de Solución de Problemas

## 🔧 Mejoras Implementadas

### Backend (recruitment_ai_api.php)

1. **Sistema de Fallback Robusto**
   - Si Gemini AI falla, el sistema usa generación de SQL basada en patrones
   - Detecta consultas comunes automáticamente:
     - Consultas de salario con números
     - Búsquedas por años de experiencia
     - Aplicaciones recientes (últimos X días)
     - Filtros por estado
     - Conteo de registros

2. **Mejor Extracción de SQL**
   - Limpia respuestas de Gemini (markdown, prefijos, etc.)
   - Normaliza espacios y formato
   - Agrega LIMIT automáticamente si falta
   - Valida que empiece con SELECT

3. **Logging Mejorado**
   - Registra todas las respuestas de Gemini
   - Registra SQL generado
   - Registra errores detallados de API
   - Útil para debugging

4. **Manejo de Errores Robusto**
   - Detecta errores de CURL
   - Verifica código HTTP
   - Valida estructura JSON
   - Mensajes de error descriptivos

5. **Configuración Optimizada de Gemini**
   - Temperature: 0.1 (más determinista)
   - TopK: 20 (más enfocado)
   - TopP: 0.8 (equilibrado)
   - StopSequences: [';'] (para detener en punto y coma)

### Frontend (recruitment_ai_analysis.php)

1. **Sistema de Notificaciones Elegante**
   - Reemplaza alerts por notificaciones modernas
   - 4 tipos: success, error, warning, info
   - Auto-desaparece después de 5 segundos
   - Animaciones suaves

2. **Visualización de SQL**
   - Botón "Ver SQL" para mostrar consulta generada
   - Botón "Copiar" para copiar SQL al portapapeles
   - Útil para debugging y aprendizaje

3. **Mejor Manejo de Errores**
   - Verifica status HTTP
   - Muestra mensajes descriptivos
   - No rompe la interfaz en caso de error

## 🐛 Solución de Problemas Comunes

### Error: "No se pudo generar una consulta SQL válida"

**Posibles causas:**

1. **API Key de Gemini inválida o expirada**
   - Verifica que la key sea correcta en `recruitment_ai_api.php`
   - Prueba la key en [Google AI Studio](https://aistudio.google.com/)

2. **Problemas de conectividad**
   - Verifica conexión a internet
   - Revisa logs de error: `error_log` en PHP

3. **Consulta muy ambigua**
   - Intenta ser más específico
   - Usa números exactos cuando sea posible
   - Ejemplo: ❌ "salarios altos" → ✅ "salarios mayores a 25000 pesos"

**Solución:**
- El sistema ahora usa **fallback automático** que intenta generar SQL sin IA
- Si la consulta coincide con patrones comunes, funcionará sin Gemini

### Error: "Consulta SQL no permitida por motivos de seguridad"

**Causa:** El SQL generado contiene operaciones prohibidas

**Operaciones prohibidas:**
- DROP, DELETE, UPDATE, INSERT
- TRUNCATE, ALTER, CREATE
- SHOW TABLES, SHOW DATABASES
- Comentarios SQL: --, /* */

**Solución:** Reformula tu consulta para que sea solo de lectura (SELECT)

### Error: HTTP 400/403/401 de Gemini API

**Posibles causas:**
1. API Key incorrecta → Verifica en el código
2. Cuota excedida → Revisa tu consumo en Google Cloud
3. Región bloqueada → Verifica que tu servidor tenga acceso

**Solución:**
- Revisa los logs de error PHP
- Verifica la respuesta completa de la API
- El sistema usará fallback si Gemini falla

### No aparecen resultados

**Verificar:**
1. ¿La consulta es muy restrictiva?
   - Prueba con filtros más amplios
2. ¿Hay datos en la base de datos?
   - Verifica que existan aplicaciones en `job_applications`
3. ¿El SQL es correcto?
   - Usa el botón "Ver SQL" para revisar la consulta

### Consulta muy lenta

**Causas comunes:**
1. Sin índices en columnas filtradas
2. Consultas complejas con múltiples JOINs
3. CAST/REGEXP en columnas sin índice

**Solución:**
- El sistema limita a 100 resultados automáticamente
- Considera agregar índices en MySQL:
  ```sql
  CREATE INDEX idx_expected_salary ON job_applications(expected_salary);
  CREATE INDEX idx_years_experience ON job_applications(years_of_experience);
  CREATE INDEX idx_applied_date ON job_applications(applied_date);
  ```

## 🧪 Consultas de Prueba

Prueba estas consultas para verificar que todo funciona:

### Básicas (usan fallback):
```
Mostrar candidatos con salario mayor a 20000 pesos
```
```
Personas con más de 3 años de experiencia
```
```
Aplicaciones de los últimos 7 días
```

### Con IA (requieren Gemini):
```
Candidatos ordenados por experiencia que aplicaron a puestos de tecnología
```
```
Comparar expectativas salariales entre diferentes niveles educativos
```
```
Análisis de ciudades con más candidatos aplicando
```

## 📊 Ver Logs para Debugging

### En el servidor (Linux/Mac):
```bash
tail -f /var/log/apache2/error.log
# o
tail -f /var/log/php_errors.log
```

### En XAMPP (Windows):
```
C:\xampp\apache\logs\error.log
C:\xampp\php\logs\php_error_log.txt
```

### En el navegador:
1. Abre las herramientas de desarrollador (F12)
2. Ve a la pestaña "Console"
3. Ejecuta una consulta
4. Revisa errores JavaScript

## 🔒 Seguridad

El sistema implementa múltiples capas de seguridad:

1. **Validación de permisos** - Solo usuarios autorizados
2. **Solo SELECT** - No se permiten modificaciones
3. **Prepared statements** - Protección contra SQL injection
4. **Límite de resultados** - Máximo 100 registros
5. **Timeout de API** - 30 segundos máximo
6. **Validación de SQL** - Detecta operaciones peligrosas

## 📞 Soporte Adicional

Si el problema persiste:

1. **Verifica los logs** de error tanto de PHP como Apache
2. **Prueba las consultas básicas** que usan fallback
3. **Verifica la conectividad** a Gemini API
4. **Revisa el SQL generado** usando el botón "Ver SQL"
5. **Ejecuta el SQL manualmente** en phpMyAdmin para verificar

## 🎯 Ejemplos de Patrones de Fallback

El sistema detecta estos patrones automáticamente:

### Salarios:
- "salario mayor a 25000"
- "aspiración salarial superior a 30000 pesos"
- "sueldo más de 20,000"

### Experiencia:
- "más de 5 años de experiencia"
- "candidatos con 3 años de experiencia"
- "experiencia mayor a 2 años"

### Fechas:
- "últimos 7 días"
- "aplicaciones recientes de 30 días"
- "nueva aplicación de los últimos 14 días"

### Estado:
- "aplicaciones nuevas"
- "candidatos con estado nuevo"
- "nueva solicitud"

---

**Última actualización:** Marzo 16, 2026
**Versión:** 1.1.0 (Mejorada con fallback robusto)

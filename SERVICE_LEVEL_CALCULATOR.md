# Service Level Calculator - Calculadora de Nivel de Servicio

## 📋 Descripción

Sistema completo y personalizable para calcular el dimensionamiento de agentes en un contact center utilizando la **fórmula de Erlang C**. Permite determinar cuántos agentes se necesitan para cumplir un objetivo de nivel de servicio específico.

## 🎯 Características Principales

### ✅ Funcionalidades Core
- **Cálculo de Erlang C**: Implementación precisa de la fórmula matemática
- **Interfaz moderna**: Diseño glassmorphism con Tailwind CSS
- **Totalmente personalizable**: Ajusta todos los parámetros según tus necesidades
- **Presets predefinidos**: Configuraciones rápidas para escenarios comunes
- **Validaciones completas**: Validación de datos en frontend y backend
- **Exportación de resultados**: Descarga los cálculos en formato CSV
- **Histórico de cálculos**: Registra todos los cálculos en base de datos (opcional)
- **Responsive**: Funciona perfectamente en móviles y tablets

### 🔧 Parámetros Configurables

#### Parámetros Principales
1. **Service Level Goal** (Objetivo de Nivel de Servicio)
   - **Porcentaje**: 1-100% (ej: 80%)
   - **Segundos**: 1-300 seg (ej: 20 segundos)
   - *Ejemplo: 80% de las llamadas atendidas en 20 segundos*

2. **Interval Length** (Duración del Intervalo)
   - 15 minutos
   - 30 minutos
   - 60 minutos

3. **Calls** (Llamadas)
   - Número de llamadas esperadas en el intervalo
   - Rango: 0+

4. **Average Handling Time** (AHT)
   - Tiempo promedio de manejo en segundos
   - Incluye tiempo de conversación + wrap-up
   - Rango: 1+ segundos

#### Parámetros Avanzados
5. **Occupancy Target** (Ocupación Objetivo)
   - Porcentaje de tiempo productivo de los agentes
   - Rango: 50-95%
   - Recomendado: 80-85%

6. **Shrinkage** (Tiempo No Productivo)
   - Incluye: breaks, meetings, training, ausencias
   - Rango: 0-50%
   - Típico: 25-35%

## 📊 Resultados Calculados

La calculadora proporciona los siguientes resultados:

1. **Required Agents** (Agentes Requeridos)
   - Número mínimo de agentes para cumplir el SL objetivo
   - Basado en la fórmula de Erlang C

2. **Required Staff** (Staff Total)
   - Incluye shrinkage
   - Fórmula: `Staff = Agentes / (1 - Shrinkage)`

3. **Service Level** (Nivel de Servicio)
   - SL proyectado con el número de agentes calculados
   - Con indicador de calidad (Excelente/Bueno/Aceptable/Bajo)

4. **Occupancy** (Ocupación)
   - Porcentaje de utilización de los agentes
   - Fórmula: `Ocupación = Workload / Agentes`
   - Con indicador de calidad (Óptimo/Bueno/Revisar/Bajo)

5. **Intensity (Erlangs)**
   - Carga de trabajo en Erlangs
   - Fórmula: `Erlangs = (Llamadas × AHT) / Intervalo`

## 🏗️ Arquitectura

### Archivos del Sistema

```
ponche-xtreme/
├── hr/
│   └── service_level_calculator.php    # Interfaz principal
├── api/
│   └── service_level_calculator.php    # API endpoint
├── sql/
│   └── service_level_calculator_schema.sql  # Schema de BD
└── docs/
    └── SERVICE_LEVEL_CALCULATOR.md     # Esta documentación
```

### Flujo de Datos

```
Usuario → Formulario → JavaScript → API → Cálculo Erlang C → Resultado → UI
                                     ↓
                              Log en BD (opcional)
```

## 🔬 Fórmula de Erlang C

### Definición
La fórmula de Erlang C calcula la probabilidad de que una llamada tenga que esperar en cola.

### Componentes

1. **Intensidad de Tráfico (Erlangs)**
   ```
   A = (Llamadas × AHT en segundos) / (Intervalo en segundos)
   ```

2. **Erlang C (Probabilidad de Espera)**
   ```
   Ec(A, N) = [A^N / N!] × [N / (N - A)] / [Σ(A^k / k!) + [A^N / N!] × [N / (N - A)]]
   ```

3. **Service Level**
   ```
   SL = 1 - Ec(A, N) × e^(-(N - A) × (T / AHT))
   ```
   
   Donde:
   - A = Intensidad de tráfico (Erlangs)
   - N = Número de agentes
   - T = Tiempo objetivo de respuesta (segundos)
   - AHT = Average Handling Time (segundos)

## 🚀 Uso

### Acceso
1. **URL**: `https://tu-dominio.com/hr/service_level_calculator.php`
2. **Permisos**: Requiere permiso `wfm_planning`

### Paso a Paso

#### 1. Ingresar Parámetros Básicos
```
Service Level Goal: 80% en 20 segundos
Interval Length: 30 minutos
Calls: 100
Average Handling Time: 240 segundos
```

#### 2. Configuración Avanzada (Opcional)
```
Occupancy Target: 85%
Shrinkage: 30%
```

#### 3. Calcular
- Click en botón "Calcular"
- Los resultados aparecen en tiempo real

#### 4. Interpretar Resultados
- **Required Agents**: 12 agentes
- **Required Staff**: 17 staff (incluyendo shrinkage)
- **Service Level**: 82.5% (Bueno)
- **Occupancy**: 83.3% (Óptimo)
- **Intensity**: 13.33 Erlangs

#### 5. Exportar (Opcional)
- Click en "Exportar Resultados"
- Se descarga CSV con todos los datos

### Presets Predefinidos

#### 🔊 Alto Volumen
```
Calls: 200
Interval: 15 min
AHT: 180 seg
SL: 80% / 20s
```

#### 📊 Estándar
```
Calls: 100
Interval: 30 min
AHT: 240 seg
SL: 80% / 20s
```

#### 📉 Bajo Volumen
```
Calls: 50
Interval: 60 min
AHT: 300 seg
SL: 80% / 20s
```

#### ⭐ Premium
```
Calls: 80
Interval: 30 min
AHT: 420 seg
SL: 90% / 15s
```

## 🔌 API Reference

### Endpoint
```
POST /api/service_level_calculator.php
```

### Autenticación
- Requiere sesión activa
- Requiere permiso `wfm_planning`

### Request Body (Cálculo Simple)

```json
{
  "action": "calculate",
  "targetSl": 80,
  "targetAns": 20,
  "intervalMinutes": 30,
  "calls": 100,
  "ahtSeconds": 240,
  "occupancyTarget": 85,
  "shrinkage": 30
}
```

### Response (Success)

```json
{
  "success": true,
  "data": {
    "required_agents": 12,
    "required_staff": 17,
    "service_level": 0.8250,
    "occupancy": 0.8333,
    "workload": 13.3333,
    "interval_seconds": 1800,
    "calls_per_agent": 8.33,
    "calls_per_staff": 5.88
  },
  "timestamp": "2026-03-13 10:30:00"
}
```

### Response (Error)

```json
{
  "success": false,
  "error": "El número de llamadas debe ser mayor que 0"
}
```

### Batch Calculation

Para calcular múltiples escenarios:

```json
{
  "action": "batch_calculate",
  "scenarios": [
    {
      "targetSl": 80,
      "targetAns": 20,
      "intervalMinutes": 30,
      "calls": 100,
      "ahtSeconds": 240,
      "occupancyTarget": 85,
      "shrinkage": 30
    },
    {
      "targetSl": 90,
      "targetAns": 15,
      "intervalMinutes": 30,
      "calls": 100,
      "ahtSeconds": 240,
      "occupancyTarget": 85,
      "shrinkage": 30
    }
  ]
}
```

## 📈 Interpretación de Resultados

### Service Level
- **≥90%**: Excelente - Objetivo premium
- **80-89%**: Bueno - Estándar de industria
- **70-79%**: Aceptable - Necesita mejora
- **<70%**: Bajo - Requiere acción inmediata

### Occupancy
- **80-90%**: Óptimo - Balance ideal
- **70-79% o 91-94%**: Bueno - Aceptable
- **60-69% o ≥95%**: Revisar - Posibles problemas
- **<60%**: Bajo - Subutilización de recursos

### Recomendaciones

1. **Occupancy muy alta (>90%)**
   - Riesgo de burnout
   - Posible deterioro de calidad
   - Considerar aumentar staff

2. **Occupancy muy baja (<70%)**
   - Subutilización de recursos
   - Costos excesivos
   - Considerar redistribuir personal

3. **SL bajo con occupancy baja**
   - Problema de distribución
   - Revisar scheduling
   - Analizar patrones de llamadas

## 💾 Base de Datos (Opcional)

### Instalación
```bash
mysql -u usuario -p database < sql/service_level_calculator_schema.sql
```

### Consultas Útiles

#### Ver últimos cálculos
```sql
SELECT * FROM service_level_calculations 
WHERE user_id = ?
ORDER BY created_at DESC 
LIMIT 10;
```

#### Análisis de tendencias
```sql
SELECT 
    DATE(created_at) as date,
    AVG(required_agents) as avg_agents,
    AVG(workload_erlangs) as avg_erlangs,
    COUNT(*) as calculations_count
FROM service_level_calculations
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(created_at)
ORDER BY date DESC;
```

## 🎨 Personalización

### Colores y Estilos
Los estilos están definidos en el `<style>` del archivo principal. Puedes personalizar:

```css
.calculator-card {
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.9) 0%, rgba(30, 41, 59, 0.7) 100%);
    /* Personaliza el gradiente */
}

.btn-calculate {
    background: linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%);
    /* Personaliza el gradiente del botón */
}
```

### Agregar Nuevos Presets
```javascript
function loadPreset(type) {
    const presets = {
        // ... presets existentes ...
        miPreset: {
            targetSl: 85,
            targetAns: 18,
            intervalMinutes: 20,
            calls: 150,
            ahtSeconds: 200,
            occupancyTarget: 87,
            shrinkage: 28
        }
    };
    // ...
}
```

### Modificar Validaciones
En el archivo API (`api/service_level_calculator.php`):

```php
// Cambiar límites de validación
if ($occupancyTarget <= 0 || $occupancyTarget > 0.95) {
    // Ajustar el límite máximo según necesidad
}
```

## 🔒 Seguridad

### Implementado
- ✅ Autenticación de sesión
- ✅ Verificación de permisos
- ✅ Validación de datos en backend
- ✅ Prevención de SQL injection (prepared statements)
- ✅ JSON sanitization
- ✅ Rate limiting por sesión

### Recomendaciones
1. Usar HTTPS en producción
2. Implementar rate limiting por IP
3. Auditar logs regularmente
4. Revisar permisos periódicamente

## 🐛 Troubleshooting

### Error: "No autenticado"
**Solución**: Verificar que la sesión esté activa y el usuario esté logueado

### Error: "No tiene permisos"
**Solución**: Asignar permiso `wfm_planning` al usuario

### Resultados inconsistentes
**Verificar**:
1. Los parámetros de entrada son correctos
2. El intervalo está en el rango correcto
3. El AHT es realista para el tipo de servicio

### No se exportan resultados
**Verificar**:
1. Permisos de escritura del navegador
2. Bloqueadores de descargas
3. Realizar al menos un cálculo antes de exportar

## 📚 Referencias

### Documentación Técnica
- [Erlang C Formula - Wikipedia](https://en.wikipedia.org/wiki/Erlang_(unit))
- [Call Center Staffing - Concepts](https://www.callcentrehelper.com/erlang-c-calculator-17835.htm)

### Recursos Adicionales
- **WFM Planning**: `hr/wfm_planning.php` - Herramienta complementaria
- **Campaign Staffing**: Integración con reportes AST Erlang
- **Vicidial Integration**: Datos en tiempo real del sistema de telefonía

## 🔄 Actualizaciones Futuras

### Roadmap
- [ ] Gráficos interactivos de resultados
- [ ] Comparación de múltiples escenarios side-by-side
- [ ] Importación de datos históricos
- [ ] Predicción con machine learning
- [ ] Integración con calendario de horarios
- [ ] API REST completa
- [ ] Exportación a Excel con gráficos
- [ ] Plantillas personalizables por usuario

## 👥 Soporte

Para soporte técnico o consultas:
- **Documentación WFM**: Ver `hr/wfm_planning.php`
- **Logs del sistema**: Revisar tabla `service_level_calculations`
- **Email de soporte**: [configurar]

## 📄 Licencia

Uso interno del sistema Ponche Xtreme.

---

**Versión**: 1.0.0  
**Fecha**: Marzo 2026  
**Autor**: Sistema Ponche Xtreme WFM

# 🚀 Guía Rápida - Calculadora de Nivel de Servicio

## Inicio Rápido (5 minutos)

### 1️⃣ Instalación (Opcional)

Si quieres guardar histórico de cálculos:

```bash
cd c:\xampp\htdocs\ponche-xtreme
mysql -u root -p hhempeos_ponche < sql\service_level_calculator_schema.sql
```

### 2️⃣ Acceso

Abre tu navegador:
```
http://localhost/ponche-xtreme/hr/service_level_calculator.php
```

O en producción:
```
https://tu-dominio.com/hr/service_level_calculator.php
```

### 3️⃣ Uso Básico

#### Ejemplo Sencillo
```
Service Level Goal: 80% en 20 segundos
Interval: 30 minutos
Calls: 100
AHT: 240 segundos

→ Click en "Calcular"
```

**Resultado**: 
- Necesitas **12 agentes** para cumplir el objetivo
- Con shrinkage necesitas **17 personas** en tu staff

### 4️⃣ Prueba Rápida

Ejecuta el test desde terminal:
```bash
cd c:\xampp\htdocs\ponche-xtreme
php tests\test_service_level_calculator.php
```

## 📋 Cheat Sheet

### Valores Típicos por Industria

| Tipo de Servicio | SL Objetivo | AHT Típico | Shrinkage |
|------------------|-------------|------------|-----------|
| Soporte Técnico  | 80/20       | 300-600s   | 30-35%    |
| Ventas Inbound   | 80/20       | 180-300s   | 25-30%    |
| Customer Service | 80/20       | 240-360s   | 28-33%    |
| Atención Premium | 90/15       | 420-600s   | 25-30%    |
| Collections      | 70/30       | 180-240s   | 30-35%    |

### Interpretación Rápida

#### Service Level
- 🟢 **≥90%**: Excelente
- 🔵 **80-89%**: Bueno (meta estándar)
- 🟡 **70-79%**: Aceptable
- 🔴 **<70%**: Bajo

#### Occupancy
- 🟢 **80-90%**: Óptimo
- 🔵 **70-79%**: Bueno
- 🟡 **60-69% o >90%**: Revisar
- 🔴 **<60%**: Bajo

### Presets Rápidos

Click en los botones de presets para:
- **Alto Volumen**: 200 calls, 15 min
- **Estándar**: 100 calls, 30 min
- **Bajo Volumen**: 50 calls, 60 min
- **Premium**: SL 90/15

## 🔧 Troubleshooting Rápido

### ❌ No se carga la página
```
Verificar:
1. ¿Estás logueado?
2. ¿Tienes permiso wfm_planning?
3. ¿XAMPP/Apache está corriendo?
```

### ❌ Error al calcular
```
Verificar:
1. ¿Todos los campos tienen valores?
2. ¿Los valores son números positivos?
3. ¿El número de llamadas es > 0?
```

### ❌ Resultados extraños
```
Revisar:
1. AHT: ¿Es en segundos? (no minutos)
2. SL: ¿Es porcentaje? (80, no 0.80)
3. Interval: ¿Seleccionaste el correcto?
```

## 📞 Ejemplos del Mundo Real

### Ejemplo 1: Call Center de Ventas
```
Situación: 
- Recibes 150 llamadas cada 30 minutos en hora pico
- Tu agente promedio habla 4 minutos por llamada
- Quieres atender 80% en 20 segundos

Inputs:
- Calls: 150
- Interval: 30 min
- AHT: 240 seg (4 min × 60)
- SL Goal: 80% / 20s

Resultado:
- Agentes: ~17
- Staff: ~24 (con 30% shrinkage)
```

### Ejemplo 2: Soporte Técnico
```
Situación:
- 80 tickets cada hora
- Resolución promedio: 7 minutos
- Meta premium: 90% en 15 segundos

Inputs:
- Calls: 80
- Interval: 60 min
- AHT: 420 seg (7 min × 60)
- SL Goal: 90% / 15s

Resultado:
- Agentes: ~10
- Staff: ~14
```

## 🎯 Tips Pro

### 1. Optimizar Occupancy
```
Si Occupancy > 90%:
→ Aumentar agentes o redistribuir carga

Si Occupancy < 70%:
→ Reducir agentes o consolidar equipos
```

### 2. Ajustar SL
```
Cada 5% adicional de SL requiere ~1-2 agentes más
90% vs 85% = +10-15% más staff
```

### 3. Shrinkage Real
```
Calcular tu shrinkage real:
- Breaks: 15 min cada 4 hrs = 6%
- Lunch: 30-60 min = 7-12%
- Meetings: 30 min/día = 6%
- Training: variable = 5-10%
- Ausencias: 2-5%
Total: 26-39%
```

### 4. Validar con Realidad
```
Después de calcular:
1. Compara con data histórica
2. Ajusta por estacionalidad
3. Considera eventos especiales
4. Pilotea antes de implementar
```

## 📊 API Rápida

### Cálculo Simple
```bash
curl -X POST http://localhost/ponche-xtreme/api/service_level_calculator.php \
-H "Content-Type: application/json" \
-d '{
  "action": "calculate",
  "targetSl": 80,
  "targetAns": 20,
  "intervalMinutes": 30,
  "calls": 100,
  "ahtSeconds": 240,
  "occupancyTarget": 85,
  "shrinkage": 30
}'
```

### JavaScript
```javascript
const calculate = async () => {
  const response = await fetch('/api/service_level_calculator.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'calculate',
      targetSl: 80,
      targetAns: 20,
      intervalMinutes: 30,
      calls: 100,
      ahtSeconds: 240,
      occupancyTarget: 85,
      shrinkage: 30
    })
  });
  const result = await response.json();
  console.log('Required Agents:', result.data.required_agents);
};
```

## 🔗 Enlaces Útiles

- **Documentación Completa**: `SERVICE_LEVEL_CALCULATOR.md`
- **Test Script**: `tests/test_service_level_calculator.php`
- **Schema BD**: `sql/service_level_calculator_schema.sql`
- **WFM Planning**: `/hr/wfm_planning.php`

## 💡 Recuerda

1. **Erlang C es teórico** - Ajusta con experiencia real
2. **Shrinkage varía** - Mide el tuyo específicamente
3. **SL no es todo** - Balance con costos y calidad
4. **Documenta supuestos** - Para auditorías y mejoras

---

**¿Necesitas ayuda?** Consulta `SERVICE_LEVEL_CALCULATOR.md` para la guía completa.

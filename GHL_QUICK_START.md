# 🚀 INICIO RÁPIDO - GHL Voice AI Reports Expandido

---

## ⚡ 5 MINUTOS DE SETUP

### Paso 1: Verificar Archivos Creados

```bash
ls -la /xampp/htdocs/ponche-xtreme/lib/voice_ai_*
# Debe existir: voice_ai_client.php (original)
#               voice_ai_extended_reports.php (NUEVO)
```

### Paso 2: Verificar API Actualizada

```bash
grep -n "disposition_analytics\|call_quality\|comprehensive_report" \
  /xampp/htdocs/ponche-xtreme/api/voice_ai_reports.php
# Debe mostrar 3+ líneas
```

### Paso 3: Cargar Documentación

Abre en tu navegador:
```
http://localhost/ponche-xtreme/ghl_api_examples.php
```

---

## 🎯 TUS PRIMERAS CONSULTAS

### ✅ 1. Probar Disposiciones

```bash
curl -X GET \
  "http://localhost/ponche-xtreme/api/voice_ai_reports.php?action=disposition_analytics&start_date=2026-01-01&end_date=2026-01-31" \
  -H "Cookie: PHPSESSID=YOUR_SESSION_ID"
```

**Si funciona**, recibirás JSON con:
```json
{
  "success": true,
  "disposition_stats": [...],
  "disposition_by_agent": [...],
  "meta": {
    "total_calls_analyzed": 123,
    "unique_dispositions": 5
  }
}
```

### ✅ 2. Verificar Calidad

```bash
curl -X GET \
  "http://localhost/ponche-xtreme/api/voice_ai_reports.php?action=call_quality&start_date=2026-01-01&end_date=2026-01-31" \
  -H "Cookie: PHPSESSID=YOUR_SESSION_ID"
```

**Busca estos campos**:
- `recording_coverage_pct` → Debe ser ≥ 99%
- `transcript_coverage_pct` → Debe ser ≥ 95%
- `quality_scores_by_agent` → Puntuaciones por agente

### ✅ 3. Reporte Completo

```bash
curl -X GET \
  "http://localhost/ponche-xtreme/api/voice_ai_reports.php?action=comprehensive_report&start_date=2026-01-01&end_date=2026-01-31" \
  -H "Cookie: PHPSESSID=YOUR_SESSION_ID" \
  > reporte_completo.json
```

---

## 📊 CASOS DE USO POR ROL

### 👤 Como Administrador

**Lo que necesitas hacer**:
1. Ejecutar `comprehensive_report` mensualmente
2. Verificar `recording_coverage_pct` sea 100%
3. Revisar alertas de disposiciones sin registrar

**Comando**:
```bash
curl "http://api/voice_ai_reports.php?action=comprehensive_report&start_date=2026-01-01&end_date=2026-01-31" \
  | jq '.quality_metrics.quality_metrics.recording_coverage_pct'
# Retorna: 99.18
```

### 👨‍💼 Como Gerente de Operaciones

**Lo que necesitas**:
- Disposiciones por agente
- Performance de calidad
- Tendencias temporales

**Comando**:
```bash
# Comparar disposiciones del mes vs mes anterior
curl "http://api/voice_ai_reports.php?action=disposition_analytics&start_date=2026-01-01&end_date=2026-01-31" \
  | jq '.disposition_by_agent | sort_by(.total_calls) | reverse | .[] | {agent: .agent_name, calls: .total_calls}'
```

### 👤 Como Agente

**Lo que necesitas**:
- Tu puntuación de calidad
- Tus disposiciones
- Comparativa con promedio

**Comando**:
```bash
curl "http://api/voice_ai_reports.php?action=call_quality&start_date=2026-01-01&end_date=2026-01-31" \
  | jq '.quality_metrics.quality_scores_by_agent[] | select(.agent_name == "Tu Nombre") | {score: .quality_score, transcript: .transcript_pct, recording: .recording_pct}'
```

---

## 🔧 AUTOMATIZAR DESCARGAS

### Opción 1: Cron Job (Linux/Unix)

```bash
# Agregar a crontab
0 2 1 * * /home/user/scripts/ghl_monthly_download.sh

# Contenido del script:
#!/bin/bash
curl "http://localhost/ponche-xtreme/api/voice_ai_reports.php?action=comprehensive_report&start_date=2026-01-01&end_date=2026-01-31" \
  > /var/reports/ghl_$(date +%Y%m%d_%H%M%S).json
```

### Opción 2: Windows Task Scheduler

```batch
:: ghl_download.bat
@echo off
set DATE=%date:~-4%-%date:~-10,2%-%date:~-7,2%
curl "http://localhost/ponche-xtreme/api/voice_ai_reports.php?action=comprehensive_report&start_date=2026-01-01&end_date=2026-01-31" > "C:\Reports\ghl_%DATE%.json"
```

Programa en Task Scheduler para ejecutarse diariamente o mensualmente.

---

## 🚨 ALERTAS IMPORTANTES

### ⚠️ Si ves esto, hay problema:

```json
{
  "success": false,
  "message": "Configuracion de GHL incompleta"
}
```

**Solución**: Verificar que `voice_ai_location_id` y `voice_ai_api_key` estén configurados

```sql
-- Verificar en BD
SELECT setting_key, setting_value FROM system_settings 
WHERE setting_key IN ('voice_ai_api_key', 'voice_ai_location_id');
```

### ⚠️ Si la respuesta es vacía:

```json
{
  "disposition_stats": [],
  "meta": {
    "total_calls_analyzed": 0
  }
}
```

**Posibles causas**:
- No hay actividad en GHL el período especificado
- Las fechas están invertidas
- El `location_id` no tiene datos

---

## 💡 TIPS Y TRUCOS

### 1. Usar jq para procesar JSON

```bash
# Contar total de disposiciones diferentes
curl "..." | jq '.disposition_stats | length'

# Obtener disposición con más llamadas
curl "..." | jq '.disposition_stats | sort_by(.total) | .[-1] | {name: .disposition, total: .total}'

# Listar agentes con baja calidad
curl "..." | jq '.quality_metrics.quality_scores_by_agent[] | select(.quality_score < 80)'
```

### 2. Guardar en CSV para Excel

```bash
curl "..." | jq -r '.disposition_by_agent[] | [.agent_name, .total_calls, .total_handled] | @csv' > report.csv
```

### 3. Enviar por email automáticamente

```bash
curl "..." | mail -s "GHL Report $(date +%Y-%m-%d)" admin@company.com
```

### 4. Comparar períodos

```bash
# Mes actual vs mes anterior
JAM=$(curl "...&start_date=2026-02-01&end_date=2026-02-28")
LAST=$(curl "...&start_date=2026-01-01&end_date=2026-01-31")

diff <(echo $LAST | jq '.meta.total_calls_analyzed') <(echo $JAM | jq '.meta.total_calls_analyzed')
```

---

## 📈 DASHBOARD RÁPIDO (HTML)

```html
<!DOCTYPE html>
<html>
<head>
  <title>GHL Quick Dashboard</title>
</head>
<body>
  <h1>GHL Metrics</h1>
  <div id="metrics"></div>

  <script>
    fetch('/api/voice_ai_reports.php?action=comprehensive_report&start_date=2026-01-01&end_date=2026-01-31')
      .then(r => r.json())
      .then(d => {
        document.getElementById('metrics').innerHTML = `
          <p>Total Calls: ${d.disposition_analytics.meta?.total_calls_analyzed}</p>
          <p>Recording Coverage: ${d.quality_metrics?.quality_metrics?.recording_coverage_pct}%</p>
          <p>Quality Score Avg: ${d.quality_metrics?.quality_metrics?.quality_scores_by_agent?.reduce((a,b) => a + b.quality_score, 0) / d.quality_metrics?.quality_metrics?.quality_scores_by_agent?.length}</p>
        `;
      });
  </script>
</body>
</html>
```

---

## ✅ CHECKLIST DE VALIDACIÓN

- [ ] Archivos creados correctamente
- [ ] Primer endpoint (`disposition_analytics`) retorna datos
- [ ] Segundo endpoint (`call_quality`) retorna datos
- [ ] Tercer endpoint (`comprehensive_report`) retorna datos
- [ ] Documentación leída: `GHL_COMPREHENSIVE_REPORTS.md`
- [ ] Ejemplos consultados: `ghl_api_examples.php`
- [ ] Al menos 1 automatización configurada
- [ ] Rol de usuario tiene permiso `voice_ai_reports`

---

## 🆘 TROUBLESHOOTING RÁPIDO

| Error | Causa | Solución |
|-------|-------|----------|
| 403 Forbidden | Permiso insuficiente | Agregar `voice_ai_reports` a usuario |
| 500 Error | Configuración GHL incompleta | Completar Location ID y API Key |
| Empty result | Sin datos en período | Verificar fechas y actividad en GHL |
| Timeout | Período muy grande | Reducir `max_pages` o rango de fechas |
| JSON error | Sesión expirada | Reloguearse |

---

## 📚 DOCUMENTACIÓN COMPLETA

Archivos para leer:
1. [GHL_COMPREHENSIVE_REPORTS.md](GHL_COMPREHENSIVE_REPORTS.md) - Documentación técnica
2. [GHL_IMPLEMENTATION_SUMMARY.md](GHL_IMPLEMENTATION_SUMMARY.md) - Resumen de cambios
3. [ghl_api_examples.php](ghl_api_examples.php) - Ejemplos prácticos

---

## 🎓 PRÓXIMO NIVEL

Una vez domines lo básico:
1. Crear alertas automáticas en Slack
2. Integrar con Power BI
3. Crear dashboard personalizado
4. Automatizar reportes mensuales

---

**¡Listo! 🚀 Comienza ahora con**:

```bash
curl "http://localhost/ponche-xtreme/api/voice_ai_reports.php?action=disposition_analytics&start_date=2026-01-01&end_date=2026-01-31"
```

---

**Versión**: 2026-03-29
**Status**: ✅ Listo para producción

# Módulo GHL - Voice AI Reports Expandido (Comprehensivo)
## Documentación de Funcionalidades Avanzadas

Última actualización: 2026-03-29

---

## 📊 Descripción General

El módulo de reportería de GHL Voice AI ha sido completamente expandido para exprimir toda la API de GoHighLevel disponible. Ahora incluye análisis profundo de **disposiciones**, **calidad de llamadas**, **métricas de interacciones** y **dashboards operacionales completos**.

### ✨ Nuevas Características Principales

1. **Análisis Integral de Disposiciones**
   - Estadísticas detalladas por disposición (Approved, Scheduled, etc.)
   - Análisis por agente, canal y período
   - Tasas de conversión y cobertura

2. **Métricas de Calidad de Llamadas**
   - Cobertura de grabaciones, transcripciones y resúmenes
   - Puntuaciones de calidad por agente
   - Distribución de duraciones de llamada

3. **Reportes de Interacciones Completos**
   - Llamadas, SMS, Email y WhatsApp integrados
   - Trending y análisis temporal
   - Queue de conversaciones asignadas

4. **Datos Operacionales Enriquecidos**
   - Catálogo de agentes Voice AI
   - Snapshot de conversaciones en tiempo real
   - Números telefónicos activos y su utilización

---

## 🔌 Nuevos Endpoints de API

### 1. **Análisis de Disposiciones** (`disposition_analytics`)

**Objetivo**: Obtener análisis completo y estratificado de disposiciones de llamadas.

```
GET /api/voice_ai_reports.php?action=disposition_analytics&start_date=2026-01-01&end_date=2026-01-31&integration_id=1
```

**Parámetros**:
- `integration_id` (opcional): ID de la integración GHL a consultar
- `start_date` (requerido): Fecha de inicio (YYYY-MM-DD)
- `end_date` (requerido): Fecha de fin (YYYY-MM-DD)
- `max_pages` (optional): Máximo de páginas a traer (default: 10)
- `page_size` (optional): Tamaño de página (default: 50)

**Respuesta Exitosa**:
```json
{
  "success": true,
  "disposition_stats": [
    {
      "disposition": "Approved",
      "total": 145,
      "inbound": 98,
      "outbound": 47,
      "avg_duration_seconds": 312,
      "total_duration_seconds": 45240,
      "recorded_calls": 143,
      "users": 3,
      "statuses": {
        "Completed": 145
      }
    },
    {
      "disposition": "Scheduled",
      "total": 67,
      "inbound": 12,
      "outbound": 55,
      "avg_duration_seconds": 156,
      "recorded_calls": 66,
      "users": 2
    }
  ],
  "disposition_by_agent": [
    {
      "agent_id": "user123",
      "agent_name": "Juan García",
      "dispositions": {
        "Approved": 89,
        "Scheduled": 34
      },
      "total_calls": 123,
      "total_handled": 123
    }
  ],
  "disposition_by_user": [
    {
      "user_id": "user123",
      "user_name": "Juan García",
      "top_disposition": "Approved",
      "top_disposition_calls": 89,
      "total_calls": 123
    }
  ],
  "disposition_timeline": {
    "2026-01-01": {
      "Approved": 12,
      "Scheduled": 5
    },
    "2026-01-02": {
      "Approved": 14,
      "Scheduled": 3
    }
  },
  "meta": {
    "pages_fetched": 3,
    "total_calls_analyzed": 212,
    "unique_dispositions": 2
  }
}
```

**Caso de Uso**: 
- Auditoría de disposiciones de llamadas
- Análisis de performance por agente
- Identificar cuellos de botella en el proceso de disposiciones

---

### 2. **Calidad de Llamadas** (`call_quality`)

**Objetivo**: Obtener métricas comprensivas de calidad de las llamadas.

```
GET /api/voice_ai_reports.php?action=call_quality&start_date=2026-01-01&end_date=2026-01-31
```

**Respuesta Exitosa**:
```json
{
  "success": true,
  "quality_metrics": {
    "total_calls": 856,
    "with_transcript": 823,
    "with_summary": 812,
    "with_recording": 849,
    "with_sentiment": 421,
    "with_actions": 634,
    "avg_duration": 245,
    "min_duration": 12,
    "max_duration": 1856,
    "recording_coverage_pct": 99.18,
    "transcript_coverage_pct": 96.14,
    "summary_coverage_pct": 94.75,
    "calls_by_duration_range": {
      "0-30s": 89,
      "31-120s": 234,
      "2-5m": 312,
      "5-15m": 198,
      "15m+": 23
    },
    "sentiment_distribution": {
      "Positive": 234,
      "Neutral": 156,
      "Negative": 31
    },
    "quality_scores_by_agent": [
      {
        "agent_name": "Juan García",
        "quality_score": 94.3,
        "transcript_pct": 98,
        "summary_pct": 96,
        "recording_pct": 100
      },
      {
        "agent_name": "María López",
        "quality_score": 87.6,
        "transcript_pct": 94,
        "summary_pct": 92,
        "recording_pct": 98
      }
    ]
  }
}
```

**Interpretación de Campos**:
- `recording_coverage_pct`: % de llamadas con grabación disponible
- `transcript_coverage_pct`: % de llamadas con transcripción
- `summary_coverage_pct`: % de llamadas con resumen de IA
- `quality_score`: Puntuación 0-100 basada en cobertura y duración promedio

**Caso de Uso**:
- Monitoreo de cumplimiento de grabación
- Identificar agentes con baja cobertura de transcripción
- Análisis de sentimiento de llamadas

---

### 3. **Reporte Comprensivo Completo** (`comprehensive_report`)

**Objetivo**: Generar un reporte único que combina TODAS las métricas disponibles.

```
GET /api/voice_ai_reports.php?action=comprehensive_report&start_date=2026-01-01&end_date=2026-01-31
```

**Respuesta Exitosa**:
```json
{
  "success": true,
  "generated_at": "2026-03-29T14:30:00-04:00",
  "version": "2026-03-29-comprehensive-v1",
  "disposition_analytics": {
    "success": true,
    "disposition_stats": [...],
    "disposition_by_agent": [...],
    "disposition_timeline": {...}
  },
  "quality_metrics": {
    "success": true,
    "quality_metrics": {...}
  },
  "interactions": {
    "total": 2145,
    "by_channel": {
      "Call": {"total": 856, "inbound": 512, "outbound": 344},
      "SMS": {"total": 678, "inbound": 456, "outbound": 222},
      "Email": {"total": 412, "inbound": 320, "outbound": 92},
      "WhatsApp": {"total": 199, "inbound": 145, "outbound": 54}
    },
    "by_direction": {
      "Inbound": 1433,
      "Outbound": 712
    }
  },
  "warnings": [],
  "performance_ms": 8742
}
```

**Caso de Uso**:
- Reportería ejecutiva completa
- Cumplimiento regulatorio
- Auditoría operacional integral

---

## 🎯 Casos de Uso Críticos

### Caso 1: Auditoría de Disposiciones (MUY CRÍTICO)

```bash
# Obtener todas las disposiciones del mes
curl "https://ponche-xtreme.com/api/voice_ai_reports.php?action=disposition_analytics&start_date=2026-01-01&end_date=2026-01-31&max_pages=20"
```

**Qué buscar**:
- ✅ Todas las disposiciones están siendo registradas
- ✅ Distribución uniforme entre agentes
- ✅ Las disposiciones coinciden con el SLA
- ⚠️ Disposiciones vacías o "Sin disposición"
- ⚠️ Disposiciones inconsistentes por agente

---

### Caso 2: Compliance de Grabaciones

```bash
# Verificar cobertura de grabaciones
curl "https://ponche-xtreme.com/api/voice_ai_reports.php?action=call_quality&start_date=2026-01-01&end_date=2026-01-31"
```

**Requisitos normales**:
- `recording_coverage_pct` >= 99%
- `transcript_coverage_pct` >= 95%
- Todas las llamadas deben estar grabadas para auditoría

---

### Caso 3: Performance de Agentes

```bash
# Analizar performance comparativo
curl "https://ponche-xtreme.com/api/voice_ai_reports.php?action=call_quality&start_date=2026-01-01&end_date=2026-01-31"
```

**Métricas a revisar**:
- Quality Score por agente (mínimo 85 puntos)
- Duración promedio de llamada (mínimo 2-5 minutos)
- Cobertura de transcripción (mínimo 95%)
- Distribución de disposiciones

---

## 📥 Parámetros Globales para Todos los Endpoints

```
start_date=YYYY-MM-DD      // Fecha de inicio (requerido)
end_date=YYYY-MM-DD        // Fecha de fin (requerido)
integration_id=N           // ID integración GHL (opcional, usa default)
max_pages=N                // Máximo de páginas (default: 10, max: 50)
page_size=N                // Tamaño de página (default: 50, max: 100)
fast_mode=0|1              // Modo rápido sin detalles (default: 1)
```

---

## 💾 Exportación de Datos

Todos los endpoints retornan JSON puro que puede ser:

1. **Descargado como JSON** directamente
2. **Importado a Excel/Sheets** para análisis
3. **Procesado por scripts** para automatización
4. **Almacenado en base de datos** para trending histórico

---

## 🔐 Permisos Requeridos

Todos los endpoints requieren:
- ✅ Sesión activa (`$_SESSION['user_id']`)
- ✅ Permiso `voice_ai_reports`
- ✅ Configuración de GHL válida (API Key + Location ID)

---

## ⚙️ Configuración de Backend

### Archivo: `/lib/voice_ai_extended_reports.php`

Contiene todas las funciones de extracción:
- `voiceAiFetchDispositionAnalytics()` - Análisis de disposiciones
- `voiceAiFetchCallQualityMetrics()` - Métricas de calidad
- `voiceAiFetchInteractions()` - Todas las interacciones
- `voiceAiGenerateComprehensiveReport()` - Reporte integral

### Archivo: `/api/voice_ai_reports.php`

Expone todos los endpoints via HTTP:
- `?action=disposition_analytics`
- `?action=call_quality`
- `?action=comprehensive_report`
- `?action=dashboard` (existente)

---

## 📈 Recomendaciones de Uso

### ✅ HACER:
1. Ejecutar reportes comprehensive mensualmente
2. Monitorear disposiciones semanalmente
3. Revisar quality metrics después de capacitación
4. Almacenar histórico para trending

### ❌ NO HACER:
1. No ejecutar con `fast_mode=true` para auditoría
2. No confiar en períodos menores a 1 semana para trends
3. No ignorar warnings en la respuesta
4. No descargar más de 6 meses en un solo request

---

## 🚀 Mejoras Futuras

- [ ] Dashboard HTML renderizado directamente
- [ ] Gráficos interactivos de disposiciones
- [ ] Alertas automáticas por anomalías
- [ ] Exportación a PDF con firma digital
- [ ] Comparativa multi-período automática
- [ ] Machine Learning para predicción de disposiciones

---

## 📞 Soporte Técnico

Para reportar errores o sugerencias:
1. Verificar que `integration_id` sea válido
2. Revisar logs en `/logs/voice_ai_*`
3. Validar permisos en base de datos
4. Contactar al equipo de desarrollo

---

**Versión**: 2026-03-29
**Desarrollado para**: Ponche Xtreme Team
**Última actualización**: 29 de Marzo de 2026

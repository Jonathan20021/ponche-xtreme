<?php
/**
 * Ejemplos de Consumo - Nuevos Endpoints GHL Voice AI Reports
 * 
 * Este archivo contiene ejemplos listos para usar de cómo consumir
 * los nuevos endpoints de reportería expandida de GHL.
 */

// ============================================================================
// EJEMPLO 1: Obtener Análisis de Disposiciones (cURL)
// ============================================================================

/**
 * Bash/cURL - Análisis de Disposiciones
 */
$curlDispositionExample = <<<'CURL'
#!/bin/bash

# Parámetros
START_DATE="2026-01-01"
END_DATE="2026-01-31"
INTEGRATION_ID="1"
MAX_PAGES="10"

# Ejecutar request
curl -s -X GET \
  "http://localhost/ponche-xtreme/api/voice_ai_reports.php?action=disposition_analytics&start_date=${START_DATE}&end_date=${END_DATE}&integration_id=${INTEGRATION_ID}&max_pages=${MAX_PAGES}" \
  -H "Cookie: PHPSESSID=YOUR_SESSION_ID" \
  | jq '.' # Usar jq para formatear JSON

# Guardar resultado en archivo
curl -s -X GET \
  "http://localhost/ponche-xtreme/api/voice_ai_reports.php?action=disposition_analytics&start_date=${START_DATE}&end_date=${END_DATE}" \
  -H "Cookie: PHPSESSID=YOUR_SESSION_ID" \
  > dispositiones_$(date +%Y%m%d_%H%M%S).json
CURL;

// ============================================================================
// EJEMPLO 2: Obtener Métricas de Calidad (cURL)
// ============================================================================

$curlQualityExample = <<<'CURL'
#!/bin/bash

# Obtener métricas de calidad para auditoría
curl -s -X GET \
  "http://localhost/ponche-xtreme/api/voice_ai_reports.php?action=call_quality&start_date=2026-01-01&end_date=2026-01-31" \
  -H "Cookie: PHPSESSID=YOUR_SESSION_ID" \
  | jq '.quality_metrics | {
    total_calls: .total_calls,
    recording_coverage_pct: .recording_coverage_pct,
    transcript_coverage_pct: .transcript_coverage_pct,
    avg_duration: .avg_duration,
    quality_scores: .quality_scores_by_agent
  }'
CURL;

// ============================================================================
// EJEMPLO 3: Reporte Comprehensivo (cURL)
// ============================================================================

$curlComprehensiveExample = <<<'CURL'
#!/bin/bash

# Reporte completo integrado
START_DATE="2026-01-01"
END_DATE="2026-01-31"

curl -s -X GET \
  "http://localhost/ponche-xtreme/api/voice_ai_reports.php?action=comprehensive_report&start_date=${START_DATE}&end_date=${END_DATE}" \
  -H "Cookie: PHPSESSID=YOUR_SESSION_ID" \
  -H "Content-Type: application/json" \
  -d '{
    "export_format": "json"
  }' \
  > reporte_comprehensive_$(date +%Y%m%d).json
CURL;

// ============================================================================
// EJEMPLO 4: JavaScript/Fetch - Análisis de Disposiciones
// ============================================================================

$jsDispositionExample = <<<'JAVASCRIPT'
// Fetch - Análisis de Disposiciones
async function fetchDispositionAnalytics() {
  const params = new URLSearchParams({
    action: 'disposition_analytics',
    start_date: '2026-01-01',
    end_date: '2026-01-31',
    integration_id: '1',
    max_pages: '10'
  });

  try {
    const response = await fetch(
      `/api/voice_ai_reports.php?${params.toString()}`,
      {
        method: 'GET',
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      }
    );

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const data = await response.json();

    if (data.success) {
      console.log('Total disposiciones:', data.disposition_stats.length);
      
      // Mostrar resumen
      data.disposition_stats.forEach(stat => {
        console.log(`${stat.disposition}: ${stat.total} llamadas (${stat.avg_duration_seconds}s promedio)`);
      });

      // Mostrar por agente
      console.log('\nPor Agente:');
      data.disposition_by_agent.forEach(agent => {
        console.log(`${agent.agent_name}: ${agent.total_calls} llamadas`);
      });

      return data;
    } else {
      console.error('Error:', data.message);
    }
  } catch (error) {
    console.error('Fetch error:', error);
  }
}

// Ejecutar
fetchDispositionAnalytics().then(data => {
  // Procesar datos
  if (data) {
    // Crear tabla HTML
    const table = document.createElement('table');
    // ... resto del código
  }
});
JAVASCRIPT;

// ============================================================================
// EJEMPLO 5: PHP - Consumir Endpoint
// ============================================================================

$phpConsumerExample = <<<'PHP'
<?php
// Consumir endpoint de disposiciones desde PHP

function getDispositionAnalytics($startDate, $endDate, $integrationId = 1) {
  $params = http_build_query([
    'action' => 'disposition_analytics',
    'start_date' => $startDate,
    'end_date' => $endDate,
    'integration_id' => $integrationId,
    'max_pages' => 10,
  ]);

  $url = "http://localhost/ponche-xtreme/api/voice_ai_reports.php?{$params}";

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_COOKIE => session_name() . '=' . session_id(),
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($httpCode !== 200) {
    throw new Exception("API Error: HTTP {$httpCode}");
  }

  $data = json_decode($response, true);
  if (!is_array($data) || !$data['success']) {
    throw new Exception($data['message'] ?? 'Unknown error');
  }

  return $data;
}

// Usar
try {
  $analytics = getDispositionAnalytics(
    date('Y-01-01'),
    date('Y-m-d')
  );

  echo "Total disposiciones: " . count($analytics['disposition_stats']) . "\n";

  foreach ($analytics['disposition_stats'] as $stat) {
    echo "- {$stat['disposition']}: {$stat['total']} llamadas\n";
  }
} catch (Exception $e) {
  echo "Error: " . $e->getMessage() . "\n";
}
?>
PHP;

// ============================================================================
// EJEMPLO 6: Automatizar Descarga Mensual
// ============================================================================

$cronJobExample = <<<'BASH'
#!/bin/bash
# Script para ejecutar automaticamente el 1º de cada mes
# Agregar a crontab: 0 2 1 * * /home/user/scripts/ghl_monthly_report.sh

BASE_URL="http://localhost/ponche-xtreme/api/voice_ai_reports.php"
OUTPUT_DIR="/var/reports/ghl"
SESSION_COOKIE="PHPSESSID=YOUR_SESSION_ID"
YESTERDAY=$(date -d 'yesterday' +%Y-%m-%d)
MONTH_START=$(date -d 'first day of previous month' +%Y-%m-%d)
MONTH_END=$(date -d 'last day of previous month' +%Y-%m-%d)

mkdir -p "$OUTPUT_DIR"

# Descargar reporte comprehensive
echo "Descargando reporte de $MONTH_START a $MONTH_END..."
curl -s -X GET \
  "${BASE_URL}?action=comprehensive_report&start_date=${MONTH_START}&end_date=${MONTH_END}" \
  -H "Cookie: ${SESSION_COOKIE}" \
  > "${OUTPUT_DIR}/comprehensive_$(date +%Y%m).json"

# Descargar análisis de disposiciones
curl -s -X GET \
  "${BASE_URL}?action=disposition_analytics&start_date=${MONTH_START}&end_date=${MONTH_END}" \
  -H "Cookie: ${SESSION_COOKIE}" \
  > "${OUTPUT_DIR}/dispositions_$(date +%Y%m).json"

# Descargar métricas de calidad
curl -s -X GET \
  "${BASE_URL}?action=call_quality&start_date=${MONTH_START}&end_date=${MONTH_END}" \
  -H "Cookie: ${SESSION_COOKIE}" \
  > "${OUTPUT_DIR}/quality_$(date +%Y%m).json"

echo "Reportes descargados a $OUTPUT_DIR"

# Enviar por email
mail -s "GHL Reports $(date +%Y-%m)" admin@example.com << EOF
Se han generado los reportes mensuales de GHL:

- Reporte Comprehensive
- Análisis de Disposiciones  
- Métricas de Calidad

Archivos guardados en: $OUTPUT_DIR
EOF

BASH;

// ============================================================================
// EJEMPLO 7: Procesar y Enviar Alertas
// ============================================================================

$alertProcessorExample = <<<'PHP'
<?php
/**
 * Procesador de Alertas - GHL Reports
 * Verifica condiciones críticas y envía alertas
 */

function checkCriticalConditions($analyticsData, $qualityData) {
  $alerts = [];

  // ALERTA 1: Disposiciones no registradas
  $missingDispositions = array_filter($analyticsData['disposition_stats'], function($stat) {
    return $stat['disposition'] === 'Sin disposición' && $stat['total'] > 0;
  });
  if (!empty($missingDispositions)) {
    $alerts[] = [
      'level' => 'WARNING',
      'message' => 'Hay ' . $missingDispositions[0]['total'] . ' llamadas sin disposición registrada',
      'action' => 'Revisar agentes que no están cerrando disposiciones'
    ];
  }

  // ALERTA 2: Baja cobertura de grabaciones
  if ($qualityData['quality_metrics']['recording_coverage_pct'] < 99) {
    $alerts[] = [
      'level' => 'CRITICAL',
      'message' => 'Cobertura de grabaciones: ' . $qualityData['quality_metrics']['recording_coverage_pct'] . '% (requerido: 99%)',
      'action' => 'Revisar configuración de grabación en el phone system'
    ];
  }

  // ALERTA 3: Agente con baja calidad
  foreach ($qualityData['quality_metrics']['quality_scores_by_agent'] as $agent) {
    if ($agent['quality_score'] < 80) {
      $alerts[] = [
        'level' => 'WARNING',
        'message' => $agent['agent_name'] . ' tiene puntuación de calidad: ' . $agent['quality_score'],
        'action' => 'Considerar capacitación o reentrenamiento'
      ];
    }
  }

  // ALERTA 4: Distribución desigual de disposiciones
  $dispositions = array_column($analyticsData['disposition_by_agent'], 'total_calls');
  $avg = array_sum($dispositions) / count($dispositions);
  foreach ($analyticsData['disposition_by_agent'] as $agent) {
    $diff = abs($agent['total_calls'] - $avg) / $avg * 100;
    if ($diff > 40) {
      $alerts[] = [
        'level' => 'INFO',
        'message' => $agent['agent_name'] . ' tiene ' . $diff . '% desviación del promedio de llamadas',
        'action' => 'Revisar asignación de carga de trabajo'
      ];
    }
  }

  return $alerts;
}

// Uso
$alerts = checkCriticalConditions($analyticsData, $qualityData);

foreach ($alerts as $alert) {
  $icon = $alert['level'] === 'CRITICAL' ? '🔴' : 
          ($alert['level'] === 'WARNING' ? '🟡' : '🔵');
  
  echo "{$icon} [{$alert['level']}] {$alert['message']}\n";
  echo "   → Acción: {$alert['action']}\n\n";
}

// Enviar por Slack
if (!empty($alerts)) {
  sendToSlack($alerts, '#ghl-alerts', 'GHL Monthly Report Alerts');
}

?>
PHP;

// ============================================================================
// EJEMPLO 8: Generar Reporte HTML
// ============================================================================

$htmlReportExample = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
  <title>GHL Voice AI - Reporte de Disposiciones</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .header { background: #1e3a8a; color: white; padding: 20px; margin-bottom: 20px; }
    .metric { 
      background: #f5f5f5; 
      padding: 15px; 
      margin: 10px 0; 
      border-left: 4px solid #60a5fa;
    }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
    th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
    th { background: #e0e7ff; font-weight: bold; }
    .high { color: #dc2626; font-weight: bold; }
    .low { color: #16a34a; font-weight: bold; }
    .chart { margin: 20px 0; }
  </style>
</head>
<body>
  <div class="header">
    <h1>Reportería GHL Voice AI</h1>
    <p>Período: <strong id="period"></strong></p>
  </div>

  <h2>📊 Resumen de Disposiciones</h2>
  <div id="dispositions-summary"></div>

  <h2>📈 Análisis por Agente</h2>
  <table>
    <thead>
      <tr>
        <th>Agente</th>
        <th>Total Llamadas</th>
        <th>Disposición Principal</th>
        <th>% Cobertura</th>
      </tr>
    </thead>
    <tbody id="agents-table"></tbody>
  </table>

  <h2>⚙️ Calidad de Llamadas</h2>
  <div class="metric">
    <strong>Grabaciones:</strong> <span id="recording-pct" class="high"></span>%
  </div>
  <div class="metric">
    <strong>Transcripciones:</strong> <span id="transcript-pct" class="low"></span>%
  </div>
  <div class="metric">
    <strong>Resúmenes:</strong> <span id="summary-pct" class="low"></span>%
  </div>

  <script>
    // Cargar datos desde API
    async function loadReport() {
      const params = new URLSearchParams({
        action: 'comprehensive_report',
        start_date: '2026-01-01',
        end_date: '2026-01-31'
      });

      const response = await fetch(`/api/voice_ai_reports.php?${params}`);
      const data = await response.json();

      // Renderizar
      document.getElementById('period').textContent = params.get('start_date') + ' a ' + params.get('end_date');

      // Disposiciones
      const dispSummary = data.disposition_analytics.disposition_stats.map(stat =>
        `<div class="metric">
          <strong>${stat.disposition}:</strong> ${stat.total} llamadas
          (${((stat.recorded_calls/stat.total)*100).toFixed(1)}% grabadas)
        </div>`
      ).join('');
      document.getElementById('dispositions-summary').innerHTML = dispSummary;

      // Agentes
      const agentsTable = data.disposition_analytics.disposition_by_agent.map(agent =>
        `<tr>
          <td>${agent.agent_name}</td>
          <td>${agent.total_calls}</td>
          <td>${agent.dispositions[Object.keys(agent.dispositions)[0]]}</td>
          <td>${((agent.total_handled/agent.total_calls)*100).toFixed(1)}%</td>
        </tr>`
      ).join('');
      document.getElementById('agents-table').innerHTML = agentsTable;

      // Calidad
      document.getElementById('recording-pct').textContent = 
        data.quality_metrics.quality_metrics.recording_coverage_pct.toFixed(1);
      document.getElementById('transcript-pct').textContent = 
        data.quality_metrics.quality_metrics.transcript_coverage_pct.toFixed(1);
      document.getElementById('summary-pct').textContent = 
        data.quality_metrics.quality_metrics.summary_coverage_pct.toFixed(1);
    }

    loadReport();
  </script>
</body>
</html>
HTML;

// ============================================================================
// Mostrar ejemplos
// ============================================================================

?><!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Ejemplos - GHL Voice AI Reports Expandido</title>
  <style>
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px;
      background: #f5f5f5;
    }
    .header {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 30px;
      border-radius: 10px;
      margin-bottom: 30px;
    }
    .example-card {
      background: white;
      border-radius: 8px;
      padding: 20px;
      margin-bottom: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      border-left: 4px solid #667eea;
    }
    .example-title {
      font-size: 18px;
      font-weight: bold;
      margin-bottom: 10px;
      color: #333;
    }
    .example-code {
      background: #f8f8f8;
      border: 1px solid #ddd;
      border-radius: 4px;
      padding: 15px;
      overflow-x: auto;
      font-family: 'Courier New', monospace;
      font-size: 13px;
      line-height: 1.4;
      color: #333;
    }
    .example-desc {
      color: #666;
      margin-bottom: 15px;
      font-size: 14px;
    }
    .copy-btn {
      background: #667eea;
      color: white;
      border: none;
      padding: 8px 15px;
      border-radius: 4px;
      cursor: pointer;
      font-size: 12px;
      margin-top: 10px;
    }
    .copy-btn:hover {
      background: #5567d8;
    }
    .badge {
      display: inline-block;
      background: #667eea;
      color: white;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 11px;
      margin-right: 5px;
      margin-bottom: 10px;
    }
    .badge.php { background: #777BB4; }
    .badge.bash { background: #4EAA25; }
    .badge.js { background: #F7DF1E; color: #333; }
    .badge.critical { background: #DC2626; }
  </style>
</head>
<body>
  <div class="header">
    <h1>📊 Ejemplos - GHL Voice AI Reports Expandido</h1>
    <p>Soluciones listas para usar para consumir los nuevos endpoints de reportería</p>
  </div>

  <div class="example-card">
    <div class="example-title">
      <span class="badge bash">BASH</span>
      1. Obtener Análisis de Disposiciones con cURL
    </div>
    <div class="example-desc">
      Descargar un archivo JSON con todas las disposiciones del período especificado
    </div>
    <div class="example-code"><?php echo htmlspecialchars(trim($curlDispositionExample)); ?></div>
    <button class="copy-btn" onclick="copyToClipboard(this.previousElementSibling)">Copiar</button>
  </div>

  <div class="example-card">
    <div class="example-title">
      <span class="badge bash">BASH</span>
      2. Obtener Métricas de Calidad
    </div>
    <div class="example-desc">
      Verificar cobertura de grabaciones, transcripciones y puntuaciones de calidad
    </div>
    <div class="example-code"><?php echo htmlspecialchars(trim($curlQualityExample)); ?></div>
    <button class="copy-btn" onclick="copyToClipboard(this.previousElementSibling)">Copiar</button>
  </div>

  <div class="example-card">
    <div class="example-title">
      <span class="badge js">JAVASCRIPT</span>
      3. Fetch - Análisis de Disposiciones
    </div>
    <div class="example-desc">
      Consumir desde navegador o Node.js
    </div>
    <div class="example-code"><?php echo htmlspecialchars(trim($jsDispositionExample)); ?></div>
    <button class="copy-btn" onclick="copyToClipboard(this.previousElementSibling)">Copiar</button>
  </div>

  <div class="example-card">
    <div class="example-title">
      <span class="badge php">PHP</span>
      4. Consumir Endpoint desde PHP
    </div>
    <div class="example-desc">
      Función lista para usar en tu aplicación PHP
    </div>
    <div class="example-code"><?php echo htmlspecialchars(trim($phpConsumerExample)); ?></div>
    <button class="copy-btn" onclick="copyToClipboard(this.previousElementSibling)">Copiar</button>
  </div>

  <div class="example-card">
    <div class="example-title">
      <span class="badge bash">BASH</span>
      <span class="badge critical">CRÍTICO</span>
      5. Automatizar Descarga Mensual
    </div>
    <div class="example-desc">
      Script para ejecutar automáticamente via cron cada mes
    </div>
    <div class="example-code"><?php echo htmlspecialchars(trim($cronJobExample)); ?></div>
    <button class="copy-btn" onclick="copyToClipboard(this.previousElementSibling)">Copiar</button>
  </div>

  <div class="example-card">
    <div class="example-title">
      <span class="badge php">PHP</span>
      6. Procesador de Alertas Críticas
    </div>
    <div class="example-desc">
      Detectar automáticamente condiciones críticas y enviar alertas
    </div>
    <div class="example-code"><?php echo htmlspecialchars(trim($alertProcessorExample)); ?></div>
    <button class="copy-btn" onclick="copyToClipboard(this.previousElementSibling)">Copiar</button>
  </div>

  <div class="example-card">
    <div class="example-title">
      <span class="badge js">JAVASCRIPT</span>
      7. Generar Reporte HTML Interactivo
    </div>
    <div class="example-desc">
      Renderizar los datos en una página HTML bonita
    </div>
    <div class="example-code"><?php echo htmlspecialchars(trim($htmlReportExample)); ?></div>
    <button class="copy-btn" onclick="copyToClipboard(this.previousElementSibling)">Copiar</button>
  </div>

  <script>
    function copyToClipboard(element) {
      const text = element.textContent;
      navigator.clipboard.writeText(text).then(() => {
        const btn = element.nextElementSibling;
        const originalText = btn.textContent;
        btn.textContent = '✓ Copiado!';
        setTimeout(() => {
          btn.textContent = originalText;
        }, 2000);
      });
    }
  </script>
</body>
</html>

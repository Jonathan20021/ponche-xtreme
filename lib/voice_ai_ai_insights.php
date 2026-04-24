<?php
/**
 * Voice AI · Claude AI Insights Library
 *
 * Turns raw GHL reporting data into narrative, actionable intelligence via
 * the global Claude API client. Every behavior (enabled / model / prompt /
 * temperature / max_tokens / cache TTL) is driven by system_settings so
 * admins can tune the AI layer from settings.php — no hardcoded values.
 *
 * All insight functions return a uniform shape:
 *   ['success' => bool, 'enabled' => bool, 'content' => string,
 *    'error' => ?string, 'model' => string, 'usage' => ?array,
 *    'cached' => bool, 'fingerprint' => string]
 */

require_once __DIR__ . '/claude_api_client.php';

if (!function_exists('voiceAiInsightDefaults')) {
    function voiceAiInsightDefaults(): array
    {
        return [
            'voice_ai_claude_enabled' => '1',
            'voice_ai_claude_model' => 'claude-sonnet-4-6',
            'voice_ai_claude_temperature' => '0.4',
            'voice_ai_claude_max_tokens' => '1400',
            'voice_ai_claude_cache_ttl' => '900',
            'voice_ai_claude_executive_prompt' => "Eres un analista senior de operaciones de call center y comunicaciones omnicanal. Recibes un JSON con KPIs, distribuciones, dispositciones y timeline de GoHighLevel. Devuelve un resumen ejecutivo en espanol profesional con estas secciones exactas en Markdown:\n\n## Veredicto\nUna frase contundente con el estado operativo del periodo.\n\n## KPIs criticos\n3-5 bullets con numeros y variacion vs periodo previo cuando este disponible.\n\n## Que funciono\n2-3 bullets con hechos concretos (agente, canal, disposicion ganadora).\n\n## Que preocupa\n2-3 bullets con problemas concretos y su impacto estimado.\n\n## Acciones sugeridas\n3-5 bullets imperativos, priorizados, accionables hoy.\n\nNunca inventes metricas. Si un dato no esta en el JSON, dilo explicitamente.",
            'voice_ai_claude_coaching_prompt' => "Eres un coach de performance para un contact center. Recibes JSON con metricas por agente (llamadas, duracion, dispositciones, quality score, grabaciones, transcripciones). Para los 5 agentes mas relevantes (mezcla de top y bottom) entrega en Markdown:\n\n### <nombre agente> — <veredicto en 3 palabras>\n- Fortaleza: <frase>\n- Riesgo: <frase>\n- Coaching inmediato: <accion concreta, empezando con verbo>\n- KPI a seguir esta semana: <metrica y objetivo>\n\nSe directo, concreto y apoyado en los numeros del JSON. No uses relleno motivacional.",
            'voice_ai_claude_risk_prompt' => "Eres un analista de riesgo comercial. Recibes JSON con dispositciones de llamadas, timeline, interacciones por canal y pipeline de oportunidades. Identifica:\n\n## Riesgos de churn / perdida\n- <contacto / segmento> — <evidencia en los datos> — <accion de retencion sugerida>\n\n## Oportunidades calientes\n- <contacto / segmento> — <senal positiva> — <siguiente paso con responsable sugerido>\n\n## Alertas operativas\n- <patron anomalo> — <impacto> — <mitigacion>\n\nMinimo 3 entradas por seccion cuando haya datos; si no alcanza, se explicito. No inventes contactos que no esten en el JSON.",
            'voice_ai_claude_anomaly_prompt' => "Eres un analista de anomalias. Recibes timeline diario y distribuciones. Detecta outliers (dias con caidas >30%, picos anomalos, canales que desaparecen). Devuelve en Markdown:\n\n## Anomalias detectadas\n- **<fecha / canal>**: <desviacion observada> — <posible causa> — <accion>\n\nSi todo esta dentro de la variacion normal, di 'No se detectaron anomalias materiales en el periodo.' Sin relleno.",
            'voice_ai_claude_forecast_prompt' => "Eres un analista de demanda. Recibes timeline diario historico (YYYY-MM-DD => conteo). Estima el volumen esperado de los proximos 7 dias considerando tendencia, estacionalidad semanal y outliers recientes. Devuelve:\n\n## Forecast 7 dias\n| Fecha | Volumen estimado | Notas |\n|---|---|---|\n...\n\n## Supuestos clave\n- <supuesto>\n\n## Recomendaciones de staffing\n- <accion>\n\nSe conservador y muestra rangos cuando la serie sea corta o ruidosa.",
            'voice_ai_claude_natural_prompt' => "Eres un analista de datos conversacional. Recibes una pregunta del usuario y un JSON con los datos disponibles del dashboard de comunicaciones (llamadas, mensajes, disposiciones, pipeline, citas, formularios). Responde en espanol, con numeros precisos del JSON. Si la pregunta no se puede responder con los datos, di exactamente que falta. Formato: parrafo corto + bullets con cifras citadas del JSON.",
            'voice_ai_claude_call_prompt' => "Eres un analista de conversaciones. Recibes el detalle de una llamada (duracion, disposicion, transcript resumido, acciones detectadas, agente, contacto). Entrega:\n\n## Diagnostico\nUna frase con el resultado real de la llamada.\n\n## Sentimiento del cliente\n<positivo / neutral / negativo> — <evidencia>\n\n## Momentos clave\n- <timestamp aprox. o paso> — <que paso>\n\n## Que hizo bien el agente\n- <bullet>\n\n## Que debe mejorar\n- <bullet>\n\n## Siguiente mejor accion\n<verbo imperativo + plazo sugerido>",
            'voice_ai_claude_opportunity_prompt' => "Eres un VP de Ventas. Recibes JSON con oportunidades, pipelines y sus montos. Devuelve:\n\n## Salud del pipeline\nVeredicto ejecutivo en una frase.\n\n## Top 5 oportunidades a empujar esta semana\n- **<nombre>** ($<valor>) — <razon> — <accion concreta>\n\n## Cuellos de botella\n- <etapa / pipeline> — <evidencia> — <accion>\n\n## Win-rate y ticket promedio\nCifras del JSON con interpretacion corta.",
        ];
    }
}

if (!function_exists('voiceAiInsightSetting')) {
    function voiceAiInsightSetting(PDO $pdo, string $key)
    {
        $defaults = voiceAiInsightDefaults();
        $default = $defaults[$key] ?? '';
        $value = getSystemSetting($pdo, $key, $default);
        if ($value === null || $value === '') {
            return $default;
        }
        return $value;
    }
}

if (!function_exists('voiceAiInsightIsEnabled')) {
    function voiceAiInsightIsEnabled(PDO $pdo): bool
    {
        return ((string) voiceAiInsightSetting($pdo, 'voice_ai_claude_enabled')) === '1';
    }
}

if (!function_exists('voiceAiInsightCacheDir')) {
    function voiceAiInsightCacheDir(): string
    {
        $dir = __DIR__ . '/../cache/voice_ai_insights';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('voiceAiInsightCompactForPrompt')) {
    /**
     * Trims large structures so the prompt stays under Claude's context.
     * Keeps KPIs and summary-level numbers, truncates lists to top-N items.
     */
    function voiceAiInsightCompactForPrompt($data, int $maxItems = 25)
    {
        if (is_array($data)) {
            $isList = array_keys($data) === range(0, count($data) - 1);
            if ($isList && count($data) > $maxItems) {
                $data = array_slice($data, 0, $maxItems);
            }
            foreach ($data as $k => $v) {
                $data[$k] = voiceAiInsightCompactForPrompt($v, $maxItems);
            }
            return $data;
        }
        if (is_string($data) && strlen($data) > 2000) {
            return substr($data, 0, 2000) . '…';
        }
        return $data;
    }
}

if (!function_exists('voiceAiInsightRun')) {
    /**
     * Core runner: enforces settings, applies cache, calls Claude, returns
     * the uniform response shape.
     */
    function voiceAiInsightRun(PDO $pdo, string $promptKey, array $context, array $overrides = []): array
    {
        $enabled = voiceAiInsightIsEnabled($pdo);
        $model = trim((string) ($overrides['model'] ?? voiceAiInsightSetting($pdo, 'voice_ai_claude_model')));
        if ($model === '') {
            $model = resolveAnthropicDefaultModel($pdo);
        }
        $temperature = (float) ($overrides['temperature'] ?? voiceAiInsightSetting($pdo, 'voice_ai_claude_temperature'));
        $maxTokens = (int) ($overrides['max_tokens'] ?? voiceAiInsightSetting($pdo, 'voice_ai_claude_max_tokens'));
        if ($maxTokens <= 0) $maxTokens = 1400;
        $cacheTtl = (int) ($overrides['cache_ttl'] ?? voiceAiInsightSetting($pdo, 'voice_ai_claude_cache_ttl'));

        $systemPrompt = (string) ($overrides['system_prompt'] ?? voiceAiInsightSetting($pdo, $promptKey));
        $userPayload = voiceAiInsightCompactForPrompt($context);
        $userQuestion = (string) ($overrides['user_question'] ?? '');

        $userPrompt = "Datos (JSON):\n" . json_encode($userPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($userQuestion !== '') {
            $userPrompt = "Pregunta del usuario: " . $userQuestion . "\n\n" . $userPrompt;
        }

        $fingerprint = md5($promptKey . '|' . $model . '|' . $systemPrompt . '|' . $userPrompt . '|' . $temperature . '|' . $maxTokens);
        $cacheFile = voiceAiInsightCacheDir() . '/' . $fingerprint . '.json';

        if ($cacheTtl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['success'])) {
                $cached['cached'] = true;
                $cached['fingerprint'] = $fingerprint;
                return $cached;
            }
        }

        if (!$enabled) {
            return [
                'success' => false,
                'enabled' => false,
                'content' => '',
                'error' => 'La capa de IA de Voice AI esta desactivada. Activala en Configuracion > Voice AI · Claude AI.',
                'model' => $model,
                'usage' => null,
                'cached' => false,
                'fingerprint' => $fingerprint,
            ];
        }

        $result = callClaudeAPI([
            'pdo' => $pdo,
            'model' => $model,
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userPrompt,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'timeout' => 120,
        ]);

        $payload = [
            'success' => !empty($result['success']),
            'enabled' => $enabled,
            'content' => (string) ($result['content'] ?? ''),
            'error' => $result['error'] ?? null,
            'model' => $model,
            'usage' => $result['usage'] ?? null,
            'cached' => false,
            'fingerprint' => $fingerprint,
            'generated_at' => date('c'),
        ];

        if ($payload['success'] && $cacheTtl > 0) {
            @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $payload;
    }
}

if (!function_exists('voiceAiInsightSharedContext')) {
    /**
     * Build one unified context object that every AI insight can reuse.
     * Combines the main dashboard payload with mega-report data (pipelines,
     * appointments, contacts growth) so Claude always receives the richest
     * signal available regardless of what GHL plan the location has.
     *
     * Cached per-request in a static (so multiple AI calls in the same HTTP
     * request reuse the same dataset) and on disk for 10 minutes so the same
     * user invoking several IA tabs in a row doesn't re-hit GHL each time.
     */
    function voiceAiInsightSharedContext(PDO $pdo, array $filters, array $options = []): array
    {
        static $memo = [];

        $integrationId = voiceAiGetContextIntegrationId();
        $fingerprint = md5(json_encode([
            'v' => 2,
            'integration_id' => $integrationId,
            'filters' => [
                'start_date' => $filters['start_date'] ?? '',
                'end_date' => $filters['end_date'] ?? '',
                'fast_mode' => $filters['fast_mode'] ?? true,
            ],
        ], JSON_UNESCAPED_UNICODE));

        if (isset($memo[$fingerprint])) {
            return $memo[$fingerprint];
        }

        $cacheTtl = (int) voiceAiInsightSetting($pdo, 'voice_ai_claude_cache_ttl');
        $cacheFile = voiceAiInsightCacheDir() . '/dashboard_' . $fingerprint . '.json';
        $useDisk = empty($options['force_refresh']) && $cacheTtl > 0;

        if ($useDisk && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['dashboard'])) {
                $memo[$fingerprint] = $cached;
                return $cached;
            }
        }

        $startedAt = microtime(true);

        $dashboardPayload = voiceAiBuildReportPayload($pdo, $filters, false);
        $dashboard = is_array($dashboardPayload) ? ($dashboardPayload['dashboard'] ?? []) : [];

        // Only pull heavyweight extras when explicitly requested to keep
        // the general IA actions fast. Pipeline + growth are cheap enough
        // that we always attach their summaries if the call succeeds.
        $pipeline = null;
        $contactsGrowth = null;
        $appointments = null;
        $forms = null;
        $surveys = null;

        if (!empty($options['include_pipeline'])) {
            $opps = voiceAiMegaFetchOpportunities($pdo, $filters);
            if (!empty($opps['success'])) {
                $pipeline = [
                    'summary' => $opps['summary'] ?? [],
                    'top' => array_slice($opps['opportunities'] ?? [], 0, 15),
                ];
            }
        }
        if (!empty($options['include_contacts_growth'])) {
            $growth = voiceAiMegaFetchContactsGrowth($pdo, $filters);
            if (!empty($growth['success'])) {
                $contactsGrowth = $growth['summary'] ?? null;
            }
        }
        if (!empty($options['include_appointments'])) {
            $appt = voiceAiMegaFetchAppointments($pdo, $filters);
            if (!empty($appt['success'])) {
                $appointments = $appt['summary'] ?? null;
            }
        }
        if (!empty($options['include_forms_surveys'])) {
            $f = voiceAiMegaFetchForms($pdo, $filters);
            $s = voiceAiMegaFetchSurveys($pdo, $filters);
            $forms = $f['summary'] ?? null;
            $surveys = $s['summary'] ?? null;
        }

        // Pull Voice AI specific call-log based metrics only when available
        // (these endpoints fail gracefully if the plan does not include them).
        $voiceAiQuality = null;
        $voiceAiDisposition = null;
        if (!empty($options['include_voice_ai_quality'])) {
            $quality = voiceAiFetchCallQualityMetrics($pdo, $filters);
            if (!empty($quality['success'])) {
                $voiceAiQuality = $quality['quality_metrics'] ?? null;
            }
            $disp = voiceAiFetchDispositionAnalytics($pdo, $filters);
            if (!empty($disp['success'])) {
                $voiceAiDisposition = [
                    'stats' => $disp['disposition_stats'] ?? [],
                    'by_agent' => $disp['disposition_by_agent'] ?? [],
                    'by_user' => $disp['disposition_by_user'] ?? [],
                ];
            }
        }

        $bundle = [
            'generated_at' => date('c'),
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'filters' => [
                'integration_id' => $integrationId,
                'start_date' => $filters['start_date'] ?? '',
                'end_date' => $filters['end_date'] ?? '',
            ],
            'dashboard' => $dashboard,
            'meta' => $dashboardPayload['meta'] ?? [],
            'pipeline' => $pipeline,
            'contacts_growth' => $contactsGrowth,
            'appointments' => $appointments,
            'forms' => $forms,
            'surveys' => $surveys,
            'voice_ai_quality' => $voiceAiQuality,
            'voice_ai_disposition' => $voiceAiDisposition,
        ];

        if ($useDisk) {
            @file_put_contents($cacheFile, json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $memo[$fingerprint] = $bundle;
        return $bundle;
    }
}

if (!function_exists('voiceAiInsightHasAnyData')) {
    /**
     * Quick signal the UI can show before spending tokens. Returns true when
     * there is at least one interaction, call, message or opportunity to
     * analyze.
     */
    function voiceAiInsightHasAnyData(array $context): bool
    {
        $d = $context['dashboard'] ?? [];
        $kpis = $d['kpis'] ?? [];
        foreach ($kpis as $kpi) {
            if ((int) ($kpi['value'] ?? 0) > 0) return true;
        }
        if (!empty($d['recent_interactions'])) return true;
        if (!empty($d['call_dispositions'])) return true;
        if (!empty($d['agents'])) return true;
        if (!empty($context['pipeline']['summary']['total'] ?? 0)) return true;
        if (!empty($context['appointments']['total'] ?? 0)) return true;
        if (!empty($context['contacts_growth']['total'] ?? 0)) return true;
        return false;
    }
}

if (!function_exists('voiceAiInsightExecutiveSummary')) {
    /**
     * Accepts either a raw dashboard array (back-compat) or the full shared
     * context bundle. When the bundle is passed, pipeline + appointments +
     * contacts growth are also folded into the prompt so the executive
     * summary can reference them.
     */
    function voiceAiInsightExecutiveSummary(PDO $pdo, array $input, array $overrides = []): array
    {
        $isBundle = isset($input['dashboard']) && is_array($input['dashboard']);
        $dashboard = $isBundle ? $input['dashboard'] : $input;

        $context = [
            'period' => $isBundle ? ($input['filters'] ?? []) : [],
            'kpis' => $dashboard['kpis'] ?? [],
            'distributions' => $dashboard['distributions'] ?? [],
            'timeline' => $dashboard['timeline'] ?? [],
            'call_dispositions' => array_slice($dashboard['call_dispositions'] ?? [], 0, 15),
            'disposition_by_user' => array_slice($dashboard['disposition_by_user'] ?? [], 0, 15),
            'queue_by_user' => array_slice($dashboard['queue_by_user'] ?? [], 0, 10),
            'agents_activity' => array_slice($dashboard['agents'] ?? [], 0, 15),
            'voice_ai_coverage' => $dashboard['voice_ai_coverage'] ?? [],
            'summary' => $dashboard['summary'] ?? [],
        ];

        if ($isBundle) {
            if (!empty($input['pipeline']['summary'])) {
                $context['pipeline_summary'] = $input['pipeline']['summary'];
            }
            if (!empty($input['appointments'])) {
                $context['appointments_summary'] = $input['appointments'];
            }
            if (!empty($input['contacts_growth'])) {
                $context['contacts_growth_summary'] = $input['contacts_growth'];
            }
        }

        return voiceAiInsightRun($pdo, 'voice_ai_claude_executive_prompt', $context, $overrides);
    }
}

if (!function_exists('voiceAiInsightAgentCoaching')) {
    /**
     * Now accepts the full shared context. Falls back to dashboard-level
     * data (agents, queue, disposition_by_user from /conversations/messages)
     * when Voice AI subscription data is empty, so coaching works on any
     * GHL location that has call activity.
     */
    function voiceAiInsightAgentCoaching(PDO $pdo, array $sharedContext, array $overrides = []): array
    {
        $dashboard = $sharedContext['dashboard'] ?? [];
        $voiceAiQuality = $sharedContext['voice_ai_quality'] ?? null;
        $voiceAiDisposition = $sharedContext['voice_ai_disposition'] ?? null;

        $scores = $voiceAiQuality['quality_scores_by_agent'] ?? [];
        if (is_array($scores) && !empty($scores)) {
            $listed = [];
            foreach ($scores as $agentId => $row) {
                $listed[] = array_merge(['agent_id' => $agentId], is_array($row) ? $row : []);
            }
            usort($listed, fn($a, $b) => ($b['quality_score'] ?? 0) <=> ($a['quality_score'] ?? 0));
            $top = array_slice($listed, 0, 3);
            $bottom = array_slice(array_reverse($listed), 0, 3);
            $scores = array_merge($top, $bottom);
        }

        $agentsActivity = $dashboard['agents'] ?? [];
        if (is_array($agentsActivity) && !empty($agentsActivity)) {
            usort($agentsActivity, function ($a, $b) {
                return ($b['interactions'] ?? ($b['total_interactions'] ?? 0))
                    <=> ($a['interactions'] ?? ($a['total_interactions'] ?? 0));
            });
            $agentsActivity = array_slice($agentsActivity, 0, 15);
        }

        $context = [
            'period' => $sharedContext['filters'] ?? [],
            'kpis_summary' => $dashboard['kpis'] ?? [],
            'voice_ai_quality_overview' => $voiceAiQuality ? [
                'total_calls' => $voiceAiQuality['total_calls'] ?? 0,
                'avg_duration' => $voiceAiQuality['avg_duration'] ?? 0,
                'recording_coverage_pct' => $voiceAiQuality['recording_coverage_pct'] ?? 0,
                'transcript_coverage_pct' => $voiceAiQuality['transcript_coverage_pct'] ?? 0,
                'summary_coverage_pct' => $voiceAiQuality['summary_coverage_pct'] ?? 0,
            ] : null,
            'voice_ai_quality_scores' => $scores,
            'voice_ai_disposition_by_user' => array_slice($voiceAiDisposition['by_user'] ?? [], 0, 15),
            'dashboard_agents_activity' => $agentsActivity,
            'dashboard_disposition_by_user' => array_slice($dashboard['disposition_by_user'] ?? [], 0, 15),
            'dashboard_call_dispositions' => array_slice($dashboard['call_dispositions'] ?? [], 0, 15),
            'dashboard_queue_by_user' => array_slice($dashboard['queue_by_user'] ?? [], 0, 15),
            'dashboard_users_catalog' => array_slice($dashboard['users_catalog'] ?? [], 0, 50),
        ];
        return voiceAiInsightRun($pdo, 'voice_ai_claude_coaching_prompt', $context, $overrides);
    }
}

if (!function_exists('voiceAiInsightChurnAndOpportunity')) {
    function voiceAiInsightChurnAndOpportunity(PDO $pdo, array $payload, array $overrides = []): array
    {
        $context = [
            'dispositions' => array_slice($payload['call_dispositions'] ?? [], 0, 20),
            'timeline' => $payload['timeline'] ?? [],
            'channels' => ($payload['distributions'] ?? [])['channels'] ?? [],
            'statuses' => ($payload['distributions'] ?? [])['statuses'] ?? [],
            'contacts' => array_slice($payload['contacts'] ?? [], 0, 20),
            'pipeline_summary' => $payload['opportunities_summary'] ?? null,
        ];
        return voiceAiInsightRun($pdo, 'voice_ai_claude_risk_prompt', $context, $overrides);
    }
}

if (!function_exists('voiceAiInsightAnomalies')) {
    function voiceAiInsightAnomalies(PDO $pdo, array $timeline, array $distributions = [], array $overrides = []): array
    {
        $context = [
            'timeline_by_day' => $timeline['by_day'] ?? [],
            'timeline_by_weekday' => $timeline['by_weekday'] ?? [],
            'timeline_by_hour' => $timeline['by_hour'] ?? [],
            'channels' => $distributions['channels'] ?? [],
            'statuses' => $distributions['statuses'] ?? [],
        ];
        return voiceAiInsightRun($pdo, 'voice_ai_claude_anomaly_prompt', $context, $overrides);
    }
}

if (!function_exists('voiceAiInsightForecast')) {
    function voiceAiInsightForecast(PDO $pdo, array $timelineByDay, array $overrides = []): array
    {
        return voiceAiInsightRun($pdo, 'voice_ai_claude_forecast_prompt', [
            'timeline_by_day' => $timelineByDay,
        ], $overrides);
    }
}

if (!function_exists('voiceAiInsightNaturalQuery')) {
    function voiceAiInsightNaturalQuery(PDO $pdo, string $question, array $dataContext, array $overrides = []): array
    {
        if (trim($question) === '') {
            return [
                'success' => false,
                'enabled' => voiceAiInsightIsEnabled($pdo),
                'content' => '',
                'error' => 'La pregunta no puede estar vacia.',
                'model' => '',
                'usage' => null,
                'cached' => false,
                'fingerprint' => '',
            ];
        }
        $overrides['user_question'] = $question;
        return voiceAiInsightRun($pdo, 'voice_ai_claude_natural_prompt', $dataContext, $overrides);
    }
}

if (!function_exists('voiceAiInsightCallAnalysis')) {
    function voiceAiInsightCallAnalysis(PDO $pdo, array $callDetail, array $overrides = []): array
    {
        $context = [
            'call' => [
                'id' => $callDetail['id'] ?? '',
                'agent_name' => $callDetail['agent_name'] ?? '',
                'contact_name' => $callDetail['contact_name'] ?? '',
                'duration_seconds' => $callDetail['duration_seconds'] ?? 0,
                'direction' => $callDetail['direction'] ?? '',
                'disposition' => $callDetail['disposition'] ?? '',
                'status' => $callDetail['status'] ?? '',
                'summary' => $callDetail['summary'] ?? '',
                'transcript' => isset($callDetail['transcript']) ? substr((string) $callDetail['transcript'], 0, 6000) : '',
                'action_types' => $callDetail['action_types'] ?? [],
                'sentiment' => $callDetail['sentiment'] ?? '',
            ],
        ];
        return voiceAiInsightRun($pdo, 'voice_ai_claude_call_prompt', $context, $overrides);
    }
}

if (!function_exists('voiceAiInsightOpportunities')) {
    function voiceAiInsightOpportunities(PDO $pdo, array $opportunitiesReport, array $overrides = []): array
    {
        $opps = $opportunitiesReport['opportunities'] ?? [];
        usort($opps, fn($a, $b) => ($b['monetary_value'] ?? 0) <=> ($a['monetary_value'] ?? 0));
        $context = [
            'summary' => $opportunitiesReport['summary'] ?? [],
            'top_opportunities' => array_slice($opps, 0, 15),
            'pipelines_count' => count($opportunitiesReport['pipelines'] ?? []),
        ];
        return voiceAiInsightRun($pdo, 'voice_ai_claude_opportunity_prompt', $context, $overrides);
    }
}

if (!function_exists('voiceAiInsightHealthCheck')) {
    function voiceAiInsightHealthCheck(PDO $pdo): array
    {
        $enabled = voiceAiInsightIsEnabled($pdo);
        $model = voiceAiInsightSetting($pdo, 'voice_ai_claude_model');
        $apiKeyPresent = resolveAnthropicApiKey($pdo) !== '';

        if (!$enabled) {
            return ['success' => false, 'enabled' => false, 'message' => 'IA desactivada en configuracion.', 'model' => $model, 'api_key_present' => $apiKeyPresent];
        }
        if (!$apiKeyPresent) {
            return ['success' => false, 'enabled' => true, 'message' => 'Falta la API Key global de Anthropic.', 'model' => $model, 'api_key_present' => false];
        }
        $result = testClaudeAPIConnection('', $model, $pdo);
        return [
            'success' => !empty($result['success']),
            'enabled' => true,
            'message' => $result['success'] ? 'OK' : ($result['error'] ?? 'Sin respuesta'),
            'model' => $model,
            'api_key_present' => true,
            'raw' => $result,
        ];
    }
}

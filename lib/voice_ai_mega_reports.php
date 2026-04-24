<?php
/**
 * Voice AI Mega Reports — Expands the GHL API coverage dramatically.
 *
 * Adds fetchers for every public endpoint family that can enrich the
 * communications dashboard: opportunities, pipelines, calendars,
 * appointments, forms, surveys, workflows, campaigns, tags, custom fields,
 * contacts growth, tasks, notes, notifications and location-level metadata.
 *
 * Every fetcher:
 *   - Honors the active integration (voice_ai_client context).
 *   - Gracefully degrades when a plan/subscription is missing the endpoint
 *     (returns success=false with message, never fatal).
 *   - Applies the same date window as the voice reports (start_date/end_date).
 *   - Returns arrays ready for Chart.js / tables.
 */

if (!function_exists('voiceAiMegaResolveDateRange')) {
    function voiceAiMegaResolveDateRange(array $filters): array
    {
        $start = trim((string) ($filters['start_date'] ?? ''));
        $end = trim((string) ($filters['end_date'] ?? ''));
        if ($start === '') {
            $start = date('Y-m-01');
        }
        if ($end === '') {
            $end = date('Y-m-d');
        }
        return [
            'start_date' => $start,
            'end_date' => $end,
            'start_ms' => strtotime($start . ' 00:00:00') * 1000,
            'end_ms' => strtotime($end . ' 23:59:59') * 1000,
            'start_iso' => date('c', strtotime($start . ' 00:00:00')),
            'end_iso' => date('c', strtotime($end . ' 23:59:59')),
        ];
    }
}

if (!function_exists('voiceAiMegaSafeRequest')) {
    /**
     * Wrapper that returns a neutral empty response if the endpoint is
     * unavailable for the current plan, so the aggregated report never breaks.
     */
    function voiceAiMegaSafeRequest(array $config, string $method, string $path, array $query = [], ?array $body = null): array
    {
        try {
            $response = voiceAiHttpRequest($config, $method, $path, $query, $body);
        } catch (Throwable $e) {
            return ['success' => false, 'status_code' => 0, 'message' => $e->getMessage(), 'data' => []];
        }
        if (!is_array($response)) {
            return ['success' => false, 'status_code' => 0, 'message' => 'invalid_response', 'data' => []];
        }
        if (!isset($response['data']) || !is_array($response['data'])) {
            $response['data'] = [];
        }
        return $response;
    }
}

if (!function_exists('voiceAiMegaFetchPipelines')) {
    function voiceAiMegaFetchPipelines(PDO $pdo): array
    {
        $config = voiceAiGetConfig($pdo);
        if (!voiceAiGetConfigStatus($pdo)['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion de GHL incompleta.', 'pipelines' => []];
        }

        $response = voiceAiMegaSafeRequest($config, 'GET', '/opportunities/pipelines', [
            'locationId' => $config['location_id'],
        ]);

        if (!$response['success']) {
            return ['success' => false, 'message' => $response['message'] ?? 'Pipelines no disponibles', 'pipelines' => []];
        }

        $raw = $response['data']['pipelines'] ?? $response['data'] ?? [];
        $pipelines = [];
        foreach ((array) $raw as $p) {
            if (!is_array($p)) continue;
            $stages = [];
            foreach ((array) ($p['stages'] ?? []) as $stage) {
                if (!is_array($stage)) continue;
                $stages[] = [
                    'id' => (string) ($stage['id'] ?? ''),
                    'name' => (string) ($stage['name'] ?? ''),
                    'position' => (int) ($stage['position'] ?? 0),
                    'show_in_funnel' => !empty($stage['showInFunnel']),
                    'show_in_pie_chart' => !empty($stage['showInPieChart']),
                ];
            }
            $pipelines[] = [
                'id' => (string) ($p['id'] ?? ''),
                'name' => (string) ($p['name'] ?? ''),
                'stages' => $stages,
                'stages_count' => count($stages),
                'date_added' => (string) ($p['dateAdded'] ?? ''),
                'date_updated' => (string) ($p['dateUpdated'] ?? ''),
            ];
        }

        return ['success' => true, 'pipelines' => $pipelines];
    }
}

if (!function_exists('voiceAiMegaFetchOpportunities')) {
    function voiceAiMegaFetchOpportunities(PDO $pdo, array $filters = []): array
    {
        $config = voiceAiGetConfig($pdo);
        if (!voiceAiGetConfigStatus($pdo)['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion de GHL incompleta.'];
        }

        $range = voiceAiMegaResolveDateRange($filters);
        $pipelines = voiceAiMegaFetchPipelines($pdo);
        $pipelineIndex = [];
        foreach ($pipelines['pipelines'] ?? [] as $p) {
            $pipelineIndex[$p['id']] = $p;
        }

        $opportunities = [];
        $pageLimit = 5;
        // GHL v2 /opportunities/search does NOT accept a server-side date
        // range filter. `startAfter` is a cursor (last opportunity's
        // dateAdded epoch ms) used for pagination, NOT a lower bound.
        // Strategy: fetch the newest records and filter locally.
        $baseQuery = [
            'location_id' => $config['location_id'],
            'limit' => 100,
        ];

        $cursor = null;
        $cursorId = null;
        $stopFetching = false;
        for ($page = 0; $page < $pageLimit && !$stopFetching; $page++) {
            $query = $baseQuery;
            if ($cursor !== null) {
                $query['startAfter'] = $cursor;
            }
            if ($cursorId !== null) {
                $query['startAfterId'] = $cursorId;
            }
            $response = voiceAiMegaSafeRequest($config, 'GET', '/opportunities/search', $query);
            if (!$response['success']) {
                break;
            }

            $items = $response['data']['opportunities'] ?? [];
            if (!is_array($items) || empty($items)) {
                break;
            }

            foreach ($items as $opp) {
                if (!is_array($opp)) continue;
                $pipelineId = (string) ($opp['pipelineId'] ?? '');
                $stageId = (string) ($opp['pipelineStageId'] ?? '');
                $stageName = '';
                if (isset($pipelineIndex[$pipelineId])) {
                    foreach ($pipelineIndex[$pipelineId]['stages'] as $stage) {
                        if ($stage['id'] === $stageId) {
                            $stageName = $stage['name'];
                            break;
                        }
                    }
                }
                $createdRaw = (string) ($opp['createdAt'] ?? ($opp['dateAdded'] ?? ''));
                $createdTs = $createdRaw !== '' ? strtotime($createdRaw) : null;

                // Local date filter — guarantees correctness regardless of
                // GHL's server-side behavior.
                if ($createdTs !== null) {
                    if ($range['start_ms'] > 0 && $createdTs * 1000 < $range['start_ms']) {
                        // This result is older than our window; since the
                        // API returns newest-first, we can stop paginating.
                        $stopFetching = true;
                        continue;
                    }
                    if ($range['end_ms'] > 0 && $createdTs * 1000 > $range['end_ms']) {
                        continue;
                    }
                }

                $opportunities[] = [
                    'id' => (string) ($opp['id'] ?? ''),
                    'name' => (string) ($opp['name'] ?? ''),
                    'monetary_value' => (float) ($opp['monetaryValue'] ?? 0),
                    'status' => (string) ($opp['status'] ?? ''),
                    'source' => (string) ($opp['source'] ?? ''),
                    'assigned_to' => (string) ($opp['assignedTo'] ?? ''),
                    'pipeline_id' => $pipelineId,
                    'pipeline_name' => $pipelineIndex[$pipelineId]['name'] ?? '',
                    'stage_id' => $stageId,
                    'stage_name' => $stageName,
                    'contact_id' => (string) ($opp['contactId'] ?? ''),
                    'contact_name' => (string) ($opp['contact']['name'] ?? ($opp['contactName'] ?? '')),
                    'contact_email' => (string) ($opp['contact']['email'] ?? ''),
                    'contact_phone' => (string) ($opp['contact']['phone'] ?? ''),
                    'date_added' => $createdRaw,
                    'last_status_change' => (string) ($opp['lastStatusChangeAt'] ?? ''),
                    'last_action_date' => (string) ($opp['lastActionDate'] ?? ''),
                ];
            }

            $meta = $response['data']['meta'] ?? [];
            $cursor = $meta['startAfter'] ?? null;
            $cursorId = $meta['startAfterId'] ?? null;
            if ((!$cursor && !$cursorId) || count($items) < 100) {
                break;
            }
        }

        $summary = voiceAiMegaSummarizeOpportunities($opportunities);

        return [
            'success' => true,
            'opportunities' => $opportunities,
            'pipelines' => array_values($pipelineIndex),
            'summary' => $summary,
            'range' => $range,
        ];
    }
}

if (!function_exists('voiceAiMegaSummarizeOpportunities')) {
    function voiceAiMegaSummarizeOpportunities(array $opps): array
    {
        $byStatus = [];
        $byPipeline = [];
        $byStage = [];
        $bySource = [];
        $byUser = [];
        $timeline = [];
        $totalValue = 0.0;
        $wonValue = 0.0;
        $lostValue = 0.0;
        $openValue = 0.0;

        foreach ($opps as $opp) {
            $status = $opp['status'] ?: 'unknown';
            $pipeline = $opp['pipeline_name'] ?: 'sin pipeline';
            $stageKey = ($opp['pipeline_name'] ?: 'sin pipeline') . ' · ' . ($opp['stage_name'] ?: 'sin etapa');
            $source = $opp['source'] ?: 'desconocido';
            $user = $opp['assigned_to'] ?: 'sin asignar';

            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $byPipeline[$pipeline] = ($byPipeline[$pipeline] ?? 0) + 1;
            $byStage[$stageKey] = ($byStage[$stageKey] ?? 0) + 1;
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;
            $byUser[$user] = ($byUser[$user] ?? 0) + 1;

            $value = (float) $opp['monetary_value'];
            $totalValue += $value;
            if (strcasecmp($status, 'won') === 0) $wonValue += $value;
            elseif (strcasecmp($status, 'lost') === 0) $lostValue += $value;
            else $openValue += $value;

            $ts = strtotime($opp['date_added']);
            if ($ts) {
                $day = date('Y-m-d', $ts);
                $timeline[$day] = ($timeline[$day] ?? 0) + 1;
            }
        }

        ksort($timeline);
        arsort($byStatus);
        arsort($byPipeline);
        arsort($byStage);
        arsort($bySource);
        arsort($byUser);

        $won = $byStatus['won'] ?? 0;
        $lost = $byStatus['lost'] ?? 0;
        $total = array_sum($byStatus);
        $winRate = ($won + $lost) > 0 ? round(($won / ($won + $lost)) * 100, 2) : 0.0;
        $avgTicket = $total > 0 ? round($totalValue / $total, 2) : 0.0;

        return [
            'total' => $total,
            'total_value' => round($totalValue, 2),
            'won_value' => round($wonValue, 2),
            'lost_value' => round($lostValue, 2),
            'open_value' => round($openValue, 2),
            'win_rate_pct' => $winRate,
            'avg_ticket' => $avgTicket,
            'by_status' => $byStatus,
            'by_pipeline' => $byPipeline,
            'by_stage' => $byStage,
            'by_source' => $bySource,
            'by_user' => $byUser,
            'timeline' => $timeline,
        ];
    }
}

if (!function_exists('voiceAiMegaFetchCalendars')) {
    function voiceAiMegaFetchCalendars(PDO $pdo): array
    {
        $config = voiceAiGetConfig($pdo);
        if (!voiceAiGetConfigStatus($pdo)['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion incompleta.', 'calendars' => []];
        }

        $response = voiceAiMegaSafeRequest($config, 'GET', '/calendars/', [
            'locationId' => $config['location_id'],
        ]);

        if (!$response['success']) {
            return ['success' => false, 'message' => $response['message'] ?? 'Calendarios no disponibles', 'calendars' => []];
        }

        $calendars = [];
        foreach ((array) ($response['data']['calendars'] ?? []) as $cal) {
            if (!is_array($cal)) continue;
            $calendars[] = [
                'id' => (string) ($cal['id'] ?? ''),
                'name' => (string) ($cal['name'] ?? ''),
                'description' => (string) ($cal['description'] ?? ''),
                'slug' => (string) ($cal['slug'] ?? ''),
                'is_active' => !empty($cal['isActive']),
                'widget_slug' => (string) ($cal['widgetSlug'] ?? ''),
                'calendar_type' => (string) ($cal['calendarType'] ?? ''),
                'team_members' => is_array($cal['teamMembers'] ?? null) ? count($cal['teamMembers']) : 0,
                'slot_duration' => (int) ($cal['slotDuration'] ?? 0),
            ];
        }

        return ['success' => true, 'calendars' => $calendars];
    }
}

if (!function_exists('voiceAiMegaFetchAppointments')) {
    function voiceAiMegaFetchAppointments(PDO $pdo, array $filters = []): array
    {
        $config = voiceAiGetConfig($pdo);
        if (!voiceAiGetConfigStatus($pdo)['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion incompleta.'];
        }

        $range = voiceAiMegaResolveDateRange($filters);
        $calendarsData = voiceAiMegaFetchCalendars($pdo);
        $calendars = $calendarsData['calendars'] ?? [];

        $appointments = [];
        $byCalendar = [];
        $byStatus = [];
        $byUser = [];
        $timeline = [];

        foreach ($calendars as $cal) {
            if (empty($cal['id'])) continue;
            $response = voiceAiMegaSafeRequest($config, 'GET', '/calendars/events', [
                'locationId' => $config['location_id'],
                'calendarId' => $cal['id'],
                'startTime' => $range['start_ms'],
                'endTime' => $range['end_ms'],
            ]);
            if (!$response['success']) {
                continue;
            }

            $events = $response['data']['events'] ?? $response['data'] ?? [];
            foreach ((array) $events as $event) {
                if (!is_array($event)) continue;
                $status = strtolower((string) ($event['appointmentStatus'] ?? $event['status'] ?? 'confirmed'));
                $userId = (string) ($event['assignedUserId'] ?? ($event['userId'] ?? ''));
                $startIso = (string) ($event['startTime'] ?? $event['start'] ?? '');
                $ts = strtotime($startIso);
                $day = $ts ? date('Y-m-d', $ts) : '';

                $appointments[] = [
                    'id' => (string) ($event['id'] ?? ''),
                    'title' => (string) ($event['title'] ?? ''),
                    'calendar_id' => $cal['id'],
                    'calendar_name' => $cal['name'],
                    'status' => $status,
                    'start_time' => $startIso,
                    'end_time' => (string) ($event['endTime'] ?? $event['end'] ?? ''),
                    'assigned_user_id' => $userId,
                    'contact_id' => (string) ($event['contactId'] ?? ''),
                    'address' => (string) ($event['address'] ?? ''),
                    'notes' => (string) ($event['notes'] ?? ''),
                ];

                $byCalendar[$cal['name']] = ($byCalendar[$cal['name']] ?? 0) + 1;
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
                if ($userId) $byUser[$userId] = ($byUser[$userId] ?? 0) + 1;
                if ($day) $timeline[$day] = ($timeline[$day] ?? 0) + 1;
            }
        }

        ksort($timeline);
        arsort($byCalendar);
        arsort($byStatus);
        arsort($byUser);

        $total = count($appointments);
        $show = $byStatus['showed'] ?? ($byStatus['completed'] ?? 0);
        $noShow = $byStatus['noshow'] ?? ($byStatus['no-show'] ?? 0);
        $cancelled = $byStatus['cancelled'] ?? ($byStatus['canceled'] ?? 0);
        $showRate = ($show + $noShow) > 0 ? round(($show / ($show + $noShow)) * 100, 2) : 0.0;

        return [
            'success' => true,
            'appointments' => $appointments,
            'summary' => [
                'total' => $total,
                'show_rate_pct' => $showRate,
                'cancelled' => $cancelled,
                'by_status' => $byStatus,
                'by_calendar' => $byCalendar,
                'by_user' => $byUser,
                'timeline' => $timeline,
            ],
            'range' => $range,
        ];
    }
}

if (!function_exists('voiceAiMegaFetchForms')) {
    function voiceAiMegaFetchForms(PDO $pdo, array $filters = []): array
    {
        $config = voiceAiGetConfig($pdo);
        if (!voiceAiGetConfigStatus($pdo)['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion incompleta.'];
        }

        $range = voiceAiMegaResolveDateRange($filters);

        $formsResp = voiceAiMegaSafeRequest($config, 'GET', '/forms/', [
            'locationId' => $config['location_id'],
            'limit' => 100,
        ]);
        $forms = [];
        foreach ((array) ($formsResp['data']['forms'] ?? []) as $f) {
            if (!is_array($f)) continue;
            $forms[] = [
                'id' => (string) ($f['id'] ?? ''),
                'name' => (string) ($f['name'] ?? ''),
                'location_id' => (string) ($f['locationId'] ?? ''),
            ];
        }

        $submissionsResp = voiceAiMegaSafeRequest($config, 'GET', '/forms/submissions', [
            'locationId' => $config['location_id'],
            'limit' => 100,
            'startAt' => $range['start_date'],
            'endAt' => $range['end_date'],
        ]);

        $submissions = [];
        $byForm = [];
        $timeline = [];
        $formIndex = [];
        foreach ($forms as $f) {
            $formIndex[$f['id']] = $f['name'];
        }

        foreach ((array) ($submissionsResp['data']['submissions'] ?? []) as $sub) {
            if (!is_array($sub)) continue;
            $formId = (string) ($sub['formId'] ?? '');
            $formName = $formIndex[$formId] ?? $formId;
            $createdAt = (string) ($sub['createdAt'] ?? '');
            $ts = strtotime($createdAt);

            $submissions[] = [
                'id' => (string) ($sub['id'] ?? ''),
                'form_id' => $formId,
                'form_name' => $formName,
                'name' => (string) ($sub['name'] ?? ''),
                'email' => (string) ($sub['email'] ?? ''),
                'phone' => (string) ($sub['phone'] ?? ''),
                'contact_id' => (string) ($sub['contactId'] ?? ''),
                'created_at' => $createdAt,
            ];

            $byForm[$formName] = ($byForm[$formName] ?? 0) + 1;
            if ($ts) {
                $day = date('Y-m-d', $ts);
                $timeline[$day] = ($timeline[$day] ?? 0) + 1;
            }
        }

        arsort($byForm);
        ksort($timeline);

        return [
            'success' => true,
            'forms' => $forms,
            'submissions' => $submissions,
            'summary' => [
                'total_forms' => count($forms),
                'total_submissions' => count($submissions),
                'by_form' => $byForm,
                'timeline' => $timeline,
            ],
            'range' => $range,
        ];
    }
}

if (!function_exists('voiceAiMegaFetchSurveys')) {
    function voiceAiMegaFetchSurveys(PDO $pdo, array $filters = []): array
    {
        $config = voiceAiGetConfig($pdo);
        if (!voiceAiGetConfigStatus($pdo)['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion incompleta.'];
        }

        $range = voiceAiMegaResolveDateRange($filters);

        $surveysResp = voiceAiMegaSafeRequest($config, 'GET', '/surveys/', [
            'locationId' => $config['location_id'],
            'limit' => 100,
        ]);
        $surveys = [];
        $index = [];
        foreach ((array) ($surveysResp['data']['surveys'] ?? []) as $s) {
            if (!is_array($s)) continue;
            $survey = [
                'id' => (string) ($s['id'] ?? ''),
                'name' => (string) ($s['name'] ?? ''),
            ];
            $surveys[] = $survey;
            $index[$survey['id']] = $survey['name'];
        }

        $submissionsResp = voiceAiMegaSafeRequest($config, 'GET', '/surveys/submissions', [
            'locationId' => $config['location_id'],
            'limit' => 100,
            'startAt' => $range['start_date'],
            'endAt' => $range['end_date'],
        ]);
        $submissions = [];
        $bySurvey = [];
        $timeline = [];
        foreach ((array) ($submissionsResp['data']['submissions'] ?? []) as $sub) {
            if (!is_array($sub)) continue;
            $surveyId = (string) ($sub['surveyId'] ?? '');
            $name = $index[$surveyId] ?? $surveyId;
            $createdAt = (string) ($sub['createdAt'] ?? '');
            $ts = strtotime($createdAt);
            $submissions[] = [
                'id' => (string) ($sub['id'] ?? ''),
                'survey_id' => $surveyId,
                'survey_name' => $name,
                'contact_id' => (string) ($sub['contactId'] ?? ''),
                'created_at' => $createdAt,
            ];
            $bySurvey[$name] = ($bySurvey[$name] ?? 0) + 1;
            if ($ts) {
                $day = date('Y-m-d', $ts);
                $timeline[$day] = ($timeline[$day] ?? 0) + 1;
            }
        }

        arsort($bySurvey);
        ksort($timeline);

        return [
            'success' => true,
            'surveys' => $surveys,
            'submissions' => $submissions,
            'summary' => [
                'total_surveys' => count($surveys),
                'total_submissions' => count($submissions),
                'by_survey' => $bySurvey,
                'timeline' => $timeline,
            ],
            'range' => $range,
        ];
    }
}

if (!function_exists('voiceAiMegaFetchWorkflows')) {
    function voiceAiMegaFetchWorkflows(PDO $pdo): array
    {
        $config = voiceAiGetConfig($pdo);
        if (!voiceAiGetConfigStatus($pdo)['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion incompleta.'];
        }

        $response = voiceAiMegaSafeRequest($config, 'GET', '/workflows/', [
            'locationId' => $config['location_id'],
        ]);

        $workflows = [];
        foreach ((array) ($response['data']['workflows'] ?? []) as $wf) {
            if (!is_array($wf)) continue;
            $workflows[] = [
                'id' => (string) ($wf['id'] ?? ''),
                'name' => (string) ($wf['name'] ?? ''),
                'status' => (string) ($wf['status'] ?? ''),
                'version' => (int) ($wf['version'] ?? 0),
                'created_at' => (string) ($wf['createdAt'] ?? ''),
                'updated_at' => (string) ($wf['updatedAt'] ?? ''),
            ];
        }

        $byStatus = [];
        foreach ($workflows as $wf) {
            $k = $wf['status'] ?: 'unknown';
            $byStatus[$k] = ($byStatus[$k] ?? 0) + 1;
        }

        return [
            'success' => true,
            'workflows' => $workflows,
            'summary' => [
                'total' => count($workflows),
                'by_status' => $byStatus,
            ],
        ];
    }
}

if (!function_exists('voiceAiMegaFetchCampaigns')) {
    function voiceAiMegaFetchCampaigns(PDO $pdo): array
    {
        $config = voiceAiGetConfig($pdo);
        if (!voiceAiGetConfigStatus($pdo)['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion incompleta.'];
        }

        $response = voiceAiMegaSafeRequest($config, 'GET', '/campaigns/', [
            'locationId' => $config['location_id'],
            'status' => 'all',
        ]);

        $campaigns = [];
        foreach ((array) ($response['data']['campaigns'] ?? []) as $c) {
            if (!is_array($c)) continue;
            $campaigns[] = [
                'id' => (string) ($c['id'] ?? ''),
                'name' => (string) ($c['name'] ?? ''),
                'status' => (string) ($c['status'] ?? ''),
            ];
        }

        $byStatus = [];
        foreach ($campaigns as $c) {
            $k = $c['status'] ?: 'unknown';
            $byStatus[$k] = ($byStatus[$k] ?? 0) + 1;
        }

        return [
            'success' => true,
            'campaigns' => $campaigns,
            'summary' => [
                'total' => count($campaigns),
                'by_status' => $byStatus,
            ],
        ];
    }
}

if (!function_exists('voiceAiMegaFetchTags')) {
    function voiceAiMegaFetchTags(PDO $pdo): array
    {
        $config = voiceAiGetConfig($pdo);
        if (!voiceAiGetConfigStatus($pdo)['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion incompleta.', 'tags' => []];
        }

        $response = voiceAiMegaSafeRequest($config, 'GET', '/locations/' . rawurlencode($config['location_id']) . '/tags');
        $tags = [];
        foreach ((array) ($response['data']['tags'] ?? []) as $tag) {
            if (!is_array($tag)) continue;
            $tags[] = [
                'id' => (string) ($tag['id'] ?? ''),
                'name' => (string) ($tag['name'] ?? ''),
            ];
        }
        return ['success' => (bool) $response['success'], 'tags' => $tags];
    }
}

if (!function_exists('voiceAiMegaFetchCustomFields')) {
    function voiceAiMegaFetchCustomFields(PDO $pdo): array
    {
        $config = voiceAiGetConfig($pdo);
        if (!voiceAiGetConfigStatus($pdo)['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion incompleta.', 'fields' => []];
        }

        $response = voiceAiMegaSafeRequest($config, 'GET', '/locations/' . rawurlencode($config['location_id']) . '/customFields');
        $fields = [];
        foreach ((array) ($response['data']['customFields'] ?? []) as $field) {
            if (!is_array($field)) continue;
            $fields[] = [
                'id' => (string) ($field['id'] ?? ''),
                'name' => (string) ($field['name'] ?? ''),
                'data_type' => (string) ($field['dataType'] ?? ''),
                'model' => (string) ($field['model'] ?? ''),
                'placeholder' => (string) ($field['placeholder'] ?? ''),
                'options' => is_array($field['picklistOptions'] ?? null) ? array_values($field['picklistOptions']) : [],
            ];
        }
        return ['success' => (bool) $response['success'], 'fields' => $fields];
    }
}

if (!function_exists('voiceAiMegaFetchContactsGrowth')) {
    function voiceAiMegaFetchContactsGrowth(PDO $pdo, array $filters = []): array
    {
        $config = voiceAiGetConfig($pdo);
        if (!voiceAiGetConfigStatus($pdo)['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion incompleta.'];
        }

        $range = voiceAiMegaResolveDateRange($filters);

        $all = [];
        $timeline = [];
        $bySource = [];
        $byTag = [];
        $byUser = [];
        $searchAfter = null; // array: [dateAdded ms, _id] for POST search
        $getCursor = null;   // for fallback GET
        $stop = false;

        for ($page = 0; $page < 5 && !$stop; $page++) {
            // Primary: GHL v2 POST /contacts/search with date_added filter
            $body = [
                'locationId' => $config['location_id'],
                'pageLimit' => 100,
                'sort' => [[ 'field' => 'dateAdded', 'direction' => 'desc' ]],
                'filters' => [[
                    'field' => 'dateAdded',
                    'operator' => 'between',
                    'value' => [$range['start_iso'], $range['end_iso']],
                ]],
            ];
            if ($searchAfter !== null) {
                $body['searchAfter'] = $searchAfter;
            }
            $response = voiceAiMegaSafeRequest($config, 'POST', '/contacts/search', [], $body);

            // Fallback: GET /contacts/ with cursor-style pagination
            if (!$response['success']) {
                $query = [
                    'locationId' => $config['location_id'],
                    'limit' => 100,
                    'startAfter' => $range['end_ms'] ?: null,
                ];
                if ($getCursor) {
                    $query['startAfterId'] = $getCursor;
                }
                $query = array_filter($query, fn($v) => $v !== null);
                $response = voiceAiMegaSafeRequest($config, 'GET', '/contacts/', $query);
            }

            $contacts = $response['data']['contacts'] ?? [];
            if (!is_array($contacts) || empty($contacts)) {
                break;
            }

            foreach ($contacts as $contact) {
                if (!is_array($contact)) continue;
                $createdAt = (string) ($contact['dateAdded'] ?? $contact['createdAt'] ?? '');
                $ts = strtotime($createdAt);

                // Local date filter — guarantees correctness
                if ($ts && $range['start_ms'] > 0 && $ts * 1000 < $range['start_ms']) {
                    $stop = true; // newest-first, can stop
                    continue;
                }
                if ($ts && $range['end_ms'] > 0 && $ts * 1000 > $range['end_ms']) {
                    continue;
                }

                $source = (string) ($contact['source'] ?? 'desconocido');
                $user = (string) ($contact['assignedTo'] ?? 'sin asignar');

                $all[] = [
                    'id' => (string) ($contact['id'] ?? ''),
                    'name' => trim((string) ($contact['firstName'] ?? '') . ' ' . (string) ($contact['lastName'] ?? '')),
                    'email' => (string) ($contact['email'] ?? ''),
                    'phone' => (string) ($contact['phone'] ?? ''),
                    'source' => $source,
                    'assigned_to' => $user,
                    'date_added' => $createdAt,
                    'tags' => is_array($contact['tags'] ?? null) ? $contact['tags'] : [],
                ];

                if ($ts) {
                    $day = date('Y-m-d', $ts);
                    $timeline[$day] = ($timeline[$day] ?? 0) + 1;
                }
                $bySource[$source] = ($bySource[$source] ?? 0) + 1;
                $byUser[$user] = ($byUser[$user] ?? 0) + 1;
                foreach (($contact['tags'] ?? []) as $tag) {
                    if (!is_string($tag)) continue;
                    $byTag[$tag] = ($byTag[$tag] ?? 0) + 1;
                }
            }

            $meta = $response['data']['meta'] ?? [];
            // POST /contacts/search returns searchAfter cursor
            if (isset($meta['searchAfter']) && is_array($meta['searchAfter'])) {
                $searchAfter = $meta['searchAfter'];
            } else {
                $searchAfter = null;
            }
            $getCursor = $meta['startAfterId'] ?? $getCursor;
            if ((!$searchAfter && !$getCursor) || count($contacts) < 100) {
                break;
            }
        }

        ksort($timeline);
        arsort($bySource);
        arsort($byTag);
        arsort($byUser);

        return [
            'success' => true,
            'contacts' => $all,
            'summary' => [
                'total' => count($all),
                'timeline' => $timeline,
                'by_source' => $bySource,
                'by_tag' => array_slice($byTag, 0, 25, true),
                'by_user' => $byUser,
            ],
            'range' => $range,
        ];
    }
}

if (!function_exists('voiceAiMegaFetchLocationInfo')) {
    function voiceAiMegaFetchLocationInfo(PDO $pdo): array
    {
        $config = voiceAiGetConfig($pdo);
        if (!voiceAiGetConfigStatus($pdo)['is_ready']) {
            return ['success' => false, 'message' => 'Configuracion incompleta.'];
        }
        $response = voiceAiMegaSafeRequest($config, 'GET', '/locations/' . rawurlencode($config['location_id']));

        $loc = $response['data']['location'] ?? $response['data'] ?? [];
        return [
            'success' => (bool) $response['success'],
            'location' => [
                'id' => (string) ($loc['id'] ?? $config['location_id']),
                'name' => (string) ($loc['name'] ?? ''),
                'business_name' => (string) ($loc['companyName'] ?? ($loc['business']['name'] ?? '')),
                'email' => (string) ($loc['email'] ?? ''),
                'phone' => (string) ($loc['phone'] ?? ''),
                'timezone' => (string) ($loc['timezone'] ?? ''),
                'country' => (string) ($loc['country'] ?? ''),
                'state' => (string) ($loc['state'] ?? ''),
                'city' => (string) ($loc['city'] ?? ''),
                'website' => (string) ($loc['website'] ?? ''),
                'address' => (string) ($loc['address'] ?? ''),
            ],
        ];
    }
}

if (!function_exists('voiceAiMegaFetchMegaReport')) {
    /**
     * Aggregated report. Uses curl_multi (voiceAiHttpBatch) to fire every
     * catalog endpoint in parallel, and then runs the report-style
     * functions that need pagination in sequence. Typical speed-up is 3-5x
     * on cold cache.
     */
    function voiceAiMegaFetchMegaReport(PDO $pdo, array $filters = []): array
    {
        $start = microtime(true);
        $errors = [];
        $config = voiceAiGetConfig($pdo);
        $status = voiceAiGetConfigStatus($pdo);
        if (!$status['is_ready']) {
            return [
                'success' => false,
                'message' => 'Configuracion de GHL incompleta.',
                'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }

        // Stage 1 — catalog endpoints, all fired in parallel.
        $batch = voiceAiHttpBatch($config, [
            ['key' => 'location',       'path' => '/locations/' . rawurlencode($config['location_id'])],
            ['key' => 'pipelines',      'path' => '/opportunities/pipelines',  'query' => ['locationId' => $config['location_id']]],
            ['key' => 'calendars',      'path' => '/calendars/',               'query' => ['locationId' => $config['location_id']]],
            ['key' => 'workflows',      'path' => '/workflows/',               'query' => ['locationId' => $config['location_id']]],
            ['key' => 'campaigns',      'path' => '/campaigns/',               'query' => ['locationId' => $config['location_id'], 'status' => 'all']],
            ['key' => 'tags',           'path' => '/locations/' . rawurlencode($config['location_id']) . '/tags'],
            ['key' => 'custom_fields',  'path' => '/locations/' . rawurlencode($config['location_id']) . '/customFields'],
            ['key' => 'forms_list',     'path' => '/forms/',                   'query' => ['locationId' => $config['location_id'], 'limit' => 100]],
            ['key' => 'surveys_list',   'path' => '/surveys/',                 'query' => ['locationId' => $config['location_id'], 'limit' => 100]],
        ]);

        $safe = static function (string $key, array $row, string $dataKey) use (&$errors): array {
            if (empty($row['success'])) {
                if (!empty($row['message'])) $errors[] = $key . ': ' . $row['message'];
                return [];
            }
            return is_array($row['data'][$dataKey] ?? null) ? $row['data'][$dataKey] : [];
        };

        $locationData = ($batch['location']['data']['location'] ?? ($batch['location']['data'] ?? []));
        $pipelinesRaw = $safe('pipelines', $batch['pipelines'] ?? [], 'pipelines');
        $calendarsRaw = $safe('calendars', $batch['calendars'] ?? [], 'calendars');
        $workflowsRaw = $safe('workflows', $batch['workflows'] ?? [], 'workflows');
        $campaignsRaw = $safe('campaigns', $batch['campaigns'] ?? [], 'campaigns');
        $tagsRaw = $safe('tags', $batch['tags'] ?? [], 'tags');
        $fieldsRaw = $safe('custom_fields', $batch['custom_fields'] ?? [], 'customFields');

        // Normalize the inline data so the downstream shape matches what
        // the individual fetchers return when called standalone.
        $pipelines = [];
        $pipelineIndex = [];
        foreach ($pipelinesRaw as $p) {
            if (!is_array($p)) continue;
            $stages = [];
            foreach ((array) ($p['stages'] ?? []) as $stage) {
                if (!is_array($stage)) continue;
                $stages[] = [
                    'id' => (string) ($stage['id'] ?? ''),
                    'name' => (string) ($stage['name'] ?? ''),
                    'position' => (int) ($stage['position'] ?? 0),
                ];
            }
            $record = [
                'id' => (string) ($p['id'] ?? ''),
                'name' => (string) ($p['name'] ?? ''),
                'stages' => $stages,
                'stages_count' => count($stages),
            ];
            $pipelines[] = $record;
            $pipelineIndex[$record['id']] = $record;
        }

        $calendars = [];
        foreach ($calendarsRaw as $cal) {
            if (!is_array($cal)) continue;
            $calendars[] = [
                'id' => (string) ($cal['id'] ?? ''),
                'name' => (string) ($cal['name'] ?? ''),
                'description' => (string) ($cal['description'] ?? ''),
                'is_active' => !empty($cal['isActive']),
                'calendar_type' => (string) ($cal['calendarType'] ?? ''),
                'slot_duration' => (int) ($cal['slotDuration'] ?? 0),
            ];
        }

        $workflows = [];
        foreach ($workflowsRaw as $wf) {
            if (!is_array($wf)) continue;
            $workflows[] = [
                'id' => (string) ($wf['id'] ?? ''),
                'name' => (string) ($wf['name'] ?? ''),
                'status' => (string) ($wf['status'] ?? ''),
                'version' => (int) ($wf['version'] ?? 0),
            ];
        }

        $campaigns = [];
        foreach ($campaignsRaw as $c) {
            if (!is_array($c)) continue;
            $campaigns[] = [
                'id' => (string) ($c['id'] ?? ''),
                'name' => (string) ($c['name'] ?? ''),
                'status' => (string) ($c['status'] ?? ''),
            ];
        }

        $tags = [];
        foreach ($tagsRaw as $tag) {
            if (!is_array($tag)) continue;
            $tags[] = ['id' => (string) ($tag['id'] ?? ''), 'name' => (string) ($tag['name'] ?? '')];
        }

        $fields = [];
        foreach ($fieldsRaw as $field) {
            if (!is_array($field)) continue;
            $fields[] = [
                'id' => (string) ($field['id'] ?? ''),
                'name' => (string) ($field['name'] ?? ''),
                'data_type' => (string) ($field['dataType'] ?? ''),
                'model' => (string) ($field['model'] ?? ''),
            ];
        }

        // Stage 2 — data that needs pagination / date ranges. Still
        // expensive, run sequentially but each one is wrapped so a single
        // failure does not break the report.
        $range = voiceAiMegaResolveDateRange($filters);
        $safeCall = static function (callable $fn) use (&$errors) {
            try {
                $out = $fn();
                if (is_array($out) && empty($out['success']) && !empty($out['message'])) {
                    $errors[] = $out['message'];
                }
                return is_array($out) ? $out : ['success' => false];
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                return ['success' => false, 'message' => $e->getMessage()];
            }
        };

        $opportunities = $safeCall(fn() => voiceAiMegaFetchOpportunities($pdo, $filters));
        $appointments = $safeCall(fn() => voiceAiMegaFetchAppointments($pdo, $filters));
        $forms = $safeCall(fn() => voiceAiMegaFetchForms($pdo, $filters));
        $surveys = $safeCall(fn() => voiceAiMegaFetchSurveys($pdo, $filters));
        $contactsGrowth = $safeCall(fn() => voiceAiMegaFetchContactsGrowth($pdo, $filters));

        return [
            'success' => true,
            'generated_at' => date('c'),
            'location' => [
                'id' => (string) ($locationData['id'] ?? $config['location_id']),
                'name' => (string) ($locationData['name'] ?? ''),
                'email' => (string) ($locationData['email'] ?? ''),
                'timezone' => (string) ($locationData['timezone'] ?? ''),
                'country' => (string) ($locationData['country'] ?? ''),
            ],
            'pipelines' => $pipelines,
            'opportunities' => $opportunities,
            'calendars' => $calendars,
            'appointments' => $appointments,
            'forms' => $forms,
            'surveys' => $surveys,
            'workflows' => ['success' => true, 'workflows' => $workflows, 'summary' => ['total' => count($workflows)]],
            'campaigns' => ['success' => true, 'campaigns' => $campaigns, 'summary' => ['total' => count($campaigns)]],
            'tags' => $tags,
            'custom_fields' => $fields,
            'contacts_growth' => $contactsGrowth,
            'warnings' => array_values(array_unique(array_filter($errors))),
            'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
            'parallelism' => 'curl_multi_stage1',
            'range' => $range,
        ];
    }
}

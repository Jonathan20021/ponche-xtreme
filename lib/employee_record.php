<?php
/**
 * lib/employee_record.php
 *
 * Expediente del colaborador: todo lo que el perfil necesita mostrar y lo que
 * usan las notificaciones y la exportación del expediente.
 *
 * Regla de oro: aquí NO se inventan criterios. La ausencia y la tardanza se
 * definen igual que en los reportes diarios que ya usa la empresa
 * (lib/daily_absence_report.php y lib/daily_tardiness_report.php): misma
 * tolerancia configurada, mismo respaldo de presencia por Vicidial y las mismas
 * justificaciones (permisos, vacaciones y licencias médicas). Si el perfil
 * dijera una cosa y el reporte otra, RRHH no podría usar ninguno de los dos.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/vicidial_report_source.php';

if (!function_exists('employeeTenure')) {
    /**
     * Tiempo laborando en años, meses y días (no solo días).
     *
     * @return array{years:int, months:int, days:int, total_days:int, label:string, short:string}
     */
    function employeeTenure(?string $hireDate, ?string $endDate = null): array
    {
        $empty = ['years' => 0, 'months' => 0, 'days' => 0, 'total_days' => 0, 'label' => 'Sin fecha de ingreso', 'short' => '—'];
        if (empty($hireDate)) {
            return $empty;
        }

        try {
            $start = new DateTimeImmutable($hireDate);
            $end   = new DateTimeImmutable($endDate ?: 'today');
        } catch (Throwable $e) {
            return $empty;
        }

        if ($end < $start) {
            return $empty;
        }

        $diff = $start->diff($end);
        $years  = (int) $diff->y;
        $months = (int) $diff->m;
        $days   = (int) $diff->d;

        $parts = [];
        if ($years > 0)  { $parts[] = $years . ' ' . ($years === 1 ? 'año' : 'años'); }
        if ($months > 0) { $parts[] = $months . ' ' . ($months === 1 ? 'mes' : 'meses'); }
        if ($days > 0 || empty($parts)) { $parts[] = $days . ' ' . ($days === 1 ? 'día' : 'días'); }

        $short = [];
        if ($years > 0)  { $short[] = $years . 'a'; }
        if ($months > 0) { $short[] = $months . 'm'; }
        if ($days > 0 || empty($short)) { $short[] = $days . 'd'; }

        return [
            'years'      => $years,
            'months'     => $months,
            'days'       => $days,
            'total_days' => (int) $start->diff($end)->days,
            'label'      => implode(', ', $parts),
            'short'      => implode(' ', $short),
        ];
    }
}

if (!function_exists('employeeJustificationsForRange')) {
    /**
     * Permisos, vacaciones y licencias médicas aprobados que cubren cada día del
     * rango. Son las MISMAS justificaciones que acepta el reporte de ausencias.
     *
     * @return array<string, array<int,array{type:string, label:string, detail:string}>>
     */
    function employeeJustificationsForRange(PDO $pdo, int $employeeId, string $from, string $to): array
    {
        $byDate = [];

        $add = static function (array &$byDate, string $start, string $end, string $type, string $label, string $detail, string $from, string $to): void {
            $cursor = max($start, $from);
            $limit  = min($end, $to);
            while ($cursor <= $limit) {
                $byDate[$cursor][] = ['type' => $type, 'label' => $label, 'detail' => $detail];
                $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
            }
        };

        // Permisos aprobados
        try {
            $stmt = $pdo->prepare("
                SELECT request_type, start_date, end_date, reason
                FROM permission_requests
                WHERE employee_id = ? AND UPPER(status) IN ('APPROVED','APROBADO')
                  AND start_date <= ? AND COALESCE(end_date, start_date) >= ?
            ");
            $stmt->execute([$employeeId, $to, $from]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $add($byDate, $r['start_date'], $r['end_date'] ?: $r['start_date'], 'PERMISO',
                    'Permiso: ' . ($r['request_type'] ?: 'general'), (string) ($r['reason'] ?? ''), $from, $to);
            }
        } catch (Throwable $e) {
            error_log('employeeJustificationsForRange permisos: ' . $e->getMessage());
        }

        // Vacaciones aprobadas
        try {
            $stmt = $pdo->prepare("
                SELECT start_date, end_date, vacation_type
                FROM vacation_requests
                WHERE employee_id = ? AND UPPER(status) IN ('APPROVED','APROBADO')
                  AND start_date <= ? AND COALESCE(end_date, start_date) >= ?
            ");
            $stmt->execute([$employeeId, $to, $from]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $add($byDate, $r['start_date'], $r['end_date'] ?: $r['start_date'], 'VACACIONES',
                    'Vacaciones', (string) ($r['vacation_type'] ?? ''), $from, $to);
            }
        } catch (Throwable $e) {
            error_log('employeeJustificationsForRange vacaciones: ' . $e->getMessage());
        }

        // Licencias médicas activas
        try {
            $stmt = $pdo->prepare("
                SELECT start_date, end_date, leave_type, diagnosis
                FROM medical_leaves
                WHERE employee_id = ? AND UPPER(status) IN ('APPROVED','APROBADO','ACTIVE','ACTIVA')
                  AND start_date <= ? AND COALESCE(end_date, start_date) >= ?
            ");
            $stmt->execute([$employeeId, $to, $from]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $add($byDate, $r['start_date'], $r['end_date'] ?: $r['start_date'], 'LICENCIA',
                    'Licencia médica', (string) ($r['diagnosis'] ?? $r['leave_type'] ?? ''), $from, $to);
            }
        } catch (Throwable $e) {
            error_log('employeeJustificationsForRange licencias: ' . $e->getMessage());
        }

        return $byDate;
    }
}

if (!function_exists('employeeAttendanceHistory')) {
    /**
     * Historial día por día: si trabajó, si faltó (justificada o no) y si llegó tarde.
     *
     * Un día solo cuenta como laborable si el colaborador tiene horario para ese
     * día de la semana; si no tiene ningún horario configurado se asume lunes a
     * viernes, que es lo que hace el resto del sistema.
     *
     * @return array{
     *   days:array<int,array<string,mixed>>,
     *   summary:array{worked:int,absent:int,absent_justified:int,late:int,late_minutes:int,scheduled:int}
     * }
     */
    function employeeAttendanceHistory(PDO $pdo, int $employeeId, int $userId, string $from, string $to): array
    {
        $result = [
            'days' => [],
            'summary' => [
                'worked' => 0, 'absent' => 0, 'absent_justified' => 0,
                'late' => 0, 'late_minutes' => 0, 'scheduled' => 0,
            ],
        ];

        if ($userId <= 0 || $from > $to) {
            return $result;
        }

        // Tolerancia de tardanza: la misma que usa el reporte diario.
        $tolerance = 10;
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tardiness_report_tolerance_minutes'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null && $val !== '') {
                $tolerance = max(0, (int) $val);
            }
        } catch (Throwable $e) { /* se queda el default */ }

        // Actividad y hora de llegada por día.
        //
        // La LLEGADA es el primer punch de tipo ENTRY, no el primer punch a secas:
        // hay días en que la única marcación es un EXIT suelto (alguien que cerró
        // el día tarde) y tomarlo como hora de entrada inventaba una tardanza de
        // horas. Es el mismo criterio de buildArrivalsForRange() en el reporte.
        $punchStmt = $pdo->prepare("
            SELECT DATE(timestamp) AS d,
                   MIN(CASE WHEN UPPER(type) = 'ENTRY' THEN timestamp END) AS first_entry,
                   COUNT(*) AS punches
            FROM attendance
            WHERE user_id = ? AND DATE(timestamp) BETWEEN ? AND ?
            GROUP BY DATE(timestamp)
        ");
        $punchStmt->execute([$userId, $from, $to]);
        $punchByDate = [];
        foreach ($punchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $punchByDate[$r['d']] = $r;
        }

        // Presencia por Vicidial: para los agentes del discador un día con
        // actividad cuenta como PRESENTE aunque no hayan ponchado. Es la misma
        // fuente y el mismo criterio que getVicidialPresenceSet() del reporte de
        // ausencias (tabla vicidial_agent_timesheet), no una consulta propia.
        $vicidialByDate = [];
        try {
            if (reportsVicidialSourceEnabled($pdo)) {
                $vStmt = $pdo->prepare("
                    SELECT t.report_date, MIN(t.first_login) AS first_login
                    FROM vicidial_agent_timesheet t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE t.user_id = ?
                      AND COALESCE(u.payroll_source, 'manual') = 'vicidial'
                      AND t.report_date BETWEEN ? AND ?
                      AND (t.total_logged_seconds > 0 OR t.nonpause_seconds > 0 OR t.calls > 0)
                    GROUP BY t.report_date
                ");
                $vStmt->execute([$userId, $from, $to]);
                foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    // login_time puede no venir (la hoja de tiempo es otra llamada);
                    // basta con que haya actividad ese día para contarlo presente.
                    $vicidialByDate[$r['report_date']] = $r['first_login'] ?: true;
                }
            }
        } catch (Throwable $e) {
            // Sin datos de Vicidial se cae al ponche, igual que los reportes.
            error_log('employeeAttendanceHistory vicidial: ' . $e->getMessage());
        }

        $justifications = employeeJustificationsForRange($pdo, $employeeId, $from, $to);

        // ¿Tenía horario vigente en cada fecha? Se consulta SIN filtrar por día de
        // la semana: así se distingue "ese día no le tocaba" (tiene horario
        // vigente pero no cubre ese día) de "aún no tenía horario cargado"
        // (períodos viejos), donde hay que caer al lunes-viernes de siempre.
        $coveredDates = [];
        try {
            $cs = $pdo->prepare("
                SELECT effective_date, end_date
                FROM employee_schedules
                WHERE employee_id = ? AND is_active = 1
            ");
            $cs->execute([$employeeId]);
            $scheduleRanges = $cs->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $scheduleRanges = [];
        }

        $hasScheduleOn = static function (string $date) use ($scheduleRanges): bool {
            foreach ($scheduleRanges as $r) {
                $eff = $r['effective_date'] ?? null;
                $end = $r['end_date'] ?? null;
                if (($eff === null || $eff <= $date) && ($end === null || $end >= $date)) {
                    return true;
                }
            }
            return false;
        };

        $today = date('Y-m-d');
        $cursor = $from;

        while ($cursor <= $to) {
            // No se juzga el futuro ni el día en curso (aún puede llegar).
            if ($cursor >= $today) {
                break;
            }

            // OJO: getScheduleConfigForUser() cae al horario GLOBAL cuando el
            // empleado no tiene horario para ese día, así que siempre devuelve
            // entry_time y haría ver el domingo como día laborable. Para saber si
            // ese día le tocaba trabajar hay que mirar sus horarios propios, que
            // ya vienen filtrados por día de la semana.
            $dayOfWeek     = (int) date('N', strtotime($cursor));
            $coveredByPlan = $hasScheduleOn($cursor);
            $daySchedules  = $coveredByPlan ? getEmployeeSchedulesByUserId($pdo, $userId, $cursor) : [];

            // Con horario vigente manda el horario; sin él, lunes a viernes.
            $isWorkday = $coveredByPlan ? !empty($daySchedules) : ($dayOfWeek <= 5);

            // Para MEDIR la tardanza se usa siempre getScheduleConfigForUser(),
            // que cae al horario global cuando la persona no tiene uno propio.
            // Es exactamente lo que hace el reporte diario de tardanzas: si aquí
            // se omitiera ese respaldo, el perfil mostraría menos tardanzas que
            // el reporte para la misma fecha.
            $schedule = getScheduleConfigForUser($pdo, $userId, $cursor);

            $punch     = $punchByDate[$cursor] ?? null;
            $vicLogin  = $vicidialByDate[$cursor] ?? null;
            $hasWorked = ($punch !== null) || ($vicLogin !== null);

            // Un día no laborable en el que sí trabajó igual se muestra como trabajado.
            if (!$isWorkday && !$hasWorked) {
                $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
                continue;
            }

            if ($isWorkday) {
                $result['summary']['scheduled']++;
            }

            $entry = [
                'date'          => $cursor,
                'is_workday'    => $isWorkday,
                'worked'        => $hasWorked,
                'arrival'       => null,
                'arrival_source'=> null,
                'scheduled_entry' => $schedule['entry_time'] ?? null,
                'late_minutes'  => 0,
                'status'        => 'TRABAJADO',
                'justification' => $justifications[$cursor] ?? [],
                'punches'       => $punch ? (int) $punch['punches'] : 0,
            ];

            if ($hasWorked) {
                $result['summary']['worked']++;

                // Llegada: el login de Vicidial manda para el agente del discador
                // (automático); si no hay, el primer punch del ponche.
                if ($vicLogin !== null && $vicLogin !== true) {
                    $entry['arrival'] = $vicLogin;
                    $entry['arrival_source'] = 'vicidial';
                } elseif ($punch !== null && !empty($punch['first_entry'])) {
                    $entry['arrival'] = $punch['first_entry'];
                    $entry['arrival_source'] = 'ponche';
                }

                // Tardanza: solo si hay horario contra el cual medir.
                if (!empty($schedule['entry_time']) && $entry['arrival']) {
                    $scheduledTs = strtotime($cursor . ' ' . $schedule['entry_time']);
                    $actualTs    = strtotime($entry['arrival']);
                    if ($scheduledTs !== false && $actualTs !== false) {
                        $diff = $actualTs - $scheduledTs;
                        if ($diff > $tolerance * 60) {
                            $entry['late_minutes'] = (int) round($diff / 60);
                            $entry['status'] = 'TARDANZA';
                            $result['summary']['late']++;
                            $result['summary']['late_minutes'] += $entry['late_minutes'];
                        }
                    }
                }
            } else {
                // No trabajó un día laborable: ausencia.
                if (!empty($entry['justification'])) {
                    $entry['status'] = 'AUSENCIA_JUSTIFICADA';
                    $result['summary']['absent_justified']++;
                } else {
                    $entry['status'] = 'AUSENCIA';
                    $result['summary']['absent']++;
                }
            }

            // Solo se guardan los días con algo que contar: trabajados sin novedad
            // no aportan al historial y llenarían la vista de ruido.
            if ($entry['status'] !== 'TRABAJADO') {
                $result['days'][] = $entry;
            }

            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }

        // Más reciente primero
        usort($result['days'], static fn($a, $b) => strcmp($b['date'], $a['date']));

        return $result;
    }
}

if (!function_exists('employeeCampaignHistory')) {
    /**
     * Todas las campañas por las que ha pasado el colaborador.
     *
     * @return array<int,array<string,mixed>>
     */
    function employeeCampaignHistory(PDO $pdo, int $employeeId): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT ec.id, ec.campaign_id, ec.is_primary, ec.start_date, ec.end_date,
                       ec.notes, ec.created_at,
                       c.name AS campaign_name, c.code AS campaign_code, c.color AS campaign_color,
                       u.full_name AS assigned_by_name
                FROM employee_campaigns ec
                LEFT JOIN campaigns c ON c.id = ec.campaign_id
                LEFT JOIN users u ON u.id = ec.assigned_by
                WHERE ec.employee_id = ?
                ORDER BY ec.end_date IS NULL DESC, ec.start_date DESC, ec.id DESC
            ");
            $stmt->execute([$employeeId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('employeeCampaignHistory: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('employeeActiveCampaigns')) {
    /** Campañas vigentes (sin fecha de fin). */
    function employeeActiveCampaigns(PDO $pdo, int $employeeId): array
    {
        return array_values(array_filter(
            employeeCampaignHistory($pdo, $employeeId),
            static fn(array $r) => empty($r['end_date'])
        ));
    }
}

if (!function_exists('employeeWarnings')) {
    /**
     * Amonestaciones del colaborador.
     *
     * @return array<int,array<string,mixed>>
     */
    function employeeWarnings(PDO $pdo, int $employeeId, int $limit = 50): array
    {
        try {
            $limit = max(1, min(200, $limit));
            $stmt = $pdo->prepare("
                SELECT w.*, u.full_name AS issued_by_name
                FROM employee_warnings w
                LEFT JOIN users u ON u.id = w.issued_by
                WHERE w.employee_id = ?
                ORDER BY w.incident_date DESC, w.id DESC
                LIMIT $limit
            ");
            $stmt->execute([$employeeId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('employeeWarnings: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('employeeMedicalLeaves')) {
    /** Licencias médicas del colaborador. */
    function employeeMedicalLeaves(PDO $pdo, int $employeeId, int $limit = 50): array
    {
        try {
            $limit = max(1, min(200, $limit));
            $stmt = $pdo->prepare("
                SELECT ml.*, u.full_name AS reviewed_by_name
                FROM medical_leaves ml
                LEFT JOIN users u ON u.id = ml.reviewed_by
                WHERE ml.employee_id = ?
                ORDER BY ml.start_date DESC, ml.id DESC
                LIMIT $limit
            ");
            $stmt->execute([$employeeId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('employeeMedicalLeaves: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('employeeRequiredDocumentsStatus')) {
    /**
     * Estado del expediente documental: qué documentos obligatorios tiene y
     * cuáles le faltan.
     *
     * El cruce se hace por `doc_key` y, para lo ya cargado antes de esta función,
     * por los `aliases` (los document_type que RRHH venía usando). Así los 631
     * documentos que ya existían siguen contando y el expediente no aparece
     * vacío el primer día.
     *
     * @return array{
     *   items:array<int,array<string,mixed>>,
     *   total:int, present:int, missing:int, pct:int,
     *   missing_labels:array<int,string>, is_complete:bool
     * }
     */
    function employeeRequiredDocumentsStatus(PDO $pdo, int $employeeId): array
    {
        $out = ['items' => [], 'total' => 0, 'present' => 0, 'missing' => 0, 'pct' => 100,
                'missing_labels' => [], 'is_complete' => true];

        try {
            $required = $pdo->query("
                SELECT id, doc_key, label, aliases, name_patterns, is_required, requires_signature, sort_order
                FROM required_document_types
                WHERE is_active = 1
                ORDER BY sort_order, label
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('employeeRequiredDocumentsStatus: ' . $e->getMessage());
            return $out;
        }

        if (empty($required)) {
            return $out;
        }

        // Documentos ya cargados del colaborador
        $docs = [];
        try {
            $stmt = $pdo->prepare("
                SELECT id, doc_key, document_type, document_name, file_path, uploaded_at, signature_id
                FROM employee_documents
                WHERE employee_id = ?
                ORDER BY uploaded_at DESC, id DESC
            ");
            $stmt->execute([$employeeId]);
            $docs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('employeeRequiredDocumentsStatus docs: ' . $e->getMessage());
        }

        // Firmas electrónicas pendientes/completadas
        $signatures = [];
        try {
            $stmt = $pdo->prepare("
                SELECT doc_key, status, signed_at, token
                FROM employee_document_signatures
                WHERE employee_id = ?
                ORDER BY id DESC
            ");
            $stmt->execute([$employeeId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
                if (!isset($signatures[$s['doc_key']])) {
                    $signatures[$s['doc_key']] = $s;
                }
            }
        } catch (Throwable $e) { /* la tabla puede no existir aún */ }

        $normalize = static function (string $v): string {
            $v = trim(mb_strtolower($v));
            if (function_exists('iconv')) {
                $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
                if ($conv !== false && $conv !== null) { $v = $conv; }
            }
            return preg_replace('/[^a-z0-9]+/', '', $v) ?? $v;
        };

        foreach ($required as $req) {
            $out['total']++;

            $aliases = array_filter(array_map('trim', explode(',', (string) ($req['aliases'] ?? ''))));
            $aliasKeys = array_map($normalize, $aliases);
            $aliasKeys[] = $normalize($req['label']);

            // Patrones a buscar DENTRO del nombre del archivo. Hacen falta porque
            // RRHH archiva mucho bajo tipos genéricos ("Otros Documentos") y el
            // nombre real está en el archivo: sin esto, un expediente completo
            // aparecía como incompleto y disparaba avisos falsos.
            $namePatterns = array_values(array_filter(array_map(
                $normalize,
                array_map('trim', explode(',', (string) ($req['name_patterns'] ?? '')))
            )));

            $found = null;
            foreach ($docs as $doc) {
                // 1. Vínculo explícito (lo más confiable)
                if (!empty($doc['doc_key']) && $doc['doc_key'] === $req['doc_key']) {
                    $found = $doc;
                    break;
                }
                // 2. El tipo de documento coincide con la etiqueta o un alias
                if (in_array($normalize((string) $doc['document_type']), $aliasKeys, true)) {
                    $found = $doc;
                    break;
                }
                // 3. El nombre del archivo contiene alguno de los patrones
                if (!empty($namePatterns)) {
                    $docName = $normalize((string) ($doc['document_name'] ?? ''));
                    if ($docName !== '') {
                        foreach ($namePatterns as $pattern) {
                            if ($pattern !== '' && strpos($docName, $pattern) !== false) {
                                $found = $doc;
                                break 2;
                            }
                        }
                    }
                }
            }

            $signature = $signatures[$req['doc_key']] ?? null;

            $item = [
                'doc_key'            => $req['doc_key'],
                'label'              => $req['label'],
                'is_required'        => (int) $req['is_required'] === 1,
                'requires_signature' => (int) $req['requires_signature'] === 1,
                'present'            => $found !== null,
                'document_id'        => $found['id'] ?? null,
                'document_name'      => $found['document_name'] ?? null,
                'file_path'          => $found['file_path'] ?? null,
                'uploaded_at'        => $found['uploaded_at'] ?? null,
                'signature_status'   => $signature['status'] ?? null,
                'signed_at'          => $signature['signed_at'] ?? null,
                'signature_token'    => $signature['token'] ?? null,
            ];

            if ($item['present']) {
                $out['present']++;
            } elseif ($item['is_required']) {
                $out['missing']++;
                $out['missing_labels'][] = $req['label'];
            }

            $out['items'][] = $item;
        }

        $requiredTotal = count(array_filter($out['items'], static fn($i) => $i['is_required']));
        $requiredPresent = count(array_filter($out['items'], static fn($i) => $i['is_required'] && $i['present']));
        $out['pct'] = $requiredTotal > 0 ? (int) round($requiredPresent * 100 / $requiredTotal) : 100;
        $out['is_complete'] = ($out['missing'] === 0);

        return $out;
    }
}

if (!function_exists('employeePersonalDataStatus')) {
    /**
     * Campos de información personal que faltan por llenar. El cliente pidió
     * avisar tanto por documentos como por datos personales incompletos.
     *
     * @return array{missing:array<int,string>, total:int, complete:int, is_complete:bool, pct:int}
     */
    function employeePersonalDataStatus(array $employee): array
    {
        // Lo mínimo para poder contratar, pagar y contactar a alguien.
        $fields = [
            'first_name'              => 'Nombre',
            'last_name'               => 'Apellido',
            'id_card_number'          => 'Cédula',
            'birth_date'              => 'Fecha de nacimiento',
            'phone'                   => 'Teléfono',
            'email'                   => 'Correo',
            'address'                 => 'Dirección',
            'hire_date'               => 'Fecha de ingreso',
            'position'                => 'Posición',
            'department_id'           => 'Departamento',
            'emergency_contact_name'  => 'Contacto de emergencia',
            'emergency_contact_phone' => 'Teléfono de emergencia',
            'bank_id'                 => 'Banco',
            'bank_account_number'     => 'Número de cuenta',
        ];

        $missing = [];
        foreach ($fields as $key => $label) {
            $value = $employee[$key] ?? null;
            if ($value === null || $value === '' || $value === '0') {
                $missing[] = $label;
            }
        }

        $total = count($fields);
        $complete = $total - count($missing);

        return [
            'missing'     => $missing,
            'total'       => $total,
            'complete'    => $complete,
            'is_complete' => empty($missing),
            'pct'         => $total > 0 ? (int) round($complete * 100 / $total) : 100,
        ];
    }
}

if (!function_exists('employeeTerminationLabels')) {
    /** Catálogos de motivo de salida y elegibilidad de recontratación. */
    function employeeTerminationLabels(): array
    {
        return [
            'reasons' => [
                'DESAHUCIO'     => 'Desahucio',
                'DESPIDO'       => 'Despido',
                'ABANDONO'      => 'Abandono',
                'RENUNCIA'      => 'Renuncia',
                'FIN_CONTRATO'  => 'Fin de contrato',
                'MUTUO_ACUERDO' => 'Mutuo acuerdo',
            ],
            'rehire' => [
                'ELIGIBLE'             => 'Elegible para recontratación',
                'REQUIERE_EVALUACION'  => 'Requiere evaluación previa',
                'NO_ELEGIBLE'          => 'No debe ser considerado',
            ],
        ];
    }
}

if (!function_exists('employeeWarningLabels')) {
    /** Catálogos de amonestaciones. */
    function employeeWarningLabels(): array
    {
        return [
            'types' => [
                'VERBAL'               => 'Amonestación verbal',
                'ESCRITA'              => 'Amonestación escrita',
                'SUSPENSION'           => 'Suspensión',
                'ULTIMA_AMONESTACION'  => 'Última amonestación',
            ],
            'severities' => [
                'LEVE'      => 'Leve',
                'GRAVE'     => 'Grave',
                'MUY_GRAVE' => 'Muy grave',
            ],
            'statuses' => [
                'ACTIVA'   => 'Activa',
                'CUMPLIDA' => 'Cumplida',
                'ANULADA'  => 'Anulada',
            ],
        ];
    }
}

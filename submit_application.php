<?php
// Handle compact job application submission (with optional CV) and trigger AI enrichment.
session_start();
require_once 'db.php';
require_once __DIR__ . '/lib/recruitment_ai.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
        exit;
    }

    if (!is_dir(__DIR__ . '/logs')) {
        @mkdir(__DIR__ . '/logs', 0755, true);
    }

    // Blindaje de codificacion. Cuando el candidato pega texto desde Word o desde un
    // PDF pueden llegar bytes que no son UTF-8 valido. json_encode() devuelve false
    // ante ellos y el snapshot del formulario se guardaba VACIO: Reclutamiento veia
    // "N/A" en todos los campos aunque el candidato si los hubiera llenado.
    $sanitizeUtf8 = function ($value) use (&$sanitizeUtf8) {
        if (is_array($value)) {
            return array_map($sanitizeUtf8, $value);
        }
        if (!is_string($value) || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        // Windows-1252 / Latin-1 es el origen habitual de esos bytes
        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }
        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    };
    $_POST = $sanitizeUtf8($_POST);

    // Compact field set
    $nombres            = trim($_POST['nombres'] ?? '');
    $apellidos          = trim($_POST['apellidos'] ?? '');
    $cedula             = trim($_POST['cedula'] ?? '');
    $telefono           = trim($_POST['telefono'] ?? '');
    $email              = trim($_POST['email'] ?? '');
    $direccion          = trim($_POST['direccion'] ?? '');
    $current_position   = trim($_POST['current_position'] ?? '');
    $current_company    = trim($_POST['current_company'] ?? '');
    $years_experience   = trim($_POST['years_of_experience'] ?? '');
    $expected_salary    = trim($_POST['expected_salary'] ?? '');
    $education_level    = trim($_POST['education_level'] ?? '');
    $availability_pref  = trim($_POST['availability_preference'] ?? '');
    $availability_details = trim($_POST['availability_details'] ?? '');
    $transport_method   = trim($_POST['transport_method'] ?? '');
    $transport_details  = trim($_POST['transport_details'] ?? '');
    $currently_studying = trim($_POST['currently_studying'] ?? '');
    $study_subject      = trim($_POST['study_subject'] ?? '');
    $study_place        = trim($_POST['study_place'] ?? '');
    $study_schedule     = trim($_POST['study_schedule'] ?? '');
    $other_commitments  = trim($_POST['other_commitments'] ?? '');
    $other_commitments_details = trim($_POST['other_commitments_details'] ?? '');
    $overtime_available = trim($_POST['overtime_available'] ?? '');
    $weekend_holiday_available = trim($_POST['weekend_holiday_available'] ?? '');
    $role_interest      = trim($_POST['role_interest'] ?? '');
    $source             = trim($_POST['source'] ?? '');
    $linkedin_url       = trim($_POST['linkedin_url'] ?? '');
    $puesto_aplicado    = trim($_POST['puesto_aplicado'] ?? '');
    $acepta             = !empty($_POST['acepta_datos']) ? 'SI' : 'NO';
    $form_version       = trim($_POST['form_version'] ?? '2026-07-23-extended');

    // Datos personales que RRHH necesita para la validación inicial y que muchos
    // candidatos no traen en el CV.
    $fecha_nacimiento   = trim($_POST['fecha_nacimiento'] ?? '');
    $nacionalidad       = trim($_POST['nacionalidad'] ?? '');
    $estado_civil       = trim($_POST['estado_civil'] ?? '');
    $tipo_sangre        = trim($_POST['tipo_sangre'] ?? '');
    $sexo               = trim($_POST['sexo'] ?? '');
    $estatura           = trim($_POST['estatura'] ?? '');
    $peso               = trim($_POST['peso'] ?? '');
    $vive_con           = trim($_POST['vive_con'] ?? '');
    $personas_vive      = trim($_POST['personas_vive'] ?? '');
    $personas_dependen  = trim($_POST['personas_dependen'] ?? '');
    $tiene_hijos        = trim($_POST['tiene_hijos'] ?? '');
    $cantidad_hijos     = trim($_POST['cantidad_hijos'] ?? '');
    $edad_hijos         = trim($_POST['edad_hijos'] ?? '');
    $casa_propia        = trim($_POST['casa_propia'] ?? '');

    // La edad se recalcula en el servidor: el campo del formulario es sólo lectura
    // y no queremos que un valor manipulado llegue a la ficha de Reclutamiento.
    $dob_sql = null;
    $edad    = '';
    if ($fecha_nacimiento !== '') {
        $dob = DateTime::createFromFormat('Y-m-d', $fecha_nacimiento);
        if ($dob && $dob->format('Y-m-d') === $fecha_nacimiento) {
            $today = new DateTime('today');
            if ($dob < $today) {
                $dob_sql = $fecha_nacimiento;
                $edad = (string) $dob->diff($today)->y;
            }
        }
    }

    // Rol de interés (reemplaza la antigua pregunta "por qué deberíamos contratarte")
    $allowed_roles = ['Inglés', 'Español', 'APPOINT'];
    if ($role_interest !== '' && !in_array($role_interest, $allowed_roles, true)) {
        $role_interest = '';
    }

    // Cursos e idiomas: filas repetibles del formulario -> se guardan tal cual las
    // renderiza hr/view_application.php.
    $normalizeRows = function ($rows, array $fields) {
        $out = [];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            $hasData = false;
            foreach ($fields as $f) {
                $v = trim((string) ($row[$f] ?? ''));
                $clean[$f] = $v;
                if ($v !== '') {
                    $hasData = true;
                }
            }
            if ($hasData) {
                $out[] = $clean;
            }
        }
        return $out;
    };
    $cursos  = $normalizeRows($_POST['cursos']  ?? [], ['curso', 'institucion', 'fecha']);
    $idiomas = $normalizeRows($_POST['idiomas'] ?? [], ['idioma', 'habla', 'lee', 'escribe']);

    // Required fields
    $required = [
        'nombres'      => $nombres,
        'apellidos'    => $apellidos,
        'cedula'       => $cedula,
        'telefono'     => $telefono,
        'acepta_datos' => $acepta === 'SI' ? 'OK' : '',
    ];
    foreach ($required as $key => $val) {
        if ($val === '') {
            echo json_encode(['success' => false, 'message' => "El campo $key es obligatorio."]);
            exit;
        }
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Correo electrónico inválido.']);
        exit;
    }
    if ($linkedin_url !== '' && !filter_var($linkedin_url, FILTER_VALIDATE_URL)) {
        // Allow it but normalize: warn-only — keep as-is
        $linkedin_url = $linkedin_url;
    }

    // Resolve and validate job_posting_id
    $job_posting_id = $_POST['job_posting_id'] ?? null;
    if (empty($job_posting_id) && !empty($_SERVER['HTTP_REFERER'])) {
        $ref = parse_url($_SERVER['HTTP_REFERER']);
        if (!empty($ref['query'])) {
            parse_str($ref['query'], $q);
            if (!empty($q['job'])) {
                $job_posting_id = $q['job'];
            }
        }
    }
    if (empty($job_posting_id)) {
        $job_posting_id = $pdo->query("
            SELECT id
            FROM job_postings
            WHERE status = 'active' AND (closing_date IS NULL OR closing_date >= CURDATE())
            ORDER BY posted_date DESC
            LIMIT 1
        ")->fetchColumn();
    }
    if (empty($job_posting_id)) {
        echo json_encode(['success' => false, 'message' => 'No hay vacantes activas para asociar tu solicitud.']);
        exit;
    }
    $job_posting_id = (int) $job_posting_id;

    $jobCheck = $pdo->prepare("
        SELECT id, title
        FROM job_postings
        WHERE id = ? AND status = 'active' AND (closing_date IS NULL OR closing_date >= CURDATE())
    ");
    $jobCheck->execute([$job_posting_id]);
    $selectedJob = $jobCheck->fetch(PDO::FETCH_ASSOC);
    if (!$selectedJob) {
        echo json_encode(['success' => false, 'message' => 'La vacante seleccionada ya no esta disponible.']);
        exit;
    }

    // CV upload (required)
    $cv_filename = null;
    $cv_path = null;
    if (!isset($_FILES['cv_file']) || $_FILES['cv_file']['error'] === UPLOAD_ERR_NO_FILE) {
        echo json_encode(['success' => false, 'message' => 'Debes adjuntar tu currículo (PDF, DOC o DOCX).']);
        exit;
    }
    if ($_FILES['cv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Error al subir el CV.']);
        exit;
    }
    $allowed = ['pdf', 'doc', 'docx'];
    $ext = strtolower(pathinfo($_FILES['cv_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Formato no permitido. Sólo PDF, DOC o DOCX.']);
        exit;
    }
    if ($_FILES['cv_file']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'CV demasiado grande (máx. 5MB).']);
        exit;
    }
    $upload_dir = __DIR__ . '/uploads/cvs/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
        echo json_encode(['success' => false, 'message' => 'No se pudo preparar la carpeta de CVs.']);
        exit;
    }
    $cv_filename = uniqid('cv_') . '_' . time() . '.' . $ext;
    $cv_path = 'uploads/cvs/' . $cv_filename;
    $cv_absolute_path = $upload_dir . $cv_filename;
    if (!move_uploaded_file($_FILES['cv_file']['tmp_name'], $cv_absolute_path)) {
        echo json_encode(['success' => false, 'message' => 'No se pudo guardar el CV.']);
        exit;
    }

    // Generate unique application code
    $application_code = null;
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $timestamp = substr(str_replace('.', '', microtime(true)), -6);
        $random = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $candidate = 'APP-' . $random . $timestamp . '-' . date('Y');
        $check = $pdo->prepare("SELECT COUNT(*) FROM job_applications WHERE application_code = ?");
        $check->execute([$candidate]);
        if ($check->fetchColumn() == 0) {
            $application_code = $candidate;
            break;
        }
        usleep(100);
    }
    if (!$application_code) {
        throw new Exception('No se pudo generar un código único de solicitud.');
    }

    // Map schedule availability into the legacy "disponibilidad" renderer keys
    $availLabels = [
        'rotating' => 'Turno rotativo',
        'weekdays' => 'Solo Lunes a Viernes',
        'weekends' => 'Fines de semana',
        'flexible' => 'Flexible / Tiempo completo',
    ];
    $dispOtroParts = [];
    if (!in_array($availability_pref, ['rotating', 'weekdays'], true) && $availability_pref !== '') {
        $dispOtroParts[] = $availLabels[$availability_pref] ?? $availability_pref;
    }
    if ($availability_details !== '') {
        $dispOtroParts[] = $availability_details;
    }

    // Map transport into the legacy "transporte" renderer keys
    $transportOtroTexto = '';
    if ($transport_method === 'propio') {
        $transportOtroTexto = 'Vehículo propio';
    } elseif ($transport_method === 'otro') {
        $transportOtroTexto = 'Otro';
    }

    // Persist a compact JSON snapshot in cover_letter (so view_application.php form-payload renderers keep working)
    $formPayload = [
        'form_version'      => $form_version,
        'puesto_aplicado'   => $puesto_aplicado,
        'rol_interes'       => $role_interest,
        'nombres'           => $nombres,
        'apellido_paterno'  => $apellidos,   // map for legacy renderer
        'apellido_materno'  => '',
        'cedula'            => $cedula,
        'telefono'          => $telefono,
        'direccion'         => $direccion,
        'email'             => $email,
        'fecha_nacimiento'  => $dob_sql ?? $fecha_nacimiento,
        'edad'              => $edad,
        'nacionalidad'      => $nacionalidad,
        'estado_civil'      => $estado_civil,
        'tipo_sangre'       => $tipo_sangre,
        'sexo'              => $sexo,
        'estatura'          => $estatura,
        'peso'              => $peso,
        'vive_con'          => $vive_con,
        'personas_vive'     => $personas_vive,
        'personas_dependen' => $personas_dependen,
        'tiene_hijos'       => $tiene_hijos,
        'cantidad_hijos'    => $cantidad_hijos,
        'edad_hijos'        => $edad_hijos,
        'casa_propia'       => $casa_propia,
        'idiomas'           => $idiomas,
        'experiencias'      => [
            ['empresa' => $current_company, 'cargo' => $current_position, 'tiempo' => $years_experience, 'sueldo' => '', 'tareas' => '', 'razon_salida' => '']
        ],
        'disponibilidad'    => [
            'turno_rotativo' => $availability_pref === 'rotating' ? 'SI' : 'NO',
            'lunes_viernes'  => $availability_pref === 'weekdays' ? 'SI' : 'NO',
            'otro'           => !empty($dispOtroParts) ? 'SI' : 'NO',
            'otro_texto'     => implode(' — ', $dispOtroParts),
        ],
        'transporte'        => [
            'carro_publico' => $transport_method === 'publico' ? 'SI' : 'NO',
            'motoconcho'    => $transport_method === 'motoconcho' ? 'SI' : 'NO',
            'a_pie'         => $transport_method === 'a_pie' ? 'SI' : 'NO',
            'otro'          => in_array($transport_method, ['propio', 'otro'], true) ? 'SI' : 'NO',
            'otro_texto'    => $transportOtroTexto,
            'detalles'      => $transport_details,
        ],
        'adicional'         => [
            'expectativas_salariales' => $expected_salary,
            'medio_vacante'           => $source !== '' ? [$source] : [],
            'horas_extras'            => $overtime_available,
            'dias_fiestas'            => $weekend_holiday_available,
            'otro_empleo'             => $other_commitments,
            'otro_empleo_detalle'     => $other_commitments_details,
            'firma'                   => trim($nombres . ' ' . $apellidos),
            'acepta_datos'            => $acepta,
        ],
        'educacion'         => [
            'nivel'               => $education_level !== '' ? [$education_level] : [],
            'estudia_actualmente' => $currently_studying !== '' ? $currently_studying : 'NO',
            'que_estudia'         => $study_subject,
            'donde_estudia'       => $study_place,
            'horario_clases'      => $study_schedule,
            'otros_cursos'        => $cursos,
        ],
    ];

    // Segunda red de seguridad: preferimos guardar el formulario con algun caracter
    // sustituido antes que perderlo entero.
    $coverLetterJson = json_encode($formPayload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($coverLetterJson === false) {
        error_log('submit_application: json_encode del formulario fallo (' . json_last_error_msg() . ')');
        $coverLetterJson = json_encode($formPayload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    if ($coverLetterJson === false) {
        $coverLetterJson = null;
    }

    $insert = $pdo->prepare("
        INSERT INTO job_applications (
            application_code, job_posting_id, first_name, last_name, email, phone,
            address, date_of_birth, education_level, years_of_experience,
            current_position, current_company, expected_salary, cv_filename, cv_path,
            cover_letter, linkedin_url, status, cedula, source, availability_preference,
            role_interest
        ) VALUES (
            :application_code, :job_posting_id, :first_name, :last_name, :email, :phone,
            :address, :date_of_birth, :education_level, :years_of_experience,
            :current_position, :current_company, :expected_salary, :cv_filename, :cv_path,
            :cover_letter, :linkedin_url, 'new', :cedula, :source, :availability_preference,
            :role_interest
        )
    ");

    $insert->execute([
        'application_code'        => $application_code,
        'job_posting_id'          => $job_posting_id,
        'first_name'              => $nombres,
        'last_name'               => $apellidos,
        'email'                   => $email !== '' ? $email : 'sin-correo@evallish.local',
        'phone'                   => $telefono,
        'address'                 => $direccion !== '' ? $direccion : null,
        'education_level'         => $education_level !== '' ? $education_level : null,
        'years_of_experience'     => $years_experience !== '' ? (int) $years_experience : null,
        'current_position'        => $current_position !== '' ? $current_position : null,
        'current_company'         => $current_company !== '' ? $current_company : null,
        'expected_salary'         => $expected_salary !== '' ? $expected_salary : null,
        'cv_filename'             => $cv_filename,
        'cv_path'                 => $cv_path,
        'cover_letter'            => $coverLetterJson,
        'linkedin_url'            => $linkedin_url !== '' ? $linkedin_url : null,
        'cedula'                  => $cedula,
        'source'                  => $source !== '' ? $source : null,
        'availability_preference' => $availability_pref !== '' ? $availability_pref : null,
        'date_of_birth'           => $dob_sql,
        'role_interest'           => $role_interest !== '' ? $role_interest : null,
    ]);

    $application_id = (int) $pdo->lastInsertId();

    // Status history seed
    $history = $pdo->prepare("INSERT INTO application_status_history (application_id, old_status, new_status, notes) VALUES (?, NULL, 'new', 'Solicitud recibida')");
    $history->execute([$application_id]);

    // Confirmation email
    try {
        require_once 'lib/email_functions.php';
        $job_title = $selectedJob['title'] ?? '';
        if ($email !== '' && function_exists('sendApplicationConfirmationEmail')) {
            sendApplicationConfirmationEmail([
                'email'            => $email,
                'first_name'       => $nombres,
                'last_name'        => $apellidos,
                'application_code' => $application_code,
                'job_title'        => $job_title,
                'applied_date'     => date('d/m/Y'),
                'positions_count'  => 1,
            ]);
        }
    } catch (Throwable $emailEx) {
        error_log('Confirmation email error: ' . $emailEx->getMessage());
    }

    // Fire-and-forget AI enrichment if enabled (best-effort, don't block response too long)
    $aiResult = ['success' => false, 'score' => null];
    try {
        $aiCfg = getRecruitmentAIConfig($pdo);
        if (!empty($aiCfg['recruitment_ai_enabled']) && $aiCfg['recruitment_ai_enabled'] !== '0') {
            // Only run inline if there's a CV (PDF). Otherwise still attempt screening from form data.
            $aiResult = processApplicationAI($pdo, $application_id);

            // Optional: auto-shortlist on high score
            if (!empty($aiResult['score']) && $aiResult['score'] >= (int) ($aiCfg['recruitment_ai_min_score_shortlist'] ?? 75)) {
                $upd = $pdo->prepare("UPDATE job_applications SET status = 'shortlisted' WHERE id = ? AND status = 'new'");
                $upd->execute([$application_id]);
                $hist2 = $pdo->prepare("INSERT INTO application_status_history (application_id, old_status, new_status, notes) VALUES (?, 'new', 'shortlisted', 'Auto-preseleccionado por IA (score alto)')");
                $hist2->execute([$application_id]);
            }
        }
    } catch (Throwable $aiEx) {
        error_log('Recruitment AI inline processing error: ' . $aiEx->getMessage());
    }

    // Enlace público de seguimiento: se le muestra al candidato al terminar y
    // Reclutamiento puede reenviárselo desde la ficha de la postulación.
    $trackingUrl = '';
    try {
        $emailCfg = require __DIR__ . '/config/email_config.php';
        if (!empty($emailCfg['app_url'])) {
            $trackingUrl = rtrim($emailCfg['app_url'], '/') . '/track_application.php?code=' . urlencode($application_code);
        }
    } catch (Throwable $cfgEx) {
        error_log('tracking url config error: ' . $cfgEx->getMessage());
    }

    echo json_encode([
        'success'          => true,
        'message'          => 'Solicitud enviada exitosamente.',
        'application_code' => $application_code,
        'application_id'   => $application_id,
        'tracking_url'     => $trackingUrl,
        'ai'               => [
            'processed' => (bool) ($aiResult['success'] ?? false),
            'score'     => $aiResult['score'] ?? null,
        ],
    ]);

} catch (PDOException $e) {
    error_log('submit_application PDO error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'No se pudo registrar la solicitud en este momento.']);
} catch (Throwable $e) {
    error_log('submit_application error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error inesperado al enviar la solicitud.']);
}

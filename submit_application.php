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
    $cover_letter_short = trim($_POST['cover_letter_short'] ?? '');
    $source             = trim($_POST['source'] ?? '');
    $linkedin_url       = trim($_POST['linkedin_url'] ?? '');
    $puesto_aplicado    = trim($_POST['puesto_aplicado'] ?? '');
    $acepta             = !empty($_POST['acepta_datos']) ? 'SI' : 'NO';
    $form_version       = trim($_POST['form_version'] ?? '2026-05-03-compact');

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

    // Resolve job_posting_id
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
        $job_posting_id = $pdo->query("SELECT id FROM job_postings WHERE status = 'active' ORDER BY posted_date DESC LIMIT 1")->fetchColumn();
    }
    if (empty($job_posting_id)) {
        echo json_encode(['success' => false, 'message' => 'No hay vacantes activas para asociar tu solicitud.']);
        exit;
    }
    $job_posting_id = (int) $job_posting_id;

    // CV upload (optional)
    $cv_filename = null;
    $cv_path = null;
    if (isset($_FILES['cv_file']) && $_FILES['cv_file']['error'] !== UPLOAD_ERR_NO_FILE) {
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
        $upload_dir = 'uploads/cvs/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $cv_filename = uniqid('cv_') . '_' . time() . '.' . $ext;
        $cv_path = $upload_dir . $cv_filename;
        if (!move_uploaded_file($_FILES['cv_file']['tmp_name'], $cv_path)) {
            echo json_encode(['success' => false, 'message' => 'No se pudo guardar el CV.']);
            exit;
        }
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

    // Persist a compact JSON snapshot in cover_letter (so view_application.php form-payload renderers keep working)
    $formPayload = [
        'form_version'      => $form_version,
        'puesto_aplicado'   => $puesto_aplicado,
        'nombres'           => $nombres,
        'apellido_paterno'  => $apellidos,   // map for legacy renderer
        'apellido_materno'  => '',
        'cedula'            => $cedula,
        'telefono'          => $telefono,
        'direccion'         => $direccion,
        'email'             => $email,
        'mensaje'           => $cover_letter_short,
        'experiencias'      => [
            ['empresa' => $current_company, 'cargo' => $current_position, 'tiempo' => $years_experience, 'sueldo' => '', 'tareas' => '', 'razon_salida' => '']
        ],
        'adicional'         => [
            'expectativas_salariales' => $expected_salary,
            'medio_vacante'           => $source !== '' ? [$source] : [],
            'firma'                   => trim($nombres . ' ' . $apellidos),
            'acepta_datos'            => $acepta,
        ],
        'educacion'         => [
            'nivel' => $education_level !== '' ? [$education_level] : [],
        ],
    ];

    $insert = $pdo->prepare("
        INSERT INTO job_applications (
            application_code, job_posting_id, first_name, last_name, email, phone,
            address, date_of_birth, education_level, years_of_experience,
            current_position, current_company, expected_salary, cv_filename, cv_path,
            cover_letter, linkedin_url, status, cedula, source, availability_preference
        ) VALUES (
            :application_code, :job_posting_id, :first_name, :last_name, :email, :phone,
            :address, NULL, :education_level, :years_of_experience,
            :current_position, :current_company, :expected_salary, :cv_filename, :cv_path,
            :cover_letter, :linkedin_url, 'new', :cedula, :source, :availability_preference
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
        'cover_letter'            => json_encode($formPayload, JSON_UNESCAPED_UNICODE),
        'linkedin_url'            => $linkedin_url !== '' ? $linkedin_url : null,
        'cedula'                  => $cedula,
        'source'                  => $source !== '' ? $source : null,
        'availability_preference' => $availability_pref !== '' ? $availability_pref : null,
    ]);

    $application_id = (int) $pdo->lastInsertId();

    // Status history seed
    $history = $pdo->prepare("INSERT INTO application_status_history (application_id, old_status, new_status, notes) VALUES (?, NULL, 'new', 'Solicitud recibida')");
    $history->execute([$application_id]);

    // Confirmation email
    try {
        require_once 'lib/email_functions.php';
        $jt = $pdo->prepare("SELECT title FROM job_postings WHERE id = ?");
        $jt->execute([$job_posting_id]);
        $job_title = $jt->fetchColumn();
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

    echo json_encode([
        'success'          => true,
        'message'          => 'Solicitud enviada exitosamente.',
        'application_code' => $application_code,
        'application_id'   => $application_id,
        'ai'               => [
            'processed' => (bool) ($aiResult['success'] ?? false),
            'score'     => $aiResult['score'] ?? null,
        ],
    ]);

} catch (PDOException $e) {
    error_log('submit_application PDO error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
} catch (Throwable $e) {
    error_log('submit_application error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error inesperado: ' . $e->getMessage()]);
}

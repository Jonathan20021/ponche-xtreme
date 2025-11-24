<?php
// Handle job application submission for public careers form
session_start();
require_once 'db.php';

header('Content-Type: application/json');

try {
    // Allow preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        echo json_encode(['success' => true, 'message' => 'OK']);
        exit;
    }

    // Only POST is allowed
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        @file_put_contents(__DIR__ . '/logs/submit_application_debug.log', json_encode([
            'timestamp' => date('c'),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
            'note' => 'Rejected - not POST'
        ]) . PHP_EOL, FILE_APPEND);
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
        exit;
    }

    // Debug snapshot to verify incoming payload
    if (!is_dir(__DIR__ . '/logs')) {
        @mkdir(__DIR__ . '/logs', 0755, true);
    }
    $debugSnapshot = [
        'timestamp' => date('c'),
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'post_keys' => array_keys($_POST ?? []),
        'post' => $_POST ?? [],
        'files' => array_map(function ($f) {
            return is_array($f) ? [
                'name' => $f['name'] ?? null,
                'type' => $f['type'] ?? null,
                'size' => $f['size'] ?? null,
                'error' => $f['error'] ?? null,
            ] : $f;
        }, $_FILES ?? []),
    ];
    @file_put_contents(__DIR__ . '/logs/submit_application_debug.log', json_encode($debugSnapshot, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);

    // Normalizar campos base
    $candidateName = trim($_POST['candidate_name'] ?? '');
    $cedula = trim($_POST['cedula'] ?? '');
    $phoneNumbers = trim($_POST['phone_numbers'] ?? '');
    $sectorResidencia = trim($_POST['sector_residencia'] ?? '');
    $appliedBefore = $_POST['applied_before'] ?? '';
    $appliedBeforeDetails = trim($_POST['applied_before_details'] ?? '');
    $source = $_POST['source'] ?? '';
    $sourceOther = trim($_POST['source_other'] ?? '');
    $knowsCompany = $_POST['knows_company'] ?? '';
    $interestReason = trim($_POST['interest_reason'] ?? '');
    $applicationLanguage = $_POST['application_language'] ?? '';
    $availabilityTime = $_POST['availability_time'] ?? '';
    $availabilityPreference = trim($_POST['availability_preference'] ?? '');
    $trainingSchedule = $_POST['training_schedule'] ?? '';
    $agreesRotatingDays = $_POST['agrees_rotating_days'] ?? '';
    $weekendHolidays = $_POST['weekend_holidays'] ?? '';
    $currentlyEmployed = $_POST['currently_employed'] ?? '';
    $currentEmploymentDetails = trim($_POST['current_employment_details'] ?? '');
    $recentCompany = trim($_POST['recent_company'] ?? '');
    $recentRole = trim($_POST['recent_role'] ?? '');
    $recentYears = trim($_POST['recent_years'] ?? '');
    $recentLastSalary = trim($_POST['recent_last_salary'] ?? '');
    $hasCallCenterExperience = $_POST['has_call_center_experience'] ?? '';
    $callCenterName = trim($_POST['call_center_name'] ?? '');
    $callCenterRole = trim($_POST['call_center_role'] ?? '');
    $callCenterSalary = trim($_POST['call_center_salary'] ?? '');

    // Validar requeridos (job_posting_id se resuelve aparte)
    $required_fields = [
        'candidate_name' => $candidateName,
        'cedula' => $cedula,
        'phone_numbers' => $phoneNumbers,
        'sector_residencia' => $sectorResidencia,
        'applied_before' => $appliedBefore,
        'source' => $source,
        'knows_company' => $knowsCompany,
        'interest_reason' => $interestReason,
        'application_language' => $applicationLanguage,
        'availability_time' => $availabilityTime,
        'training_schedule' => $trainingSchedule,
        'agrees_rotating_days' => $agreesRotatingDays,
        'weekend_holidays' => $weekendHolidays,
        'currently_employed' => $currentlyEmployed,
        'has_call_center_experience' => $hasCallCenterExperience
    ];
    foreach ($required_fields as $field => $value) {
        if ($value === '' || $value === null) {
            echo json_encode(['success' => false, 'message' => "El campo $field es requerido"]);
            exit;
        }
    }
    if (strtolower($source) === 'otro' && $sourceOther === '') {
        echo json_encode(['success' => false, 'message' => 'El campo source_other es requerido cuando la fuente es Otro']);
        exit;
    }

    // Defaults para opcionales
    if ($candidateName === '') {
        $candidateName = 'Candidato';
    }
    if ($interestReason === '') {
        $interestReason = 'N/A';
    }
    if ($applicationLanguage === '') {
        $applicationLanguage = 'Espanol';
    }
    if ($availabilityTime === '') {
        $availabilityTime = 'Horario abierto';
    }
    if ($trainingSchedule === '') {
        $trainingSchedule = 'Horario abierto';
    }

    // Resolver job_posting_id
    $primaryJobId = $_POST['job_posting_id'] ?? null;
    if (empty($primaryJobId) && !empty($_POST['additional_positions'][0])) {
        $primaryJobId = $_POST['additional_positions'][0];
    }
    if (empty($primaryJobId) && !empty($_SERVER['HTTP_REFERER'])) {
        $ref = parse_url($_SERVER['HTTP_REFERER']);
        if (!empty($ref['query'])) {
            parse_str($ref['query'], $q);
            if (!empty($q['job'])) {
                $primaryJobId = $q['job'];
            }
        }
    }
    if (empty($primaryJobId)) {
        $fallbackJob = $pdo->query("SELECT id FROM job_postings WHERE status = 'active' ORDER BY posted_date DESC LIMIT 1")->fetchColumn();
        if ($fallbackJob) {
            $primaryJobId = $fallbackJob;
        }
    }
    if (!empty($primaryJobId) && is_numeric($primaryJobId)) {
        $primaryJobId = (int) $primaryJobId;
    }
    if (empty($primaryJobId)) {
        echo json_encode(['success' => false, 'message' => 'El campo job_posting_id es requerido']);
        exit;
    }

    // Email opcional
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Formato de correo electrónico inválido']);
        exit;
    }

    // CV opcional
    $cv_filename = null;
    $cv_path = null;
    if (isset($_FILES['cv_file']) && $_FILES['cv_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['cv_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Error al subir el CV. Intenta nuevamente.']);
            exit;
        }
        $allowed_extensions = ['pdf', 'doc', 'docx'];
        $file_extension = strtolower(pathinfo($_FILES['cv_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_extensions)) {
            echo json_encode(['success' => false, 'message' => 'Formato de archivo no permitido. Solo PDF, DOC, DOCX']);
            exit;
        }
        if ($_FILES['cv_file']['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'El archivo es demasiado grande. Máximo 5MB']);
            exit;
        }
        $upload_dir = 'uploads/cvs/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $cv_filename = uniqid('cv_') . '_' . time() . '.' . $file_extension;
        $cv_path = $upload_dir . $cv_filename;
        if (!move_uploaded_file($_FILES['cv_file']['tmp_name'], $cv_path)) {
            echo json_encode(['success' => false, 'message' => 'No se pudo guardar el CV en el servidor']);
            exit;
        }
    }

    // Código de aplicación
    $application_code = null;
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $timestamp = substr(str_replace('.', '', microtime(true)), -6);
        $random = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $application_code = 'APP-' . $random . $timestamp . '-' . date('Y');
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM job_applications WHERE application_code = ?");
        $check_stmt->execute([$application_code]);
        if ($check_stmt->fetchColumn() == 0) {
            break;
        }
        usleep(100);
    }
    if ($attempt >= 10) {
        throw new Exception("No se pudo generar un código único de aplicación");
    }

    // Otras posiciones
    $job_posting_ids = [$primaryJobId];
    if (!empty($_POST['additional_positions']) && is_array($_POST['additional_positions'])) {
        $job_posting_ids = array_unique(array_merge($job_posting_ids, $_POST['additional_positions']));
    }

    // Separar nombre
    $nameParts = preg_split('/\\s+/', trim($candidateName));
    $firstName = $nameParts[0] ?? 'Candidato';
    $lastName = trim(implode(' ', array_slice($nameParts, 1)));
    if ($lastName === '') {
        $lastName = 'N/A';
    }

    // Datos a persistir
    $application_data = [
        'application_code' => $application_code,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => !empty($_POST['email']) ? $_POST['email'] : 'sin-correo@evallish.local',
        'phone' => $phoneNumbers,
        'address' => $sectorResidencia,
        'city' => null,
        'state' => null,
        'postal_code' => null,
        'date_of_birth' => null,
        'education_level' => 'N/A',
        'years_of_experience' => $recentYears !== '' ? $recentYears : null,
        'current_position' => $recentRole !== '' ? $recentRole : null,
        'current_company' => $recentCompany !== '' ? $recentCompany : null,
        'expected_salary' => $recentLastSalary !== '' ? $recentLastSalary : null,
        'availability_date' => null,
        'cv_filename' => $cv_filename,
        'cv_path' => $cv_path,
        'cover_letter' => null,
        'linkedin_url' => null,
        'portfolio_url' => null,
        'cedula' => $cedula,
        'sector_residencia' => $sectorResidencia,
        'applied_before' => $appliedBefore,
        'applied_before_details' => $appliedBeforeDetails !== '' ? $appliedBeforeDetails : null,
        'source' => $source,
        'source_other' => $sourceOther !== '' ? $sourceOther : null,
        'knows_company' => $knowsCompany,
        'interest_reason' => $interestReason,
        'application_language' => $applicationLanguage,
        'availability_time' => $availabilityTime,
        'availability_preference' => $availabilityPreference !== '' ? $availabilityPreference : null,
        'training_schedule' => $trainingSchedule,
        'agrees_rotating_days' => $agreesRotatingDays,
        'weekend_holidays' => $weekendHolidays,
        'currently_employed' => $currentlyEmployed,
        'current_employment_details' => $currentEmploymentDetails !== '' ? $currentEmploymentDetails : null,
        'recent_company' => $recentCompany !== '' ? $recentCompany : null,
        'recent_role' => $recentRole !== '' ? $recentRole : null,
        'recent_years' => $recentYears !== '' ? $recentYears : null,
        'recent_last_salary' => $recentLastSalary !== '' ? $recentLastSalary : null,
        'has_call_center_experience' => $hasCallCenterExperience,
        'call_center_name' => $callCenterName !== '' ? $callCenterName : null,
        'call_center_role' => $callCenterRole !== '' ? $callCenterRole : null,
        'call_center_salary' => $callCenterSalary !== '' ? $callCenterSalary : null
    ];

    $stmt = $pdo->prepare("
        INSERT INTO job_applications (
            application_code, job_posting_id, first_name, last_name, email, phone,
            address, city, state, postal_code, date_of_birth,
            education_level, years_of_experience, current_position, current_company,
            expected_salary, availability_date, cv_filename, cv_path,
            cover_letter, linkedin_url, portfolio_url, status,
            cedula, sector_residencia, applied_before, applied_before_details,
            source, source_other, knows_company, interest_reason, application_language,
            availability_time, availability_preference, training_schedule, agrees_rotating_days,
            weekend_holidays, currently_employed, current_employment_details, recent_company,
            recent_role, recent_years, recent_last_salary, has_call_center_experience,
            call_center_name, call_center_role, call_center_salary
        ) VALUES (
            :application_code, :job_posting_id, :first_name, :last_name, :email, :phone,
            :address, :city, :state, :postal_code, :date_of_birth,
            :education_level, :years_of_experience, :current_position, :current_company,
            :expected_salary, :availability_date, :cv_filename, :cv_path,
            :cover_letter, :linkedin_url, :portfolio_url, 'new',
            :cedula, :sector_residencia, :applied_before, :applied_before_details,
            :source, :source_other, :knows_company, :interest_reason, :application_language,
            :availability_time, :availability_preference, :training_schedule, :agrees_rotating_days,
            :weekend_holidays, :currently_employed, :current_employment_details, :recent_company,
            :recent_role, :recent_years, :recent_last_salary, :has_call_center_experience,
            :call_center_name, :call_center_role, :call_center_salary
        )
    ");

    $application_ids = [];
    foreach ($job_posting_ids as $job_id) {
        $application_data['job_posting_id'] = $job_id;
        $stmt->execute($application_data);
        $application_ids[] = $pdo->lastInsertId();
    }

    $history_stmt = $pdo->prepare("
        INSERT INTO application_status_history (application_id, old_status, new_status, notes)
        VALUES (:application_id, NULL, 'new', 'Solicitud recibida')
    ");
    foreach ($application_ids as $app_id) {
        $history_stmt->execute(['application_id' => $app_id]);
    }

    $stmt = $pdo->prepare("SELECT title FROM job_postings WHERE id = ?");
    $stmt->execute([$primaryJobId]);
    $job_title = $stmt->fetchColumn();

    // Email (si hay email)
    try {
        require_once 'lib/email_functions.php';
        $emailData = [
            'email' => !empty($_POST['email']) ? $_POST['email'] : null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'application_code' => $application_code,
            'job_title' => $job_title,
            'applied_date' => date('d/m/Y'),
            'positions_count' => count($job_posting_ids)
        ];
        if (!empty($emailData['email'])) {
            $emailResult = sendApplicationConfirmationEmail($emailData);
            if (!$emailResult['success']) {
                error_log("Failed to send confirmation email: " . $emailResult['message']);
            }
        }
    } catch (Exception $emailException) {
        error_log("Email notification error: " . $emailException->getMessage());
    }

    $positions_count = count($job_posting_ids);
    $message = $positions_count > 1
        ? "Solicitud enviada exitosamente a {$positions_count} vacantes"
        : 'Solicitud enviada exitosamente';

    echo json_encode([
        'success' => true,
        'message' => $message,
        'application_code' => $application_code,
        'application_ids' => $application_ids,
        'positions_count' => $positions_count
    ]);

} catch (PDOException $e) {
    error_log("Application submission PDO error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Error al procesar la solicitud: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Application submission error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Error inesperado: ' . $e->getMessage()]);
}

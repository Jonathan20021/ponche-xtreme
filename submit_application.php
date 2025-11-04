<?php
// Handle job application submission
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    // Validate required fields
    $required_fields = ['job_posting_id', 'first_name', 'last_name', 'email', 'phone', 'education_level', 'years_of_experience'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(['success' => false, 'message' => "El campo $field es requerido"]);
            exit;
        }
    }

    // Validate email format
    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Formato de correo electrónico inválido']);
        exit;
    }

    // Handle CV file upload
    $cv_filename = null;
    $cv_path = null;

    if (isset($_FILES['cv_file']) && $_FILES['cv_file']['error'] === UPLOAD_ERR_OK) {
        $allowed_extensions = ['pdf', 'doc', 'docx'];
        $file_extension = strtolower(pathinfo($_FILES['cv_file']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            echo json_encode(['success' => false, 'message' => 'Formato de archivo no permitido. Solo PDF, DOC, DOCX']);
            exit;
        }

        // Check file size (5MB max)
        if ($_FILES['cv_file']['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'El archivo es demasiado grande. Máximo 5MB']);
            exit;
        }

        // Create uploads directory if it doesn't exist
        $upload_dir = 'uploads/cvs/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Generate unique filename
        $cv_filename = uniqid('cv_') . '_' . time() . '.' . $file_extension;
        $cv_path = $upload_dir . $cv_filename;

        if (!move_uploaded_file($_FILES['cv_file']['tmp_name'], $cv_path)) {
            echo json_encode(['success' => false, 'message' => 'Error al subir el archivo']);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'El CV es requerido']);
        exit;
    }

    // Generate unique application code with timestamp and random component
    $application_code = null;
    $max_attempts = 10;
    $attempt = 0;
    
    while ($attempt < $max_attempts) {
        // Use microtime for better uniqueness
        $timestamp = substr(str_replace('.', '', microtime(true)), -6);
        $random = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $application_code = 'APP-' . $random . $timestamp . '-' . date('Y');
        
        // Check if code already exists
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM job_applications WHERE application_code = ?");
        $check_stmt->execute([$application_code]);
        
        if ($check_stmt->fetchColumn() == 0) {
            break; // Code is unique
        }
        
        $attempt++;
        usleep(100); // Small delay to ensure different microtime
    }
    
    if ($attempt >= $max_attempts) {
        throw new Exception("No se pudo generar un código único de aplicación");
    }

    // Collect all job posting IDs (primary + additional positions)
    $job_posting_ids = [$_POST['job_posting_id']];
    if (!empty($_POST['additional_positions']) && is_array($_POST['additional_positions'])) {
        $job_posting_ids = array_merge($job_posting_ids, $_POST['additional_positions']);
        // Remove duplicates
        $job_posting_ids = array_unique($job_posting_ids);
    }

    // Prepare application data
    $application_data = [
        'application_code' => $application_code,
        'first_name' => $_POST['first_name'],
        'last_name' => $_POST['last_name'],
        'email' => $_POST['email'],
        'phone' => $_POST['phone'],
        'address' => $_POST['address'] ?? null,
        'city' => $_POST['city'] ?? null,
        'state' => $_POST['state'] ?? null,
        'postal_code' => $_POST['postal_code'] ?? null,
        'date_of_birth' => !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null,
        'education_level' => $_POST['education_level'],
        'years_of_experience' => $_POST['years_of_experience'],
        'current_position' => $_POST['current_position'] ?? null,
        'current_company' => $_POST['current_company'] ?? null,
        'expected_salary' => $_POST['expected_salary'] ?? null,
        'availability_date' => !empty($_POST['availability_date']) ? $_POST['availability_date'] : null,
        'cv_filename' => $cv_filename,
        'cv_path' => $cv_path,
        'cover_letter' => $_POST['cover_letter'] ?? null,
        'linkedin_url' => $_POST['linkedin_url'] ?? null,
        'portfolio_url' => $_POST['portfolio_url'] ?? null
    ];

    // Insert application for each selected job posting
    $stmt = $pdo->prepare("
        INSERT INTO job_applications (
            application_code, job_posting_id, first_name, last_name, email, phone,
            address, city, state, postal_code, date_of_birth,
            education_level, years_of_experience, current_position, current_company,
            expected_salary, availability_date, cv_filename, cv_path,
            cover_letter, linkedin_url, portfolio_url, status
        ) VALUES (
            :application_code, :job_posting_id, :first_name, :last_name, :email, :phone,
            :address, :city, :state, :postal_code, :date_of_birth,
            :education_level, :years_of_experience, :current_position, :current_company,
            :expected_salary, :availability_date, :cv_filename, :cv_path,
            :cover_letter, :linkedin_url, :portfolio_url, 'new'
        )
    ");

    $application_ids = [];
    foreach ($job_posting_ids as $job_id) {
        $application_data['job_posting_id'] = $job_id;
        $stmt->execute($application_data);
        $application_ids[] = $pdo->lastInsertId();
    }

    // Log status history for each application
    $history_stmt = $pdo->prepare("
        INSERT INTO application_status_history (application_id, old_status, new_status, notes)
        VALUES (:application_id, NULL, 'new', 'Solicitud recibida')
    ");
    
    foreach ($application_ids as $app_id) {
        $history_stmt->execute(['application_id' => $app_id]);
    }

    // Get job title for the primary position
    $stmt = $pdo->prepare("SELECT title FROM job_postings WHERE id = ?");
    $stmt->execute([$_POST['job_posting_id']]);
    $job_title = $stmt->fetchColumn();

    // Send confirmation email (non-blocking)
    try {
        require_once 'lib/email_functions.php';
        $emailData = [
            'email' => $_POST['email'],
            'first_name' => $_POST['first_name'],
            'last_name' => $_POST['last_name'],
            'application_code' => $application_code,
            'job_title' => $job_title,
            'applied_date' => date('d/m/Y'),
            'positions_count' => count($job_posting_ids)
        ];
        
        $emailResult = sendApplicationConfirmationEmail($emailData);
        if (!$emailResult['success']) {
            error_log("Failed to send confirmation email: " . $emailResult['message']);
        }
    } catch (Exception $emailException) {
        // Log email error but don't fail the application
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

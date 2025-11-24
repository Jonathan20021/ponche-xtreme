<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_job_postings', '../unauthorized.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: job_postings.php");
    exit;
}

try {
    // Insert job posting first
    $stmt = $pdo->prepare("
        INSERT INTO job_postings 
        (title, department, location, employment_type, description, requirements, 
         responsibilities, salary_range, closing_date, posted_date, created_by, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'active')
    ");
    
    $stmt->execute([
        $_POST['title'],
        $_POST['department'],
        $_POST['location'],
        $_POST['employment_type'],
        $_POST['description'],
        $_POST['requirements'] ?? null,
        $_POST['responsibilities'] ?? null,
        $_POST['salary_range'] ?? null,
        !empty($_POST['closing_date']) ? $_POST['closing_date'] : null,
        $_SESSION['user_id']
    ]);

    $jobId = $pdo->lastInsertId();
    $_SESSION['success_message'] = "Vacante publicada exitosamente";

    // Handle optional banner upload
    if (isset($_FILES['banner_image']) && $_FILES['banner_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $banner = $_FILES['banner_image'];

        if ($banner['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Error al subir el banner de la vacante.');
        }

        if ($banner['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('El banner supera el l��mite de 5MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($banner['tmp_name']);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Formato de banner no permitido. Usa JPG, PNG o WebP.');
        }

        $baseUploadPath = realpath(__DIR__ . '/..');
        if ($baseUploadPath === false) {
            throw new RuntimeException('No se encontr�� la ruta base para guardar el banner.');
        }
        $uploadDir = $baseUploadPath . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'job_banners';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = $allowed[$mime];
        $filename = "job_{$jobId}." . $extension;

        // Clean up any previous banner for this job id
        foreach (glob($uploadDir . DIRECTORY_SEPARATOR . "job_{$jobId}.*") as $oldFile) {
            @unlink($oldFile);
        }

        $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($banner['tmp_name'], $destination)) {
            throw new RuntimeException('No se pudo guardar el banner en el servidor.');
        }
    }
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error al publicar la vacante";
    error_log($e->getMessage());
} catch (RuntimeException $e) {
    $_SESSION['error_message'] = "Vacante publicada, pero el banner no se pudo guardar: " . $e->getMessage();
    error_log($e->getMessage());
}

header("Location: job_postings.php");
exit;

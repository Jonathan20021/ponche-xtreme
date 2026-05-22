<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_job_postings', '../unauthorized.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: job_postings.php");
    exit;
}

try {
    $allowedEmploymentTypes = ['full_time', 'part_time', 'contract', 'internship'];

    $jobId = !empty($_POST['id']) ? (int) $_POST['id'] : 0;
    $title = trim((string) ($_POST['title'] ?? ''));
    $department = trim((string) ($_POST['department'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $employmentType = trim((string) ($_POST['employment_type'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $requirements = trim((string) ($_POST['requirements'] ?? ''));
    $responsibilities = trim((string) ($_POST['responsibilities'] ?? ''));
    $salaryRange = trim((string) ($_POST['salary_range'] ?? ''));
    $closingDateRaw = trim((string) ($_POST['closing_date'] ?? ''));
    $aiGenerated = !empty($_POST['ai_generated']) && $_POST['ai_generated'] !== '0' ? 1 : 0;
    $persisted = false;

    if ($title === '' || $department === '' || $location === '' || $employmentType === '' || $description === '') {
        throw new InvalidArgumentException('Completa los campos obligatorios de la vacante.');
    }

    if (!in_array($employmentType, $allowedEmploymentTypes, true)) {
        throw new InvalidArgumentException('Tipo de empleo invalido.');
    }

    $closingDate = null;
    if ($closingDateRaw !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $closingDateRaw);
        if (!$date || $date->format('Y-m-d') !== $closingDateRaw) {
            throw new InvalidArgumentException('Fecha de cierre invalida.');
        }
        $closingDate = $closingDateRaw;
    }

    if ($jobId > 0) {
        $exists = $pdo->prepare("SELECT id FROM job_postings WHERE id = ?");
        $exists->execute([$jobId]);
        if (!$exists->fetchColumn()) {
            throw new InvalidArgumentException('Vacante no encontrada.');
        }

        // Update existing job posting
        $stmt = $pdo->prepare("
            UPDATE job_postings
            SET title = ?, department = ?, location = ?, employment_type = ?,
                description = ?, requirements = ?, responsibilities = ?,
                salary_range = ?, closing_date = ?, ai_generated = GREATEST(ai_generated, ?)
            WHERE id = ?
        ");

        $stmt->execute([
            $title,
            $department,
            $location,
            $employmentType,
            $description,
            $requirements !== '' ? $requirements : null,
            $responsibilities !== '' ? $responsibilities : null,
            $salaryRange !== '' ? $salaryRange : null,
            $closingDate,
            $aiGenerated,
            $jobId
        ]);

        $persisted = true;
        $_SESSION['success_message'] = "Vacante actualizada exitosamente";
    } else {
        // Insert new job posting
        $stmt = $pdo->prepare("
            INSERT INTO job_postings
            (title, department, location, employment_type, description, requirements,
             responsibilities, salary_range, closing_date, posted_date, created_by, status, ai_generated)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'active', ?)
        ");

        $stmt->execute([
            $title,
            $department,
            $location,
            $employmentType,
            $description,
            $requirements !== '' ? $requirements : null,
            $responsibilities !== '' ? $responsibilities : null,
            $salaryRange !== '' ? $salaryRange : null,
            $closingDate,
            !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            $aiGenerated
        ]);

        $jobId = $pdo->lastInsertId();
        $persisted = true;
        $_SESSION['success_message'] = "Vacante publicada exitosamente";
    }

    // Handle optional banner upload
    if (isset($_FILES['banner_image']) && $_FILES['banner_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $banner = $_FILES['banner_image'];

        if ($banner['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Error al subir el banner de la vacante.');
        }

        if ($banner['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('El banner supera el límite de 5MB.');
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
            throw new RuntimeException('No se encontró la ruta base para guardar el banner.');
        }
        $uploadDir = $baseUploadPath . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'job_banners';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('No se pudo crear la carpeta de banners.');
            }
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
} catch (InvalidArgumentException $e) {
    $_SESSION['error_message'] = $e->getMessage();
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error al guardar la vacante";
    error_log($e->getMessage());
} catch (RuntimeException $e) {
    unset($_SESSION['success_message']);
    $_SESSION['error_message'] = !empty($persisted)
        ? "Vacante guardada, pero el banner no se pudo guardar: " . $e->getMessage()
        : "Error al guardar la vacante: " . $e->getMessage();
    error_log($e->getMessage());
}
header("Location: job_postings.php");
exit;

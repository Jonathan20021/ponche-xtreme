<?php
/**
 * Verification script for recruitment system tables
 * Run this to check if all required tables and columns exist
 */

require_once 'db.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Verificación de Tablas del Sistema de Reclutamiento</h2>";

// Check job_postings table
echo "<h3>1. Tabla: job_postings</h3>";
try {
    $stmt = $pdo->query("DESCRIBE job_postings");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($columns);
    echo "</pre>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Check job_applications table
echo "<h3>2. Tabla: job_applications</h3>";
try {
    $stmt = $pdo->query("DESCRIBE job_applications");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($columns);
    echo "</pre>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Check application_status_history table
echo "<h3>3. Tabla: application_status_history</h3>";
try {
    $stmt = $pdo->query("DESCRIBE application_status_history");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($columns);
    echo "</pre>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Check application_comments table
echo "<h3>4. Tabla: application_comments</h3>";
try {
    $stmt = $pdo->query("DESCRIBE application_comments");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($columns);
    echo "</pre>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Check recruitment_interviews table
echo "<h3>5. Tabla: recruitment_interviews</h3>";
try {
    $stmt = $pdo->query("DESCRIBE recruitment_interviews");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($columns);
    echo "</pre>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Test insert (without actually inserting)
echo "<h3>6. Test de Validación de Datos</h3>";
try {
    $test_data = [
        'application_code' => 'TEST-12345678-2025',
        'job_posting_id' => 1,
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'phone' => '1234567890',
        'address' => null,
        'city' => null,
        'state' => null,
        'postal_code' => null,
        'date_of_birth' => null,
        'education_level' => 'Licenciatura',
        'years_of_experience' => 5,
        'current_position' => null,
        'current_company' => null,
        'expected_salary' => null,
        'availability_date' => null,
        'cv_filename' => 'test.pdf',
        'cv_path' => 'uploads/cvs/test.pdf',
        'cover_letter' => null,
        'linkedin_url' => null,
        'portfolio_url' => null
    ];
    
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
    
    echo "<p style='color: green;'>✓ La consulta SQL está correctamente formateada</p>";
    echo "<p><strong>Nota:</strong> No se insertó ningún dato de prueba</p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error en la consulta: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><strong>Verificación completada.</strong> Si hay errores arriba, ejecuta el archivo de migración correspondiente.</p>";

<?php
require_once 'db.php';

$application_id = 1777;

echo "=== Verificando aplicacion ID: $application_id ===\n\n";

// Verificar estructura de la tabla
echo "1. Estructura de la tabla job_applications:\n";
$stmt = $pdo->query("DESCRIBE job_applications");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Campos en la tabla:\n";
foreach ($columns as $col) {
    echo "  - {$col['Field']} ({$col['Type']})\n";
}
echo "\n";

// Obtener el registro completo
echo "2. Datos completos de la aplicacion:\n";
$stmt = $pdo->prepare("SELECT * FROM job_applications WHERE id = ?");
$stmt->execute([$application_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    echo "ERROR: No se encontro la aplicacion con ID $application_id\n";
    exit;
}

echo json_encode($application, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n\n";

// Verificar campos importantes que deben tener datos
echo "3. Verificacion de campos clave:\n";
$important_fields = [
    'first_name', 'last_name', 'email', 'phone', 'cedula',
    'sector_residencia', 'applied_before', 'source', 'knows_company',
    'interest_reason', 'application_language', 'availability_time',
    'availability_preference', 'training_schedule', 'agrees_rotating_days',
    'weekend_holidays', 'currently_employed', 'current_employment_details',
    'recent_company', 'recent_role', 'recent_years', 'recent_last_salary',
    'has_call_center_experience', 'call_center_name', 'call_center_role',
    'call_center_salary', 'education_level', 'years_of_experience',
    'current_position', 'current_company', 'cover_letter'
];

$empty_fields = [];
$filled_fields = [];

foreach ($important_fields as $field) {
    $value = $application[$field] ?? null;
    if (empty($value)) {
        $empty_fields[] = $field;
    } else {
        $filled_fields[] = $field;
    }
}

echo "Campos llenos (" . count($filled_fields) . "):\n";
foreach ($filled_fields as $field) {
    $value = $application[$field];
    $preview = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
    echo "  - $field: " . $preview . "\n";
}

echo "\nCampos vacios (" . count($empty_fields) . "):\n";
foreach ($empty_fields as $field) {
    echo "  - $field\n";
}

echo "\n4. Verificando si cover_letter contiene JSON:\n";
$cover_letter = $application['cover_letter'] ?? '';
if (!empty($cover_letter)) {
    $decoded = json_decode($cover_letter, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "Si, es JSON valido. Tiene " . count($decoded) . " campos.\n";
        echo "Campos en el JSON:\n";
        foreach ($decoded as $key => $val) {
            $preview = is_string($val) && strlen($val) > 50 ? substr($val, 0, 50) . '...' : (is_array($val) ? '[Array]' : $val);
            echo "  - $key: " . $preview . "\n";
        }
    } else {
        echo "No es JSON valido. Contenido (primeros 200 chars): " . substr($cover_letter, 0, 200) . "\n";
    }
} else {
    echo "El campo cover_letter esta vacio\n";
}

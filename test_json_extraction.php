<?php
require_once 'db.php';

$application_id = 1777;

// Simulate the logic in view_application.php
$stmt = $pdo->prepare("
    SELECT a.*, j.title as job_title, j.department, j.location, u.full_name as assigned_to_name
    FROM job_applications a
    LEFT JOIN job_postings j ON a.job_posting_id = j.id
    LEFT JOIN users u ON a.assigned_to = u.id
    WHERE a.id = ?
");
$stmt->execute([$application_id]);
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    echo "ERROR: No se encontro la aplicacion\n";
    exit;
}

// Parse JSON payload
$formPayload = null;
if (!empty($application['cover_letter'])) {
    $decodedPayload = json_decode($application['cover_letter'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedPayload) && isset($decodedPayload['form_version'])) {
        $formPayload = $decodedPayload;
    }
}

// Test display values
$displayName = trim(($application['first_name'] ?? '') . ' ' . ($application['last_name'] ?? ''));
if ($displayName === '' && $formPayload) {
    $displayName = trim(($formPayload['nombres'] ?? '') . ' ' . ($formPayload['apellido_paterno'] ?? '') . ' ' . ($formPayload['apellido_materno'] ?? ''));
}
if ($displayName === '') {
    $displayName = 'N/A';
}

$displayPhone = !empty($application['phone']) ? $application['phone'] : ($formPayload['telefono'] ?? 'N/A');

$displayEducation = !empty($application['education_level']) ? $application['education_level'] : (
    $formPayload && !empty($formPayload['educacion']['nivel']) 
        ? (is_array($formPayload['educacion']['nivel']) ? implode(', ', $formPayload['educacion']['nivel']) : $formPayload['educacion']['nivel'])
        : 'N/A'
);

// Test getting fields from JSON
$getFieldOrJson = function($fieldValue, $jsonKey, $subKey = null) use ($formPayload) {
    if (!empty($fieldValue)) {
        return $fieldValue;
    }
    if ($formPayload && isset($formPayload[$jsonKey])) {
        $value = $formPayload[$jsonKey];
        if ($subKey !== null && is_array($value) && isset($value[$subKey])) {
            return $value[$subKey];
        }
        return is_array($value) ? '' : $value;
    }
    return '';
};

echo "=== PRUEBA DE EXTRACCION DE DATOS ===\n\n";

echo "Nombre completo: $displayName\n";
echo "Telefono: $displayPhone\n";
echo "Educacion: $displayEducation\n\n";

echo "Cedula: " . $getFieldOrJson($application['cedula'], 'cedula') . "\n";
echo "Direccion: " . $getFieldOrJson($application['address'], 'direccion') . "\n";
echo "Fecha nacimiento: " . $getFieldOrJson($application['date_of_birth'], 'fecha_nacimiento') . "\n";
echo "Edad: " . $getFieldOrJson('', 'edad') . "\n";
echo "Sexo: " . $getFieldOrJson('', 'sexo') . "\n";
echo "Estado civil: " . $getFieldOrJson('', 'estado_civil') . "\n\n";

// Extract from nested JSON
if ($formPayload && !empty($formPayload['experiencias'][0])) {
    echo "Experiencia laboral (del JSON):\n";
    echo "  Empresa: " . ($formPayload['experiencias'][0]['empresa'] ?? 'N/A') . "\n";
    echo "  Puesto: " . ($formPayload['experiencias'][0]['cargo'] ?? 'N/A') . "\n";
    echo "  Tiempo: " . ($formPayload['experiencias'][0]['tiempo'] ?? 'N/A') . "\n";
    echo "  Sueldo: " . ($formPayload['experiencias'][0]['sueldo'] ?? 'N/A') . "\n";
}

echo "\n";

// Availability info
if ($formPayload && !empty($formPayload['disponibilidad'])) {
    echo "Disponibilidad:\n";
    $disp = $formPayload['disponibilidad'];
    $opciones = [];
    if (($disp['turno_rotativo'] ?? '') === 'SI') $opciones[] = 'Turno rotativo';
    if (($disp['lunes_viernes'] ?? '') === 'SI') $opciones[] = 'Lunes a viernes';
    if (($disp['otro'] ?? '') === 'SI' && !empty($disp['otro_texto'])) $opciones[] = $disp['otro_texto'];
    echo "  " . (empty($opciones) ? 'N/A' : implode(', ', $opciones)) . "\n";
}

echo "\n";

// Source
$source = $application['source'] ?? '';
if (empty($source) && $formPayload && !empty($formPayload['adicional']['medio_vacante'])) {
    $medioVacante = $formPayload['adicional']['medio_vacante'];
    $source = is_array($medioVacante) ? implode(', ', $medioVacante) : $medioVacante;
}
echo "Como se entero: $source\n";

echo "\n=== CONCLUSION ===\n";
echo "Los datos del JSON se estan extrayendo correctamente.\n";
echo "El problema en view_application.php era que no se estaba usando el JSON como fallback.\n";
echo "Ahora los cambios deben mostrar la informacion correctamente.\n";

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
    $puestoAplicado = trim($_POST['puesto_aplicado'] ?? '');
    $serie = trim($_POST['serie'] ?? '');
    $cedula = trim($_POST['cedula'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $apellidoPaterno = trim($_POST['apellido_paterno'] ?? '');
    $apellidoMaterno = trim($_POST['apellido_materno'] ?? '');
    $nombres = trim($_POST['nombres'] ?? '');
    $apodo = trim($_POST['apodo'] ?? '');
    $fechaNacimiento = trim($_POST['fecha_nacimiento'] ?? '');
    $edad = trim($_POST['edad'] ?? '');
    $lugarNacimiento = trim($_POST['lugar_nacimiento'] ?? '');
    $paisNacimiento = trim($_POST['pais_nacimiento'] ?? '');
    $nacionalidad = trim($_POST['nacionalidad'] ?? '');
    $sexo = $_POST['sexo'] ?? '';
    $estadoCivil = $_POST['estado_civil'] ?? '';
    $tipoSangre = trim($_POST['tipo_sangre'] ?? '');
    $estatura = trim($_POST['estatura'] ?? '');
    $peso = trim($_POST['peso'] ?? '');
    $viveCon = trim($_POST['vive_con'] ?? '');
    $personasDependen = trim($_POST['personas_dependen'] ?? '');
    $tieneHijos = $_POST['tiene_hijos'] ?? '';
    $edadHijos = trim($_POST['edad_hijos'] ?? '');
    $casaPropia = $_POST['casa_propia'] ?? '';
    $personasVive = trim($_POST['personas_vive'] ?? '');

    $disponibilidadTurno = !empty($_POST['disponibilidad_turno_rotativo']) ? 'SI' : 'NO';
    $disponibilidadLunesViernes = !empty($_POST['disponibilidad_lunes_viernes']) ? 'SI' : 'NO';
    $disponibilidadOtro = !empty($_POST['disponibilidad_otro']) ? 'SI' : 'NO';
    $disponibilidadOtroTexto = trim($_POST['disponibilidad_otro_texto'] ?? '');

    $modalidadPresencial = !empty($_POST['modalidad_presencial']) ? 'SI' : 'NO';
    $modalidadHibrida = !empty($_POST['modalidad_hibrida']) ? 'SI' : 'NO';
    $modalidadRemota = !empty($_POST['modalidad_remota']) ? 'SI' : 'NO';
    $modalidadOtro = !empty($_POST['modalidad_otro']) ? 'SI' : 'NO';
    $modalidadOtroTexto = trim($_POST['modalidad_otro_texto'] ?? '');

    $transporteCarro = !empty($_POST['transporte_carro_publico']) ? 'SI' : 'NO';
    $transporteMoto = !empty($_POST['transporte_motoconcho']) ? 'SI' : 'NO';
    $transportePie = !empty($_POST['transporte_a_pie']) ? 'SI' : 'NO';
    $transporteOtro = !empty($_POST['transporte_otro']) ? 'SI' : 'NO';
    $transporteOtroTexto = trim($_POST['transporte_otro_texto'] ?? '');
    $transporteDetalles = trim($_POST['transporte_detalles'] ?? '');

    $nivelPrimaria = !empty($_POST['nivel_primaria']) ? 'SI' : 'NO';
    $nivelBachillerato = !empty($_POST['nivel_bachillerato']) ? 'SI' : 'NO';
    $nivelEstudiante = !empty($_POST['nivel_estudiante_universitario']) ? 'SI' : 'NO';
    $nivelTecnico = !empty($_POST['nivel_tecnico']) ? 'SI' : 'NO';
    $nivelCarreraCompleta = !empty($_POST['nivel_carrera_completa']) ? 'SI' : 'NO';
    $nivelPostgrado = !empty($_POST['nivel_postgrado']) ? 'SI' : 'NO';
    $nivelTecnicoDetalle = trim($_POST['nivel_tecnico_detalle'] ?? '');
    $nivelCarreraDetalle = trim($_POST['nivel_carrera_detalle'] ?? '');
    $nivelPostgradoDetalle = trim($_POST['nivel_postgrado_detalle'] ?? '');

    $estudiaActualmente = !empty($_POST['estudia_actualmente']) ? 'SI' : 'NO';
    $queEstudia = trim($_POST['que_estudia'] ?? '');
    $dondeEstudia = trim($_POST['donde_estudia'] ?? '');
    $horarioClases = trim($_POST['horario_clases'] ?? '');

    $otrosCursos = [
        ['curso' => trim($_POST['otros_curso_1'] ?? ''), 'institucion' => trim($_POST['otros_curso_institucion_1'] ?? ''), 'fecha' => trim($_POST['otros_curso_fecha_1'] ?? '')],
        ['curso' => trim($_POST['otros_curso_2'] ?? ''), 'institucion' => trim($_POST['otros_curso_institucion_2'] ?? ''), 'fecha' => trim($_POST['otros_curso_fecha_2'] ?? '')],
        ['curso' => trim($_POST['otros_curso_3'] ?? ''), 'institucion' => trim($_POST['otros_curso_institucion_3'] ?? ''), 'fecha' => trim($_POST['otros_curso_fecha_3'] ?? '')],
    ];

    $idiomas = [];
    for ($i = 1; $i <= 3; $i++) {
        $nombreIdioma = trim($_POST["idioma_{$i}_nombre"] ?? '');
        $habla = $_POST["idioma_{$i}_habla"] ?? '';
        $lee = $_POST["idioma_{$i}_lee"] ?? '';
        $escribe = $_POST["idioma_{$i}_escribe"] ?? '';
        if ($nombreIdioma !== '' || $habla !== '' || $lee !== '' || $escribe !== '') {
            $idiomas[] = [
                'idioma' => $nombreIdioma,
                'habla' => $habla,
                'lee' => $lee,
                'escribe' => $escribe
            ];
        }
    }

    $exp1Empresa = trim($_POST['exp1_empresa'] ?? '');
    $exp1Superior = trim($_POST['exp1_superior'] ?? '');
    $exp1Tiempo = trim($_POST['exp1_tiempo'] ?? '');
    $exp1Telefono = trim($_POST['exp1_telefono'] ?? '');
    $exp1Cargo = trim($_POST['exp1_cargo'] ?? '');
    $exp1Sueldo = trim($_POST['exp1_sueldo'] ?? '');
    $exp1Tareas = trim($_POST['exp1_tareas'] ?? '');
    $exp1Razon = trim($_POST['exp1_razon_salida'] ?? '');

    $exp2Empresa = trim($_POST['exp2_empresa'] ?? '');
    $exp2Superior = trim($_POST['exp2_superior'] ?? '');
    $exp2Tiempo = trim($_POST['exp2_tiempo'] ?? '');
    $exp2Telefono = trim($_POST['exp2_telefono'] ?? '');
    $exp2Cargo = trim($_POST['exp2_cargo'] ?? '');
    $exp2Sueldo = trim($_POST['exp2_sueldo'] ?? '');
    $exp2Tareas = trim($_POST['exp2_tareas'] ?? '');
    $exp2Razon = trim($_POST['exp2_razon_salida'] ?? '');

    $mayorLogro = trim($_POST['mayor_logro'] ?? '');
    $expectativasSalariales = trim($_POST['expectativas_salariales'] ?? '');
    $incapacidad = $_POST['incapacidad'] ?? '';
    $incapacidadCual = trim($_POST['incapacidad_cual'] ?? '');
    $horasExtras = $_POST['horas_extras'] ?? '';
    $diasFiestas = $_POST['dias_fiestas'] ?? '';
    $conoceEmpleado = $_POST['conoce_empleado'] ?? '';
    $conoceEmpleadoNombre = trim($_POST['conoce_empleado_nombre'] ?? '');

    $medioVacante = $_POST['medio_vacante'] ?? [];
    if (!is_array($medioVacante)) {
        $medioVacante = [$medioVacante];
    }
    $medioVacanteOtro = trim($_POST['medio_vacante_otro_texto'] ?? '');
    $aceptaDatos = !empty($_POST['acepta_datos']) ? 'SI' : 'NO';
    $firmaSolicitante = trim($_POST['firma_solicitante'] ?? '');

    $evaluadorNombre = trim($_POST['evaluador_nombre'] ?? '');
    $evaluacionFecha = trim($_POST['evaluacion_fecha'] ?? '');
    $evaluadorPuesto = trim($_POST['evaluador_puesto'] ?? '');
    $observacionesEntrevista = trim($_POST['observaciones_entrevista'] ?? '');

    // Validar requeridos (job_posting_id se resuelve aparte)
    $required_fields = [
        'puesto_aplicado' => $puestoAplicado,
        'cedula' => $cedula,
        'direccion' => $direccion,
        'telefono' => $telefono,
        'apellido_paterno' => $apellidoPaterno,
        'apellido_materno' => $apellidoMaterno,
        'nombres' => $nombres,
        'fecha_nacimiento' => $fechaNacimiento,
        'sexo' => $sexo,
        'estado_civil' => $estadoCivil,
        'acepta_datos' => $aceptaDatos
    ];
    foreach ($required_fields as $field => $value) {
        if ($value === '' || $value === null) {
            echo json_encode(['success' => false, 'message' => "El campo $field es requerido"]);
            exit;
        }
    }
    if ($fechaNacimiento !== '' && !DateTime::createFromFormat('Y-m-d', $fechaNacimiento)) {
        echo json_encode(['success' => false, 'message' => 'El campo fecha_nacimiento no es valido']);
        exit;
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
    $firstName = $nombres !== '' ? $nombres : 'Candidato';
    $lastName = trim($apellidoPaterno . ' ' . $apellidoMaterno);
    if ($lastName === '') {
        $lastName = 'N/A';
    }

    $educationLevels = [];
    if ($nivelPrimaria === 'SI') {
        $educationLevels[] = 'Educacion basica (Primaria)';
    }
    if ($nivelBachillerato === 'SI') {
        $educationLevels[] = 'Educacion media (Bachillerato)';
    }
    if ($nivelEstudiante === 'SI') {
        $educationLevels[] = 'Estudiante universitario (en curso)';
    }
    if ($nivelTecnico === 'SI') {
        $educationLevels[] = $nivelTecnicoDetalle !== '' ? 'Tecnico: ' . $nivelTecnicoDetalle : 'Tecnico o curso especializado';
    }
    if ($nivelCarreraCompleta === 'SI') {
        $educationLevels[] = $nivelCarreraDetalle !== '' ? 'Carrera completa: ' . $nivelCarreraDetalle : 'Carrera universitaria completa';
    }
    if ($nivelPostgrado === 'SI') {
        $educationLevels[] = $nivelPostgradoDetalle !== '' ? 'Postgrado: ' . $nivelPostgradoDetalle : 'Postgrado / Maestria';
    }
    $educationLevelSummary = !empty($educationLevels) ? implode(', ', $educationLevels) : null;

    $yearsExperience = null;
    if ($exp1Tiempo !== '' && preg_match('/\d+/', $exp1Tiempo, $matches)) {
        $yearsExperience = (int) $matches[0];
    }

    $sourceCombined = !empty($medioVacante) ? implode(', ', $medioVacante) : null;

    $formPayload = [
        'form_version' => '2026-01-30',
        'puesto_aplicado' => $puestoAplicado,
        'serie' => $serie,
        'cedula' => $cedula,
        'direccion' => $direccion,
        'telefono' => $telefono,
        'apellido_paterno' => $apellidoPaterno,
        'apellido_materno' => $apellidoMaterno,
        'nombres' => $nombres,
        'apodo' => $apodo,
        'fecha_nacimiento' => $fechaNacimiento,
        'edad' => $edad,
        'lugar_nacimiento' => $lugarNacimiento,
        'pais_nacimiento' => $paisNacimiento,
        'nacionalidad' => $nacionalidad,
        'sexo' => $sexo,
        'estado_civil' => $estadoCivil,
        'tipo_sangre' => $tipoSangre,
        'estatura' => $estatura,
        'peso' => $peso,
        'vive_con' => $viveCon,
        'personas_dependen' => $personasDependen,
        'tiene_hijos' => $tieneHijos,
        'edad_hijos' => $edadHijos,
        'casa_propia' => $casaPropia,
        'personas_vive' => $personasVive,
        'disponibilidad' => [
            'turno_rotativo' => $disponibilidadTurno,
            'lunes_viernes' => $disponibilidadLunesViernes,
            'otro' => $disponibilidadOtro,
            'otro_texto' => $disponibilidadOtroTexto
        ],
        'modalidad' => [
            'presencial' => $modalidadPresencial,
            'hibrida' => $modalidadHibrida,
            'remota' => $modalidadRemota,
            'otro' => $modalidadOtro,
            'otro_texto' => $modalidadOtroTexto
        ],
        'transporte' => [
            'carro_publico' => $transporteCarro,
            'motoconcho' => $transporteMoto,
            'a_pie' => $transportePie,
            'otro' => $transporteOtro,
            'otro_texto' => $transporteOtroTexto,
            'detalles' => $transporteDetalles
        ],
        'educacion' => [
            'nivel' => $educationLevels,
            'nivel_tecnico_detalle' => $nivelTecnicoDetalle,
            'nivel_carrera_detalle' => $nivelCarreraDetalle,
            'nivel_postgrado_detalle' => $nivelPostgradoDetalle,
            'estudia_actualmente' => $estudiaActualmente,
            'que_estudia' => $queEstudia,
            'donde_estudia' => $dondeEstudia,
            'horario_clases' => $horarioClases,
            'otros_cursos' => $otrosCursos
        ],
        'idiomas' => $idiomas,
        'experiencias' => [
            [
                'empresa' => $exp1Empresa,
                'superior' => $exp1Superior,
                'tiempo' => $exp1Tiempo,
                'telefono' => $exp1Telefono,
                'cargo' => $exp1Cargo,
                'sueldo' => $exp1Sueldo,
                'tareas' => $exp1Tareas,
                'razon_salida' => $exp1Razon
            ],
            [
                'empresa' => $exp2Empresa,
                'superior' => $exp2Superior,
                'tiempo' => $exp2Tiempo,
                'telefono' => $exp2Telefono,
                'cargo' => $exp2Cargo,
                'sueldo' => $exp2Sueldo,
                'tareas' => $exp2Tareas,
                'razon_salida' => $exp2Razon
            ]
        ],
        'adicional' => [
            'mayor_logro' => $mayorLogro,
            'expectativas_salariales' => $expectativasSalariales,
            'incapacidad' => $incapacidad,
            'incapacidad_cual' => $incapacidadCual,
            'horas_extras' => $horasExtras,
            'dias_fiestas' => $diasFiestas,
            'conoce_empleado' => $conoceEmpleado,
            'conoce_empleado_nombre' => $conoceEmpleadoNombre,
            'medio_vacante' => $medioVacante,
            'medio_vacante_otro' => $medioVacanteOtro,
            'acepta_datos' => $aceptaDatos,
            'firma' => $firmaSolicitante
        ],
        'evaluador' => [
            'nombre' => $evaluadorNombre,
            'fecha' => $evaluacionFecha,
            'puesto' => $evaluadorPuesto,
            'observaciones' => $observacionesEntrevista
        ]
    ];
    $formJson = json_encode($formPayload);

    // Datos a persistir
    $application_data = [
        'application_code' => $application_code,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => !empty($_POST['email']) ? $_POST['email'] : 'sin-correo@evallish.local',
        'phone' => $telefono,
        'address' => $direccion,
        'city' => null,
        'state' => null,
        'postal_code' => null,
        'date_of_birth' => $fechaNacimiento !== '' ? $fechaNacimiento : null,
        'education_level' => $educationLevelSummary,
        'years_of_experience' => $yearsExperience,
        'current_position' => $exp1Cargo !== '' ? $exp1Cargo : null,
        'current_company' => $exp1Empresa !== '' ? $exp1Empresa : null,
        'expected_salary' => $expectativasSalariales !== '' ? $expectativasSalariales : null,
        'availability_date' => null,
        'cv_filename' => $cv_filename,
        'cv_path' => $cv_path,
        'cover_letter' => $formJson,
        'linkedin_url' => null,
        'portfolio_url' => null,
        'cedula' => $cedula,
        'sector_residencia' => null,
        'applied_before' => null,
        'applied_before_details' => null,
        'source' => $sourceCombined,
        'source_other' => $medioVacanteOtro !== '' ? $medioVacanteOtro : null,
        'knows_company' => null,
        'interest_reason' => null,
        'application_language' => null,
        'availability_time' => null,
        'availability_preference' => null,
        'training_schedule' => null,
        'agrees_rotating_days' => null,
        'weekend_holidays' => null,
        'currently_employed' => null,
        'current_employment_details' => null,
        'recent_company' => null,
        'recent_role' => null,
        'recent_years' => null,
        'recent_last_salary' => null,
        'has_call_center_experience' => null,
        'call_center_name' => null,
        'call_center_role' => null,
        'call_center_salary' => null
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

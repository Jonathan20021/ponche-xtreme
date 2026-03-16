<?php
session_start();
require_once '../db.php';

// Check permissions
if (!isset($_SESSION['user_id']) || !userHasPermission('hr_recruitment_ai')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
    exit;
}

// Set JSON header
header('Content-Type: application/json');

// Gemini AI Configuration
define('GEMINI_API_KEY', 'AIzaSyDJMoBOmGPa5wQUck3OKiUMlenHP5oyJ5o');
define('GEMINI_MODEL', 'gemini-2.0-flash-exp');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action !== 'analyze') {
    echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    exit;
}

$userQuery = $input['query'] ?? '';

if (empty($userQuery)) {
    echo json_encode(['success' => false, 'error' => 'Consulta vacía']);
    exit;
}

try {
    // Get database schema information for context
    $schema = getDatabaseSchema();
    
    // Generate SQL query using Gemini AI
    $sqlQuery = generateSQLWithGemini($userQuery, $schema);
    
    if (!$sqlQuery) {
        // Try fallback method
        $sqlQuery = generateSQLFallback($userQuery);
        
        if (!$sqlQuery) {
            throw new Exception('No se pudo interpretar tu consulta. Por favor intenta de forma más específica. 

Ejemplos válidos:
• "¿Cuántos candidatos tienen salario entre 20000 y 30000 pesos?"
• "Mostrar candidatos con más de 3 años de experiencia"
• "Aplicaciones de los últimos 7 días"
• "Candidatos con salario mayor a 25000"');
        }
    }
    
    // Execute the query
    $stmt = $pdo->prepare($sqlQuery);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate insights using Gemini AI
    $insights = generateInsights($userQuery, $results);
    
    echo json_encode([
        'success' => true,
        'results' => $results,
        'insights' => $insights,
        'query' => $sqlQuery, // For debugging
        'record_count' => count($results)
    ]);
    
} catch (Exception $e) {
    error_log('Recruitment AI Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Get database schema information for AI context
 */
function getDatabaseSchema() {
    return [
        'job_applications' => [
            'description' => 'Tabla de aplicaciones de trabajo',
            'columns' => [
                'id' => 'ID único de la aplicación',
                'application_code' => 'Código único de la aplicación',
                'job_posting_id' => 'ID de la vacante (relación con job_postings)',
                'first_name' => 'Nombre del candidato',
                'last_name' => 'Apellido del candidato',
                'email' => 'Email del candidato',
                'phone' => 'Teléfono del candidato',
                'city' => 'Ciudad',
                'state' => 'Estado/Provincia',
                'country' => 'País',
                'date_of_birth' => 'Fecha de nacimiento',
                'education_level' => 'Nivel educativo (ej: Licenciatura, Maestría)',
                'years_of_experience' => 'Años de experiencia laboral',
                'current_position' => 'Posición actual',
                'current_company' => 'Empresa actual',
                'expected_salary' => 'Salario esperado',
                'availability_date' => 'Fecha de disponibilidad',
                'status' => 'Estado (new, reviewing, shortlisted, interview_scheduled, interviewed, offer_extended, hired, rejected, withdrawn)',
                'overall_rating' => 'Calificación general (1-5)',
                'applied_date' => 'Fecha de aplicación',
                'last_updated' => 'Última actualización',
                'assigned_to' => 'Asignado a (ID de usuario)'
            ]
        ],
        'job_postings' => [
            'description' => 'Tabla de vacantes de empleo',
            'columns' => [
                'id' => 'ID único de la vacante',
                'title' => 'Título de la vacante',
                'department' => 'Departamento',
                'location' => 'Ubicación',
                'employment_type' => 'Tipo de empleo (full_time, part_time, contract, internship)',
                'description' => 'Descripción del puesto',
                'requirements' => 'Requisitos',
                'responsibilities' => 'Responsabilidades',
                'salary_range' => 'Rango salarial',
                'status' => 'Estado (active, inactive, closed)',
                'posted_date' => 'Fecha de publicación',
                'closing_date' => 'Fecha de cierre',
                'created_at' => 'Fecha de creación'
            ]
        ],
        'applicant_skills' => [
            'description' => 'Tabla de habilidades de candidatos',
            'columns' => [
                'id' => 'ID único',
                'application_id' => 'ID de la aplicación',
                'skill_name' => 'Nombre de la habilidad',
                'proficiency_level' => 'Nivel de dominio (beginner, intermediate, advanced, expert)',
                'years_experience' => 'Años de experiencia con esta habilidad'
            ]
        ],
        'recruitment_interviews' => [
            'description' => 'Tabla de entrevistas programadas',
            'columns' => [
                'id' => 'ID único',
                'application_id' => 'ID de la aplicación',
                'interview_type' => 'Tipo de entrevista (phone_screening, technical, hr, manager, final)',
                'interview_date' => 'Fecha y hora de la entrevista',
                'status' => 'Estado (scheduled, completed, cancelled, rescheduled, no_show)',
                'rating' => 'Calificación de la entrevista (1-5)',
                'feedback' => 'Retroalimentación'
            ]
        ]
    ];
}

/**
 * Generate SQL query using Gemini AI
 */
function generateSQLWithGemini($userQuery, $schema) {
    $systemPrompt = buildSystemPrompt($schema);
    
    $fullPrompt = $systemPrompt . "\n\nConsulta del usuario: \"" . $userQuery . "\"\n\nSQL:";
    
    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $fullPrompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'topK' => 20,
            'topP' => 0.8,
            'maxOutputTokens' => 512,
            'stopSequences' => [';']
        ],
        'safetySettings' => [
            [
                'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                'threshold' => 'BLOCK_NONE'
            ]
        ]
    ];
    
    $response = callGeminiAPI($payload);
    
    if (!$response) {
        error_log("Gemini API returned null response for query: $userQuery");
        return null;
    }
    
    // Log the raw response for debugging
    error_log("Gemini Raw Response: " . $response);
    
    // Extract SQL from response
    $sqlQuery = extractSQL($response);
    
    if (!$sqlQuery) {
        error_log("Failed to extract SQL from response: $response");
        return null;
    }
    
    // Validate and sanitize SQL
    try {
        $sqlQuery = validateSQL($sqlQuery, $userQuery);
        error_log("Generated SQL: " . $sqlQuery);
        return $sqlQuery;
    } catch (Exception $e) {
        error_log("SQL Validation Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Build system prompt for Gemini AI
 */
function buildSystemPrompt($schema) {
    $schemaText = "Eres un experto en SQL para MySQL. Convierte consultas en lenguaje natural a SQL válido.\n\n";
    $schemaText .= "IMPORTANTE: Responde SOLO con la consulta SQL, sin texto adicional, sin explicaciones, sin markdown.\n\n";
    $schemaText .= "BASE DE DATOS:\n\n";
    
    foreach ($schema as $table => $info) {
        $schemaText .= "Tabla: $table\n";
        $schemaText .= "Descripción: " . $info['description'] . "\n";
        $schemaText .= "Columnas:\n";
        foreach ($info['columns'] as $column => $description) {
            $schemaText .= "  - $column: $description\n";
        }
        $schemaText .= "\n";
    }
    
    $schemaText .= "REGLAS ESTRICTAS:\n";
    $schemaText .= "1. SOLO devuelve SQL, sin explicación\n";
    $schemaText .= "2. Usa SELECT únicamente (solo lectura)\n";
    $schemaText .= "3. Agrega LIMIT 100 para limitar resultados\n";
    $schemaText .= "4. Para salarios con texto (RD$20,000), extrae números: CAST(REGEXP_REPLACE(expected_salary, '[^0-9]', '') AS UNSIGNED)\n";
    $schemaText .= "5. Para búsquedas de texto usa LIKE '%texto%'\n";
    $schemaText .= "6. Para fechas usa DATE_SUB(CURDATE(), INTERVAL X DAY)\n";
    $schemaText .= "7. Ordena resultados lógicamente con ORDER BY\n\n";
    
    $schemaText .= "EJEMPLOS:\n\n";
    $schemaText .= "Usuario: 'Mostrar candidatos con más de 5 años de experiencia'\n";
    $schemaText .= "SQL: SELECT * FROM job_applications WHERE years_of_experience > 5 ORDER BY years_of_experience DESC LIMIT 100\n\n";
    
    $schemaText .= "Usuario: '¿Cuántos candidatos tienen expectativas entre 20000 y 30000 pesos?'\n";
    $schemaText .= "SQL: SELECT id, first_name, last_name, email, phone, expected_salary, years_of_experience, status, applied_date FROM job_applications WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) BETWEEN 20000 AND 30000 ORDER BY expected_salary DESC LIMIT 100\n\n";
    
    $schemaText .= "Usuario: '¿Cuántas personas aplicaron con salario mayor a 20000 pesos?'\n";
    $schemaText .= "SQL: SELECT id, first_name, last_name, email, expected_salary, status FROM job_applications WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) > 20000 ORDER BY expected_salary DESC LIMIT 100\n\n";
    
    $schemaText .= "Usuario: 'Aplicaciones de los últimos 7 días'\n";
    $schemaText .= "SQL: SELECT * FROM job_applications WHERE applied_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY applied_date DESC LIMIT 100\n\n";
    
    $schemaText .= "Usuario: 'Candidatos con salario entre 25000 y 35000'\n";
    $schemaText .= "SQL: SELECT id, first_name, last_name, email, expected_salary FROM job_applications WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) BETWEEN 25000 AND 35000 ORDER BY expected_salary DESC LIMIT 100\n\n";
    
    $schemaText .= "IMPORTANTE: Si el usuario pide un FILTRO (salario, experiencia, fechas), SIEMPRE incluye WHERE.\n";
    $schemaText .= "NUNCA devuelvas SELECT * FROM job_applications sin WHERE si hay un filtro solicitado.\n\n";
    
    return $schemaText;
}

/**
 * Call Gemini API
 */
function callGeminiAPI($payload) {
    $url = GEMINI_API_URL . '?key=' . GEMINI_API_KEY;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("Gemini API CURL Error: " . $curlError);
        return null;
    }
    
    if ($httpCode !== 200) {
        error_log("Gemini API Error: HTTP $httpCode - Response: " . substr($response, 0, 500));
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Gemini API JSON Error: " . json_last_error_msg());
        return null;
    }
    
    if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        error_log("Gemini API: Invalid response structure - " . json_encode($data));
        
        // Check for blocked content or other errors
        if (isset($data['error'])) {
            error_log("Gemini API Error Detail: " . json_encode($data['error']));
        }
        
        if (isset($data['candidates'][0]['finishReason'])) {
            error_log("Gemini Finish Reason: " . $data['candidates'][0]['finishReason']);
        }
        
        return null;
    }
    
    return $data['candidates'][0]['content']['parts'][0]['text'];
}

/**
 * Extract SQL from Gemini response
 */
function extractSQL($response) {
    if (empty($response)) {
        return null;
    }
    
    // Remove markdown code blocks
    $response = preg_replace('/```sql\s*/i', '', $response);
    $response = preg_replace('/```\s*/', '', $response);
    
    // Remove common prefixes
    $response = preg_replace('/^(SQL:|Query:|Consulta:|Respuesta:)\s*/i', '', $response);
    
    // Trim whitespace
    $response = trim($response);
    
    // If multiple lines, try to find SELECT statement
    if (strpos($response, "\n") !== false) {
        $lines = explode("\n", $response);
        foreach ($lines as $line) {
            $line = trim($line);
            if (stripos($line, 'SELECT') === 0 && strlen($line) > 10) {
                return cleanSQL($line);
            }
        }
    }
    
    // If single line or no SELECT found in lines, return cleaned response
    return cleanSQL($response);
}

/**
 * Clean and normalize SQL
 */
function cleanSQL($sql) {
    $sql = trim($sql);
    
    // Remove trailing semicolon if present
    $sql = rtrim($sql, ';');
    
    // Remove extra whitespace
    $sql = preg_replace('/\s+/', ' ', $sql);
    
    // Ensure it starts with SELECT
    if (stripos($sql, 'SELECT') !== 0) {
        return null;
    }
    
    // Ensure LIMIT is present, if not add it
    if (stripos($sql, 'LIMIT') === false) {
        $sql .= ' LIMIT 100';
    }
    
    return $sql;
}

/**
 * Fallback SQL generator for common patterns
 */
function generateSQLFallback($userQuery) {
    $query = strtolower($userQuery);
    // Remove accents for better matching
    $queryNoAccents = str_replace(
        ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'],
        ['a', 'e', 'i', 'o', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'n'],
        $query
    );
    $sql = null;
    
    // Pattern: salary range (entre X y Y)
    if (preg_match('/(?:entre|between)[^\d]*([\d,]+)[^\d]+(?:y|and)[^\d]*([\d,]+)/i', $userQuery, $matches)) {
        $amount1 = preg_replace('/[^0-9]/', '', $matches[1]);
        $amount2 = preg_replace('/[^0-9]/', '', $matches[2]);
        
        if (!empty($amount1) && !empty($amount2)) {
            $min = min($amount1, $amount2);
            $max = max($amount1, $amount2);
            
            // Always show candidate list for salary ranges (more useful than just count)
            $sql = "SELECT id, first_name, last_name, email, phone, expected_salary, years_of_experience, current_position, status, applied_date 
                    FROM job_applications 
                    WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) BETWEEN $min AND $max 
                    ORDER BY CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) DESC 
                    LIMIT 100";
        }
    }
    // Pattern: salary greater than (mayor a X)
    else if (preg_match('/(?:salario|salary|sueldo|aspiracion|expectativa)[^\d]+(?:mayor|mas|superior|arriba)[^\d]+([\d,]+)/i', $query, $matches)) {
        $amount = preg_replace('/[^0-9]/', '', $matches[1]);
        
        if (!empty($amount)) {
            // Always show candidate list for salary filters
            $sql = "SELECT id, first_name, last_name, email, phone, expected_salary, years_of_experience, current_position, status, applied_date 
                    FROM job_applications 
                    WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) > $amount 
                    ORDER BY CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) DESC 
                    LIMIT 100";
        }
    }
    // Pattern: salary less than (menor a X)
    else if (preg_match('/(?:salario|salary|sueldo|aspiracion|expectativa)[^\d]+(?:menor|menos|inferior|debajo)[^\d]+([\d,]+)/i', $query, $matches)) {
        $amount = preg_replace('/[^0-9]/', '', $matches[1]);
        
        if (!empty($amount)) {
            // Always show candidate list for salary filters
            $sql = "SELECT id, first_name, last_name, email, phone, expected_salary, years_of_experience, current_position, status, applied_date 
                    FROM job_applications 
                    WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) < $amount 
                    ORDER BY CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) ASC 
                    LIMIT 100";
        }
    }
    // Pattern: experience with number
    else if (preg_match('/(?:experiencia|experience)[^\d]+([\d]+)/i', $query, $matches) ||
             preg_match('/([\d]+)[^\d]+(?:ano|year|experiencia|experience)/i', $query, $matches)) {
        $years = preg_replace('/[^0-9]/', '', $matches[1]);
        
        if (!empty($years)) {
            $operator = (strpos($queryNoAccents, 'mas') !== false || strpos($query, 'mayor') !== false) ? '>' : '>=';
            
            // Always show candidate list for experience filters
            $sql = "SELECT id, first_name, last_name, email, phone, years_of_experience, current_position, expected_salary, status, applied_date 
                    FROM job_applications 
                    WHERE years_of_experience $operator $years 
                    ORDER BY years_of_experience DESC 
                    LIMIT 100";
        }
    }
    // Pattern: recent applications (últimos X días)
    else if (preg_match('/(?:ultimo|reciente|last)[^\d]+([\d]+)/i', $queryNoAccents, $matches)) {
        $days = preg_replace('/[^0-9]/', '', $matches[1]);
        
        if (!empty($days)) {
            $sql = "SELECT id, first_name, last_name, email, expected_salary, status, applied_date 
                    FROM job_applications 
                    WHERE applied_date >= DATE_SUB(CURDATE(), INTERVAL $days DAY) 
                    ORDER BY applied_date DESC 
                    LIMIT 100";
        }
    }
    // Pattern: search by current position or experience (call center, supervisor, etc.)
    else if (preg_match('/(?:experiencia|candidatos?|aplicantes?)\s+(?:en|con|de)\s+([a-zA-Z][a-zA-Z\s]*?)(?:\s*\?|$)/i', $userQuery, $matches) ||
             preg_match('/(?:posicion|puesto|cargo)\s+(?:de|como)\s+([a-zA-Z][a-zA-Z\s]*?)(?:\s*\?|$)/i', $userQuery, $matches) ||
             preg_match('/(?:buscar|mostrar|listar)\s+.*?(?:con|de|en)\s+(?:experiencia\s+en\s+|posicion\s+de\s+)?([a-zA-Z][a-zA-Z\s]*?)(?:\s*\?|$)/i', $userQuery, $matches)) {
        
        $searchTerm = trim($matches[1]);
        
        // Clean the search term (remove common words)
        $commonWords = ['el', 'la', 'los', 'las', 'de', 'del', 'en', 'con', 'para', 'por', 'como', 'experiencia', 'posicion', 'puesto', 'cargo', 'the', 'a', 'an', 'in', 'at', 'on', 'with', 'for', 'experience', 'position'];
        $searchWords = explode(' ', strtolower($searchTerm));
        $searchWords = array_filter($searchWords, function($word) use ($commonWords) {
            return !in_array($word, $commonWords) && strlen($word) > 2;
        });
        
        if (!empty($searchWords)) {
            $searchTerm = implode(' ', $searchWords);
            
            // Sanitize search term to prevent SQL injection (allow only alphanumeric and spaces)
            $searchTerm = preg_replace('/[^a-zA-Z0-9\s]/', '', $searchTerm);
            $searchTerm = trim($searchTerm);
            
            if (!empty($searchTerm)) {
                $sql = "SELECT id, first_name, last_name, email, phone, current_position, years_of_experience, expected_salary, status, applied_date 
                        FROM job_applications 
                        WHERE current_position LIKE '%$searchTerm%' 
                        ORDER BY applied_date DESC 
                        LIMIT 100";
            }
        }
    }
    // Pattern: by status
    else if (preg_match('/(nuevo|nueva|new)/', $query) && strpos($query, 'aplicacion') !== false) {
        $sql = "SELECT id, first_name, last_name, email, expected_salary, applied_date 
                FROM job_applications 
                WHERE status = 'new' 
                ORDER BY applied_date DESC 
                LIMIT 100";
    }
    // Pattern: count all
    else if (strpos($queryNoAccents, 'cuanto') !== false || strpos($query, 'count') !== false || strpos($query, 'total') !== false) {
        $sql = "SELECT COUNT(*) as total, 
                'Total de aplicaciones' as descripcion 
                FROM job_applications";
    }
    // Default: NO mostrar todo, sino error
    else {
        return null; // No generar SQL si no se reconoce el patrón
    }
    
    if ($sql) {
        error_log("Fallback SQL generated for query '$userQuery': " . $sql);
    }
    
    return $sql;
}

/**
 * Validate SQL query for security and correctness
 */
function validateSQL($sql, $userQuery = '') {
    // Convert to uppercase for checking
    $sqlUpper = strtoupper($sql);
    
    // Check for dangerous operations
    $dangerousPatterns = [
        'DROP', 'DELETE', 'INSERT', 'UPDATE', 'TRUNCATE', 
        'ALTER', 'CREATE', 'REPLACE', 'EXEC', 'EXECUTE',
        'SHOW TABLES', 'SHOW DATABASES', '--', '/*', '*/'
    ];
    
    foreach ($dangerousPatterns as $pattern) {
        if (strpos($sqlUpper, $pattern) !== false) {
            throw new Exception("Consulta SQL no permitida por motivos de seguridad");
        }
    }
    
    // Must start with SELECT
    if (stripos(trim($sql), 'SELECT') !== 0) {
        throw new Exception("Solo se permiten consultas SELECT");
    }
    
    // Check if user requested a filter but SQL has no WHERE clause
    if ($userQuery) {
        $queryLower = strtolower($userQuery);
        $needsFilter = (
            // Salary filters
            preg_match('/(salario|salary|sueldo|aspiracion|expectativa).*(mayor|menor|mas|menos|entre|between|>|<)/i', $userQuery) ||
            // Experience filters
            preg_match('/(experiencia|experience).*(año|year|anos).*(mayor|menor|mas|menos|>|<|con)/i', $userQuery) ||
            // Date filters
            preg_match('/(ultimos|reciente|desde|hasta|last|recent|dias|day)/i', $userQuery) ||
            // Status filters
            preg_match('/(estado|status|nuevo|nueva|entrevista|interview)/i', $userQuery) ||
            // Position/department filters
            preg_match('/(vacante|puesto|position|departamento|department)/i', $userQuery)
        );
        
        $hasWhere = stripos($sqlUpper, 'WHERE') !== false;
        
        if ($needsFilter && !$hasWhere) {
            error_log("SQL rejected: User requested filter but SQL has no WHERE clause. SQL: $sql");
            throw new Exception("SQL generado sin filtro cuando se solicitó uno");
        }
    }
    
    return $sql;
}

/**
 * Generate insights using Gemini AI
 */
function generateInsights($userQuery, $results) {
    if (empty($results)) {
        return "No se encontraron resultados para esta consulta. Intenta ajustar tus filtros o criterios de búsqueda.";
    }
    
    $resultCount = count($results);
    $sampleData = array_slice($results, 0, 3); // Take first 3 records as sample
    
    $prompt = "Analiza los siguientes datos de reclutamiento y proporciona insights breves y útiles (máximo 2-3 oraciones):\n\n";
    $prompt .= "Consulta original: $userQuery\n";
    $prompt .= "Total de registros encontrados: $resultCount\n";
    $prompt .= "Muestra de datos:\n" . json_encode($sampleData, JSON_PRETTY_PRINT);
    $prompt .= "\n\nProvee insights accionables para RH basados en estos datos.";
    
    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 200,
        ]
    ];
    
    $response = callGeminiAPI($payload);
    
    if (!$response) {
        return "Se encontraron $resultCount registro(s) que coinciden con tu consulta.";
    }
    
    return trim($response);
}

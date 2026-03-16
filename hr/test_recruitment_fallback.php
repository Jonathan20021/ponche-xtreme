<?php
/**
 * Test script for recruitment AI fallback system
 * Run this to test SQL generation without hitting the API
 */

// Test queries
$testQueries = [
    "¿Cuántos candidatos tienen expectativas salariales entre RD\$20,000 y RD\$30,000?",
    "Candidatos con salario entre 25000 y 35000 pesos",
    "Mostrar personas con salario mayor a 20000",
    "Candidatos con más de 3 años de experiencia",
    "Aplicaciones de los últimos 7 días",
    "Aplicaciones nuevas",
    "Candidatos con salario menor a 15000",
    "¿Cuales candidatos tienen experiencia en call center?",
    "Mostrar candidatos con experiencia en supervisor",
    "Buscar aplicantes con posición de gerente",
];

echo "<h1>Test de Generación de SQL - Fallback System</h1>";
echo "<hr>";

foreach ($testQueries as $query) {
    echo "<h3>Consulta: <em>" . htmlspecialchars($query) . "</em></h3>";
    
    $sql = generateSQLFallback($query);
    
    if ($sql) {
        echo "<pre style='background: #1e293b; color: #10b981; padding: 15px; border-radius: 8px;'>";
        echo htmlspecialchars($sql);
        echo "</pre>";
    } else {
        echo "<pre style='background: #7f1d1d; color: #fca5a5; padding: 15px; border-radius: 8px;'>";
        echo "❌ No se pudo generar SQL para esta consulta";
        echo "</pre>";
    }
    
    echo "<hr>";
}

// Fallback function (copied from recruitment_ai_api.php V3 - WITH ACCENT HANDLING)
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
            
            // Check if user wants count (check both original and accent-removed)
            if (strpos($queryNoAccents, 'cuanto') !== false || strpos($query, 'count') !== false || strpos($queryNoAccents, 'numero') !== false) {
                $sql = "SELECT COUNT(*) as total, 
                        'Candidatos con salario entre RD$$min y RD$$max' as descripcion 
                        FROM job_applications 
                        WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) BETWEEN $min AND $max";
            } else {
                $sql = "SELECT id, first_name, last_name, email, expected_salary, years_of_experience, status, applied_date 
                        FROM job_applications 
                        WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) BETWEEN $min AND $max 
                        ORDER BY CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) DESC 
                        LIMIT 100";
            }
        }
    }
    // Pattern: salary greater than (mayor a X)
    else if (preg_match('/(?:salario|salary|sueldo|aspiracion|expectativa)[^\d]+(?:mayor|mas|superior|arriba)[^\d]+([\d,]+)/i', $query, $matches)) {
        $amount = preg_replace('/[^0-9]/', '', $matches[1]);
        
        if (!empty($amount)) {
            if (strpos($queryNoAccents, 'cuanto') !== false || strpos($query, 'count') !== false || strpos($queryNoAccents, 'numero') !== false) {
                $sql = "SELECT COUNT(*) as total, 
                        'Candidatos con salario mayor a RD$$amount' as descripcion 
                        FROM job_applications 
                        WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) > $amount";
            } else {
                $sql = "SELECT id, first_name, last_name, email, expected_salary, years_of_experience, status, applied_date 
                        FROM job_applications 
                        WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) > $amount 
                        ORDER BY CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) DESC 
                        LIMIT 100";
            }
        }
    }
    // Pattern: salary less than (menor a X)
    else if (preg_match('/(?:salario|salary|sueldo|aspiracion|expectativa)[^\d]+(?:menor|menos|inferior|debajo)[^\d]+([\d,]+)/i', $query, $matches)) {
        $amount = preg_replace('/[^0-9]/', '', $matches[1]);
        
        if (!empty($amount)) {
            if (strpos($queryNoAccents, 'cuanto') !== false || strpos($query, 'count') !== false || strpos($queryNoAccents, 'numero') !== false) {
                $sql = "SELECT COUNT(*) as total, 
                        'Candidatos con salario menor a RD$$amount' as descripcion 
                        FROM job_applications 
                        WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) < $amount";
            } else {
                $sql = "SELECT id, first_name, last_name, email, expected_salary, years_of_experience, status, applied_date 
                        FROM job_applications 
                        WHERE CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) < $amount 
                        ORDER BY CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(expected_salary, 'RD', ''), '$', ''), ',', ''), ' ', ''), '.', '') AS UNSIGNED) ASC 
                        LIMIT 100";
            }
        }
    }
    // Pattern: experience with number
    else if (preg_match('/(?:experiencia|experience)[^\d]+([\d]+)/i', $query, $matches) ||
             preg_match('/([\d]+)[^\d]+(?:ano|year|experiencia|experience)/i', $query, $matches)) {
        $years = preg_replace('/[^0-9]/', '', $matches[2] ?? $matches[1]);
        $operator = (strpos($query, 'más') !== false || strpos($query, 'mayor') !== false || strpos($query, 'mas') !== false) ? '>' : '>=';
        $years = preg_replace('/[^0-9]/', '', $matches[1]);
        
        if (!empty($years)) {
            $operator = (strpos($queryNoAccents, 'mas') !== false || strpos($query, 'mayor') !== false) ? '>' : '>=';
            
            if (strpos($queryNoAccents, 'cuanto') !== false || strpos($query, 'count') !== false) {
                $sql = "SELECT COUNT(*) as total, 
                        'Candidatos con $operator $years años de experiencia' as descripcion 
                        FROM job_applications 
                        WHERE years_of_experience $operator $years";
            } else {
                $sql = "SELECT id, first_name, last_name, email, years_of_experience, current_position, expected_salary, status 
                        FROM job_applications 
                        WHERE years_of_experience $operator $years 
                        ORDER BY years_of_experience DESC 
                        LIMIT 100";
            }
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

?>
<style>
    body { 
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        padding: 20px; 
        background: #0f172a; 
        color: #e2e8f0;
    }
    h1 { color: #60a5fa; }
    h3 { color: #818cf8; margin-top: 20px; }
    em { color: #fbbf24; }
</style>

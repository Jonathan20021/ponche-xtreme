<?php
/**
 * Test simple para code_details - sin autenticación
 */
require_once 'db.php';

header('Content-Type: application/json');

$codeId = (int)($_GET['id'] ?? 4);

try {
    // Test 1: Conexión a BD
    echo "<!-- Conexión OK -->\n";
    
    // Test 2: Query del código
    $codeStmt = $pdo->prepare("
        SELECT 
            id, code, code_name, role_type, usage_context, 
            is_active, valid_from, valid_until, max_uses, 
            current_uses, created_by, created_at, updated_at
        FROM authorization_codes 
        WHERE id = ?
    ");
    $codeStmt->execute([$codeId]);
    $code = $codeStmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<!-- Código encontrado: " . ($code ? 'SI' : 'NO') . " -->\n";
    
    if (!$code) {
        echo json_encode([
            'success' => false,
            'message' => 'Código no encontrado',
            'code_id_buscado' => $codeId
        ]);
        exit;
    }
    
    // Test 3: Query del historial
    $historyStmt = $pdo->prepare("
        SELECT 
            acl.id,
            acl.used_at,
            acl.usage_context,
            acl.reference_id,
            acl.reference_table,
            acl.ip_address,
            u.full_name as user_name,
            u.username
        FROM authorization_code_logs acl
        LEFT JOIN users u ON acl.user_id = u.id
        WHERE acl.authorization_code_id = ?
        ORDER BY acl.used_at DESC
        LIMIT 50
    ");
    $historyStmt->execute([$codeId]);
    $usageHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<!-- Registros de uso: " . count($usageHistory) . " -->\n";
    
    // Format usage history
    foreach ($usageHistory as &$usage) {
        $refInfo = '';
        if ($usage['reference_table'] && $usage['reference_id']) {
            $refInfo = ucfirst($usage['reference_table']) . ' #' . $usage['reference_id'];
        }
        $usage['reference_info'] = $refInfo;
    }
    
    // Retornar respuesta
    echo json_encode([
        'success' => true,
        'message' => 'Detalles obtenidos',
        'data' => [
            'code' => $code,
            'usage_history' => $usageHistory
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}

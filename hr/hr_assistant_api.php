<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/hr_assistant_functions.php';
require_once __DIR__ . '/../lib/gemini_api.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'error' => 'No autenticado'
    ]);
    exit;
}

// Get POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['message'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Mensaje no proporcionado'
    ]);
    exit;
}

$userMessage = trim($data['message']);
$conversationHistory = $data['history'] ?? [];

if (empty($userMessage)) {
    echo json_encode([
        'success' => false,
        'error' => 'Mensaje vacío'
    ]);
    exit;
}

try {
    $userId = $_SESSION['user_id'];
    
    // Get employee context
    $employeeContext = getEmployeeContext($pdo, $userId);
    
    // Build system context
    $systemContext = GeminiAPI::buildSystemContext($employeeContext);
    
    // Initialize Gemini API
    $gemini = new GeminiAPI();
    
    // Get response from Gemini
    $result = $gemini->chat($userMessage, $conversationHistory, $systemContext);
    
    if ($result['success']) {
        // Save conversation to database
        try {
            $stmt = $pdo->prepare("
                INSERT INTO hr_assistant_chat_history (user_id, message, response, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $userMessage, $result['text']]);
        } catch (PDOException $e) {
            // Log error but don't fail the request
            error_log("Failed to save chat history: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'response' => $result['text']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['error'] ?? 'Error al procesar la solicitud'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error del servidor: ' . $e->getMessage()
    ]);
}

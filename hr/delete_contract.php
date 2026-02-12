<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

ensurePermission('hr_employees', '../unauthorized.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$contractId = (int) ($_POST['id'] ?? 0);

if ($contractId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de contrato inválido']);
    exit;
}

try {
    // Check if contract exists
    $checkStmt = $pdo->prepare("SELECT id, employee_name FROM employment_contracts WHERE id = ?");
    $checkStmt->execute([$contractId]);
    $contract = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$contract) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contrato no encontrado']);
        exit;
    }

    // Delete the contract
    $deleteStmt = $pdo->prepare("DELETE FROM employment_contracts WHERE id = ?");
    $deleteStmt->execute([$contractId]);

    echo json_encode([
        'success' => true,
        'message' => 'Contrato eliminado exitosamente',
        'employee_name' => $contract['employee_name']
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al eliminar el contrato: ' . $e->getMessage()
    ]);
}

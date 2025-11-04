<?php
/**
 * Cleanup script for duplicate application codes
 * This will remove any duplicate entries keeping only the first one
 */

require_once 'db.php';

try {
    // Delete the specific duplicate entry
    $stmt = $pdo->prepare("DELETE FROM job_applications WHERE application_code = 'APP-7DEC7EF9-2025'");
    $stmt->execute();
    
    echo "✓ Registro duplicado eliminado exitosamente\n";
    echo "Puedes intentar enviar la solicitud nuevamente.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

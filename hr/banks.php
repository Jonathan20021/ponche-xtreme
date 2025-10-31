<?php
session_start();
require_once '../db.php';

// Check permissions
ensurePermission('hr_employees');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

$successMsg = null;
$errorMsg = null;

// Handle bank creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bank'])) {
    $name = trim($_POST['name']);
    $code = trim($_POST['code']) ?: null;
    $swiftCode = trim($_POST['swift_code']) ?: null;
    $country = trim($_POST['country']) ?: 'República Dominicana';
    
    if (!empty($name)) {
        $bankId = addBank($pdo, $name, $code, $swiftCode, $country);
        if ($bankId) {
            $successMsg = "Banco '{$name}' agregado correctamente.";
        } else {
            $errorMsg = "Error al agregar el banco. Es posible que ya exista.";
        }
    } else {
        $errorMsg = "El nombre del banco es obligatorio.";
    }
}

// Handle bank update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_bank'])) {
    $bankId = (int)$_POST['bank_id'];
    $name = trim($_POST['name']);
    $code = trim($_POST['code']) ?: null;
    $swiftCode = trim($_POST['swift_code']) ?: null;
    $country = trim($_POST['country']) ?: 'República Dominicana';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    if (!empty($name)) {
        try {
            $stmt = $pdo->prepare("UPDATE banks SET name = ?, code = ?, swift_code = ?, country = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $code, $swiftCode, $country, $isActive, $bankId]);
            $successMsg = "Banco actualizado correctamente.";
        } catch (Exception $e) {
            $errorMsg = "Error al actualizar el banco: " . $e->getMessage();
        }
    } else {
        $errorMsg = "El nombre del banco es obligatorio.";
    }
}

// Handle bank deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_bank'])) {
    $bankId = (int)$_POST['bank_id'];
    
    try {
        // Check if bank is in use
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE bank_id = ?");
        $checkStmt->execute([$bankId]);
        $count = $checkStmt->fetchColumn();
        
        if ($count > 0) {
            $errorMsg = "No se puede eliminar el banco porque está asignado a {$count} empleado(s). Desactívalo en su lugar.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM banks WHERE id = ?");
            $stmt->execute([$bankId]);
            $successMsg = "Banco eliminado correctamente.";
        }
    } catch (Exception $e) {
        $errorMsg = "Error al eliminar el banco: " . $e->getMessage();
    }
}

// Get all banks
$banks = getAllBanks($pdo, false); // Include inactive banks
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Bancos - HR</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-university text-blue-400 mr-3"></i>
                    Gestión de Bancos
                </h1>
                <p class="text-slate-400">Administra los bancos disponibles para los empleados</p>
            </div>
            <div class="flex gap-3">
                <button onclick="document.getElementById('addModal').classList.remove('hidden')" class="btn-primary">
                    <i class="fas fa-plus"></i>
                    Agregar Banco
                </button>
                <a href="employees.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver a Empleados
                </a>
            </div>
        </div>

        <?php if ($successMsg): ?>
            <div class="status-banner success mb-6"><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        
        <?php if ($errorMsg): ?>
            <div class="status-banner error mb-6"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <!-- Banks List -->
        <div class="glass-card">
            <h2 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-list text-blue-400 mr-2"></i>
                Bancos Registrados (<?= count($banks) ?>)
            </h2>

            <?php if (empty($banks)): ?>
                <p class="text-slate-400 text-center py-8">No hay bancos registrados.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Nombre</th>
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Código</th>
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">SWIFT</th>
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">País</th>
                                <th class="text-center py-3 px-4 text-slate-300 font-semibold">Estado</th>
                                <th class="text-center py-3 px-4 text-slate-300 font-semibold">Empleados</th>
                                <th class="text-center py-3 px-4 text-slate-300 font-semibold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($banks as $bank): ?>
                                <?php
                                // Count employees using this bank
                                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE bank_id = ?");
                                $countStmt->execute([$bank['id']]);
                                $employeeCount = $countStmt->fetchColumn();
                                ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-700/30 transition-colors">
                                    <td class="py-3 px-4 text-white font-medium">
                                        <?= htmlspecialchars($bank['name']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-slate-300">
                                        <?= htmlspecialchars($bank['code'] ?? '-') ?>
                                    </td>
                                    <td class="py-3 px-4 text-slate-300">
                                        <?= htmlspecialchars($bank['swift_code'] ?? '-') ?>
                                    </td>
                                    <td class="py-3 px-4 text-slate-300">
                                        <?= htmlspecialchars($bank['country']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <?php if ($bank['is_active']): ?>
                                            <span class="inline-block px-2 py-1 rounded text-xs font-semibold text-white bg-green-500">
                                                Activo
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-block px-2 py-1 rounded text-xs font-semibold text-white bg-gray-500">
                                                Inactivo
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-center text-slate-300">
                                        <?= $employeeCount ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex gap-2 justify-center">
                                            <button onclick="editBank(<?= htmlspecialchars(json_encode($bank)) ?>)" 
                                                    class="text-blue-400 hover:text-blue-300 transition-colors" 
                                                    title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($employeeCount == 0): ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('¿Estás seguro de eliminar este banco?')">
                                                    <input type="hidden" name="delete_bank" value="1">
                                                    <input type="hidden" name="bank_id" value="<?= $bank['id'] ?>">
                                                    <button type="submit" class="text-red-400 hover:text-red-300 transition-colors" title="Eliminar">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Bank Modal -->
    <div id="addModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="glass-card m-4" style="width: min(500px, 95%);">
            <h3 class="text-xl font-semibold text-white mb-4">Agregar Nuevo Banco</h3>
            <form method="POST">
                <input type="hidden" name="add_bank" value="1">
                
                <div class="form-group mb-4">
                    <label for="add_name">Nombre del Banco *</label>
                    <input type="text" id="add_name" name="name" required placeholder="Ej: Banco Popular">
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="add_code">Código</label>
                        <input type="text" id="add_code" name="code" placeholder="Ej: BPD">
                    </div>
                    <div class="form-group">
                        <label for="add_swift_code">Código SWIFT</label>
                        <input type="text" id="add_swift_code" name="swift_code" placeholder="Ej: BPOPDOMM">
                    </div>
                </div>
                
                <div class="form-group mb-6">
                    <label for="add_country">País</label>
                    <input type="text" id="add_country" name="country" value="República Dominicana">
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-save"></i>
                        Guardar
                    </button>
                    <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Bank Modal -->
    <div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="glass-card m-4" style="width: min(500px, 95%);">
            <h3 class="text-xl font-semibold text-white mb-4">Editar Banco</h3>
            <form method="POST">
                <input type="hidden" name="update_bank" value="1">
                <input type="hidden" name="bank_id" id="edit_bank_id">
                
                <div class="form-group mb-4">
                    <label for="edit_name">Nombre del Banco *</label>
                    <input type="text" id="edit_name" name="name" required>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="form-group">
                        <label for="edit_code">Código</label>
                        <input type="text" id="edit_code" name="code">
                    </div>
                    <div class="form-group">
                        <label for="edit_swift_code">Código SWIFT</label>
                        <input type="text" id="edit_swift_code" name="swift_code">
                    </div>
                </div>
                
                <div class="form-group mb-4">
                    <label for="edit_country">País</label>
                    <input type="text" id="edit_country" name="country">
                </div>
                
                <div class="form-group mb-6">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="edit_is_active" name="is_active" class="form-checkbox h-5 w-5 text-blue-500">
                        <span class="text-slate-300">Banco activo</span>
                    </label>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" class="btn-primary flex-1">
                        <i class="fas fa-save"></i>
                        Guardar Cambios
                    </button>
                    <button type="button" onclick="document.getElementById('editModal').classList.add('hidden')" class="btn-secondary flex-1">
                        <i class="fas fa-times"></i>
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function editBank(bank) {
            document.getElementById('edit_bank_id').value = bank.id;
            document.getElementById('edit_name').value = bank.name || '';
            document.getElementById('edit_code').value = bank.code || '';
            document.getElementById('edit_swift_code').value = bank.swift_code || '';
            document.getElementById('edit_country').value = bank.country || '';
            document.getElementById('edit_is_active').checked = bank.is_active == 1;
            
            document.getElementById('editModal').classList.remove('hidden');
        }
    </script>

    <?php include '../footer.php'; ?>
</body>
</html>

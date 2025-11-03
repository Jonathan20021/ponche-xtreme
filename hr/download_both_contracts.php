<?php
session_start();
require_once '../db.php';
ensurePermission('hr_employees');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Get data from session
$contractData = $_SESSION['contract_data'] ?? null;

if (!$contractData) {
    header('Location: contracts.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Descargar Contratos</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-2xl mx-auto">
            <div class="glass-card text-center">
                <div class="mb-6">
                    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-green-500/20 mb-4">
                        <i class="fas fa-check-circle text-5xl text-green-400"></i>
                    </div>
                    <h1 class="text-3xl font-bold text-white mb-2">
                        ¡Contratos Generados Exitosamente!
                    </h1>
                    <p class="text-slate-400">
                        Los contratos para <strong class="text-white"><?= htmlspecialchars($contractData['employee_name']) ?></strong> han sido creados
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
                    <form action="generate_contract.php" method="POST" target="_blank">
                        <input type="hidden" name="employee_name" value="<?= htmlspecialchars($contractData['employee_name']) ?>">
                        <input type="hidden" name="id_card" value="<?= htmlspecialchars($contractData['id_card']) ?>">
                        <input type="hidden" name="province" value="<?= htmlspecialchars($contractData['province']) ?>">
                        <input type="hidden" name="position" value="<?= htmlspecialchars($contractData['position']) ?>">
                        <input type="hidden" name="salary" value="<?= htmlspecialchars($contractData['salary']) ?>">
                        <input type="hidden" name="work_schedule" value="<?= htmlspecialchars($contractData['work_schedule']) ?>">
                        <input type="hidden" name="contract_date" value="<?= htmlspecialchars($contractData['contract_date']) ?>">
                        <input type="hidden" name="city" value="<?= htmlspecialchars($contractData['city']) ?>">
                        <input type="hidden" name="action" value="employment">
                        
                        <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-6 py-4 rounded-lg font-semibold transition-all duration-200 shadow-lg hover:shadow-xl">
                            <i class="fas fa-file-contract text-2xl mb-2"></i>
                            <div class="text-sm">Descargar</div>
                            <div class="font-bold">Contrato de Trabajo</div>
                        </button>
                    </form>

                    <form action="generate_confidentiality_contract.php" method="POST" target="_blank">
                        <input type="hidden" name="from_both" value="1">
                        
                        <button type="submit" class="w-full bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white px-6 py-4 rounded-lg font-semibold transition-all duration-200 shadow-lg hover:shadow-xl">
                            <i class="fas fa-shield-alt text-2xl mb-2"></i>
                            <div class="text-sm">Descargar</div>
                            <div class="font-bold">Contrato de Confidencialidad</div>
                        </button>
                    </form>
                </div>

                <div class="border-t border-slate-700 pt-6">
                    <a href="contracts.php" class="btn-secondary inline-flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i>
                        Volver a Contratos
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php include '../footer.php'; ?>
</body>
</html>

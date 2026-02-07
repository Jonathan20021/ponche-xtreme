<?php
session_start();
require_once '../db.php';
ensurePermission('hr_employees', '../unauthorized.php');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Get all employees for the dropdown
$stmt = $pdo->query("
    SELECT e.id, e.first_name, e.last_name, e.employee_code, e.identification_number, 
           e.position, e.hire_date, e.address, e.state,
           u.hourly_rate, u.username
    FROM employees e
    JOIN users u ON u.id = e.user_id
    WHERE e.employment_status = 'ACTIVE'
    ORDER BY e.first_name, e.last_name
");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get existing contracts
$contractsStmt = $pdo->query("
    SELECT c.*, e.employee_code
    FROM employment_contracts c
    LEFT JOIN employees e ON e.id = c.employee_id
    ORDER BY c.created_at DESC
");
$contracts = $contractsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generador de Contratos</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-white mb-2">
                    <i class="fas fa-file-contract text-cyan-400 mr-3"></i>
                    Generador de Contratos
                </h1>
                <p class="text-slate-400">Genere contratos de trabajo automatizados</p>
            </div>
        </div>

        <!-- Contract Generation Form -->
        <div class="glass-card mb-8">
            <h2 class="text-xl font-bold text-white mb-6">
                <i class="fas fa-plus-circle text-green-400 mr-2"></i>
                Nuevo Contrato
            </h2>
            
            <form action="generate_contract.php" method="POST" target="_blank" id="contractForm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Manual Input Fields -->
                    <div class="md:col-span-2">
                        <label class="block text-slate-300 font-semibold mb-2">
                            <i class="fas fa-user-edit mr-2"></i>Nombre Completo del Empleado
                        </label>
                        <input type="text" name="employee_name" required
                               class="w-full px-4 py-3 bg-slate-800/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-cyan-500"
                               placeholder="Ej: Juan Carlos Pérez García">
                    </div>

                    <div>
                        <label class="block text-slate-300 font-semibold mb-2">
                            <i class="fas fa-id-card mr-2"></i>Número de Cédula
                        </label>
                        <input type="text" name="id_card" required
                               class="w-full px-4 py-3 bg-slate-800/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-cyan-500"
                               placeholder="Ej: 001-1234567-8">
                    </div>

                    <div>
                        <label class="block text-slate-300 font-semibold mb-2">
                            <i class="fas fa-map-marked-alt mr-2"></i>Provincia
                        </label>
                        <input type="text" name="province" required
                               class="w-full px-4 py-3 bg-slate-800/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-cyan-500"
                               placeholder="Ej: Santiago">
                    </div>

                    <div>
                        <label class="block text-slate-300 font-semibold mb-2">
                            <i class="fas fa-briefcase mr-2"></i>Cargo/Posición
                        </label>
                        <input type="text" name="position" required
                               class="w-full px-4 py-3 bg-slate-800/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-cyan-500"
                               placeholder="Ej: Representante de Servicios">
                    </div>

                    <div>
                        <label class="block text-slate-300 font-semibold mb-2">
                            <i class="fas fa-file-invoice-dollar mr-2"></i>Tipo de Pago
                        </label>
                        <select name="payment_type" required
                                class="w-full px-4 py-3 bg-slate-800/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-cyan-500">
                            <option value="">Seleccione tipo de pago...</option>
                            <option value="por_hora">Por Hora</option>
                            <option value="mensual">Salario Mensual Fijo</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-slate-300 font-semibold mb-2">
                            <i class="fas fa-money-bill-wave mr-2"></i>Salario (RD$)
                        </label>
                        <input type="number" name="salary" step="0.01" required
                               class="w-full px-4 py-3 bg-slate-800/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-cyan-500"
                               placeholder="Ej: 30000.00">
                    </div>

                    <div>
                        <label class="block text-slate-300 font-semibold mb-2">
                            <i class="fas fa-clock mr-2"></i>Horario de Trabajo
                        </label>
                        <input type="text" name="work_schedule" required
                               class="w-full px-4 py-3 bg-slate-800/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-cyan-500"
                               placeholder="Ej: 44 horas semanales">
                    </div>

                    <div>
                        <label class="block text-slate-300 font-semibold mb-2">
                            <i class="fas fa-calendar-alt mr-2"></i>Fecha del Contrato
                        </label>
                        <input type="date" name="contract_date" value="<?= date('Y-m-d') ?>" required
                               class="w-full px-4 py-3 bg-slate-800/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-cyan-500">
                    </div>

                    <div>
                        <label class="block text-slate-300 font-semibold mb-2">
                            <i class="fas fa-map-marker-alt mr-2"></i>Ciudad
                        </label>
                        <input type="text" name="city" value="Ciudad de Santiago" required
                               class="w-full px-4 py-3 bg-slate-800/50 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-cyan-500">
                    </div>
                </div>

                <div class="mt-6 flex gap-4">
                    <button type="submit" name="action" value="employment" class="btn-primary">
                        <i class="fas fa-file-contract mr-2"></i>
                        Generar Contrato de Trabajo
                    </button>
                    <button type="submit" name="action" value="confidentiality" formtarget="_blank" class="btn-secondary">
                        <i class="fas fa-shield-alt mr-2"></i>
                        Generar Contrato de Confidencialidad
                    </button>
                    <button type="submit" name="action" value="both" class="bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white px-6 py-3 rounded-lg font-semibold transition-all duration-200 shadow-lg hover:shadow-xl">
                        <i class="fas fa-file-pdf mr-2"></i>
                        Generar Ambos Contratos
                    </button>
                </div>
            </form>
        </div>

        <!-- Contracts List -->
        <div class="glass-card">
            <h2 class="text-xl font-bold text-white mb-6">
                <i class="fas fa-list text-blue-400 mr-2"></i>
                Contratos Generados
            </h2>
            
            <?php if (empty($contracts)): ?>
                <p class="text-slate-400 text-center py-8">No hay contratos generados aún</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Empleado</th>
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Cédula</th>
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Tipo</th>
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Fecha Contrato</th>
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Salario</th>
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Generado</th>
                                <th class="text-center py-3 px-4 text-slate-300 font-semibold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contracts as $contract): ?>
                                <?php
                                    $contractType = $contract['contract_type'] ?? 'TRABAJO';
                                    $badgeClass = $contractType === 'CONFIDENCIALIDAD' 
                                        ? 'bg-purple-500/20 text-purple-300' 
                                        : 'bg-blue-500/20 text-blue-300';
                                    $typeLabel = $contractType === 'CONFIDENCIALIDAD' ? 'Confidencialidad' : 'Trabajo';
                                ?>
                                <tr class="border-b border-slate-800 hover:bg-slate-800/30">
                                    <td class="py-3 px-4 text-white">
                                        <?= htmlspecialchars($contract['employee_name']) ?>
                                    </td>
                                    <td class="py-3 px-4 text-slate-300">
                                        <?= htmlspecialchars($contract['id_card']) ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-1 rounded text-xs font-semibold <?= $badgeClass ?>">
                                            <?= $typeLabel ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-slate-300">
                                        <?= date('d/m/Y', strtotime($contract['contract_date'])) ?>
                                    </td>
                                    <td class="py-3 px-4 text-green-400 font-semibold">
                                        <?php if ($contractType === 'TRABAJO'): ?>
                                            <?php 
                                                $paymentType = $contract['payment_type'] ?? 'mensual';
                                                $paymentLabel = $paymentType === 'por_hora' ? '/hora' : '/mes';
                                            ?>
                                            RD$ <?= number_format($contract['salary'], 2) ?> <span class="text-xs text-slate-400"><?= $paymentLabel ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-500">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-slate-400 text-sm">
                                        <?= date('d/m/Y H:i', strtotime($contract['created_at'])) ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <a href="view_contract.php?id=<?= $contract['id'] ?>" target="_blank"
                                           class="inline-flex items-center gap-2 px-3 py-1 bg-blue-500/20 text-blue-300 rounded hover:bg-blue-500/30 transition-colors">
                                            <i class="fas fa-eye"></i>
                                            Ver PDF
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../footer.php'; ?>
</body>
</html>

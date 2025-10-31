<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Licencias Médicas - HR</title>
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
                    <i class="fas fa-notes-medical text-red-400 mr-3"></i>
                    Licencias Médicas
                </h1>
                <p class="text-slate-400">Gestión de licencias médicas, maternidad, paternidad y más</p>
            </div>
            <div class="flex gap-3">
                <button onclick="document.getElementById('createModal').classList.remove('hidden')" class="btn-primary">
                    <i class="fas fa-plus"></i>
                    Nueva Licencia
                </button>
                <a href="index.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver a HR
                </a>
            </div>
        </div>

        <?php if (isset($successMsg)): ?>
            <div class="status-banner success mb-6">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($successMsg) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($errorMsg)): ?>
            <div class="status-banner error mb-6">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Total</p>
                        <h3 class="text-3xl font-bold text-white"><?= $stats['total'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                        <i class="fas fa-file-medical text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Pendientes</p>
                        <h3 class="text-3xl font-bold text-white"><?= $stats['pending'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                        <i class="fas fa-clock text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Aprobadas</p>
                        <h3 class="text-3xl font-bold text-white"><?= $stats['approved'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                        <i class="fas fa-check-circle text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Activas</p>
                        <h3 class="text-3xl font-bold text-white"><?= $stats['active'] ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                        <i class="fas fa-user-injured text-white text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm mb-1">Total Días</p>
                        <h3 class="text-3xl font-bold text-white"><?= number_format($stats['total_days'], 0) ?></h3>
                    </div>
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
                        <i class="fas fa-calendar-times text-white text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="glass-card mb-6">
            <form method="GET" class="flex flex-wrap gap-4 items-end">
                <div class="form-group flex-1 min-w-[150px]">
                    <label for="year">Año</label>
                    <select id="year" name="year">
                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?= $y ?>" <?= $yearFilter == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group flex-1 min-w-[150px]">
                    <label for="status">Estado</label>
                    <select id="status" name="status">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todos</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pendientes</option>
                        <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Aprobadas</option>
                        <option value="extended" <?= $statusFilter === 'extended' ? 'selected' : '' ?>>Extendidas</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completadas</option>
                        <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rechazadas</option>
                    </select>
                </div>
                <div class="form-group flex-1 min-w-[150px]">
                    <label for="type">Tipo</label>
                    <select id="type" name="type">
                        <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>Todos</option>
                        <option value="medical" <?= $typeFilter === 'medical' ? 'selected' : '' ?>>Médica</option>
                        <option value="maternity" <?= $typeFilter === 'maternity' ? 'selected' : '' ?>>Maternidad</option>
                        <option value="paternity" <?= $typeFilter === 'paternity' ? 'selected' : '' ?>>Paternidad</option>
                        <option value="accident" <?= $typeFilter === 'accident' ? 'selected' : '' ?>>Accidente</option>
                        <option value="surgery" <?= $typeFilter === 'surgery' ? 'selected' : '' ?>>Cirugía</option>
                        <option value="chronic" <?= $typeFilter === 'chronic' ? 'selected' : '' ?>>Crónica</option>
                    </select>
                </div>
                <div class="form-group flex-1 min-w-[200px]">
                    <label for="employee">Empleado</label>
                    <input type="text" id="employee" name="employee" value="<?= htmlspecialchars($employeeFilter) ?>" placeholder="Buscar empleado...">
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-search"></i>
                    Buscar
                </button>
                <button type="button" onclick="window.location.href='medical_leaves.php'" class="btn-secondary">
                    <i class="fas fa-redo"></i>
                    Limpiar
                </button>
            </form>
        </div>

        <!-- Medical Leaves List -->
        <div class="glass-card">
            <h2 class="text-xl font-semibold text-white mb-4">
                <i class="fas fa-list text-red-400 mr-2"></i>
                Licencias Médicas (<?= count($leaves) ?>)
            </h2>

            <?php if (empty($leaves)): ?>
                <p class="text-slate-400 text-center py-8">No se encontraron licencias médicas.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Empleado</th>
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Tipo</th>
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Fechas</th>
                                <th class="text-center py-3 px-4 text-slate-300 font-semibold">Días</th>
                                <th class="text-left py-3 px-4 text-slate-300 font-semibold">Diagnóstico</th>
                                <th class="text-center py-3 px-4 text-slate-300 font-semibold">Estado</th>
                                <th class="text-center py-3 px-4 text-slate-300 font-semibold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaves as $leave): ?>
                                <?php
                                $statusColors = [
                                    'PENDING' => 'bg-yellow-500',
                                    'APPROVED' => 'bg-green-500',
                                    'EXTENDED' => 'bg-blue-500',
                                    'COMPLETED' => 'bg-gray-500',
                                    'REJECTED' => 'bg-red-500',
                                    'CANCELLED' => 'bg-orange-500'
                                ];
                                $statusColor = $statusColors[$leave['status']] ?? 'bg-gray-500';
                                
                                $typeColors = [
                                    'MEDICAL' => 'text-red-400',
                                    'MATERNITY' => 'text-pink-400',
                                    'PATERNITY' => 'text-blue-400',
                                    'ACCIDENT' => 'text-orange-400',
                                    'SURGERY' => 'text-purple-400',
                                    'CHRONIC' => 'text-yellow-400'
                                ];
                                $typeColor = $typeColors[$leave['leave_type']] ?? 'text-gray-400';
                                
                                $isActive = in_array($leave['status'], ['APPROVED', 'EXTENDED']) && $leave['end_date'] >= date('Y-m-d');
                                ?>
                                <tr class="border-b border-slate-700/50 hover:bg-slate-800/30 transition-colors">
                                    <td class="py-3 px-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white" 
                                                 style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                                                <?= strtoupper(substr($leave['first_name'], 0, 1) . substr($leave['last_name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <p class="text-white font-medium"><?= htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']) ?></p>
                                                <p class="text-slate-400 text-sm"><?= htmlspecialchars($leave['employee_code']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="<?= $typeColor ?> font-medium">
                                            <i class="fas fa-notes-medical mr-1"></i>
                                            <?= ucfirst(strtolower($leave['leave_type'])) ?>
                                        </span>
                                        <?php if ($leave['is_work_related']): ?>
                                            <span class="block text-xs text-orange-400 mt-1">
                                                <i class="fas fa-exclamation-triangle"></i> Laboral
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <p class="text-white text-sm">
                                            <i class="fas fa-calendar-alt text-blue-400 mr-1"></i>
                                            <?= date('d/m/Y', strtotime($leave['start_date'])) ?>
                                        </p>
                                        <p class="text-slate-400 text-sm">
                                            <i class="fas fa-calendar-check text-green-400 mr-1"></i>
                                            <?= date('d/m/Y', strtotime($leave['end_date'])) ?>
                                        </p>
                                        <?php if ($isActive): ?>
                                            <span class="inline-block px-2 py-1 rounded text-xs font-semibold text-white bg-green-600 mt-1">
                                                <i class="fas fa-circle animate-pulse"></i> Activa
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="text-white font-bold text-lg"><?= number_format($leave['total_days'], 0) ?></span>
                                        <?php if ($leave['extension_count'] > 0): ?>
                                            <p class="text-blue-400 text-xs mt-1">
                                                <i class="fas fa-plus-circle"></i> <?= $leave['extension_count'] ?> ext.
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <p class="text-slate-300 text-sm">
                                            <?= htmlspecialchars($leave['diagnosis'] ?: 'No especificado') ?>
                                        </p>
                                        <?php if ($leave['medical_center']): ?>
                                            <p class="text-slate-500 text-xs mt-1">
                                                <i class="fas fa-hospital"></i> <?= htmlspecialchars($leave['medical_center']) ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold text-white <?= $statusColor ?>">
                                            <?= ucfirst(strtolower($leave['status'])) ?>
                                        </span>
                                        <?php if (!$leave['is_paid']): ?>
                                            <p class="text-red-400 text-xs mt-1">
                                                <i class="fas fa-ban"></i> No pagada
                                            </p>
                                        <?php elseif ($leave['payment_percentage'] < 100): ?>
                                            <p class="text-yellow-400 text-xs mt-1">
                                                <?= $leave['payment_percentage'] ?>% pago
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex gap-2 justify-center">
                                            <button onclick='viewLeave(<?= json_encode($leave) ?>)' class="btn-sm btn-primary" title="Ver detalles">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($leave['status'] === 'PENDING'): ?>
                                                <button onclick="reviewLeave(<?= $leave['id'] ?>, '<?= htmlspecialchars($leave['first_name'] . ' ' . $leave['last_name']) ?>')" class="btn-sm btn-secondary" title="Revisar">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if (in_array($leave['status'], ['APPROVED', 'EXTENDED'])): ?>
                                                <button onclick="extendLeave(<?= $leave['id'] ?>, '<?= $leave['end_date'] ?>')" class="btn-sm" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);" title="Extender">
                                                    <i class="fas fa-plus"></i>
                                                </button>
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

    <?php include 'medical_leaves_modals.php'; ?>
    <?php include '../footer.php'; ?>
</body>
</html>

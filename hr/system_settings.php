<?php
session_start();
require_once '../db.php';
require_once '../lib/logging_functions.php';

// Check permissions
ensurePermission('system_settings');

$success = null;
$error = null;

// Function to get a setting value
function getSystemSetting($pdo, $key, $default = null) {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['setting_value'] : $default;
}

// Function to update a setting
function updateSystemSetting($pdo, $key, $value, $userId) {
    $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = ?");
    return $stmt->execute([$value, $userId, $key]);
}

// Handle form submission
if (isset($_POST['update_settings'])) {
    try {
        $pdo->beginTransaction();
        
        $exchangeRate = !empty($_POST['exchange_rate']) ? (float)$_POST['exchange_rate'] : 58.50;
        
        // Validate exchange rate
        if ($exchangeRate <= 0) {
            throw new Exception('La tasa de cambio debe ser mayor a 0');
        }
        
        // Get old value for logging
        $oldRate = getSystemSetting($pdo, 'exchange_rate_usd_to_dop', '0');
        
        // Update exchange rate
        updateSystemSetting($pdo, 'exchange_rate_usd_to_dop', $exchangeRate, $_SESSION['user_id']);
        updateSystemSetting($pdo, 'exchange_rate_last_update', date('Y-m-d H:i:s'), $_SESSION['user_id']);
        
        $pdo->commit();
        
        // Log the change
        $change_data = [
            'old_rate' => $oldRate,
            'new_rate' => $exchangeRate,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        log_system_setting_changed($pdo, $_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['role'], 'exchange_rate_usd_to_dop', $change_data);
        
        $success = "Configuración actualizada correctamente. Nueva tasa: $exchangeRate DOP por USD";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error al actualizar la configuración: " . $e->getMessage();
    }
}

// Get current settings
$currentExchangeRate = getSystemSetting($pdo, 'exchange_rate_usd_to_dop', '58.50');
$lastUpdate = getSystemSetting($pdo, 'exchange_rate_last_update', 'N/A');

$theme = $_SESSION['theme'] ?? 'dark';
if (!in_array($theme, ['dark', 'light'], true)) {
    $theme = 'dark';
}
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/theme.css" rel="stylesheet">
    <title>Configuración del Sistema - HR</title>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <?php include '../header.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-4xl mx-auto">
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-white mb-2">
                        <i class="fas fa-cog text-blue-400 mr-3"></i>
                        Configuración del Sistema
                    </h1>
                    <p class="text-slate-400">Administra las configuraciones globales del sistema</p>
                </div>
                <a href="dashboard.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Volver al Dashboard
                </a>
            </div>
            
            <?php if ($error): ?>
                <div class="status-banner error mb-6">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="status-banner success mb-6">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>
            
            <!-- Exchange Rate Configuration -->
            <div class="glass-card mb-6">
                <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-700">
                    <div>
                        <h2 class="text-xl font-semibold text-white flex items-center">
                            <i class="fas fa-dollar-sign text-green-400 mr-3"></i>
                            Tasa de Cambio USD/DOP
                        </h2>
                        <p class="text-sm text-slate-400 mt-1">Configura la tasa de cambio para cálculos de nómina y reportes</p>
                    </div>
                </div>
                
                <form method="POST" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="form-group">
                            <label for="exchange_rate" class="flex items-center">
                                <i class="fas fa-exchange-alt text-blue-400 mr-2"></i>
                                Tasa de Cambio (1 USD = X DOP) *
                            </label>
                            <input 
                                type="number" 
                                id="exchange_rate" 
                                name="exchange_rate" 
                                step="0.01" 
                                min="0.01" 
                                required 
                                value="<?= htmlspecialchars($currentExchangeRate) ?>"
                                class="text-lg font-semibold"
                            >
                            <p class="text-xs text-slate-400 mt-2">
                                <i class="fas fa-info-circle"></i>
                                Esta tasa se usará para convertir entre USD y DOP en todos los cálculos
                            </p>
                        </div>
                        
                        <div class="bg-slate-800/50 rounded-lg p-4 border border-slate-700">
                            <div class="text-sm text-slate-400 mb-2">
                                <i class="fas fa-clock mr-2"></i>
                                Última actualización
                            </div>
                            <div class="text-white font-semibold">
                                <?= $lastUpdate !== 'N/A' ? date('d/m/Y H:i:s', strtotime($lastUpdate)) : 'N/A' ?>
                            </div>
                            <div class="text-sm text-slate-400 mt-3 mb-2">
                                <i class="fas fa-money-bill-wave mr-2"></i>
                                Tasa actual
                            </div>
                            <div class="text-2xl text-green-400 font-bold">
                                <?= number_format($currentExchangeRate, 2) ?> DOP
                            </div>
                        </div>
                    </div>
                    
                    <!-- Example Calculations -->
                    <div class="bg-blue-900/20 border border-blue-500/30 rounded-lg p-4">
                        <h3 class="text-white font-semibold mb-3 flex items-center">
                            <i class="fas fa-calculator text-blue-400 mr-2"></i>
                            Ejemplos de Conversión
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                            <div class="bg-slate-800/50 rounded p-3">
                                <div class="text-slate-400 mb-1">100 USD =</div>
                                <div class="text-white font-semibold" id="example_100">
                                    <?= number_format(100 * $currentExchangeRate, 2) ?> DOP
                                </div>
                            </div>
                            <div class="bg-slate-800/50 rounded p-3">
                                <div class="text-slate-400 mb-1">500 USD =</div>
                                <div class="text-white font-semibold" id="example_500">
                                    <?= number_format(500 * $currentExchangeRate, 2) ?> DOP
                                </div>
                            </div>
                            <div class="bg-slate-800/50 rounded p-3">
                                <div class="text-slate-400 mb-1">1,000 USD =</div>
                                <div class="text-white font-semibold" id="example_1000">
                                    <?= number_format(1000 * $currentExchangeRate, 2) ?> DOP
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex gap-3 pt-4">
                        <button type="submit" name="update_settings" class="btn-primary flex-1">
                            <i class="fas fa-save"></i>
                            Guardar Configuración
                        </button>
                        <button type="button" onclick="resetToDefault()" class="btn-secondary">
                            <i class="fas fa-undo"></i>
                            Restablecer Predeterminado (58.50)
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Information Card -->
            <div class="glass-card bg-yellow-900/20 border-yellow-500/30">
                <h3 class="text-white font-semibold mb-3 flex items-center">
                    <i class="fas fa-exclamation-triangle text-yellow-400 mr-2"></i>
                    Información Importante
                </h3>
                <ul class="text-sm text-slate-300 space-y-2">
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-1"></i>
                        <span>La tasa de cambio se aplicará a todos los cálculos de nómina, reportes y conversiones entre USD y DOP</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-1"></i>
                        <span>Los cambios en la tasa afectarán los cálculos futuros, no los registros históricos</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-1"></i>
                        <span>Se recomienda actualizar la tasa regularmente para mantener cálculos precisos</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-1"></i>
                        <span>Todos los cambios quedan registrados en el log de actividades del sistema</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    
    <?php include '../footer.php'; ?>
    
    <script>
        // Update examples in real-time
        document.getElementById('exchange_rate').addEventListener('input', function() {
            const rate = parseFloat(this.value) || 0;
            document.getElementById('example_100').textContent = (100 * rate).toFixed(2) + ' DOP';
            document.getElementById('example_500').textContent = (500 * rate).toFixed(2) + ' DOP';
            document.getElementById('example_1000').textContent = (1000 * rate).toFixed(2) + ' DOP';
        });
        
        function resetToDefault() {
            document.getElementById('exchange_rate').value = '58.50';
            document.getElementById('exchange_rate').dispatchEvent(new Event('input'));
        }
    </script>
</body>
</html>

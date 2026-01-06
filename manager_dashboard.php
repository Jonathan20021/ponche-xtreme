<?php
session_start();
require_once 'db.php';

// Check permissions - only manager role
ensurePermission('manager_dashboard');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle manager punch submission
$manager_punch_error = null;
$manager_punch_success = null;

if (isset($_SESSION['manager_punch_success'])) {
    $manager_punch_success = $_SESSION['manager_punch_success'];
    unset($_SESSION['manager_punch_success']);
}
if (isset($_SESSION['manager_punch_error'])) {
    $manager_punch_error = $_SESSION['manager_punch_error'];
    unset($_SESSION['manager_punch_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manager_punch_type'])) {
    $user_id = (int)$_SESSION['user_id'];
    $typeSlug = strtoupper(trim($_POST['manager_punch_type'] ?? ''));
    
    // Only allow specific punch types for managers
    $allowedTypes = ['ENTRY', 'BREAK', 'LUNCH', 'BANO', 'EXIT'];
    
    if (!in_array($typeSlug, $allowedTypes, true)) {
        $_SESSION['manager_punch_error'] = "Tipo de asistencia no permitido para gerentes.";
        header('Location: manager_dashboard.php');
        exit;
    }
    
    // Validate if attendance type exists and is active
    $typeStmt = $pdo->prepare("SELECT * FROM attendance_types WHERE UPPER(slug) = ? AND is_active = 1");
    $typeStmt->execute([$typeSlug]);
    $typeRow = $typeStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$typeRow) {
        $_SESSION['manager_punch_error'] = "Tipo de asistencia no válido o inactivo.";
        header('Location: manager_dashboard.php');
        exit;
    }
    
    $typeLabel = $typeRow['label'] ?? $typeSlug;
    
    // Validate unique per day constraint
    if ((int)($typeRow['is_unique_daily'] ?? 0) === 1) {
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM attendance 
            WHERE user_id = ? AND type = ? AND DATE(timestamp) = CURDATE()
        ");
        $checkStmt->execute([$user_id, $typeSlug]);
        $exists = (int)$checkStmt->fetchColumn();
        
        if ($exists > 0) {
            $_SESSION['manager_punch_error'] = "Solo puedes registrar '{$typeLabel}' una vez por día.";
            header('Location: manager_dashboard.php');
            exit;
        }
    }
    
    // Validate ENTRY/EXIT sequence
    require_once 'lib/authorization_functions.php';
    $sequenceValidation = validateEntryExitSequence($pdo, $user_id, $typeSlug);
    if (!$sequenceValidation['valid']) {
        $_SESSION['manager_punch_error'] = $sequenceValidation['message'];
        header('Location: manager_dashboard.php');
        exit;
    }
    
    // Check authorization requirements
    $authSystemEnabled = isAuthorizationSystemEnabled($pdo);
    $authRequiredForOvertime = isAuthorizationRequiredForContext($pdo, 'overtime');
    $authRequiredForEarlyPunch = isAuthorizationRequiredForContext($pdo, 'early_punch');
    $authorizationCodeId = null;
    
    // Check overtime authorization
    if ($authSystemEnabled && $authRequiredForOvertime) {
        $isOvertime = isOvertimeAttempt($pdo, $user_id, $typeSlug);
        
        if ($isOvertime) {
            $_SESSION['manager_punch_error'] = "Se requiere código de autorización para registrar hora extra. Use el formulario principal de punch.";
            header('Location: manager_dashboard.php');
            exit;
        }
    }
    
    // Check early punch authorization
    if ($authSystemEnabled && $authRequiredForEarlyPunch) {
        $isEarly = isEarlyPunchAttempt($pdo, $user_id);
        
        if ($isEarly) {
            $_SESSION['manager_punch_error'] = "Se requiere código de autorización para marcar entrada antes de su horario. Use el formulario principal de punch.";
            header('Location: manager_dashboard.php');
            exit;
        }
    }
    
    // Register the punch
    $ip_address = $_SERVER['REMOTE_ADDR'] === '::1' ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'];
    $insertStmt = $pdo->prepare("
        INSERT INTO attendance (user_id, type, ip_address, timestamp) 
        VALUES (?, ?, ?, NOW())
    ");
    $insertStmt->execute([$user_id, $typeSlug, $ip_address]);
    
    // Log attendance registration
    require_once 'lib/logging_functions.php';
    $recordId = $pdo->lastInsertId();
    log_custom_action(
        $pdo,
        $user_id,
        $_SESSION['full_name'] ?? $_SESSION['username'],
        $_SESSION['role'],
        'attendance',
        'create',
        "Registro de asistencia gerente desde dashboard: {$typeSlug}",
        'attendance_record',
        $recordId,
        ['type' => $typeSlug, 'ip_address' => $ip_address]
    );
    
    $_SESSION['manager_punch_success'] = "¡Asistencia registrada exitosamente como {$typeLabel}!";
    header('Location: manager_dashboard.php');
    exit;
}

include 'header.php';
?>

<style>
.admin-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
}

.admin-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    cursor: pointer;
}

.admin-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--punch-gradient);
    transition: all 0.3s ease;
}

.admin-card:hover {
    transform: translateY(-2px);
    border-color: var(--border-hover);
    box-shadow: var(--card-shadow-hover);
}

.admin-card:active {
    transform: scale(0.98);
}

/* Theme Variables */
.theme-dark {
    --card-bg: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.95));
    --border-color: rgba(148, 163, 184, 0.1);
    --border-hover: rgba(148, 163, 184, 0.3);
    --card-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.3);
    --text-primary: #f1f5f9;
    --text-secondary: #94a3b8;
    --text-muted: #64748b;
    --punch-status-bg: rgba(15, 23, 42, 0.6);
    --stat-badge-bg: rgba(99, 102, 241, 0.1);
    --stat-badge-border: rgba(99, 102, 241, 0.2);
    --stat-badge-text: #a5b4fc;
    --filter-btn-bg: rgba(30, 41, 59, 0.8);
    --filter-btn-border: rgba(148, 163, 184, 0.2);
    --filter-btn-text: #94a3b8;
    --filter-btn-active-bg: rgba(99, 102, 241, 0.2);
    --filter-btn-active-border: #6366f1;
    --filter-btn-active-text: #a5b4fc;
    --summary-card-bg: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.95));
    --last-update-bg: rgba(15, 23, 42, 0.4);
}

.theme-light {
    --card-bg: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(248, 250, 252, 0.95));
    --border-color: rgba(203, 213, 225, 0.5);
    --border-hover: rgba(99, 102, 241, 0.4);
    --card-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.1);
    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-muted: #64748b;
    --punch-status-bg: rgba(248, 250, 252, 0.8);
    --stat-badge-bg: rgba(99, 102, 241, 0.08);
    --stat-badge-border: rgba(99, 102, 241, 0.2);
    --stat-badge-text: #4f46e5;
    --filter-btn-bg: rgba(255, 255, 255, 0.9);
    --filter-btn-border: rgba(203, 213, 225, 0.5);
    --filter-btn-text: #475569;
    --filter-btn-active-bg: rgba(99, 102, 241, 0.1);
    --filter-btn-active-border: #6366f1;
    --filter-btn-active-text: #4f46e5;
    --summary-card-bg: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(248, 250, 252, 0.95));
    --last-update-bg: rgba(248, 250, 252, 0.6);
}

.admin-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.admin-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--punch-color-start, #6366f1), var(--punch-color-end, #4338ca));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    font-weight: 600;
    color: white;
    flex-shrink: 0;
}

.admin-info {
    flex: 1;
    min-width: 0;
}

.admin-name {
    font-weight: 600;
    font-size: 1rem;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.admin-role {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.admin-department {
    font-size: 0.7rem;
    color: var(--text-muted);
}

.punch-status {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem;
    background: var(--punch-status-bg);
    border-radius: 8px;
    margin-bottom: 0.75rem;
}

.punch-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--punch-color-start), var(--punch-color-end));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.125rem;
    color: white;
    flex-shrink: 0;
}

.punch-details {
    flex: 1;
    min-width: 0;
}

.punch-type {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-primary);
    margin-bottom: 0.125rem;
}

.punch-duration {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.admin-stats {
    display: flex;
    gap: 0.5rem;
    font-size: 0.75rem;
    flex-wrap: wrap;
}

.stat-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.625rem;
    background: var(--stat-badge-bg);
    border: 1px solid var(--stat-badge-border);
    border-radius: 6px;
    color: var(--stat-badge-text);
}

.stat-badge.paid {
    background: rgba(34, 197, 94, 0.1);
    border-color: rgba(34, 197, 94, 0.2);
    color: #86efac;
}

.stat-badge.unpaid {
    background: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.2);
    color: #fca5a5;
}

.status-offline .admin-card::before {
    background: linear-gradient(90deg, #6b7280, #4b5563);
}

.status-not-today .admin-card::before {
    background: linear-gradient(90deg, #f59e0b, #d97706);
}

.status-completed .admin-card::before {
    background: linear-gradient(90deg, #22c55e, #15803d);
}

.status-completed .admin-card .punch-duration {
    color: #22c55e;
}

/* Light theme adjustments for status colors */
.theme-light .stat-badge.paid {
    background: rgba(34, 197, 94, 0.1);
    border-color: rgba(34, 197, 94, 0.3);
    color: #15803d;
}

.theme-light .stat-badge.unpaid {
    background: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.3);
    color: #b91c1c;
}

.theme-light .summary-value.text-green-400 {
    color: #15803d !important;
}

.theme-light .summary-value.text-blue-400 {
    color: #1e40af !important;
}

.theme-light .summary-value.text-orange-400 {
    color: #c2410c !important;
}

.theme-light .summary-value.text-purple-400 {
    color: #7c3aed !important;
}

/* Modal Styles */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 1rem;
}

.modal-overlay.active {
    display: flex;
}

.modal-container {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    max-width: 1200px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.modal-close {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: var(--filter-btn-bg);
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-close:hover {
    background: rgba(239, 68, 68, 0.1);
    border-color: rgba(239, 68, 68, 0.3);
    color: #ef4444;
}

.modal-body {
    padding: 1.5rem;
}

.modal-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .modal-grid {
        grid-template-columns: 1fr;
    }
}

.modal-section {
    background: var(--punch-status-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
}

.modal-section-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.punch-timeline {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    max-height: 400px;
    overflow-y: auto;
}

.punch-timeline-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    transition: all 0.2s;
}

.punch-timeline-item:hover {
    border-color: var(--border-hover);
}

.punch-timeline-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--item-color-start), var(--item-color-end));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
}

.punch-timeline-content {
    flex: 1;
}

.punch-timeline-type {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--text-primary);
}

.punch-timeline-time {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
}

.stat-box {
    background: var(--punch-status-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
}

.stat-box-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
}

.stat-box-label {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.chart-container {
    position: relative;
    height: 300px;
    margin-top: 1rem;
}

.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.loading-spinner {
    width: 50px;
    height: 50px;
    border: 4px solid rgba(99, 102, 241, 0.2);
    border-top-color: #6366f1;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.filter-bar {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 1.5rem;
}

.filter-btn {
    padding: 0.5rem 1rem;
    background: var(--filter-btn-bg);
    border: 1px solid var(--filter-btn-border);
    border-radius: 8px;
    color: var(--filter-btn-text);
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
}

.filter-btn:hover {
    border-color: var(--filter-btn-active-border);
    color: var(--filter-btn-active-text);
}

.filter-btn.active {
    background: var(--filter-btn-active-bg);
    border-color: var(--filter-btn-active-border);
    color: var(--filter-btn-active-text);
}

.stats-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.summary-card {
    background: var(--summary-card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
}

.summary-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
}

.summary-label {
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.last-update {
    text-align: center;
    color: var(--text-muted);
    font-size: 0.75rem;
    margin-top: 1rem;
    padding: 0.5rem;
    background: var(--last-update-bg);
    border-radius: 6px;
}

.pulse-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    background: #22c55e;
    border-radius: 50%;
    margin-right: 0.5rem;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}

/* Manager Punch Button Styles */
.manager-punch-btn {
    border: none;
    cursor: pointer;
    font-family: inherit;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.manager-punch-btn:active {
    transform: scale(0.95) !important;
}

.manager-punch-btn:focus {
    outline: 2px solid rgba(255, 255, 255, 0.5);
    outline-offset: 2px;
}

@keyframes fade-in {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-fade-in {
    animation: fade-in 0.3s ease-out;
}

.role-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.role-badge.supervisor {
    background: rgba(139, 92, 246, 0.2);
    color: #a78bfa;
    border: 1px solid rgba(139, 92, 246, 0.3);
}

.role-badge.manager {
    background: rgba(236, 72, 153, 0.2);
    color: #f9a8d4;
    border: 1px solid rgba(236, 72, 153, 0.3);
}

.role-badge.hr {
    background: rgba(59, 130, 246, 0.2);
    color: #93c5fd;
    border: 1px solid rgba(59, 130, 246, 0.3);
}

.role-badge.developer {
    background: rgba(16, 185, 129, 0.2);
    color: #6ee7b7;
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.theme-light .role-badge.supervisor {
    background: rgba(139, 92, 246, 0.15);
    color: #7c3aed;
}

.theme-light .role-badge.manager {
    background: rgba(236, 72, 153, 0.15);
    color: #db2777;
}

.theme-light .role-badge.hr {
    background: rgba(59, 130, 246, 0.15);
    color: #2563eb;
}

.theme-light .role-badge.developer {
    background: rgba(16, 185, 129, 0.15);
    color: #059669;
}
</style>

<div class="container-fluid py-4">
    <!-- Manager Quick Punch Section -->
    <section class="glass-card mb-4">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-xl font-semibold text-primary flex items-center gap-2">
                    <i class="fas fa-fingerprint text-emerald-400"></i>
                    Mi Registro de Asistencia
                </h2>
                <p class="text-sm text-muted mt-1">Marca tu asistencia como gerente</p>
            </div>
        </div>
        
        <?php if ($manager_punch_success): ?>
            <div class="bg-green-500/10 border border-green-500/30 rounded-lg p-4 mb-4 animate-fade-in">
                <div class="flex items-center gap-2">
                    <i class="fas fa-check-circle text-green-400"></i>
                    <p class="text-green-300 text-sm"><?= htmlspecialchars($manager_punch_success) ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($manager_punch_error): ?>
            <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-4 animate-fade-in">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-red-400"></i>
                    <p class="text-red-300 text-sm"><?= htmlspecialchars($manager_punch_error) ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
            <?php
            // Definir los tipos de punch permitidos para gerentes
            $managerPunchTypes = [
                ['slug' => 'ENTRY', 'label' => 'Entrada', 'icon' => 'fas fa-sign-in-alt', 'color_start' => '#10b981', 'color_end' => '#059669'],
                ['slug' => 'BREAK', 'label' => 'Break', 'icon' => 'fas fa-coffee', 'color_start' => '#f59e0b', 'color_end' => '#d97706'],
                ['slug' => 'LUNCH', 'label' => 'Almuerzo', 'icon' => 'fas fa-utensils', 'color_start' => '#3b82f6', 'color_end' => '#2563eb'],
                ['slug' => 'BANO', 'label' => 'Baño', 'icon' => 'fas fa-restroom', 'color_start' => '#8b5cf6', 'color_end' => '#7c3aed'],
                ['slug' => 'EXIT', 'label' => 'Salida', 'icon' => 'fas fa-sign-out-alt', 'color_start' => '#ef4444', 'color_end' => '#dc2626'],
            ];
            
            foreach ($managerPunchTypes as $type):
            ?>
                <button type="submit" 
                        name="manager_punch_type" 
                        value="<?= htmlspecialchars($type['slug'], ENT_QUOTES, 'UTF-8') ?>" 
                        class="manager-punch-btn group relative overflow-hidden rounded-xl p-4 transition-all duration-300 hover:scale-105 hover:shadow-lg"
                        style="background: linear-gradient(135deg, <?= $type['color_start'] ?> 0%, <?= $type['color_end'] ?> 100%);">
                    <div class="absolute inset-0 bg-white/0 group-hover:bg-white/10 transition-colors duration-300"></div>
                    <div class="relative flex flex-col items-center gap-2">
                        <i class="<?= $type['icon'] ?> text-2xl text-white"></i>
                        <span class="text-xs font-semibold text-white text-center"><?= htmlspecialchars($type['label']) ?></span>
                    </div>
                </button>
            <?php endforeach; ?>
        </form>
    </section>

    <div class="glass-card mb-4">
        <div class="panel-heading">
            <div>
                <h1 class="text-primary text-2xl font-semibold mb-2">
                    <i class="fas fa-user-tie text-pink-400"></i>
                    Monitor de Personal Administrativo en Tiempo Real
                </h1>
                <p class="text-muted text-sm">Vista en vivo del estado actual de todo el personal administrativo</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2 px-3 py-2 bg-green-500/10 border border-green-500/20 rounded-lg">
                    <span class="pulse-dot"></span>
                    <span class="text-green-400 text-sm font-medium">EN VIVO</span>
                </div>
                <button onclick="refreshData()" class="btn-secondary" id="refreshBtn">
                    <i class="fas fa-sync-alt"></i>
                    Actualizar
                </button>
            </div>
        </div>
    </div>

    <!-- Stats Summary -->
    <div class="stats-summary" id="statsSummary">
        <div class="summary-card">
            <div class="summary-value" id="totalAdmins">-</div>
            <div class="summary-label">
                <i class="fas fa-users"></i> Total Personal
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-value text-green-400" id="activeAdmins">-</div>
            <div class="summary-label">
                <i class="fas fa-check-circle"></i> Activos Hoy
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-value text-blue-400" id="paidPunches">-</div>
            <div class="summary-label">
                <i class="fas fa-dollar-sign"></i> En Punch Pagado
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-value text-orange-400" id="unpaidPunches">-</div>
            <div class="summary-label">
                <i class="fas fa-pause-circle"></i> En Pausa/Break
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-value text-purple-400" id="bySupervisor">-</div>
            <div class="summary-label">
                <i class="fas fa-user-shield"></i> Supervisores
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
        <button class="filter-btn active" data-filter="all" onclick="filterAdmins('all')">
            <i class="fas fa-users"></i> Todos
        </button>
        <button class="filter-btn" data-filter="active" onclick="filterAdmins('active')">
            <i class="fas fa-check-circle"></i> Activos
        </button>
        <button class="filter-btn" data-filter="paid" onclick="filterAdmins('paid')">
            <i class="fas fa-dollar-sign"></i> Punch Pagado
        </button>
        <button class="filter-btn" data-filter="unpaid" onclick="filterAdmins('unpaid')">
            <i class="fas fa-pause-circle"></i> Pausas/Breaks
        </button>
        <button class="filter-btn" data-filter="offline" onclick="filterAdmins('offline')">
            <i class="fas fa-times-circle"></i> Sin Registro Hoy
        </button>
        <button class="filter-btn" data-filter="supervisor" onclick="filterAdmins('supervisor')">
            <i class="fas fa-user-shield"></i> Supervisores
        </button>
        <button class="filter-btn" data-filter="hr" onclick="filterAdmins('hr')">
            <i class="fas fa-user-friends"></i> HR
        </button>
        <button class="filter-btn" data-filter="manager" onclick="filterAdmins('manager')">
            <i class="fas fa-user-tie"></i> Gerentes
        </button>
    </div>

    <!-- Admins Grid -->
    <div class="admin-grid" id="adminsGrid">
        <div class="text-center py-8 text-muted">
            <i class="fas fa-spinner fa-spin text-4xl mb-3"></i>
            <p>Cargando datos en tiempo real...</p>
        </div>
    </div>

    <div class="last-update" id="lastUpdate">
        Última actualización: Cargando...
    </div>
</div>

<!-- Modal de Detalles del Empleado -->
<div class="modal-overlay" id="adminModal" onclick="closeModalOnOverlay(event)">
    <div class="modal-container">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-user-circle"></i>
                <span id="modalAdminName">Cargando...</span>
            </div>
            <button class="modal-close" onclick="closeAdminModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body">
            <!-- Estadísticas Rápidas -->
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-box-value" id="modalTotalPunches">-</div>
                    <div class="stat-box-label">
                        <i class="fas fa-fingerprint"></i> Total Punches
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-box-value text-green-400" id="modalPaidTime">-</div>
                    <div class="stat-box-label">
                        <i class="fas fa-dollar-sign"></i> Tiempo Pagado
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-box-value text-orange-400" id="modalUnpaidTime">-</div>
                    <div class="stat-box-label">
                        <i class="fas fa-pause-circle"></i> Tiempo No Pagado
                    </div>
                </div>
            </div>

            <!-- Grid de Secciones -->
            <div class="modal-grid">
                <!-- Historial de Punches -->
                <div class="modal-section">
                    <div class="modal-section-title">
                        <i class="fas fa-history"></i>
                        Historial del Día
                        <span class="pulse-dot" style="margin-left: auto;"></span>
                    </div>
                    <div class="punch-timeline" id="punchTimeline">
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-spinner fa-spin"></i>
                            <p class="text-sm mt-2">Cargando historial...</p>
                        </div>
                    </div>
                </div>

                <!-- Gráfica de Distribución -->
                <div class="modal-section">
                    <div class="modal-section-title">
                        <i class="fas fa-chart-pie"></i>
                        Distribución de Tiempo
                    </div>
                    <div class="chart-container">
                        <canvas id="timeDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Detalles por Tipo de Punch -->
            <div class="modal-section">
                <div class="modal-section-title">
                    <i class="fas fa-list-ul"></i>
                    Desglose por Tipo de Punch
                </div>
                <div id="punchBreakdown">
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let adminsData = [];
let currentFilter = 'all';
let refreshInterval;
let currentAdminId = null;
let modalRefreshInterval = null;
let adminChart = null;
let lastChartData = null; // Para comparar y evitar recrear la gráfica innecesariamente

// Inicializar al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    refreshData();
    startAutoRefresh();
});

function startAutoRefresh() {
    // Actualizar cada 30 segundos (reducido para evitar rate limiting)
    refreshInterval = setInterval(refreshData, 30000);
}

function stopAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
}

async function refreshData() {
    const refreshBtn = document.getElementById('refreshBtn');
    const icon = refreshBtn.querySelector('i');
    
    icon.classList.add('fa-spin');
    
    try {
        const response = await fetch('manager_realtime_api.php');
        const data = await response.json();
        
        if (data.success) {
            adminsData = data.administrative_staff;
            updateStats(data);
            filterAdmins(currentFilter);
            updateLastUpdateTime(data.timestamp);
        } else {
            console.error('Error:', data.error);
        }
    } catch (error) {
        console.error('Error al obtener datos:', error);
    } finally {
        icon.classList.remove('fa-spin');
    }
}

function updateStats(data) {
    const activeCount = data.administrative_staff.filter(a => a.status === 'active').length;
    const paidCount = data.administrative_staff.filter(a => a.status === 'active' && a.current_punch.is_paid === 1).length;
    const unpaidCount = data.administrative_staff.filter(a => a.status === 'active' && a.current_punch.is_paid === 0).length;
    const supervisorCount = data.administrative_staff.filter(a => a.role === 'supervisor').length;
    
    document.getElementById('totalAdmins').textContent = data.total_administrative;
    document.getElementById('activeAdmins').textContent = activeCount;
    document.getElementById('paidPunches').textContent = paidCount;
    document.getElementById('unpaidPunches').textContent = unpaidCount;
    document.getElementById('bySupervisor').textContent = supervisorCount;
}

function renderAdmins(admins) {
    const grid = document.getElementById('adminsGrid');
    
    if (admins.length === 0) {
        grid.innerHTML = `
            <div class="text-center py-8 text-muted col-span-full">
                <i class="fas fa-users-slash text-4xl mb-3"></i>
                <p>No hay personal administrativo para mostrar</p>
            </div>
        `;
        return;
    }
    
    grid.innerHTML = admins.map(admin => createAdminCard(admin)).join('');
}

function createAdminCard(admin) {
    const initials = admin.full_name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
    const statusClass = `status-${admin.status}`;
    const paidBadge = admin.current_punch.is_paid === 1 ? 'paid' : 'unpaid';
    const paidLabel = admin.current_punch.is_paid === 1 ? 'Pagado' : 'No Pagado';
    
    const roleLabels = {
        'supervisor': 'Supervisor',
        'manager': 'Gerente',
        'hr': 'HR',
        'developer': 'Desarrollador',
        'operations': 'Operaciones'
    };
    
    const roleLabel = roleLabels[admin.role] || admin.role;
    
    return `
        <div class="admin-card ${statusClass}" 
             data-status="${admin.status}" 
             data-paid="${admin.current_punch.is_paid}"
             data-role="${admin.role}"
             data-user-id="${admin.user_id}"
             onclick="openAdminModal(${admin.user_id}, '${admin.full_name.replace(/'/g, "\\'")}')"
             style="--punch-gradient: linear-gradient(90deg, ${admin.current_punch.color_start}, ${admin.current_punch.color_end}); --punch-color-start: ${admin.current_punch.color_start}; --punch-color-end: ${admin.current_punch.color_end};">
            
            <div class="admin-header">
                <div class="admin-avatar">${initials}</div>
                <div class="admin-info">
                    <div class="admin-name" title="${admin.full_name}">${admin.full_name}</div>
                    <div class="admin-role">
                        <span class="role-badge ${admin.role}">${roleLabel}</span>
                    </div>
                    <div class="admin-department">
                        <i class="fas fa-building text-xs"></i> ${admin.department}
                    </div>
                </div>
            </div>
            
            <div class="punch-status">
                <div class="punch-icon">
                    <i class="${admin.current_punch.icon}"></i>
                </div>
                <div class="punch-details">
                    <div class="punch-type">${admin.current_punch.label}</div>
                    <div class="punch-duration">
                        <i class="fas fa-clock text-xs"></i> ${admin.current_punch.duration_formatted}
                    </div>
                </div>
            </div>
            
            <div class="admin-stats">
                <div class="stat-badge">
                    <i class="fas fa-fingerprint"></i>
                    ${admin.punches_today} punches hoy
                </div>
                <div class="stat-badge ${paidBadge}">
                    <i class="fas ${admin.current_punch.is_paid === 1 ? 'fa-dollar-sign' : 'fa-pause-circle'}"></i>
                    ${paidLabel}
                </div>
            </div>
        </div>
    `;
}

function filterAdmins(filter) {
    currentFilter = filter;
    
    // Update button states
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`[data-filter="${filter}"]`).classList.add('active');
    
    // Filter administrative staff
    let filtered = adminsData;
    
    switch(filter) {
        case 'active':
            filtered = adminsData.filter(a => a.status === 'active');
            break;
        case 'paid':
            filtered = adminsData.filter(a => a.status === 'active' && a.current_punch.is_paid === 1);
            break;
        case 'unpaid':
            filtered = adminsData.filter(a => a.status === 'active' && a.current_punch.is_paid === 0);
            break;
        case 'offline':
            filtered = adminsData.filter(a => a.status !== 'active');
            break;
        case 'supervisor':
            filtered = adminsData.filter(a => a.role === 'supervisor');
            break;
        case 'hr':
            filtered = adminsData.filter(a => a.role === 'hr');
            break;
        case 'manager':
            filtered = adminsData.filter(a => a.role === 'manager');
            break;
    }
    
    renderAdmins(filtered);
}

function updateLastUpdateTime(timestamp) {
    const date = new Date(timestamp);
    const formatted = date.toLocaleString('es-DO', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
    document.getElementById('lastUpdate').innerHTML = `
        <span class="pulse-dot"></span>
        Última actualización: ${formatted} - Actualización automática cada 5 segundos
    `;
}

// Limpiar intervalo al salir de la página
window.addEventListener('beforeunload', function() {
    stopAutoRefresh();
});

// ===== MODAL FUNCTIONALITY =====
function openAdminModal(userId, fullName) {
    currentAdminId = userId;
    document.getElementById('modalAdminName').textContent = fullName;
    document.getElementById('adminModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    
    // Cargar datos del empleado
    loadAdminDetails(userId);
    
    // Iniciar actualización automática del modal cada 3 segundos
    if (modalRefreshInterval) {
        clearInterval(modalRefreshInterval);
    }
    modalRefreshInterval = setInterval(() => {
        if (currentAdminId) {
            loadAdminDetails(currentAdminId);
        }
    }, 3000);
}

function closeAdminModal() {
    document.getElementById('adminModal').classList.remove('active');
    document.body.style.overflow = 'auto';
    currentAdminId = null;
    
    // Detener actualización del modal
    if (modalRefreshInterval) {
        clearInterval(modalRefreshInterval);
        modalRefreshInterval = null;
    }
    
    // Destruir gráfica
    if (adminChart) {
        adminChart.destroy();
        adminChart = null;
    }
    
    // Limpiar datos de comparación
    lastChartData = null;
}

function closeModalOnOverlay(event) {
    if (event.target.id === 'adminModal') {
        closeAdminModal();
    }
}

async function loadAdminDetails(userId) {
    try {
        const timestamp = new Date().getTime();
        const response = await fetch(`manager_admin_details_api.php?user_id=${userId}&_=${timestamp}`, {
            cache: 'no-cache',
            headers: {
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache'
            }
        });
        const data = await response.json();
        
        if (data.success) {
            updateModalStats(data.stats);
            updatePunchTimeline(data.punches);
            updatePunchBreakdown(data.stats.by_type);
            updateChart(data.chart_data);
        } else {
            console.error('Error:', data.error);
        }
    } catch (error) {
        console.error('Error al cargar detalles:', error);
    }
}

function updateModalStats(stats) {
    document.getElementById('modalTotalPunches').textContent = stats.total_punches;
    document.getElementById('modalPaidTime').textContent = stats.total_paid_time_formatted;
    document.getElementById('modalUnpaidTime').textContent = stats.total_unpaid_time_formatted;
}

function updatePunchTimeline(punches) {
    const timeline = document.getElementById('punchTimeline');
    
    if (!Array.isArray(punches) || punches.length === 0) {
        timeline.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="fas fa-inbox text-2xl mb-2"></i>
                <p class="text-sm">No hay punches registrados hoy</p>
            </div>
        `;
        return;
    }

    const timelineItems = punches.map(punch => {
        const paidLabel = punch.is_paid ? 
            '<span class="ml-2 text-green-400"><i class="fas fa-dollar-sign"></i> Pagado</span>' : 
            '<span class="ml-2 text-orange-400"><i class="fas fa-pause-circle"></i> No pagado</span>';
        
        return `
            <div class="punch-timeline-item" style="--item-color-start: ${punch.color_start}; --item-color-end: ${punch.color_end};">
                <div class="punch-timeline-icon">
                    <i class="${punch.icon}"></i>
                </div>
                <div class="punch-timeline-content">
                    <div class="punch-timeline-type">${punch.type_label}</div>
                    <div class="punch-timeline-time">
                        <i class="fas fa-clock"></i> ${punch.time}
                        ${paidLabel}
                    </div>
                </div>
            </div>
        `;
    }).join('');

    timeline.innerHTML = timelineItems;
}

function updatePunchBreakdown(byType) {
    const breakdown = document.getElementById('punchBreakdown');
    
    if (Object.keys(byType).length === 0) {
        breakdown.innerHTML = `
            <div class="text-center text-muted py-4">
                <p class="text-sm">No hay datos disponibles</p>
            </div>
        `;
        return;
    }
    
    const items = Object.entries(byType).map(([type, data]) => {
        const paidBadge = data.is_paid ? 'paid' : 'unpaid';
        const paidLabel = data.is_paid ? 'Pagado' : 'No Pagado';
        
        return `
            <div class="flex items-center justify-between p-3 bg-opacity-50 rounded-lg mb-2" style="background: var(--punch-status-bg);">
                <div class="flex-1">
                    <div class="font-semibold text-sm" style="color: var(--text-primary);">${data.label}</div>
                    <div class="text-xs" style="color: var(--text-secondary);">
                        ${data.count} ${data.count === 1 ? 'vez' : 'veces'} • ${data.total_time_formatted}
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <div class="stat-badge ${paidBadge} text-xs">
                        ${paidLabel}
                    </div>
                    <div class="text-lg font-bold" style="color: var(--text-primary);">
                        ${data.percentage}%
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    breakdown.innerHTML = items;
}

function updateChart(chartData) {
    // Comparar datos para evitar recrear la gráfica si no cambiaron
    const currentDataString = JSON.stringify(chartData);
    if (lastChartData === currentDataString && adminChart) {
        // Los datos no cambiaron, no hacer nada
        return;
    }
    
    // Actualizar datos guardados
    lastChartData = currentDataString;
    
    const canvas = document.getElementById('timeDistributionChart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    // Si la gráfica ya existe, actualizar sus datos en lugar de recrearla
    if (adminChart) {
        adminChart.data.labels = chartData.labels;
        adminChart.data.datasets[0].data = chartData.data;
        adminChart.data.datasets[0].backgroundColor = chartData.colors;
        adminChart.update('none'); // 'none' evita animaciones en actualizaciones
        return;
    }
    
    // Crear nueva gráfica solo si no existe
    adminChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: chartData.labels,
            datasets: [{
                data: chartData.data,
                backgroundColor: chartData.colors,
                borderWidth: 2,
                borderColor: getComputedStyle(document.body).getPropertyValue('--border-color') || '#1e293b'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 750
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: getComputedStyle(document.body).getPropertyValue('--text-primary') || '#f1f5f9',
                        padding: 15,
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const isPaid = chartData.isPaid[context.dataIndex];
                            const paidLabel = isPaid ? '💰 Pagado' : '⏸️ No Pagado';
                            return `${label}: ${value.toFixed(1)} min (${paidLabel})`;
                        }
                    }
                }
            }
        }
    });
}

// Cerrar modal con tecla ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && currentAdminId) {
        closeAdminModal();
    }
});
</script>

<?php include 'footer.php'; ?>

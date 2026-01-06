<?php
session_start();
require_once 'db.php';

// Check permissions
ensurePermission('supervisor_dashboard');

$theme = $_SESSION['theme'] ?? 'dark';
$bodyClass = $theme === 'light' ? 'theme-light' : 'theme-dark';

// Handle supervisor punch submission
$supervisor_punch_error = null;
$supervisor_punch_success = null;

if (isset($_SESSION['supervisor_punch_success'])) {
    $supervisor_punch_success = $_SESSION['supervisor_punch_success'];
    unset($_SESSION['supervisor_punch_success']);
}
if (isset($_SESSION['supervisor_punch_error'])) {
    $supervisor_punch_error = $_SESSION['supervisor_punch_error'];
    unset($_SESSION['supervisor_punch_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['supervisor_punch_type'])) {
    $user_id = (int)$_SESSION['user_id'];
    $typeSlug = strtoupper(trim($_POST['supervisor_punch_type'] ?? ''));
    
    // Only allow specific punch types for supervisors
    $allowedTypes = ['ENTRY', 'BREAK', 'LUNCH', 'BANO', 'EXIT'];
    
    if (!in_array($typeSlug, $allowedTypes, true)) {
        $_SESSION['supervisor_punch_error'] = "Tipo de asistencia no permitido para supervisores.";
        header('Location: supervisor_dashboard.php');
        exit;
    }
    
    // Validate if attendance type exists and is active
    $typeStmt = $pdo->prepare("SELECT * FROM attendance_types WHERE UPPER(slug) = ? AND is_active = 1");
    $typeStmt->execute([$typeSlug]);
    $typeRow = $typeStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$typeRow) {
        $_SESSION['supervisor_punch_error'] = "Tipo de asistencia no válido o inactivo.";
        header('Location: supervisor_dashboard.php');
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
            $_SESSION['supervisor_punch_error'] = "Solo puedes registrar '{$typeLabel}' una vez por día.";
            header('Location: supervisor_dashboard.php');
            exit;
        }
    }
    
    // Validate ENTRY/EXIT sequence
    require_once 'lib/authorization_functions.php';
    $sequenceValidation = validateEntryExitSequence($pdo, $user_id, $typeSlug);
    if (!$sequenceValidation['valid']) {
        $_SESSION['supervisor_punch_error'] = $sequenceValidation['message'];
        header('Location: supervisor_dashboard.php');
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
            $_SESSION['supervisor_punch_error'] = "Se requiere código de autorización para registrar hora extra. Use el formulario principal de punch.";
            header('Location: supervisor_dashboard.php');
            exit;
        }
    }
    
    // Check early punch authorization
    if ($authSystemEnabled && $authRequiredForEarlyPunch) {
        $isEarly = isEarlyPunchAttempt($pdo, $user_id);
        
        if ($isEarly) {
            $_SESSION['supervisor_punch_error'] = "Se requiere código de autorización para marcar entrada antes de su horario. Use el formulario principal de punch.";
            header('Location: supervisor_dashboard.php');
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
        "Registro de asistencia supervisor desde dashboard: {$typeSlug}",
        'attendance_record',
        $recordId,
        ['type' => $typeSlug, 'ip_address' => $ip_address]
    );
    
    $_SESSION['supervisor_punch_success'] = "¡Asistencia registrada exitosamente como {$typeLabel}!";
    header('Location: supervisor_dashboard.php');
    exit;
}

include 'header.php';
?>

<style>
.supervisor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
}

.agent-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.agent-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--punch-gradient);
    transition: all 0.3s ease;
}

.agent-card:hover {
    transform: translateY(-2px);
    border-color: var(--border-hover);
    box-shadow: var(--card-shadow-hover);
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

.agent-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.agent-avatar {
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

.agent-info {
    flex: 1;
    min-width: 0;
}

.agent-name {
    font-weight: 600;
    font-size: 1rem;
    color: var(--text-primary);
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.agent-department {
    font-size: 0.75rem;
    color: var(--text-secondary);
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

.agent-stats {
    display: flex;
    gap: 0.5rem;
    font-size: 0.75rem;
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

.status-offline .agent-card::before {
    background: linear-gradient(90deg, #6b7280, #4b5563);
}

.status-not-today .agent-card::before {
    background: linear-gradient(90deg, #f59e0b, #d97706);
}

.status-completed .agent-card::before {
    background: linear-gradient(90deg, #22c55e, #15803d);
}

.status-completed .agent-card .punch-duration {
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

.theme-light .ninja-edit-btn {
    background: rgba(99, 102, 241, 0.12);
    border-color: rgba(99, 102, 241, 0.4);
    color: #4338ca;
}

.theme-light .ninja-edit-btn:hover {
    background: rgba(99, 102, 241, 0.2);
}

.theme-light .punch-edit-controls {
    background: rgba(99, 102, 241, 0.08);
    border-color: rgba(99, 102, 241, 0.35);
}

.theme-light .punch-edit-row button {
    background: rgba(16, 185, 129, 0.15);
    color: #047857;
    border-color: rgba(16, 185, 129, 0.4);
}

.theme-light .punch-edit-row button:hover:not(:disabled) {
    background: rgba(16, 185, 129, 0.25);
}

.theme-light .punch-create-container {
    background: rgba(99, 102, 241, 0.06);
    border-color: rgba(99, 102, 241, 0.4);
}

.theme-light .ninja-add-btn {
    background: rgba(16, 185, 129, 0.12);
    border-color: rgba(16, 185, 129, 0.4);
    color: #047857;
}

.theme-light .ninja-add-btn:hover {
    background: rgba(16, 185, 129, 0.2);
}

.theme-light .punch-edit-cancel {
    background: rgba(239, 68, 68, 0.12);
    color: #b91c1c;
    border-color: rgba(239, 68, 68, 0.4);
}

.theme-light .punch-edit-status {
    background: rgba(0, 0, 0, 0.05);
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

.punch-timeline-actions {
    margin-top: 0.75rem;
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

.punch-create-container {
    margin-bottom: 1rem;
    padding: 1rem;
    border: 2px dashed rgba(99, 102, 241, 0.4);
    border-radius: 12px;
    background: rgba(99, 102, 241, 0.08);
}

.punch-create-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.punch-create-title {
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.ninja-add-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.9rem;
    border-radius: 8px;
    border: 1px solid rgba(16, 185, 129, 0.4);
    background: rgba(16, 185, 129, 0.15);
    color: #34d399;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.ninja-add-btn:hover {
    background: rgba(16, 185, 129, 0.25);
    border-color: rgba(16, 185, 129, 0.6);
    transform: translateY(-1px);
}

.ninja-edit-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.7rem;
    border-radius: 7px;
    border: 1px solid rgba(99, 102, 241, 0.4);
    background: rgba(99, 102, 241, 0.15);
    color: #c7d2fe;
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.ninja-edit-btn:hover {
    background: rgba(99, 102, 241, 0.25);
    border-color: rgba(99, 102, 241, 0.6);
    transform: translateY(-1px);
}

.punch-edit-controls {
    margin-top: 0.75rem;
    padding: 1rem;
    border-radius: 10px;
    border: 2px solid rgba(99, 102, 241, 0.3);
    background: rgba(99, 102, 241, 0.1);
    animation: slideDown 0.2s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        max-height: 0;
        padding: 0 1rem;
    }
    to {
        opacity: 1;
        max-height: 200px;
        padding: 1rem;
    }
}

.punch-edit-controls.is-hidden {
    display: none;
}

.punch-edit-row {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.punch-edit-row select,
.punch-edit-row input[type="time"] {
    flex: 1;
    min-width: 120px;
    background: var(--card-bg);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 0.5rem 0.75rem;
    font-size: 0.85rem;
    font-weight: 500;
}

.punch-edit-row input[type="time"] {
    flex: 0 0 140px;
    min-width: 140px;
}

.punch-edit-row select:focus,
.punch-edit-row input[type="time"]:focus {
    outline: none;
    border-color: rgba(99, 102, 241, 0.5);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

/* Estilos para el input de hora en tema claro */
.theme-light .punch-edit-row input[type="time"] {
    color-scheme: light;
}

/* Estilos para el input de hora en tema oscuro */
.theme-dark .punch-edit-row input[type="time"] {
    color-scheme: dark;
}

.punch-edit-row button {
    background: rgba(16, 185, 129, 0.15);
    color: #34d399;
    border: 1px solid rgba(16, 185, 129, 0.4);
    border-radius: 8px;
    padding: 0.5rem 0.9rem;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.punch-edit-row button:hover:not(:disabled) {
    background: rgba(16, 185, 129, 0.25);
    transform: translateY(-1px);
}

.punch-edit-row button[disabled] {
    opacity: 0.6;
    cursor: not-allowed;
}

.punch-edit-cancel {
    background: rgba(239, 68, 68, 0.1);
    color: #fca5a5;
    border-color: rgba(239, 68, 68, 0.3);
}

.punch-edit-cancel:hover:not(:disabled) {
    background: rgba(239, 68, 68, 0.2);
    transform: translateY(-1px);
}

.punch-edit-status {
    margin-top: 0.75rem;
    padding: 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--text-secondary);
    text-align: center;
    border-radius: 6px;
    background: rgba(0, 0, 0, 0.2);
}

.interaction-indicator {
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
    display: none;
    align-items: center;
    gap: 0.4rem;
    padding: 0.35rem 0.65rem;
    background: rgba(99, 102, 241, 0.15);
    border: 1px solid rgba(99, 102, 241, 0.3);
    border-radius: 6px;
    font-size: 0.7rem;
    color: #a5b4fc;
    z-index: 10;
}

.interaction-indicator.active {
    display: flex;
}

.interaction-indicator i {
    font-size: 0.65rem;
}

.punch-create-container {
    position: relative;
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

.agent-card {
    cursor: pointer;
}

.agent-card:active {
    transform: scale(0.98);
}

/* Supervisor Punch Button Styles */
.supervisor-punch-btn {
    border: none;
    cursor: pointer;
    font-family: inherit;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

.supervisor-punch-btn:active {
    transform: scale(0.95) !important;
}

.supervisor-punch-btn:focus {
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
</style>

<div class="container-fluid py-4">
    <!-- Supervisor Quick Punch Section -->
    <section class="glass-card mb-4">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-xl font-semibold text-primary flex items-center gap-2">
                    <i class="fas fa-fingerprint text-emerald-400"></i>
                    Mi Registro de Asistencia
                </h2>
                <p class="text-sm text-muted mt-1">Marca tu asistencia como supervisor</p>
            </div>
        </div>
        
        <?php if ($supervisor_punch_success): ?>
            <div class="bg-green-500/10 border border-green-500/30 rounded-lg p-4 mb-4 animate-fade-in">
                <div class="flex items-center gap-2">
                    <i class="fas fa-check-circle text-green-400"></i>
                    <p class="text-green-300 text-sm"><?= htmlspecialchars($supervisor_punch_success) ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($supervisor_punch_error): ?>
            <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-4 animate-fade-in">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-circle text-red-400"></i>
                    <p class="text-red-300 text-sm"><?= htmlspecialchars($supervisor_punch_error) ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
            <?php
            // Definir los tipos de punch permitidos para supervisores con sus configuraciones
            $supervisorPunchTypes = [
                ['slug' => 'ENTRY', 'label' => 'Entrada', 'icon' => 'fas fa-sign-in-alt', 'color_start' => '#10b981', 'color_end' => '#059669'],
                ['slug' => 'BREAK', 'label' => 'Break', 'icon' => 'fas fa-coffee', 'color_start' => '#f59e0b', 'color_end' => '#d97706'],
                ['slug' => 'LUNCH', 'label' => 'Almuerzo', 'icon' => 'fas fa-utensils', 'color_start' => '#3b82f6', 'color_end' => '#2563eb'],
                ['slug' => 'BANO', 'label' => 'Baño', 'icon' => 'fas fa-restroom', 'color_start' => '#8b5cf6', 'color_end' => '#7c3aed'],
                ['slug' => 'EXIT', 'label' => 'Salida', 'icon' => 'fas fa-sign-out-alt', 'color_start' => '#ef4444', 'color_end' => '#dc2626'],
            ];
            
            foreach ($supervisorPunchTypes as $type):
            ?>
                <button type="submit" 
                        name="supervisor_punch_type" 
                        value="<?= htmlspecialchars($type['slug'], ENT_QUOTES, 'UTF-8') ?>" 
                        class="supervisor-punch-btn group relative overflow-hidden rounded-xl p-4 transition-all duration-300 hover:scale-105 hover:shadow-lg"
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
                    <i class="fas fa-users-cog text-cyan-400"></i>
                    Monitor de Agentes en Tiempo Real
                </h1>
                <p class="text-muted text-sm">Vista en vivo del estado actual de todos los agentes</p>
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
            <div class="summary-value" id="totalAgents">-</div>
            <div class="summary-label">
                <i class="fas fa-users"></i> Total Agentes
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-value text-green-400" id="activeAgents">-</div>
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
    </div>

    <!-- Filters -->
    <div class="filter-bar">
        <button class="filter-btn active" data-filter="all" onclick="filterAgents('all')">
            <i class="fas fa-users"></i> Todos
        </button>
        <button class="filter-btn" data-filter="active" onclick="filterAgents('active')">
            <i class="fas fa-check-circle"></i> Activos
        </button>
        <button class="filter-btn" data-filter="paid" onclick="filterAgents('paid')">
            <i class="fas fa-dollar-sign"></i> Punch Pagado
        </button>
        <button class="filter-btn" data-filter="unpaid" onclick="filterAgents('unpaid')">
            <i class="fas fa-pause-circle"></i> Pausas/Breaks
        </button>
        <button class="filter-btn" data-filter="offline" onclick="filterAgents('offline')">
            <i class="fas fa-times-circle"></i> Sin Registro Hoy
        </button>
    </div>

    <!-- Agents Grid -->
    <div class="supervisor-grid" id="agentsGrid">
        <div class="text-center py-8 text-muted">
            <i class="fas fa-spinner fa-spin text-4xl mb-3"></i>
            <p>Cargando datos en tiempo real...</p>
        </div>
    </div>

    <div class="last-update" id="lastUpdate">
        Última actualización: Cargando...
    </div>
</div>

<!-- Modal de Detalles del Agente -->
<div class="modal-overlay" id="agentModal" onclick="closeModalOnOverlay(event)">
    <div class="modal-container">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-user-circle"></i>
                <span id="modalAgentName">Cargando...</span>
            </div>
            <button class="modal-close" onclick="closeAgentModal()">
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
let agentsData = [];
let punchTypesCache = [];
let modalPunchesCache = [];
let punchStatusMessages = {};
let activePunchEditorId = null;
let currentFilter = 'all';
let refreshInterval;
let isUserInteracting = false;
let interactionTimeout = null;

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
        const response = await fetch('supervisor_realtime_api.php');
        const data = await response.json();
        
        if (data.success) {
            agentsData = data.agents;
            if (Array.isArray(data.types_available)) {
                punchTypesCache = data.types_available;
            }
            updateStats(data);
            
            // Aplicar el filtro actual en lugar de mostrar todos
            filterAgents(currentFilter);
            
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
    const activeCount = data.agents.filter(a => a.status === 'active').length;
    const paidCount = data.agents.filter(a => a.status === 'active' && a.current_punch.is_paid === 1).length;
    const unpaidCount = data.agents.filter(a => a.status === 'active' && a.current_punch.is_paid === 0).length;
    
    document.getElementById('totalAgents').textContent = data.total_agents;
    document.getElementById('activeAgents').textContent = activeCount;
    document.getElementById('paidPunches').textContent = paidCount;
    document.getElementById('unpaidPunches').textContent = unpaidCount;
}

function renderAgents(agents) {
    const grid = document.getElementById('agentsGrid');
    
    if (agents.length === 0) {
        grid.innerHTML = `
            <div class="text-center py-8 text-muted col-span-full">
                <i class="fas fa-users-slash text-4xl mb-3"></i>
                <p>No hay agentes para mostrar</p>
            </div>
        `;
        return;
    }
    
    grid.innerHTML = agents.map(agent => createAgentCard(agent)).join('');
}

function createAgentCard(agent) {
    const initials = agent.full_name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
    const statusClass = `status-${agent.status}`;
    const paidBadge = agent.current_punch.is_paid === 1 ? 'paid' : 'unpaid';
    const paidLabel = agent.current_punch.is_paid === 1 ? 'Pagado' : 'No Pagado';
    
    // Badge de campaña si existe
    const campaignBadge = agent.campaign && agent.campaign.code ? `
        <div class="stat-badge" style="background: ${agent.campaign.color}15; border-color: ${agent.campaign.color}40; color: ${agent.campaign.color};">
            <i class="fas fa-bullhorn"></i>
            ${agent.campaign.code}
        </div>
    ` : '';
    
    return `
        <div class="agent-card ${statusClass}" 
             data-status="${agent.status}" 
             data-paid="${agent.current_punch.is_paid}"
             data-user-id="${agent.user_id}"
             data-campaign="${agent.campaign ? agent.campaign.code : ''}"
             onclick="openAgentModal(${agent.user_id}, '${agent.full_name}')"
             style="--punch-gradient: linear-gradient(90deg, ${agent.current_punch.color_start}, ${agent.current_punch.color_end}); --punch-color-start: ${agent.current_punch.color_start}; --punch-color-end: ${agent.current_punch.color_end};">
            
            <div class="agent-header">
                <div class="agent-avatar">${initials}</div>
                <div class="agent-info">
                    <div class="agent-name" title="${agent.full_name}">${agent.full_name}</div>
                    <div class="agent-department">
                        <i class="fas fa-building text-xs"></i> ${agent.department}
                    </div>
                </div>
            </div>
            
            <div class="punch-status">
                <div class="punch-icon">
                    <i class="${agent.current_punch.icon}"></i>
                </div>
                <div class="punch-details">
                    <div class="punch-type">${agent.current_punch.label}</div>
                    <div class="punch-duration">
                        <i class="fas fa-clock text-xs"></i> ${agent.current_punch.duration_formatted}
                    </div>
                </div>
            </div>
            
            <div class="agent-stats">
                <div class="stat-badge">
                    <i class="fas fa-fingerprint"></i>
                    ${agent.punches_today} punches hoy
                </div>
                <div class="stat-badge ${paidBadge}">
                    <i class="fas ${agent.current_punch.is_paid === 1 ? 'fa-dollar-sign' : 'fa-pause-circle'}"></i>
                    ${paidLabel}
                </div>
                ${campaignBadge}
            </div>
        </div>
    `;
}

function filterAgents(filter) {
    currentFilter = filter;
    
    // Update button states
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`[data-filter="${filter}"]`).classList.add('active');
    
    // Filter agents
    let filtered = agentsData;
    
    switch(filter) {
        case 'active':
            filtered = agentsData.filter(a => a.status === 'active');
            break;
        case 'paid':
            filtered = agentsData.filter(a => a.status === 'active' && a.current_punch.is_paid === 1);
            break;
        case 'unpaid':
            filtered = agentsData.filter(a => a.status === 'active' && a.current_punch.is_paid === 0);
            break;
        case 'offline':
            filtered = agentsData.filter(a => a.status !== 'active');
            break;
    }
    
    renderAgents(filtered);
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
let currentAgentId = null;
let modalRefreshInterval = null;
let agentChart = null;
let lastChartData = null; // Para comparar y evitar recrear la gráfica innecesariamente

function openAgentModal(userId, fullName) {
    currentAgentId = userId;
    document.getElementById('modalAgentName').textContent = fullName;
    document.getElementById('agentModal').classList.add('active');
    document.body.style.overflow = 'hidden';
    activePunchEditorId = null;
    punchStatusMessages = {};
    modalPunchesCache = [];
    
    // Cargar datos del agente
    loadAgentDetails(userId);
    
    // Iniciar actualización automática del modal cada 3 segundos
    if (modalRefreshInterval) {
        clearInterval(modalRefreshInterval);
    }
    modalRefreshInterval = setInterval(() => {
        if (currentAgentId && !isUserInteracting) {
            // Preservar el estado del editor activo durante las actualizaciones automáticas
            // Solo si el usuario NO está interactuando con los controles
            loadAgentDetails(currentAgentId, true);
        }
    }, 3000);
}

function closeAgentModal() {
    hideAllPunchEditors();
    document.getElementById('agentModal').classList.remove('active');
    document.body.style.overflow = 'auto';
    currentAgentId = null;
    activePunchEditorId = null;
    punchStatusMessages = {};
    modalPunchesCache = [];
    
    // Limpiar estado de interacción
    isUserInteracting = false;
    if (interactionTimeout) {
        clearTimeout(interactionTimeout);
        interactionTimeout = null;
    }
    
    // Detener actualización del modal
    if (modalRefreshInterval) {
        clearInterval(modalRefreshInterval);
        modalRefreshInterval = null;
    }
    
    // Destruir gráfica
    if (agentChart) {
        agentChart.destroy();
        agentChart = null;
    }
    
    // Limpiar datos de comparación
    lastChartData = null;
}

function closeModalOnOverlay(event) {
    if (event.target.id === 'agentModal') {
        closeAgentModal();
    }
}

async function loadAgentDetails(userId, preserveEditorState = false) {
    try {
        const timestamp = new Date().getTime();
        const response = await fetch(`supervisor_agent_details_api.php?user_id=${userId}&_=${timestamp}`, {
            cache: 'no-cache',
            headers: {
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache'
            }
        });
        const data = await response.json();
        
        if (data.success) {
            if (Array.isArray(data.attendance_types) && data.attendance_types.length) {
                punchTypesCache = data.attendance_types;
            }
            updateModalStats(data.stats);
            updatePunchTimeline(data.punches, preserveEditorState);
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

function escapeHtml(value) {
    if (value === null || value === undefined) {
        return '';
    }
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function buildPunchOptions(selectedSlug, config = {}) {
    const selected = (selectedSlug || '').toUpperCase();
    if (!Array.isArray(punchTypesCache) || punchTypesCache.length === 0) {
        return selected ? `<option value="${selected}" selected>${selected}</option>` : '';
    }

    const {
        disableUniqueTaken = false,
        existingTypes = [],
        includePlaceholder = false
    } = config;

    const takenSet = new Set(
        (existingTypes || []).map(value => (value || '').toUpperCase())
    );

    const options = [];

    if (includePlaceholder) {
        const placeholderSelected = selected === '' ? 'selected' : '';
        options.push(`<option value="" disabled ${placeholderSelected}>Selecciona un tipo</option>`);
    }

    punchTypesCache.forEach(type => {
        const slug = (type.slug || '').toUpperCase();
        if (!slug) {
            return;
        }
        const label = escapeHtml(type.label || slug);
        const isUnique = (type.is_unique_daily || type.is_unique_daily === 0)
            ? Number(type.is_unique_daily) === 1
            : false;
        const alreadyTaken = disableUniqueTaken && isUnique && takenSet.has(slug) && slug !== selected;
        const disabledAttr = alreadyTaken ? 'disabled' : '';
        const suffix = alreadyTaken ? ' (registrado)' : '';
        const isSelected = slug === selected ? 'selected' : '';
        options.push(`<option value="${slug}" ${isSelected} ${disabledAttr}>${label}${suffix}</option>`);
    });

    return options.join('');
}

function applyStoredPunchStatus(key) {
    const statusKey = String(key);
    const state = punchStatusMessages[statusKey];
    const statusEl = statusKey === 'new'
        ? document.getElementById('punch-create-status')
        : document.getElementById(`punch-edit-status-${key}`);
    if (!statusEl) {
        return;
    }
    if (!state || !state.message) {
        statusEl.textContent = '';
        statusEl.style.color = 'var(--text-secondary)';
        return;
    }
    statusEl.textContent = state.message;
    statusEl.style.color = state.isError ? '#f87171' : 'var(--text-secondary)';
}

function hideAllPunchEditors() {
    document.querySelectorAll('.punch-edit-controls').forEach(ctrl => {
        ctrl.classList.add('is-hidden');
    });
}

function setPunchEditStatus(punchId, message, isError = false) {
    const statusKey = String(punchId);
    if (!message) {
        delete punchStatusMessages[statusKey];
    } else {
        punchStatusMessages[statusKey] = { message, isError };
    }

    const statusEl = statusKey === 'new'
        ? document.getElementById('punch-create-status')
        : document.getElementById(`punch-edit-status-${punchId}`);
    if (!statusEl) {
        return;
    }
    statusEl.textContent = message;
    statusEl.style.color = isError ? '#f87171' : 'var(--text-secondary)';
}

function openPunchNinja(event, punchId, currentType, currentTimestamp) {
    event.preventDefault();
    event.stopPropagation();

    const controls = document.getElementById(`punch-edit-${punchId}`);
    const select = document.getElementById(`punch-select-${punchId}`);
    const timeInput = document.getElementById(`punch-time-${punchId}`);

    if (!controls || !select) {
        return;
    }

    activePunchEditorId = String(punchId);
    hideAllPunchEditors();

    const existingTypes = modalPunchesCache.map(p => (p.type || '').toUpperCase());
    select.innerHTML = buildPunchOptions(currentType, { disableUniqueTaken: false, existingTypes });
    select.value = (currentType || '').toUpperCase();

    controls.classList.remove('is-hidden');
    applyStoredPunchStatus(punchId);
    
    // Agregar listeners para detectar interacción del usuario
    setupInteractionListeners(select);
    if (timeInput) {
        setupInteractionListeners(timeInput);
    }
    
    select.focus();
}

function setupInteractionListeners(select) {
    const indicator = document.getElementById('interaction-indicator');
    
    // Marcar como interactuando cuando el usuario abre el dropdown o escribe
    select.addEventListener('focus', () => {
        isUserInteracting = true;
        if (indicator) indicator.classList.add('active');
        if (interactionTimeout) clearTimeout(interactionTimeout);
    });
    
    select.addEventListener('mousedown', () => {
        isUserInteracting = true;
        if (indicator) indicator.classList.add('active');
        if (interactionTimeout) clearTimeout(interactionTimeout);
    });
    
    select.addEventListener('change', () => {
        // Esperar 1 segundo después de un cambio antes de permitir actualizaciones
        isUserInteracting = true;
        if (indicator) indicator.classList.add('active');
        if (interactionTimeout) clearTimeout(interactionTimeout);
        interactionTimeout = setTimeout(() => {
            isUserInteracting = false;
            if (indicator) indicator.classList.remove('active');
        }, 1000);
    });
    
    select.addEventListener('blur', () => {
        // Esperar 500ms después de perder el foco antes de permitir actualizaciones
        if (interactionTimeout) clearTimeout(interactionTimeout);
        interactionTimeout = setTimeout(() => {
            isUserInteracting = false;
            if (indicator) indicator.classList.remove('active');
        }, 500);
    });
}

function cancelPunchEdit(punchId) {
    const controls = document.getElementById(`punch-edit-${punchId}`);
    if (controls) {
        controls.classList.add('is-hidden');
    }
    setPunchEditStatus(punchId, '');
    if (activePunchEditorId === String(punchId)) {
        activePunchEditorId = null;
    }
    // Liberar el bloqueo de interacción
    isUserInteracting = false;
    if (interactionTimeout) {
        clearTimeout(interactionTimeout);
        interactionTimeout = null;
    }
}

async function submitPunchEdit(punchId) {
    const select = document.getElementById(`punch-select-${punchId}`);
    const timeInput = document.getElementById(`punch-time-${punchId}`);
    
    if (!select) {
        console.error('Select no encontrado para punch:', punchId);
        return;
    }
    
    const newType = (select.value || '').toUpperCase();
    if (!newType) {
        setPunchEditStatus(punchId, 'Selecciona un tipo válido.', true);
        return;
    }
    
    const newTime = timeInput ? timeInput.value : null;
    if (!newTime) {
        setPunchEditStatus(punchId, 'Ingresa una hora válida.', true);
        return;
    }
    
    if (!currentAgentId) {
        setPunchEditStatus(punchId, 'No hay un agente seleccionado.', true);
        return;
    }

    const controls = document.getElementById(`punch-edit-${punchId}`);
    if (!controls) {
        return;
    }

    const buttons = controls.querySelectorAll('button');
    buttons.forEach(btn => {
        btn.disabled = true;
    });
    setPunchEditStatus(punchId, 'Actualizando...', false);

    // Asegurar que punchId y currentAgentId sean números
    const numericPunchId = parseInt(punchId, 10);
    const numericUserId = parseInt(currentAgentId, 10);
    
    const payload = {
        punch_id: numericPunchId,
        user_id: numericUserId,
        new_type: newType,
        new_time: newTime
    };
    
    console.log('Enviando datos:', payload);

    try {
        const response = await fetch('supervisor_update_punch_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload),
            cache: 'no-cache',
            redirect: 'follow'
        });
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Respuesta no JSON:', text);
            setPunchEditStatus(punchId, 'Error: Respuesta inválida del servidor', true);
            buttons.forEach(btn => {
                btn.disabled = false;
            });
            return;
        }
        
        const data = await response.json();
        console.log('Respuesta recibida:', data);

        if (data.success) {
            setPunchEditStatus(punchId, '✓ Punch actualizado', false);
            // Cerrar el editor después de 1 segundo
            setTimeout(() => {
                cancelPunchEdit(punchId);
                loadAgentDetails(currentAgentId, false);
                refreshData();
            }, 1000);
        } else {
            let errorMsg = data.error || 'No se pudo actualizar el punch.';
            
            // Mostrar información de debug si está disponible
            if (data.debug) {
                console.error('Debug info:', data.debug);
                errorMsg += ' (Ver consola para detalles)';
            }
            
            setPunchEditStatus(punchId, errorMsg, true);
            buttons.forEach(btn => {
                btn.disabled = false;
            });
        }
    } catch (error) {
        console.error('Error en submitPunchEdit:', error);
        setPunchEditStatus(punchId, 'Error: ' + error.message, true);
        buttons.forEach(btn => {
            btn.disabled = false;
        });
    }
}

function getExistingPunchSlugs() {
    return modalPunchesCache.map(punch => (punch.type || '').toUpperCase());
}

function openPunchCreate(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    if (!currentAgentId || !Array.isArray(punchTypesCache) || punchTypesCache.length === 0) {
        return;
    }

    activePunchEditorId = 'new';
    hideAllPunchEditors();

    const controls = document.getElementById('punch-create-controls');
    const select = document.getElementById('punch-create-select');

    if (!controls || !select) {
        return;
    }

    const existingTypes = getExistingPunchSlugs();
    select.innerHTML = buildPunchOptions('', {
        disableUniqueTaken: true,
        existingTypes,
        includePlaceholder: true
    });
    select.value = '';
    controls.classList.remove('is-hidden');
    applyStoredPunchStatus('new');
    
    // Agregar listeners para detectar interacción del usuario
    setupInteractionListeners(select);
    
    select.focus();
}

function cancelPunchCreate() {
    const controls = document.getElementById('punch-create-controls');
    if (controls) {
        controls.classList.add('is-hidden');
    }
    setPunchEditStatus('new', '');
    if (activePunchEditorId === 'new') {
        activePunchEditorId = null;
    }
    // Liberar el bloqueo de interacción
    isUserInteracting = false;
    if (interactionTimeout) {
        clearTimeout(interactionTimeout);
        interactionTimeout = null;
    }
}

async function submitPunchCreate() {
    const select = document.getElementById('punch-create-select');
    if (!select) {
        console.error('Select de creación no encontrado');
        return;
    }
    const newType = (select.value || '').toUpperCase();
    if (!newType) {
        setPunchEditStatus('new', 'Selecciona un tipo válido.', true);
        return;
    }
    if (!currentAgentId) {
        setPunchEditStatus('new', 'No hay un agente seleccionado.', true);
        return;
    }

    const controls = document.getElementById('punch-create-controls');
    if (!controls) {
        return;
    }

    const buttons = controls.querySelectorAll('button');
    buttons.forEach(btn => {
        btn.disabled = true;
    });
    setPunchEditStatus('new', 'Registrando punch...', false);

    // Asegurar que currentAgentId sea número
    const numericUserId = parseInt(currentAgentId, 10);
    
    const payload = {
        user_id: numericUserId,
        punch_type: newType
    };
    
    console.log('Creando punch:', payload);

    try {
        const response = await fetch('supervisor_create_punch_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload),
            cache: 'no-cache',
            redirect: 'follow'
        });
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Respuesta no JSON:', text);
            setPunchEditStatus('new', 'Error: Respuesta inválida del servidor', true);
            buttons.forEach(btn => {
                btn.disabled = false;
            });
            return;
        }
        
        const data = await response.json();
        console.log('Respuesta recibida:', data);

        if (data.success) {
            setPunchEditStatus('new', '✓ Punch registrado', false);
            // Cerrar el editor después de 1 segundo
            setTimeout(() => {
                cancelPunchCreate();
                loadAgentDetails(currentAgentId, false);
                refreshData();
            }, 1000);
        } else {
            let errorMsg = data.error || 'No se pudo registrar el punch.';
            
            // Mostrar información de debug si está disponible
            if (data.debug) {
                console.error('Debug info:', data.debug);
                errorMsg += ' (Ver consola para detalles)';
            }
            
            setPunchEditStatus('new', errorMsg, true);
            buttons.forEach(btn => {
                btn.disabled = false;
            });
        }
    } catch (error) {
        console.error('Error en submitPunchCreate:', error);
        setPunchEditStatus('new', 'Error: ' + error.message, true);
        buttons.forEach(btn => {
            btn.disabled = false;
        });
    }
}

function updatePunchTimeline(punches, preserveEditorState = false) {
    const timeline = document.getElementById('punchTimeline');
    modalPunchesCache = Array.isArray(punches) ? punches.slice() : [];

    const existingTypes = getExistingPunchSlugs();
    
    // Si estamos preservando el estado y el editor de creación está activo, guardamos su HTML
    let savedCreateContainer = null;
    if (preserveEditorState && activePunchEditorId === 'new') {
        const container = document.querySelector('.punch-create-container');
        if (container) {
            savedCreateContainer = container.cloneNode(true);
        }
    }

    const createControls = punchTypesCache.length > 0 ? `
        <div class="punch-create-container" id="punch-create-container">
            <div class="interaction-indicator" id="interaction-indicator">
                <i class="fas fa-pause-circle"></i>
                <span>Actualizaciones pausadas</span>
            </div>
            <div class="punch-create-header">
                <div class="punch-create-title">
                    <i class="fas fa-user-ninja"></i>
                    Modo Ninja
                </div>
                <button type="button" class="ninja-add-btn" onclick="openPunchCreate(event)">
                    <i class="fas fa-plus-circle"></i> Agregar Punch
                </button>
            </div>
            <div class="punch-edit-controls is-hidden" id="punch-create-controls">
                <div class="punch-edit-row">
                    <select id="punch-create-select">
                        ${buildPunchOptions('', { disableUniqueTaken: true, existingTypes, includePlaceholder: true })}
                    </select>
                    <button type="button" onclick="submitPunchCreate()">Registrar</button>
                    <button type="button" class="punch-edit-cancel" onclick="cancelPunchCreate()">Cancelar</button>
                </div>
                <div class="punch-edit-status" id="punch-create-status"></div>
            </div>
        </div>
    ` : '';

    let timelineItems = '';
    if (!Array.isArray(punches) || punches.length === 0) {
        timelineItems = `
            <div class="text-center text-muted py-4">
                <i class="fas fa-inbox text-2xl mb-2"></i>
                <p class="text-sm">No hay punches registrados hoy</p>
            </div>
        `;
    } else {
        timelineItems = punches.map(punch => {
            const safeLabel = escapeHtml(punch.type_label);
            const safeTime = escapeHtml(punch.time);
            const sanitizedType = (punch.type || '').toUpperCase();
            const jsCurrentType = sanitizedType.replace(/\\/g, '\\\\').replace(/'/g, '\\\'');
            const punchTimestamp = punch.timestamp || '';
            const punchTime = punchTimestamp ? punchTimestamp.substring(11, 16) : ''; // HH:MM
            const jsTimestamp = punchTimestamp.replace(/'/g, '\\\'');
            
            const ninjaControls = punchTypesCache.length > 0 ? `
                <div class="punch-timeline-actions">
                    <button type="button" class="ninja-edit-btn" onclick="openPunchNinja(event, ${punch.id}, '${jsCurrentType}', '${jsTimestamp}')">
                        <i class="fas fa-user-ninja"></i> Ninja
                    </button>
                </div>
                <div class="punch-edit-controls is-hidden" id="punch-edit-${punch.id}">
                    <div class="punch-edit-row">
                        <select id="punch-select-${punch.id}">
                            ${buildPunchOptions(sanitizedType)}
                        </select>
                        <input type="time" id="punch-time-${punch.id}" value="${punchTime}" />
                        <button type="button" onclick="submitPunchEdit(${punch.id})">Aplicar</button>
                        <button type="button" class="punch-edit-cancel" onclick="cancelPunchEdit(${punch.id})">Cancelar</button>
                    </div>
                    <div class="punch-edit-status" id="punch-edit-status-${punch.id}"></div>
                </div>
            ` : '';
            
            return `
                <div class="punch-timeline-item" style="--item-color-start: ${punch.color_start}; --item-color-end: ${punch.color_end};">
                    <div class="punch-timeline-icon">
                        <i class="${punch.icon}"></i>
                    </div>
                    <div class="punch-timeline-content">
                        <div class="punch-timeline-type">${safeLabel}</div>
                        <div class="punch-timeline-time">
                            <i class="fas fa-clock"></i> ${safeTime}
                            ${punch.is_paid ? '<span class="ml-2 text-green-400"><i class="fas fa-dollar-sign"></i> Pagado</span>' : '<span class="ml-2 text-orange-400"><i class="fas fa-pause-circle"></i> No pagado</span>'}
                        </div>
                        ${ninjaControls}
                    </div>
                </div>
            `;
        }).join('');
    }

    timeline.innerHTML = `${createControls}${timelineItems}`;
    
    // Si teníamos el contenedor de creación guardado, restaurarlo
    if (savedCreateContainer) {
        const newContainer = document.getElementById('punch-create-container');
        if (newContainer && newContainer.parentNode) {
            newContainer.parentNode.replaceChild(savedCreateContainer, newContainer);
            
            // Re-adjuntar los event listeners
            const select = savedCreateContainer.querySelector('#punch-create-select');
            if (select) {
                setupInteractionListeners(select);
            }
        }
    } else if (preserveEditorState) {
        // Solo restaurar el estado del editor si se solicita y no lo guardamos ya
        restorePunchEditorState(existingTypes);
    }
}

function restorePunchEditorState(existingTypes = []) {
    if (!activePunchEditorId) {
        return;
    }

    if (activePunchEditorId === 'new') {
        const controls = document.getElementById('punch-create-controls');
        const select = document.getElementById('punch-create-select');
        if (controls && select) {
            // Guardar el valor seleccionado antes de reconstruir
            const currentValue = select.value;
            const wasFocused = document.activeElement === select;
            
            controls.classList.remove('is-hidden');
            select.innerHTML = buildPunchOptions('', {
                disableUniqueTaken: true,
                existingTypes,
                includePlaceholder: true
            });
            
            // Restaurar el valor seleccionado
            if (currentValue) {
                select.value = currentValue;
            }
            
            // Restaurar el foco si estaba activo
            if (wasFocused) {
                requestAnimationFrame(() => select.focus());
            }
            
            applyStoredPunchStatus('new');
        }
        return;
    }

    const punchId = parseInt(activePunchEditorId, 10);
    if (Number.isNaN(punchId)) {
        return;
    }

    const punch = modalPunchesCache.find(item => Number(item.id) === punchId);
    if (!punch) {
        activePunchEditorId = null;
        return;
    }

    const controls = document.getElementById(`punch-edit-${punchId}`);
    const select = document.getElementById(`punch-select-${punchId}`);
    const timeInput = document.getElementById(`punch-time-${punchId}`);
    
    if (controls && select) {
        // Guardar el valor seleccionado y el estado de foco
        const currentValue = select.value;
        const currentTime = timeInput ? timeInput.value : null;
        const wasFocusedSelect = document.activeElement === select;
        const wasFocusedTime = timeInput && document.activeElement === timeInput;
        
        controls.classList.remove('is-hidden');
        select.innerHTML = buildPunchOptions(punch.type, {
            disableUniqueTaken: false,
            existingTypes
        });
        
        // Restaurar el valor seleccionado o el original
        select.value = currentValue || (punch.type || '').toUpperCase();
        
        // Restaurar el valor de tiempo si existe
        if (timeInput && currentTime) {
            timeInput.value = currentTime;
        }
        
        // Restaurar el foco si estaba activo
        if (wasFocusedSelect) {
            requestAnimationFrame(() => select.focus());
        } else if (wasFocusedTime && timeInput) {
            requestAnimationFrame(() => timeInput.focus());
        }
        
        applyStoredPunchStatus(punchId);
    }
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
    if (lastChartData === currentDataString && agentChart) {
        // Los datos no cambiaron, no hacer nada
        return;
    }
    
    // Actualizar datos guardados
    lastChartData = currentDataString;
    
    const canvas = document.getElementById('timeDistributionChart');
    if (!canvas) return;
    
    const ctx = canvas.getContext('2d');
    
    // Si la gráfica ya existe, actualizar sus datos en lugar de recrearla
    if (agentChart) {
        agentChart.data.labels = chartData.labels;
        agentChart.data.datasets[0].data = chartData.data;
        agentChart.data.datasets[0].backgroundColor = chartData.colors;
        agentChart.update('none'); // 'none' evita animaciones en actualizaciones
        return;
    }
    
    // Crear nueva gráfica solo si no existe
    agentChart = new Chart(ctx, {
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
    if (e.key === 'Escape' && currentAgentId) {
        closeAgentModal();
    }
});
</script>

<?php include 'footer.php'; ?>

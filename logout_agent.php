<?php
session_start();

require_once __DIR__ . '/db.php';

$userId = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;
$shouldBlockLogout = false;

if ($userId && $role === 'AGENT') {
    $exitSlug = sanitizeAttendanceTypeSlug('EXIT');

    if ($exitSlug !== '') {
        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM attendance
                WHERE user_id = ?
                  AND DATE(timestamp) = CURDATE()
                  AND UPPER(type) = ?
                LIMIT 1
            ");
            $stmt->execute([$userId, $exitSlug]);
            $hasExitToday = $stmt->fetchColumn() !== false;

            if (!$hasExitToday) {
                $shouldBlockLogout = true;
            }
        } catch (PDOException $e) {
            // If there is a database error we allow logout to avoid trapping the user.
        }
    }
}

if ($shouldBlockLogout) {
    $_SESSION['logout_error'] = 'Debes registrar tu salida (EXIT) antes de cerrar sesion.';
    header('Location: agent_dashboard.php');
    exit;
}

session_destroy();
header('Location: login_agent.php');
exit;

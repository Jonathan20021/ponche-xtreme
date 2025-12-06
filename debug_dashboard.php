<?php
/**
 * Script de diagnóstico para el dashboard ejecutivo
 */
session_start();
require_once 'db.php';

echo "<h1>Diagnóstico del Dashboard Ejecutivo</h1>";

try {
    // Verificar tablas principales
    echo "<h2>1. Conteo de registros en tablas principales</h2>";
    $tables = ['employees', 'users', 'campaigns', 'attendance', 'payroll_periods', 'payroll_entries'];
    
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
            $count = $stmt->fetch()['count'];
            echo "<p><strong>$table:</strong> $count registros</p>";
        } catch (Exception $e) {
            echo "<p><strong>$table:</strong> ERROR - " . $e->getMessage() . "</p>";
        }
    }
    
    // Verificar empleados activos
    echo "<h2>2. Empleados activos</h2>";
    $stmt = $pdo->query("
        SELECT 
            e.id, 
            e.first_name, 
            e.last_name, 
            e.employment_status,
            u.is_active,
            u.hourly_rate,
            u.preferred_currency,
            c.name as campaign_name
        FROM employees e 
        INNER JOIN users u ON u.id = e.user_id 
        LEFT JOIN campaigns c ON c.id = e.campaign_id 
        WHERE e.employment_status IN ('ACTIVE', 'TRIAL') 
        AND u.is_active = 1 
        LIMIT 10
    ");
    
    $employees = $stmt->fetchAll();
    echo "<p>Empleados activos encontrados: " . count($employees) . "</p>";
    
    if (count($employees) > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Nombre</th><th>Estado</th><th>Activo</th><th>Tarifa</th><th>Moneda</th><th>Campaña</th></tr>";
        foreach ($employees as $emp) {
            echo "<tr>";
            echo "<td>" . $emp['id'] . "</td>";
            echo "<td>" . $emp['first_name'] . " " . $emp['last_name'] . "</td>";
            echo "<td>" . $emp['employment_status'] . "</td>";
            echo "<td>" . ($emp['is_active'] ? 'Sí' : 'No') . "</td>";
            echo "<td>" . $emp['hourly_rate'] . "</td>";
            echo "<td>" . $emp['preferred_currency'] . "</td>";
            echo "<td>" . ($emp['campaign_name'] ?: 'Sin campaña') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Verificar attendance de hoy
    echo "<h2>3. Registros de asistencia de hoy</h2>";
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM attendance 
        WHERE DATE(timestamp) = CURDATE()
    ");
    $todayAttendance = $stmt->fetch()['count'];
    echo "<p>Registros de asistencia hoy: $todayAttendance</p>";
    
    if ($todayAttendance > 0) {
        $stmt = $pdo->query("
            SELECT 
                a.user_id,
                u.full_name,
                a.type,
                a.timestamp
            FROM attendance a
            INNER JOIN users u ON u.id = a.user_id
            WHERE DATE(a.timestamp) = CURDATE()
            ORDER BY a.timestamp DESC
            LIMIT 10
        ");
        
        $attendanceRecords = $stmt->fetchAll();
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Usuario</th><th>Nombre</th><th>Tipo</th><th>Hora</th></tr>";
        foreach ($attendanceRecords as $record) {
            echo "<tr>";
            echo "<td>" . $record['user_id'] . "</td>";
            echo "<td>" . $record['full_name'] . "</td>";
            echo "<td>" . $record['type'] . "</td>";
            echo "<td>" . $record['timestamp'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Verificar tipos de attendance
    echo "<h2>4. Tipos de attendance</h2>";
    $stmt = $pdo->query("SELECT * FROM attendance_types");
    $attendanceTypes = $stmt->fetchAll();
    echo "<p>Tipos de attendance configurados: " . count($attendanceTypes) . "</p>";
    
    if (count($attendanceTypes) > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Slug</th><th>Label</th><th>Es Pagado</th></tr>";
        foreach ($attendanceTypes as $type) {
            echo "<tr>";
            echo "<td>" . $type['slug'] . "</td>";
            echo "<td>" . $type['label'] . "</td>";
            echo "<td>" . ($type['is_paid'] ? 'Sí' : 'No') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Probar la API directamente
    echo "<h2>5. Prueba de API</h2>";
    echo "<p><a href='executive_dashboard_api.php' target='_blank'>Probar API del Dashboard Ejecutivo</a></p>";
    
    // Verificar permisos del usuario actual
    echo "<h2>6. Información del usuario actual</h2>";
    if (isset($_SESSION['user_id'])) {
        echo "<p>Usuario ID: " . $_SESSION['user_id'] . "</p>";
        echo "<p>Nombre: " . ($_SESSION['full_name'] ?? 'No definido') . "</p>";
        echo "<p>Rol: " . ($_SESSION['role'] ?? 'No definido') . "</p>";
        
        // Verificar si tiene permiso
        if (function_exists('userHasPermission')) {
            $hasPermission = userHasPermission('executive_dashboard');
            echo "<p>Tiene permiso executive_dashboard: " . ($hasPermission ? 'Sí' : 'No') . "</p>";
        }
    } else {
        echo "<p>No hay usuario logueado</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>

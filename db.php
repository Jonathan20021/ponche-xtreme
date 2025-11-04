<?php
// Configuración de base de datos
$host = '192.185.46.27';
$dbname = 'hhempeos_ponche';
$username = 'hhempeos_ponche';
$password = 'Hugo##2025#';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES utf8mb4");
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// MySQLi connection for helpdesk and other modules
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Error de conexión MySQLi: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

if (!function_exists('getScheduleConfig')) {
    /**
     * Returns the active schedule configuration.
     */
    function getScheduleConfig(PDO $pdo): array
    {
        $stmt = $pdo->prepare("SELECT * FROM schedule_config WHERE id = 1");
        $stmt->execute();
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            $default = [
                'entry_time' => '10:00:00',
                'exit_time' => '19:00:00',
                'lunch_time' => '14:00:00',
                'break_time' => '17:00:00',
                'lunch_minutes' => 45,
                'break_minutes' => 15,
                'meeting_minutes' => 45,
                'scheduled_hours' => 8.00,
                'overtime_enabled' => 1,
                'overtime_multiplier' => 1.50,
                'overtime_start_minutes' => 0,
            ];

            $insert = $pdo->prepare("
                INSERT INTO schedule_config 
                (id, entry_time, exit_time, lunch_time, break_time, lunch_minutes, break_minutes, meeting_minutes, scheduled_hours, overtime_enabled, overtime_multiplier, overtime_start_minutes)
                VALUES (1, :entry_time, :exit_time, :lunch_time, :break_time, :lunch_minutes, :break_minutes, :meeting_minutes, :scheduled_hours, :overtime_enabled, :overtime_multiplier, :overtime_start_minutes)
            ");
            $insert->execute($default);

            $config = array_merge(['id' => 1], $default);
        }

        return $config;
    }
}

if (!function_exists('getUserExitTimes')) {
    /**
     * Returns a map of username => configured exit time (HH:MM:SS).
     */
    function getUserExitTimes(PDO $pdo): array
    {
        try {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'exit_time'");
            $hasExitColumn = $columnStmt && $columnStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hasExitColumn) {
                return [];
            }
        } catch (PDOException $e) {
            return [];
        }

        try {
            $stmt = $pdo->query("
                SELECT 
                    username, 
                    exit_time 
                FROM users 
                WHERE exit_time IS NOT NULL AND exit_time <> ''
            ");
        } catch (PDOException $e) {
            return [];
        }

        $exitTimes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $username = $row['username'] ?? null;
            if ($username === null) {
                continue;
            }

            $rawTime = trim((string) ($row['exit_time'] ?? ''));
            if ($rawTime === '') {
                continue;
            }

            if (strlen($rawTime) === 5) {
                $rawTime .= ':00';
            }

            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $rawTime) === 1) {
                $exitTimes[$username] = $rawTime;
                continue;
            }

            $parsed = strtotime($rawTime);
            if ($parsed !== false) {
                $exitTimes[$username] = date('H:i:s', $parsed);
            }
        }

        return $exitTimes;
    }
}

if (!function_exists('userHasPermission')) {
    /**
     * Checks whether the authenticated role has access to the given section.
     */
    function userHasPermission(string $sectionKey, ?string $role = null): bool
    {
        global $pdo;

        if (!$pdo instanceof PDO) {
            return false;
        }

        if ($role === null) {
            $role = $_SESSION['role'] ?? null;
        }

        if (!$role) {
            return false;
        }

        // Query database directly without caching to ensure real-time permission checks
        $stmt = $pdo->prepare("SELECT role FROM section_permissions WHERE section_key = ?");
        $stmt->execute([$sectionKey]);
        $allowedRoles = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return in_array($role, $allowedRoles, true);
    }
}

if (!function_exists('ensurePermission')) {
    /**
     * Guards a page by verifying session and permissions.
     */
    function ensurePermission(string $sectionKey, string $redirect = 'unauthorized.php'): void
    {
        if (!isset($_SESSION['user_id']) || !userHasPermission($sectionKey)) {
            header('Location: ' . $redirect . '?section=' . urlencode($sectionKey));
            exit;
        }
    }
}

if (!function_exists('getAllRoles')) {
    /**
     * Returns a list of roles ordered by label then name.
     */
    function getAllRoles(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT name, COALESCE(label, name) AS label FROM roles ORDER BY label");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('getAllDepartments')) {
    /**
     * Returns all departments ordered by name.
     */
    function getAllDepartments(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT id, name, COALESCE(description, '') AS description FROM departments ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('ensureDepartmentExists')) {
    /**
     * Inserts the department if missing and returns its id.
     */
    function ensureDepartmentExists(PDO $pdo, string $name, ?string $description = null): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        static $departmentCache = [];

        if (empty($departmentCache)) {
            $stmt = $pdo->query("SELECT id, name FROM departments");
            $departmentCache = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $departmentCache[$row['name']] = (int) $row['id'];
            }
        }

        if (isset($departmentCache[$name])) {
            return $departmentCache[$name];
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO departments (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $description]);
            $departmentId = (int) $pdo->lastInsertId();
            $departmentCache[$name] = $departmentId;
            return $departmentId;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $stmt = $pdo->prepare("SELECT id FROM departments WHERE name = ?");
                $stmt->execute([$name]);
                $departmentId = (int) $stmt->fetchColumn();
                if ($departmentId > 0) {
                    $departmentCache[$name] = $departmentId;
                    return $departmentId;
                }
                return null;
            }
            throw $e;
        }
    }
}

if (!function_exists('ensureRoleExists')) {
    /**
     * Inserts the role into roles table if it does not already exist.
     */
    function ensureRoleExists(PDO $pdo, string $roleName, ?string $label = null): void
    {
        if ($roleName === '') {
            return;
        }

        static $roleCache = [];
        if (empty($roleCache)) {
            $stmt = $pdo->query("SELECT name FROM roles");
            $roleCache = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        if (in_array($roleName, $roleCache, true)) {
            return;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO roles (name, label) VALUES (?, ?)");
            $stmt->execute([$roleName, $label ?? $roleName]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        if (!in_array($roleName, $roleCache, true)) {
            $roleCache[] = $roleName;
        }
    }
}

if (!function_exists('sanitizeAttendanceTypeSlug')) {
    /**
     * Normalizes the slug for attendance types.
     */
    function sanitizeAttendanceTypeSlug(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $transliterated = $value;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false && $converted !== null) {
                $transliterated = $converted;
            }
        }

        $transliterated = preg_replace('/[^A-Za-z0-9]+/', '_', $transliterated);
        $transliterated = trim($transliterated, '_');

        return strtoupper($transliterated);
    }
}

if (!function_exists('getAttendanceTypes')) {
    /**
     * Retrieves attendance types optionally filtered by active state.
     */
    function getAttendanceTypes(PDO $pdo, bool $activeOnly = false): array
    {
        try {
            $sql = "SELECT id, slug, label, icon_class, shortcut_key, color_start, color_end, sort_order, is_unique_daily, is_active
                    FROM attendance_types";
            if ($activeOnly) {
                $sql .= " WHERE is_active = 1";
            }
            $sql .= " ORDER BY sort_order ASC, label ASC";

            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            // Tabla inexistente u otro error, devolver arreglo vacio para degradacion elegante
            return [];
        }
    }
}

if (!function_exists('getUserHourlyRates')) {
    /**
     * Returns an associative array username => hourly_rate.
     */
    function getUserHourlyRates(PDO $pdo): array
    {
        $compensation = getUserCompensation($pdo);
        $rates = [];
        foreach ($compensation as $username => $data) {
            $rates[$username] = $data['hourly_rate'];
        }
        return $rates;
    }
}

if (!function_exists('getUserCompensation')) {
    /**
     * Returns compensation data keyed by username.
     */
    function getUserCompensation(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT 
                u.username,
                u.hourly_rate,
                u.monthly_salary,
                u.hourly_rate_dop,
                u.monthly_salary_dop,
                u.preferred_currency,
                u.department_id,
                u.overtime_multiplier,
                d.name AS department_name
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
        ");
        $data = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $data[$row['username']] = [
                'hourly_rate' => (float) $row['hourly_rate'],
                'monthly_salary' => (float) $row['monthly_salary'],
                'hourly_rate_dop' => (float) $row['hourly_rate_dop'],
                'monthly_salary_dop' => (float) $row['monthly_salary_dop'],
                'preferred_currency' => $row['preferred_currency'] ?? 'USD',
                'department_id' => $row['department_id'] !== null ? (int) $row['department_id'] : null,
                'department_name' => $row['department_name'] ?? null,
                'overtime_multiplier' => $row['overtime_multiplier'] !== null ? (float) $row['overtime_multiplier'] : null,
            ];
        }
        return $data;
    }
}

if (!function_exists('getUserOvertimeMultipliers')) {
    /**
     * Returns a map of username => overtime multiplier (or null if using global config).
     */
    function getUserOvertimeMultipliers(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT username, overtime_multiplier FROM users");
        $multipliers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $multipliers[$row['username']] = $row['overtime_multiplier'] !== null ? (float) $row['overtime_multiplier'] : null;
        }
        return $multipliers;
    }
}

if (!function_exists('getUserHourlyRateForDate')) {
    /**
     * Returns the hourly rate for a specific user on a specific date.
     * Uses the hourly_rate_history table to find the applicable rate.
     * Falls back to current user rate if no history exists.
     */
    function getUserHourlyRateForDate(PDO $pdo, int $userId, string $date, string $currency = 'USD'): float
    {
        try {
            // Check if hourly_rate_history table exists
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'hourly_rate_history'");
            if (!$tableCheck || !$tableCheck->fetch()) {
                // Table doesn't exist, fall back to current rate
                return getUserCurrentHourlyRate($pdo, $userId, $currency);
            }

            // Find the most recent rate effective on or before the given date
            $column = $currency === 'DOP' ? 'hourly_rate_dop' : 'hourly_rate_usd';
            $stmt = $pdo->prepare("
                SELECT {$column} as rate
                FROM hourly_rate_history
                WHERE user_id = ? AND effective_date <= ?
                ORDER BY effective_date DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $date]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && isset($result['rate'])) {
                return (float) $result['rate'];
            }

            // No history found, use current rate
            return getUserCurrentHourlyRate($pdo, $userId, $currency);
        } catch (PDOException $e) {
            // Error accessing history, fall back to current rate
            return getUserCurrentHourlyRate($pdo, $userId, $currency);
        }
    }
}

if (!function_exists('getUserCurrentHourlyRate')) {
    /**
     * Returns the current hourly rate for a user from the users table.
     */
    function getUserCurrentHourlyRate(PDO $pdo, int $userId, string $currency = 'USD'): float
    {
        $column = $currency === 'DOP' ? 'hourly_rate_dop' : 'hourly_rate';
        $stmt = $pdo->prepare("SELECT {$column} as rate FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (float) $result['rate'] : 0.0;
    }
}

if (!function_exists('addHourlyRateHistory')) {
    /**
     * Adds a new hourly rate entry to the history table.
     */
    function addHourlyRateHistory(PDO $pdo, int $userId, float $rateUsd, float $rateDop, string $effectiveDate, ?int $createdBy = null, ?string $notes = null): bool
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO hourly_rate_history (user_id, hourly_rate_usd, hourly_rate_dop, effective_date, created_by, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            return $stmt->execute([$userId, $rateUsd, $rateDop, $effectiveDate, $createdBy, $notes]);
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('getUserRateHistory')) {
    /**
     * Returns all rate history entries for a user, ordered by effective date descending.
     */
    function getUserRateHistory(PDO $pdo, int $userId): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    h.id,
                    h.hourly_rate_usd,
                    h.hourly_rate_dop,
                    h.effective_date,
                    h.created_at,
                    h.notes,
                    u.username as created_by_username
                FROM hourly_rate_history h
                LEFT JOIN users u ON u.id = h.created_by
                WHERE h.user_id = ?
                ORDER BY h.effective_date DESC, h.id DESC
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('deleteRateHistoryEntry')) {
    /**
     * Deletes a specific rate history entry.
     */
    function deleteRateHistoryEntry(PDO $pdo, int $historyId): bool
    {
        try {
            $stmt = $pdo->prepare("DELETE FROM hourly_rate_history WHERE id = ?");
            return $stmt->execute([$historyId]);
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('getAllBanks')) {
    /**
     * Returns all active banks ordered by name.
     */
    function getAllBanks(PDO $pdo, bool $activeOnly = true): array
    {
        try {
            $sql = "SELECT id, name, code, swift_code, country FROM banks";
            if ($activeOnly) {
                $sql .= " WHERE is_active = 1";
            }
            $sql .= " ORDER BY name";
            
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('addBank')) {
    /**
     * Adds a new bank to the system.
     */
    function addBank(PDO $pdo, string $name, ?string $code = null, ?string $swiftCode = null, string $country = 'República Dominicana'): ?int
    {
        try {
            $stmt = $pdo->prepare("INSERT INTO banks (name, code, swift_code, country) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $code, $swiftCode, $country]);
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('getAllScheduleTemplates')) {
    /**
     * Returns all schedule templates ordered by name.
     */
    function getAllScheduleTemplates(PDO $pdo, bool $activeOnly = true): array
    {
        try {
            $sql = "SELECT id, name, description, entry_time, exit_time, lunch_time, break_time, 
                           lunch_minutes, break_minutes, scheduled_hours, is_active 
                    FROM schedule_templates";
            if ($activeOnly) {
                $sql .= " WHERE is_active = 1";
            }
            $sql .= " ORDER BY name";
            
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('getEmployeeSchedule')) {
    /**
     * Returns the active schedule for an employee.
     * If no active schedule exists, returns null.
     */
    function getEmployeeSchedule(PDO $pdo, int $employeeId, ?string $date = null): ?array
    {
        try {
            $date = $date ?? date('Y-m-d');
            
            $stmt = $pdo->prepare("
                SELECT * FROM employee_schedules 
                WHERE employee_id = ? 
                AND is_active = 1
                AND (effective_date IS NULL OR effective_date <= ?)
                AND (end_date IS NULL OR end_date >= ?)
                ORDER BY effective_date DESC
                LIMIT 1
            ");
            $stmt->execute([$employeeId, $date, $date]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('getEmployeeScheduleByUserId')) {
    /**
     * Returns the active schedule for an employee by user_id.
     */
    function getEmployeeScheduleByUserId(PDO $pdo, int $userId, ?string $date = null): ?array
    {
        try {
            $date = $date ?? date('Y-m-d');
            
            $stmt = $pdo->prepare("
                SELECT * FROM employee_schedules 
                WHERE user_id = ? 
                AND is_active = 1
                AND (effective_date IS NULL OR effective_date <= ?)
                AND (end_date IS NULL OR end_date >= ?)
                ORDER BY effective_date DESC
                LIMIT 1
            ");
            $stmt->execute([$userId, $date, $date]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('createEmployeeSchedule')) {
    /**
     * Creates a new schedule for an employee.
     */
    function createEmployeeSchedule(PDO $pdo, int $employeeId, int $userId, array $scheduleData): ?int
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO employee_schedules (
                    employee_id, user_id, schedule_name, entry_time, exit_time, 
                    lunch_time, break_time, lunch_minutes, break_minutes, 
                    scheduled_hours, is_active, effective_date, end_date, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $employeeId,
                $userId,
                $scheduleData['schedule_name'] ?? null,
                $scheduleData['entry_time'] ?? '10:00:00',
                $scheduleData['exit_time'] ?? '19:00:00',
                $scheduleData['lunch_time'] ?? '14:00:00',
                $scheduleData['break_time'] ?? '17:00:00',
                $scheduleData['lunch_minutes'] ?? 45,
                $scheduleData['break_minutes'] ?? 15,
                $scheduleData['scheduled_hours'] ?? 8.00,
                $scheduleData['is_active'] ?? 1,
                $scheduleData['effective_date'] ?? date('Y-m-d'),
                $scheduleData['end_date'] ?? null,
                $scheduleData['notes'] ?? null
            ]);
            
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('createEmployeeScheduleFromTemplate')) {
    /**
     * Creates an employee schedule from a template.
     */
    function createEmployeeScheduleFromTemplate(PDO $pdo, int $employeeId, int $userId, int $templateId, ?string $effectiveDate = null): ?int
    {
        try {
            // Get template data
            $stmt = $pdo->prepare("SELECT * FROM schedule_templates WHERE id = ?");
            $stmt->execute([$templateId]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$template) {
                return null;
            }
            
            // Create schedule from template
            $scheduleData = [
                'schedule_name' => $template['name'],
                'entry_time' => $template['entry_time'],
                'exit_time' => $template['exit_time'],
                'lunch_time' => $template['lunch_time'],
                'break_time' => $template['break_time'],
                'lunch_minutes' => $template['lunch_minutes'],
                'break_minutes' => $template['break_minutes'],
                'scheduled_hours' => $template['scheduled_hours'],
                'is_active' => 1,
                'effective_date' => $effectiveDate ?? date('Y-m-d'),
                'notes' => 'Creado desde template: ' . $template['name']
            ];
            
            return createEmployeeSchedule($pdo, $employeeId, $userId, $scheduleData);
        } catch (PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('updateEmployeeSchedule')) {
    /**
     * Updates an existing employee schedule.
     */
    function updateEmployeeSchedule(PDO $pdo, int $scheduleId, array $scheduleData): bool
    {
        try {
            $stmt = $pdo->prepare("
                UPDATE employee_schedules SET
                    schedule_name = ?, entry_time = ?, exit_time = ?, 
                    lunch_time = ?, break_time = ?, lunch_minutes = ?, 
                    break_minutes = ?, scheduled_hours = ?, is_active = ?,
                    effective_date = ?, end_date = ?, notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            return $stmt->execute([
                $scheduleData['schedule_name'] ?? null,
                $scheduleData['entry_time'] ?? '10:00:00',
                $scheduleData['exit_time'] ?? '19:00:00',
                $scheduleData['lunch_time'] ?? '14:00:00',
                $scheduleData['break_time'] ?? '17:00:00',
                $scheduleData['lunch_minutes'] ?? 45,
                $scheduleData['break_minutes'] ?? 15,
                $scheduleData['scheduled_hours'] ?? 8.00,
                $scheduleData['is_active'] ?? 1,
                $scheduleData['effective_date'] ?? null,
                $scheduleData['end_date'] ?? null,
                $scheduleData['notes'] ?? null,
                $scheduleId
            ]);
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('deactivateEmployeeSchedules')) {
    /**
     * Deactivates all schedules for an employee.
     */
    function deactivateEmployeeSchedules(PDO $pdo, int $employeeId): bool
    {
        try {
            $stmt = $pdo->prepare("UPDATE employee_schedules SET is_active = 0 WHERE employee_id = ?");
            return $stmt->execute([$employeeId]);
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('getScheduleConfigForUser')) {
    /**
     * Returns the schedule configuration for a specific user.
     * First checks if the user has a custom schedule, otherwise returns global config.
     */
    function getScheduleConfigForUser(PDO $pdo, int $userId, ?string $date = null): array
    {
        // Try to get employee schedule first
        $employeeSchedule = getEmployeeScheduleByUserId($pdo, $userId, $date);
        
        if ($employeeSchedule) {
            return [
                'entry_time' => $employeeSchedule['entry_time'],
                'exit_time' => $employeeSchedule['exit_time'],
                'lunch_time' => $employeeSchedule['lunch_time'],
                'break_time' => $employeeSchedule['break_time'],
                'lunch_minutes' => (int)$employeeSchedule['lunch_minutes'],
                'break_minutes' => (int)$employeeSchedule['break_minutes'],
                'scheduled_hours' => (float)$employeeSchedule['scheduled_hours'],
                'schedule_name' => $employeeSchedule['schedule_name'] ?? 'Horario Personalizado',
                'is_custom' => true
            ];
        }
        
        // Fall back to global schedule config
        $globalConfig = getScheduleConfig($pdo);
        $globalConfig['schedule_name'] = 'Horario Global';
        $globalConfig['is_custom'] = false;
        
        return $globalConfig;
    }
}

if (!function_exists('getSystemSetting')) {
    /**
     * Gets a system setting value by key.
     * Returns the default value if the setting doesn't exist.
     */
    function getSystemSetting(PDO $pdo, string $key, $default = null)
    {
        try {
            $stmt = $pdo->prepare("SELECT setting_value, setting_type FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                return $default;
            }
            
            $value = $result['setting_value'];
            $type = $result['setting_type'] ?? 'string';
            
            // Cast to appropriate type
            switch ($type) {
                case 'number':
                    return is_numeric($value) ? (float)$value : $default;
                case 'boolean':
                    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
                case 'json':
                    return json_decode($value, true) ?? $default;
                default:
                    return $value;
            }
        } catch (PDOException $e) {
            return $default;
        }
    }
}

if (!function_exists('getExchangeRate')) {
    /**
     * Gets the current USD to DOP exchange rate from system settings.
     * Returns a default rate if not configured.
     */
    function getExchangeRate(PDO $pdo): float
    {
        return (float)getSystemSetting($pdo, 'exchange_rate_usd_to_dop', 58.50);
    }
}

if (!function_exists('convertCurrency')) {
    /**
     * Converts an amount from one currency to another using the system exchange rate.
     * 
     * @param PDO $pdo Database connection
     * @param float $amount Amount to convert
     * @param string $fromCurrency Source currency (USD or DOP)
     * @param string $toCurrency Target currency (USD or DOP)
     * @return float Converted amount
     */
    function convertCurrency(PDO $pdo, float $amount, string $fromCurrency, string $toCurrency): float
    {
        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency = strtoupper($toCurrency);
        
        // No conversion needed if same currency
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }
        
        $exchangeRate = getExchangeRate($pdo);
        
        // Convert USD to DOP
        if ($fromCurrency === 'USD' && $toCurrency === 'DOP') {
            return $amount * $exchangeRate;
        }
        
        // Convert DOP to USD
        if ($fromCurrency === 'DOP' && $toCurrency === 'USD') {
            return $amount / $exchangeRate;
        }
        
        // Invalid currency combination
        return $amount;
    }
}

if (!function_exists('updateSystemSetting')) {
    /**
     * Updates a system setting value.
     */
    function updateSystemSetting(PDO $pdo, string $key, $value, ?int $userId = null): bool
    {
        try {
            $stmt = $pdo->prepare("
                UPDATE system_settings 
                SET setting_value = ?, updated_by = ?, updated_at = NOW() 
                WHERE setting_key = ?
            ");
            return $stmt->execute([$value, $userId, $key]);
        } catch (PDOException $e) {
            return false;
        }
    }
}
?>

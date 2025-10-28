<?php
// Nueva conexion a la base de datos
$host = 'localhost';
$dbname = 'ponche';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

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
            ];

            $insert = $pdo->prepare("
                INSERT INTO schedule_config 
                (id, entry_time, exit_time, lunch_time, break_time, lunch_minutes, break_minutes, meeting_minutes, scheduled_hours)
                VALUES (1, :entry_time, :exit_time, :lunch_time, :break_time, :lunch_minutes, :break_minutes, :meeting_minutes, :scheduled_hours)
            ");
            $insert->execute($default);

            $config = array_merge(['id' => 1], $default);
        }

        return $config;
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

        static $permissionCache = [];

        if (!array_key_exists($sectionKey, $permissionCache)) {
            $stmt = $pdo->prepare("SELECT role FROM section_permissions WHERE section_key = ?");
            $stmt->execute([$sectionKey]);
            $permissionCache[$sectionKey] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        return in_array($role, $permissionCache[$sectionKey], true);
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
            ];
        }
        return $data;
    }
}
?>

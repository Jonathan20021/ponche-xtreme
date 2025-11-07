<?php
/**
 * Authorization Codes Functions Library
 * Sistema de Códigos de Autorización - Ponche Xtreme
 * 
 * Este archivo contiene todas las funciones relacionadas con el sistema
 * de códigos de autorización para hora extra y otros contextos.
 */

if (!defined('AUTH_CODE_ENABLED')) {
    define('AUTH_CODE_ENABLED', true);
}

/**
 * Verifica si el sistema de códigos de autorización está habilitado
 */
function isAuthorizationSystemEnabled(PDO $pdo): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT setting_value 
            FROM system_settings 
            WHERE setting_key = 'authorization_codes_enabled'
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (bool)$result['setting_value'] : false;
    } catch (PDOException $e) {
        error_log("Error checking authorization system status: " . $e->getMessage());
        return false;
    }
}

/**
 * Verifica si se requiere código de autorización para un contexto específico
 */
function isAuthorizationRequiredForContext(PDO $pdo, string $context): bool {
    try {
        $settingKey = "authorization_require_for_{$context}";
        $stmt = $pdo->prepare("
            SELECT setting_value 
            FROM system_settings 
            WHERE setting_key = ?
        ");
        $stmt->execute([$settingKey]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (bool)$result['setting_value'] : false;
    } catch (PDOException $e) {
        error_log("Error checking authorization requirement: " . $e->getMessage());
        return false;
    }
}

/**
 * Valida un código de autorización
 * 
 * @param PDO $pdo Conexión a la base de datos
 * @param string $code Código a validar
 * @param string $context Contexto de uso (overtime, special_punch, etc)
 * @return array Array con 'valid' (bool), 'code_id' (int|null), 'message' (string)
 */
function validateAuthorizationCode(PDO $pdo, string $code, string $context = 'overtime'): array {
    try {
        $code = trim($code);
        
        if (empty($code)) {
            return [
                'valid' => false,
                'code_id' => null,
                'message' => 'Código vacío'
            ];
        }

        // Buscar el código
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                code_name,
                is_active, 
                usage_context,
                valid_from, 
                valid_until, 
                max_uses, 
                current_uses,
                role_type
            FROM authorization_codes
            WHERE code = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$code]);
        $authCode = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$authCode) {
            return [
                'valid' => false,
                'code_id' => null,
                'message' => 'Código no encontrado o inactivo'
            ];
        }

        // Verificar contexto de uso (si está especificado en el código)
        if (!empty($authCode['usage_context']) && $authCode['usage_context'] !== $context) {
            return [
                'valid' => false,
                'code_id' => $authCode['id'],
                'message' => "Código no válido para este contexto. Esperado: {$authCode['usage_context']}, Recibido: {$context}"
            ];
        }

        // Verificar fecha de inicio
        if (!empty($authCode['valid_from'])) {
            $validFrom = strtotime($authCode['valid_from']);
            if (time() < $validFrom) {
                return [
                    'valid' => false,
                    'code_id' => $authCode['id'],
                    'message' => 'Código aún no es válido. Válido desde: ' . date('Y-m-d H:i', $validFrom)
                ];
            }
        }

        // Verificar fecha de expiración
        if (!empty($authCode['valid_until'])) {
            $validUntil = strtotime($authCode['valid_until']);
            if (time() > $validUntil) {
                return [
                    'valid' => false,
                    'code_id' => $authCode['id'],
                    'message' => 'Código expirado. Válido hasta: ' . date('Y-m-d H:i', $validUntil)
                ];
            }
        }

        // Verificar límite de usos
        if ($authCode['max_uses'] !== null && $authCode['current_uses'] >= $authCode['max_uses']) {
            return [
                'valid' => false,
                'code_id' => $authCode['id'],
                'message' => "Código ha alcanzado el límite de usos ({$authCode['max_uses']} usos)"
            ];
        }

        // Código válido
        return [
            'valid' => true,
            'code_id' => $authCode['id'],
            'code_name' => $authCode['code_name'],
            'role_type' => $authCode['role_type'],
            'message' => 'Código válido'
        ];

    } catch (PDOException $e) {
        error_log("Error validating authorization code: " . $e->getMessage());
        return [
            'valid' => false,
            'code_id' => null,
            'message' => 'Error al validar el código'
        ];
    }
}

/**
 * Registra el uso de un código de autorización
 */
function logAuthorizationCodeUsage(
    PDO $pdo,
    int $codeId,
    int $userId,
    string $context,
    ?int $referenceId = null,
    ?string $referenceTable = null,
    ?array $additionalData = null
): bool {
    try {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $additionalDataJson = $additionalData ? json_encode($additionalData) : null;

        // Insertar log (sin user_agent para compatibilidad)
        $stmt = $pdo->prepare("
            INSERT INTO authorization_code_logs (
                authorization_code_id,
                user_id,
                usage_context,
                reference_id,
                reference_table,
                ip_address,
                additional_data
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $codeId,
            $userId,
            $context,
            $referenceId,
            $referenceTable,
            $ipAddress,
            $additionalDataJson
        ]);

        // Incrementar contador de usos
        $updateStmt = $pdo->prepare("
            UPDATE authorization_codes
            SET current_uses = current_uses + 1
            WHERE id = ?
        ");
        $updateStmt->execute([$codeId]);

        return true;

    } catch (PDOException $e) {
        error_log("Error logging authorization code usage: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtiene todos los códigos de autorización activos
 */
function getActiveAuthorizationCodes(PDO $pdo, ?string $context = null): array {
    try {
        $sql = "
            SELECT 
                ac.id,
                ac.code_name,
                ac.code,
                ac.role_type,
                ac.usage_context,
                ac.valid_from,
                ac.valid_until,
                ac.max_uses,
                ac.current_uses,
                ac.created_at,
                u.full_name as created_by_name
            FROM authorization_codes ac
            LEFT JOIN users u ON ac.created_by = u.id
            WHERE ac.is_active = 1
        ";

        if ($context !== null) {
            $sql .= " AND (ac.usage_context IS NULL OR ac.usage_context = ?)";
            $stmt = $pdo->prepare($sql . " ORDER BY ac.role_type, ac.code_name");
            $stmt->execute([$context]);
        } else {
            $stmt = $pdo->query($sql . " ORDER BY ac.role_type, ac.code_name");
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error getting authorization codes: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene un código de autorización por ID
 */
function getAuthorizationCodeById(PDO $pdo, int $id): ?array {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                ac.*,
                u.full_name as created_by_name
            FROM authorization_codes ac
            LEFT JOIN users u ON ac.created_by = u.id
            WHERE ac.id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;

    } catch (PDOException $e) {
        error_log("Error getting authorization code: " . $e->getMessage());
        return null;
    }
}

/**
 * Crea un nuevo código de autorización
 */
function createAuthorizationCode(
    PDO $pdo,
    string $codeName,
    string $code,
    string $roleType,
    ?string $usageContext = null,
    ?int $createdBy = null,
    ?string $validFrom = null,
    ?string $validUntil = null,
    ?int $maxUses = null
): array {
    try {
        // Validar que el código no exista
        $checkStmt = $pdo->prepare("SELECT id FROM authorization_codes WHERE code = ?");
        $checkStmt->execute([$code]);
        if ($checkStmt->fetch()) {
            return [
                'success' => false,
                'message' => 'El código ya existe'
            ];
        }

        $stmt = $pdo->prepare("
            INSERT INTO authorization_codes (
                code_name,
                code,
                role_type,
                usage_context,
                created_by,
                valid_from,
                valid_until,
                max_uses
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $codeName,
            $code,
            $roleType,
            $usageContext,
            $createdBy,
            $validFrom,
            $validUntil,
            $maxUses
        ]);

        return [
            'success' => true,
            'code_id' => $pdo->lastInsertId(),
            'message' => 'Código creado exitosamente'
        ];

    } catch (PDOException $e) {
        error_log("Error creating authorization code: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error al crear el código'
        ];
    }
}

/**
 * Actualiza un código de autorización existente
 */
function updateAuthorizationCode(
    PDO $pdo,
    int $id,
    string $codeName,
    string $code,
    string $roleType,
    bool $isActive,
    ?string $usageContext = null,
    ?string $validFrom = null,
    ?string $validUntil = null,
    ?int $maxUses = null
): array {
    try {
        // Validar que el código no exista en otro registro
        $checkStmt = $pdo->prepare("SELECT id FROM authorization_codes WHERE code = ? AND id != ?");
        $checkStmt->execute([$code, $id]);
        if ($checkStmt->fetch()) {
            return [
                'success' => false,
                'message' => 'El código ya existe en otro registro'
            ];
        }

        $stmt = $pdo->prepare("
            UPDATE authorization_codes
            SET code_name = ?,
                code = ?,
                role_type = ?,
                is_active = ?,
                usage_context = ?,
                valid_from = ?,
                valid_until = ?,
                max_uses = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $codeName,
            $code,
            $roleType,
            $isActive ? 1 : 0,
            $usageContext,
            $validFrom,
            $validUntil,
            $maxUses,
            $id
        ]);

        return [
            'success' => true,
            'message' => 'Código actualizado exitosamente'
        ];

    } catch (PDOException $e) {
        error_log("Error updating authorization code: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error al actualizar el código'
        ];
    }
}

/**
 * Elimina (desactiva) un código de autorización
 */
function deleteAuthorizationCode(PDO $pdo, int $id): array {
    try {
        $stmt = $pdo->prepare("
            UPDATE authorization_codes
            SET is_active = 0
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        return [
            'success' => true,
            'message' => 'Código desactivado exitosamente'
        ];

    } catch (PDOException $e) {
        error_log("Error deleting authorization code: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error al eliminar el código'
        ];
    }
}

/**
 * Obtiene estadísticas de uso de códigos de autorización
 */
function getAuthorizationCodeStats(PDO $pdo, ?int $codeId = null, ?int $days = 30): array {
    try {
        $sql = "
            SELECT 
                COUNT(*) as total_uses,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT DATE(used_at)) as days_used,
                MIN(used_at) as first_use,
                MAX(used_at) as last_use
            FROM authorization_code_logs
            WHERE used_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ";

        if ($codeId !== null) {
            $sql .= " AND authorization_code_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$days, $codeId]);
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$days]);
        }

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    } catch (PDOException $e) {
        error_log("Error getting authorization code stats: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene el historial de uso de un código
 */
function getAuthorizationCodeUsageHistory(PDO $pdo, int $codeId, int $limit = 50): array {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                acl.id,
                acl.user_id,
                u.username,
                u.full_name,
                acl.usage_context,
                acl.reference_id,
                acl.reference_table,
                acl.ip_address,
                acl.used_at
            FROM authorization_code_logs acl
            LEFT JOIN users u ON acl.user_id = u.id
            WHERE acl.authorization_code_id = ?
            ORDER BY acl.used_at DESC
            LIMIT ?
        ");
        $stmt->execute([$codeId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Error getting authorization code history: " . $e->getMessage());
        return [];
    }
}

/**
 * Verifica si un empleado está intentando registrar hora extra
 * Solo se considera overtime DESPUÉS de 8 horas efectivas/pagadas trabajadas en el día
 */
function isOvertimeAttempt(PDO $pdo, int $userId, string $typeSlug): bool {
    try {
        // Obtener configuración
        $stmt = $pdo->prepare("
            SELECT 
                u.exit_time as user_exit_time,
                sc.exit_time as global_exit_time,
                sc.overtime_start_minutes,
                sc.overtime_enabled
            FROM users u
            CROSS JOIN schedule_config sc
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$config || !$config['overtime_enabled']) {
            return false;
        }

        // NUEVA LÓGICA: Calcular horas pagadas trabajadas HOY
        require_once __DIR__ . '/../db.php';
        $paidTypes = getPaidAttendanceTypeSlugs($pdo);
        
        if (empty($paidTypes)) {
            return false; // No hay tipos pagados configurados
        }
        
        // Construir query para sumar segundos de tipos pagados
        $paidTypesPlaceholders = implode(',', array_fill(0, count($paidTypes), '?'));
        $paidTypesUpper = array_map('strtoupper', $paidTypes);
        
        $hoursStmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(seconds), 0) as total_paid_seconds
            FROM attendance
            WHERE user_id = ?
            AND DATE(timestamp) = CURDATE()
            AND UPPER(type) IN ($paidTypesPlaceholders)
        ");
        
        $params = array_merge([$userId], $paidTypesUpper);
        $hoursStmt->execute($params);
        $result = $hoursStmt->fetch(PDO::FETCH_ASSOC);
        
        $totalPaidSeconds = (int)($result['total_paid_seconds'] ?? 0);
        $totalPaidHours = $totalPaidSeconds / 3600;
        
        // Log para debugging
        error_log("Usuario $userId - Horas pagadas hoy: " . number_format($totalPaidHours, 2) . " horas ($totalPaidSeconds segundos)");
        
        // Si ya tiene 8 o más horas pagadas trabajadas, cualquier hora adicional es overtime
        if ($totalPaidHours >= 8.0) {
            error_log("Usuario $userId - OVERTIME detectado (>= 8 horas pagadas)");
            return true;
        }
        
        // Si tiene menos de 8 horas, NO es overtime aunque sea fuera de horario
        error_log("Usuario $userId - NO es overtime (solo " . number_format($totalPaidHours, 2) . " horas pagadas)");
        return false;

    } catch (PDOException $e) {
        error_log("Error checking overtime attempt: " . $e->getMessage());
        return false;
    }
}

/**
 * Valida que no se registren dos ENTRY o dos EXIT consecutivos sin el tipo opuesto intermedio
 * @param PDO $pdo Conexión a la base de datos
 * @param int $userId ID del usuario
 * @param string $typeSlug Tipo de punch a registrar (ENTRY, EXIT, etc.)
 * @return array ['valid' => bool, 'message' => string]
 */
function validateEntryExitSequence(PDO $pdo, int $userId, string $typeSlug): array {
    try {
        // Solo validar para ENTRY y EXIT
        $typeSlugUpper = strtoupper($typeSlug);
        if (!in_array($typeSlugUpper, ['ENTRY', 'EXIT'])) {
            return ['valid' => true, 'message' => 'No requiere validación de secuencia'];
        }

        // Obtener el último punch del usuario hoy
        $stmt = $pdo->prepare("
            SELECT type, timestamp 
            FROM attendance 
            WHERE user_id = ? 
            AND DATE(timestamp) = CURDATE()
            ORDER BY timestamp DESC 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $lastPunch = $stmt->fetch(PDO::FETCH_ASSOC);

        // Si no hay punch previo hoy, permitir cualquiera
        if (!$lastPunch) {
            return ['valid' => true, 'message' => 'Primer registro del día'];
        }

        $lastTypeUpper = strtoupper($lastPunch['type']);

        // Validar secuencia correcta
        if ($typeSlugUpper === 'ENTRY' && $lastTypeUpper === 'ENTRY') {
            return [
                'valid' => false, 
                'message' => '❌ No puedes registrar dos ENTRY consecutivos. Debes registrar EXIT primero.'
            ];
        }

        if ($typeSlugUpper === 'EXIT' && $lastTypeUpper === 'EXIT') {
            return [
                'valid' => false, 
                'message' => '❌ No puedes registrar dos EXIT consecutivos. Debes registrar ENTRY primero.'
            ];
        }

        return ['valid' => true, 'message' => 'Secuencia válida'];

    } catch (PDOException $e) {
        error_log("Error validating entry/exit sequence: " . $e->getMessage());
        return ['valid' => false, 'message' => 'Error al validar secuencia'];
    }
}

/**
 * Verifica si el usuario está intentando hacer punch antes de su hora de entrada programada
 * 
 * @param PDO $pdo Conexión a la base de datos
 * @param int $userId ID del usuario
 * @param string|null $currentTime Hora actual en formato H:i:s (opcional, usa hora actual si no se proporciona)
 * @param int $graceMinutes Minutos de tolerancia antes de considerar early punch (default: 0)
 * @return bool True si está intentando entrar antes de su hora programada
 */
function isEarlyPunchAttempt(PDO $pdo, int $userId, ?string $currentTime = null, int $graceMinutes = 0): bool {
    try {
        error_log("=== Early Punch Detection START ===");
        error_log("User ID: $userId");
        
        // Usar hora actual si no se proporciona
        if ($currentTime === null) {
            $currentTime = date('H:i:s');
        }
        error_log("Current time: $currentTime");
        error_log("Grace minutes: $graceMinutes");

        // 1. Intentar obtener horario individual del empleado
        $stmt = $pdo->prepare("
            SELECT st.entry_time 
            FROM employee_schedules es
            INNER JOIN schedule_templates st ON es.schedule_template_id = st.id
            WHERE es.user_id = ? AND es.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $employeeSchedule = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($employeeSchedule && !empty($employeeSchedule['entry_time'])) {
            $scheduledEntryTime = $employeeSchedule['entry_time'];
            error_log("Found individual schedule - entry_time: $scheduledEntryTime");
        } else {
            // 2. Si no tiene horario individual, usar horario global de schedule_config
            $stmt = $pdo->query("SELECT entry_time FROM schedule_config LIMIT 1");
            $globalSchedule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($globalSchedule && !empty($globalSchedule['entry_time'])) {
                $scheduledEntryTime = $globalSchedule['entry_time'];
                error_log("Using global schedule - entry_time: $scheduledEntryTime");
            } else {
                error_log("No schedule found, cannot determine early punch");
                return false; // No hay horario configurado, no se puede determinar si es early
            }
        }

        // 3. Aplicar margen de tolerancia (grace period)
        if ($graceMinutes > 0) {
            $adjustedEntryTime = date('H:i:s', strtotime($scheduledEntryTime . " -$graceMinutes minutes"));
            error_log("Adjusted entry time with $graceMinutes min grace: $adjustedEntryTime");
        } else {
            $adjustedEntryTime = $scheduledEntryTime;
        }

        // 4. Comparar hora actual con hora de entrada programada
        $currentTimeObj = new DateTime($currentTime);
        $scheduledTimeObj = new DateTime($adjustedEntryTime);

        $isEarly = $currentTimeObj < $scheduledTimeObj;
        
        error_log("Current: " . $currentTimeObj->format('H:i:s') . 
                  " vs Scheduled: " . $scheduledTimeObj->format('H:i:s'));
        error_log("Is Early Punch: " . ($isEarly ? 'YES' : 'NO'));
        error_log("=== Early Punch Detection END ===");

        return $isEarly;

    } catch (PDOException $e) {
        error_log("Error detecting early punch: " . $e->getMessage());
        return false; // En caso de error, no bloquear el registro
    }
}

/**
 * Genera un código aleatorio único
 */
function generateUniqueAuthCode(PDO $pdo, int $length = 8): string {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $maxAttempts = 100;
    $attempt = 0;

    do {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        // Verificar si el código ya existe
        $stmt = $pdo->prepare("SELECT id FROM authorization_codes WHERE code = ?");
        $stmt->execute([$code]);
        $exists = $stmt->fetch();

        if (!$exists) {
            return $code;
        }

        $attempt++;
    } while ($attempt < $maxAttempts);

    // Si después de 100 intentos no se genera un código único, agregar timestamp
    return $code . substr(time(), -4);
}

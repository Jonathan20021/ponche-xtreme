<?php
/**
 * lib/crypto_vault.php
 *
 * Cifrado autenticado (AES-256-GCM) para secretos en reposo: contraseñas de
 * acceso remoto (AnyDesk/RustDesk, etc.) de la bóveda del helpdesk.
 *
 * CLAVE — CERO CONFIGURACIÓN: vive en la BASE DE DATOS COMPARTIDA (system_settings,
 * clave 'helpdesk_vault_key'). Como la oficina y HostGator usan la MISMA base, los
 * dos servidores comparten automáticamente la misma clave: al hacer git pull no
 * hay nada que configurar ni copiar. Se genera sola la primera vez.
 *
 * (Opcional, más estricto: si existe config/vault_key.php con una clave válida,
 * tiene prioridad. No es necesario.)
 */

require_once __DIR__ . '/../db.php';

if (!function_exists('vaultKey')) {
    function vaultKey(): string
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }
        // 1) Archivo local opcional (si alguien quiere separar la clave de la DB).
        $keyFile = __DIR__ . '/../config/vault_key.php';
        if (is_file($keyFile)) {
            $stored = include $keyFile;
            $raw = is_string($stored) ? base64_decode($stored, true) : false;
            if ($raw !== false && strlen($raw) === 32) {
                return $key = $raw;
            }
        }
        // 2) DB compartida (por defecto): misma clave en todos los servidores.
        global $pdo;
        try {
            $stored = getSystemSetting($pdo, 'helpdesk_vault_key', '');
            $raw = ($stored !== '') ? base64_decode($stored, true) : false;
            if ($raw !== false && strlen($raw) === 32) {
                return $key = $raw;
            }
            // Generar y persistir una vez en la DB compartida.
            $raw = random_bytes(32);
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type) VALUES ('helpdesk_vault_key', ?, 'text') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([base64_encode($raw)]);
            return $key = $raw;
        } catch (Throwable $e) {
            // Último recurso (DB caída): clave derivada determinista para no dar fatal.
            error_log('vaultKey: ' . $e->getMessage());
            return $key = hash('sha256', 'ponche-helpdesk-vault-fallback-key', true);
        }
    }
}

if (!function_exists('vaultEncrypt')) {
    /** Cifra texto plano -> base64(iv|tag|ciphertext). '' -> ''. */
    function vaultEncrypt(?string $plain): string
    {
        $plain = (string) $plain;
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', vaultKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return '';
        }
        return base64_encode($iv . $tag . $cipher);
    }
}

if (!function_exists('vaultDecrypt')) {
    /** Descifra base64(iv|tag|ciphertext) -> texto plano. Falla/vacío -> ''. */
    function vaultDecrypt(?string $blob): string
    {
        $blob = (string) $blob;
        if ($blob === '') {
            return '';
        }
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 12 + 16 + 1) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', vaultKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
}

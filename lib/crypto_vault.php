<?php
/**
 * lib/crypto_vault.php
 *
 * Cifrado autenticado (AES-256-GCM) para secretos en reposo: contraseñas de
 * acceso remoto (AnyDesk/RustDesk, etc.) de la bóveda del helpdesk.
 *
 * La clave vive en config/vault_key.php (un archivo PHP que hace `return` de la
 * clave: si se pide por HTTP se ejecuta y NO imprime nada). Está en .gitignore.
 * Se genera sola la primera vez. NO borrar (se pierden las credenciales) ni subir.
 */

if (!function_exists('vaultKey')) {
    function vaultKey(): string
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }
        $keyFile = __DIR__ . '/../config/vault_key.php';
        if (is_file($keyFile)) {
            $stored = include $keyFile;
            $raw = is_string($stored) ? base64_decode($stored, true) : false;
            if ($raw !== false && strlen($raw) === 32) {
                return $key = $raw;
            }
        }
        // Generar y persistir una clave nueva de 256 bits.
        $dir = dirname($keyFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $raw = random_bytes(32);
        $php = "<?php\n// Clave de cifrado de la boveda (AES-256). NO subir a git. NO borrar: se\n"
             . "// pierden todas las contrasenas guardadas. Generada automaticamente.\n"
             . "return '" . base64_encode($raw) . "';\n";
        @file_put_contents($keyFile, $php, LOCK_EX);
        @chmod($keyFile, 0600);
        return $key = $raw;
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

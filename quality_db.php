<?php
if (!function_exists('getQualityDbConnection')) {
    function getQualityDbConnection(): ?PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $host = '192.185.46.27';
        $dbname = 'hhempeos_calidad';
        $user = 'hhempeos_calidad';
        $pass = 'Evallish.2026';

        try {
            $pdo = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('Quality DB connection failed: ' . $e->getMessage());
            return null;
        }

        return $pdo;
    }
}

if (!defined('QUALITY_MEDIA_BASE_URL')) {
    $envBaseUrl = getenv('QUALITY_MEDIA_BASE_URL') ?: '';
    $defaultBaseUrl = 'https://qa.evallishbpo.com/public';
    define('QUALITY_MEDIA_BASE_URL', $envBaseUrl !== '' ? $envBaseUrl : $defaultBaseUrl);
}

<?php
/**
 * Conexión PDO a la base de datos de la app de Finanzas.
 *
 * Arquitectura:
 *   - ponche-xtreme corre en cPanel (online)
 *   - app de Finanzas corre en local (desarrollo) o en otro cPanel (producción)
 *   - Ambas bases de datos viven en el MISMO servidor MySQL de cPanel
 *
 * Por eso ponche puede escribir directamente al esquema de finanzas
 * (hhempeos_financial_system) sin necesidad de HTTP — esto es lo que
 * permite que el portal del agente cree solicitudes de préstamo aunque
 * la app de Finanzas local esté apagada en el momento.
 *
 * Cuando la app de Finanzas se levante, verá los nuevos préstamos en
 * estado 'pending' y podrá aprobarlos, desembolsarlos y programar
 * deducciones por nómina.
 */

if (!function_exists('getFinanzasPdo')) {
    function getFinanzasPdo(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            // Credenciales del esquema de finanzas — mismas que usa la app Next.js
            // (configurables vía env vars si se desea sobreescribir).
            $host     = getenv('FINANZAS_DB_HOST')     ?: '192.185.46.27';
            $dbname   = getenv('FINANZAS_DB_NAME')     ?: 'hhempeos_financial_system';
            $username = getenv('FINANZAS_DB_USER')     ?: 'hhempeos_finanzas';
            $password = getenv('FINANZAS_DB_PASSWORD') ?: 'Hacker#2002';
            $port     = getenv('FINANZAS_DB_PORT')     ?: '3306';

            try {
                $pdo = new PDO(
                    "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
                    $username,
                    $password,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                        PDO::ATTR_TIMEOUT            => 8,
                    ]
                );
                $pdo->exec("SET NAMES utf8mb4");
                $pdo->exec("SET time_zone = '-04:00'");
            } catch (PDOException $e) {
                throw new RuntimeException(
                    'No se pudo conectar al esquema de finanzas: ' . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }
        }
        return $pdo;
    }
}

/**
 * Helper de bajo nivel: comprueba si la conexión está viva. Útil para mostrar
 * un mensaje claro al usuario antes de abrir el form de préstamos.
 */
if (!function_exists('finanzasDbAvailable')) {
    function finanzasDbAvailable(): bool {
        try {
            getFinanzasPdo()->query('SELECT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

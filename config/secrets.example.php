<?php
/**
 * ============================================================
 *  PLANTILLA DE SECRETOS  —  SÍ se versiona (sin valores reales)
 * ============================================================
 *  Copia este archivo a  config/secrets.php  y rellena los valores REALES.
 *  config/secrets.php está en .gitignore y NO debe subirse al repositorio.
 *
 *  Alternativa recomendada en producción: define variables de entorno
 *  (tienen prioridad sobre este archivo). Nombres de las variables:
 *    DB_HOST DB_NAME DB_USER DB_PASS
 *    (Calidad usa los valores de db_quality de este archivo)
 *    FINANZAS_DB_HOST FINANZAS_DB_NAME FINANZAS_DB_USER FINANZAS_DB_PASSWORD FINANZAS_DB_PORT
 *    SMTP_PASS
 *    WASAPI_TOKEN
 *    GEMINI_KEY_MAIN GEMINI_KEY_WASAPI GEMINI_KEY_HR
 * ============================================================
 */

return [
    'db_main' => [
        'host' => 'CAMBIAR',
        'name' => 'CAMBIAR',
        'user' => 'CAMBIAR',
        'pass' => 'CAMBIAR',
    ],
    'db_quality' => [
        'host' => 'CAMBIAR',
        'name' => 'CAMBIAR',
        'user' => 'CAMBIAR',
        'pass' => 'CAMBIAR',
    ],
    'db_finanzas' => [
        'host' => 'CAMBIAR',
        'name' => 'CAMBIAR',
        'user' => 'CAMBIAR',
        'pass' => 'CAMBIAR',
        'port' => '3306',
    ],
    'smtp' => [
        'host'   => 'CAMBIAR',
        'port'   => 465,
        'secure' => 'ssl',
        'user'   => 'CAMBIAR',
        'pass'   => 'CAMBIAR',
    ],
    'wasapi_token'      => 'CAMBIAR',
    'gemini_key_main'   => 'CAMBIAR',
    'gemini_key_wasapi' => 'CAMBIAR',
    'gemini_key_hr'     => 'CAMBIAR',
];

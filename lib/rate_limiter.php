<?php
/**
 * Rate Limiter para prevenir Too Many Requests (429)
 * Limita las solicitudes por IP para evitar sobrecargar el servidor
 */

class RateLimiter {
    private $cacheDir;
    private $maxRequests;
    private $timeWindow;
    
    public function __construct($maxRequests = 60, $timeWindow = 60) {
        $this->cacheDir = __DIR__ . '/../cache/rate_limit/';
        $this->maxRequests = $maxRequests; // Máximo de requests
        $this->timeWindow = $timeWindow;   // Ventana de tiempo en segundos
        
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Verifica si la IP puede hacer más requests
     * @param string|null $identifier Identificador único (IP por defecto)
     * @return bool true si se permite, false si excede el límite
     */
    public function checkLimit($identifier = null) {
        if ($identifier === null) {
            $identifier = $this->getClientIP();
        }
        
        $filename = $this->cacheDir . md5($identifier) . '.json';
        $now = time();
        
        // Leer datos existentes
        $data = [];
        if (file_exists($filename)) {
            $content = @file_get_contents($filename);
            if ($content) {
                $data = json_decode($content, true) ?: [];
            }
        }
        
        // Limpiar requests antiguos
        $data = array_filter($data, function($timestamp) use ($now) {
            return ($now - $timestamp) < $this->timeWindow;
        });
        
        // Verificar límite
        if (count($data) >= $this->maxRequests) {
            return false;
        }
        
        // Agregar nuevo request
        $data[] = $now;
        @file_put_contents($filename, json_encode($data), LOCK_EX);
        
        return true;
    }
    
    /**
     * Obtiene la IP del cliente
     */
    private function getClientIP() {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Si hay múltiples IPs, tomar la primera
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }
        
        return '127.0.0.1';
    }
    
    /**
     * Limpia archivos de caché antiguos (llamar periódicamente)
     */
    public function cleanup() {
        $files = glob($this->cacheDir . '*.json');
        $now = time();
        
        foreach ($files as $file) {
            if (($now - filemtime($file)) > 3600) { // 1 hora
                @unlink($file);
            }
        }
    }
}

/**
 * Función helper para verificar rate limit rápidamente
 * @param int $maxRequests Máximo de requests por ventana
 * @param int $timeWindow Ventana de tiempo en segundos
 * @return bool
 */
function checkRateLimit($maxRequests = 60, $timeWindow = 60) {
    static $limiter = null;
    
    if ($limiter === null) {
        $limiter = new RateLimiter($maxRequests, $timeWindow);
    }
    
    return $limiter->checkLimit();
}

/**
 * Responde con error 429 si se excede el límite
 */
function enforceRateLimit($maxRequests = 60, $timeWindow = 60) {
    if (!checkRateLimit($maxRequests, $timeWindow)) {
        http_response_code(429);
        header('Retry-After: ' . $timeWindow);
        
        if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Too many requests. Please wait before trying again.',
                'retry_after' => $timeWindow
            ]);
        } else {
            echo '<h1>Too Many Requests</h1>';
            echo '<p>Please wait a moment before making more requests.</p>';
        }
        exit;
    }
}

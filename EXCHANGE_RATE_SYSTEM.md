# Sistema de Tasa de Cambio USD/DOP

## Descripción General

El sistema de tasa de cambio permite configurar y mantener actualizada la tasa de conversión entre USD (Dólares) y DOP (Pesos Dominicanos) para garantizar cálculos precisos en nómina, reportes y conversiones de moneda.

## Características

### 1. Configuración Centralizada
- **Ubicación**: `hr/system_settings.php`
- **Permisos**: Solo usuarios con rol ADMIN o HR pueden acceder
- Tasa de cambio configurable desde la interfaz web
- Historial de cambios registrado en activity logs

### 2. Funciones Disponibles

#### `getExchangeRate(PDO $pdo): float`
Obtiene la tasa de cambio actual configurada en el sistema.
```php
$exchangeRate = getExchangeRate($pdo);
// Retorna: 58.50 (o el valor configurado)
```

#### `convertCurrency(PDO $pdo, float $amount, string $fromCurrency, string $toCurrency): float`
Convierte un monto de una moneda a otra usando la tasa configurada.
```php
// Convertir 100 USD a DOP
$amountInDOP = convertCurrency($pdo, 100, 'USD', 'DOP');

// Convertir 5850 DOP a USD
$amountInUSD = convertCurrency($pdo, 5850, 'DOP', 'USD');
```

#### `getSystemSetting(PDO $pdo, string $key, $default = null)`
Obtiene cualquier configuración del sistema por su clave.
```php
$rate = getSystemSetting($pdo, 'exchange_rate_usd_to_dop', 58.50);
```

#### `updateSystemSetting(PDO $pdo, string $key, $value, ?int $userId = null): bool`
Actualiza una configuración del sistema.
```php
updateSystemSetting($pdo, 'exchange_rate_usd_to_dop', 59.00, $_SESSION['user_id']);
```

## Uso en Cálculos de Nómina

### Ejemplo: Calcular salario en ambas monedas

```php
// Obtener compensación del empleado
$compensation = getUserCompensation($pdo);
$employeeData = $compensation[$username];

// Obtener tasa de cambio actual
$exchangeRate = getExchangeRate($pdo);

// Si el empleado tiene tarifa por hora en USD
if ($employeeData['hourly_rate'] > 0) {
    $hoursWorked = 40; // horas trabajadas
    $salaryUSD = $employeeData['hourly_rate'] * $hoursWorked;
    $salaryDOP = convertCurrency($pdo, $salaryUSD, 'USD', 'DOP');
}

// Si el empleado tiene salario mensual en DOP
if ($employeeData['monthly_salary_dop'] > 0) {
    $salaryDOP = $employeeData['monthly_salary_dop'];
    $salaryUSD = convertCurrency($pdo, $salaryDOP, 'DOP', 'USD');
}
```

### Ejemplo: Reportes con conversión automática

```php
// En reportes de nómina
foreach ($employees as $employee) {
    $preferredCurrency = $employee['preferred_currency'];
    
    // Calcular en moneda preferida
    if ($preferredCurrency === 'USD') {
        $salary = $employee['hourly_rate'] * $hoursWorked;
        $salaryConverted = convertCurrency($pdo, $salary, 'USD', 'DOP');
    } else {
        $salary = $employee['hourly_rate_dop'] * $hoursWorked;
        $salaryConverted = convertCurrency($pdo, $salary, 'DOP', 'USD');
    }
    
    echo "Salario: $" . number_format($salary, 2) . " {$preferredCurrency}";
    echo " (Equivalente: $" . number_format($salaryConverted, 2) . " " . ($preferredCurrency === 'USD' ? 'DOP' : 'USD') . ")";
}
```

## Estructura de Base de Datos

### Tabla: `system_settings`

```sql
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_setting_key (setting_key)
);
```

### Configuraciones Predeterminadas

| setting_key | setting_value | setting_type | description |
|------------|---------------|--------------|-------------|
| exchange_rate_usd_to_dop | 58.50 | number | Tasa de cambio de USD a DOP |
| exchange_rate_last_update | [timestamp] | string | Última actualización de la tasa |

## Instalación

1. Ejecutar la migración SQL:
```bash
mysql -u usuario -p nombre_db < migrations/add_system_settings.sql
```

2. Verificar que los permisos estén asignados:
```sql
SELECT * FROM permissions WHERE name = 'system_settings';
SELECT * FROM role_permissions WHERE permission_name = 'system_settings';
```

3. Acceder a la configuración:
   - URL: `hr/system_settings.php`
   - Requiere sesión activa con rol ADMIN o HR

## Logging y Auditoría

Todos los cambios en la tasa de cambio quedan registrados en `activity_logs`:

```php
log_system_setting_changed($pdo, $user_id, $user_name, $user_role, 'exchange_rate_usd_to_dop', [
    'old_rate' => 58.50,
    'new_rate' => 59.00,
    'updated_at' => date('Y-m-d H:i:s')
]);
```

## Mejores Prácticas

1. **Actualización Regular**: Actualizar la tasa de cambio regularmente (diaria o semanalmente) para mantener precisión
2. **Verificación**: Siempre verificar la tasa antes de generar nóminas importantes
3. **Comunicación**: Notificar al equipo cuando se actualice la tasa de cambio
4. **Respaldo**: Los cambios históricos quedan en activity_logs para auditoría

## Archivos Relacionados

- `migrations/add_system_settings.sql` - Migración de base de datos
- `hr/system_settings.php` - Interfaz de configuración
- `db.php` - Funciones de acceso a configuración
- `lib/logging_functions.php` - Funciones de logging

## Permisos Requeridos

- **Permiso**: `system_settings`
- **Roles con acceso**: ADMIN, HR
- **Descripción**: Permite administrar configuraciones del sistema como tasa de cambio

## Soporte

Para agregar nuevas configuraciones al sistema, seguir este patrón:

```sql
INSERT INTO system_settings (setting_key, setting_value, setting_type, description) 
VALUES ('nueva_configuracion', 'valor', 'string', 'Descripción de la configuración');
```

Luego usar `getSystemSetting()` para obtener el valor en el código PHP.

# Sistema de Códigos de Empleado

## Descripción General

El sistema genera automáticamente códigos únicos de empleado cuando se crea un nuevo usuario en el sistema. Estos códigos son utilizados por Recursos Humanos para identificar a los empleados de manera única.

## Formato del Código

Los códigos de empleado siguen el formato: **EMP-YYYY-XXXX**

- **EMP**: Prefijo fijo que identifica el código como de empleado
- **YYYY**: Año actual (4 dígitos)
- **XXXX**: Número secuencial de 4 dígitos (0001-9999)

### Ejemplos:
- `EMP-2025-0001` - Primer empleado creado en 2025
- `EMP-2025-0042` - Empleado número 42 creado en 2025
- `EMP-2026-0001` - Primer empleado creado en 2026 (la secuencia se reinicia cada año)

## Implementación Técnica

### Base de Datos

El campo `employee_code` se agregó a la tabla `users`:
- Tipo: `VARCHAR(20)`
- Único: Sí (no pueden existir códigos duplicados)
- Nullable: Sí (para compatibilidad con datos existentes)
- Índice: Sí (para búsquedas rápidas)

### Generación Automática

El código se genera automáticamente en dos puntos del sistema:

1. **register.php** - Portal de registro de agentes
2. **settings.php** - Panel de administración (creación de usuarios)

#### Algoritmo de Generación:

1. Obtener el año actual
2. Buscar el último código de empleado del año actual
3. Extraer el número secuencial y sumar 1
4. Si no existe ningún código del año actual, comenzar desde 0001
5. Formatear el código con el formato EMP-YYYY-XXXX

```php
// Ejemplo de código de generación
$currentYear = date('Y');
$codeStmt = $pdo->prepare("SELECT employee_code FROM users WHERE employee_code LIKE  ORDER BY employee_code DESC LIMIT 1");
$codeStmt->execute(["EMP-{$currentYear}-%"]);
$lastCode = $codeStmt->fetch();

if ($lastCode && $lastCode['employee_code']) {
    $lastNumber = (int)substr($lastCode['employee_code'], -4);
    $newNumber = $lastNumber + 1;
} else {
    $newNumber = 1;
}

$employeeCode = sprintf("EMP-%s-%04d", $currentYear, $newNumber);
```

## Migración de Datos Existentes

Para usuarios existentes sin código de empleado, ejecutar la migración:

```bash
mysql -u [usuario] -p ponche < migrations/add_employee_code.sql
```

Esta migración:
1. Agrega el campo `employee_code` a la tabla `users`
2. Genera códigos para todos los usuarios existentes basándose en su ID

## Uso por Recursos Humanos

Los códigos de empleado pueden ser utilizados para:
- Identificación única de empleados en reportes
- Referencias en documentación de RH
- Integración con sistemas externos de nómina
- Búsqueda rápida de empleados en el sistema

## Consideraciones

- **Unicidad**: El sistema garantiza que no se generen códigos duplicados
- **Secuencia anual**: La numeración se reinicia cada año calendario
- **Límite**: Máximo 9,999 empleados por año
- **Retrocompatibilidad**: Usuarios existentes pueden tener códigos NULL hasta que se ejecute la migración

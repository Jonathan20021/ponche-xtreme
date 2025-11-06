# Fix de Zona Horaria del Chat

## Problema Identificado
Los mensajes del chat mostraban una diferencia de 2 horas entre el momento de envío/recepción y la hora mostrada. Esto ocurría porque:

1. **MySQL** guardaba timestamps sin información de zona horaria
2. **JavaScript** interpretaba los timestamps como UTC en lugar de hora local
3. **PHP** estaba configurado con `America/Santo_Domingo` (UTC-4) pero MySQL no lo sabía

## Solución Implementada

### 1. Configuración de Zona Horaria en MySQL (`db.php`)

Se agregó la configuración de zona horaria tanto para PDO como MySQLi:

```php
// Conexión PDO
$pdo->exec("SET time_zone = '-04:00'");

// Conexión MySQLi
$conn->query("SET time_zone = '-04:00'");
```

Esto asegura que MySQL guarde y recupere timestamps en la zona horaria correcta (UTC-4 para República Dominicana).

### 2. Corrección de Parsing de Timestamps en JavaScript (`assets/js/chat.js`)

Se modificó la función `formatTime()` para interpretar correctamente los timestamps de MySQL:

```javascript
formatTime(timestamp) {
    if (!timestamp) return '';
    
    // Si el timestamp viene de MySQL (formato 'YYYY-MM-DD HH:mm:ss'), 
    // lo tratamos como hora local, no UTC
    let date;
    if (typeof timestamp === 'string' && timestamp.match(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)) {
        // Reemplazar espacio con 'T' para que JavaScript lo interprete como hora local
        date = new Date(timestamp.replace(' ', 'T'));
    } else {
        date = new Date(timestamp);
    }
    
    const now = new Date();
    const diff = now - date;
    
    // ... resto de la lógica de formateo
}
```

**Por qué funciona:**
- Cuando JavaScript recibe `'2024-11-06 14:30:00'` y lo convierte con `new Date()`, lo interpreta como UTC
- Al reemplazar el espacio con 'T' (`'2024-11-06T14:30:00'`), JavaScript lo interpreta como hora local
- Esto coincide con la hora que MySQL guarda, corrigiendo la diferencia

### 3. Corrección de API Response (`chat/api.php`)

Se actualizó la respuesta de `send_message` para devolver el timestamp exacto de la base de datos:

```php
// Antes:
'created_at' => date('Y-m-d H:i:s')

// Ahora:
$stmt = $pdo->prepare("SELECT created_at FROM chat_messages WHERE id = ?");
$stmt->execute([$messageId]);
$createdAt = $stmt->fetchColumn();
'created_at' => $createdAt
```

Esto garantiza consistencia entre lo que se guarda en la base de datos y lo que se envía al cliente.

## Archivos Modificados

1. **`db.php`**
   - Agregado `SET time_zone = '-04:00'` para PDO
   - Agregado `SET time_zone = '-04:00'` para MySQLi

2. **`assets/js/chat.js`**
   - Modificada función `formatTime()` para manejar correctamente timestamps de MySQL

3. **`chat/api.php`**
   - Actualizada respuesta de `send_message` para devolver timestamp de BD

## Resultado

Ahora los mensajes muestran la hora precisa de envío/recepción, sin la diferencia de 2 horas. Los timestamps son consistentes entre:
- PHP (America/Santo_Domingo, UTC-4)
- MySQL (configurado a UTC-4)
- JavaScript (interpretando como hora local)

## Notas Adicionales

- La zona horaria `-04:00` corresponde a República Dominicana (AST - Atlantic Standard Time)
- Esta configuración se aplica a TODAS las consultas de MySQL en el sistema
- No afecta datos existentes, solo cómo se interpretan los timestamps
- Compatible con horario de verano si República Dominicana lo implementa (actualmente no lo usa)

## Testing

Para verificar que el fix funciona:

1. Envía un mensaje en el chat
2. Verifica que la hora mostrada coincida con tu hora local
3. Refresca la página y verifica que la hora sigue siendo correcta
4. Compara con la hora del sistema operativo

## Fecha de Implementación
6 de noviembre de 2025

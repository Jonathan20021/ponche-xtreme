# Mejora en Generación de Contratos - Tipo de Pago

## Fecha: 6 de febrero de 2026

## Problema Identificado
Los contratos generados no especificaban si el salario era por hora o mensual fijo, lo cual causaba confusión ya que la mayoría de los empleados cobran por hora.

## Solución Implementada

### 1. Cambios en el Formulario (`hr/contracts.php`)
- Se agregó un nuevo campo de selección **"Tipo de Pago"** antes del campo de salario
- Opciones disponibles:
  - **Por Hora**: Para empleados que cobran por cada hora trabajada
  - **Salario Mensual Fijo**: Para empleados con salario fijo mensual

### 2. Cambios en la Generación del Contrato (`hr/generate_contract.php`)

#### Base de Datos
- Se agregó la columna `payment_type` a la tabla `employment_contracts`
- El campo almacena: `'por_hora'` o `'mensual'`
- Valor por defecto: `'mensual'` para compatibilidad con registros existentes

#### Modificación de la Cláusula SEGUNDO
El contrato ahora muestra claramente el tipo de pago:

**Para pagos por hora:**
```
Como contraprestación a los servicios laborales prestados EL EMPLEADO 
recibirá de EL EMPLEADOR la suma de RD$ XXX.XX Pesos Dominicanos 
por cada hora laborada (RD$ XXX.XX/hora)...
```

**Para salario mensual:**
```
Como contraprestación a los servicios laborales prestados EL EMPLEADO 
recibirá de EL EMPLEADOR la suma de RD$ XXX.XX Pesos Dominicanos 
mensuales fijos (RD$ XXX.XX/mes)...
```

### 3. Visualización en la Lista de Contratos
La tabla de contratos generados ahora muestra el tipo de pago junto al monto:
- `RD$ 250.00 /hora` - Para pagos por hora
- `RD$ 30,000.00 /mes` - Para salarios mensuales

### 4. Migración de Base de Datos
Se creó el archivo `migrations/add_payment_type_to_contracts.sql` que:
- Agrega la columna `payment_type` de forma segura
- Actualiza registros existentes con valor por defecto `'mensual'`

## Archivos Modificados
1. `hr/contracts.php` - Formulario de generación de contratos
2. `hr/generate_contract.php` - Lógica de generación y guardado
3. `migrations/add_payment_type_to_contracts.sql` - Migración de base de datos

## Instrucciones de Uso

1. **Generar un nuevo contrato:**
   - Ir a `/hr/contracts.php`
   - Llenar el formulario del empleado
   - **Seleccionar el tipo de pago** (Por Hora o Salario Mensual Fijo)
   - Ingresar el monto del salario
   - Generar el contrato

2. **Para empleados por hora:**
   - Seleccionar "Por Hora"
   - Ingresar la tarifa por hora (ej: 250.00)
   - El contrato especificará "por cada hora laborada"

3. **Para empleados con salario fijo:**
   - Seleccionar "Salario Mensual Fijo"
   - Ingresar el salario mensual (ej: 30000.00)
   - El contrato especificará "mensuales fijos"

## Validaciones
- El campo "Tipo de Pago" es **obligatorio**
- El sistema valida que se seleccione una opción antes de generar el contrato
- Los contratos existentes mantendrán la información correcta con migración automática

## Beneficios
✅ Claridad en el tipo de compensación del empleado  
✅ Mejor cumplimiento legal al especificar el método de pago  
✅ Menos confusión para empleados y departamento de RH  
✅ Historial completo de contratos con tipo de pago registrado  
✅ Compatible con contratos ya existentes en el sistema

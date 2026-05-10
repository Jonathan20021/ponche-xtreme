# Integración Préstamos Finanzas ↔ Ponche-Xtreme

Documentación de la integración entre la app de Finanzas (Next.js, en
`hugo-finanzas`) y ponche-xtreme (PHP). Permite que los agentes soliciten
préstamos desde el portal del agente, sean aprobados desde Finanzas, y las
cuotas se descuenten automáticamente en la nómina del punch.

## Arquitectura

```
┌─────────────────────────────┐     HTTPS + X-API-Key    ┌─────────────────────────────┐
│  ponche-xtreme (PHP)         │ ───────────────────────▶ │  app de Finanzas (Next.js)   │
│                              │                          │                              │
│  • agents/request_loan.php   │ ◀───────────────────────│  • /api/loans/external-types │
│  • agents/my_loans.php       │                          │  • /api/loans/external-      │
│  • agents/loans_api_client   │                          │      request                 │
│                              │                          │                              │
│  BD: hhempeos_ponche         │                          │  BD: hhempeos_financial      │
│   - employees                │                          │   - loans                    │
│   - employee_deductions ◀────┼──── INSERT cuotas ───────│   - loan_installments        │
│   - payroll_periods          │                          │   - loan_payroll_deductions  │
└─────────────────────────────┘                          └─────────────────────────────┘
```

## Configuración

### 1. API key compartida

Ya instalada en `hhempeos_financial_system.loan_settings.external_api_key`.

Si cambias el valor en producción, actualiza también:
- `LOANS_API_KEY` en `agents/loans_api_client.php` (o env var)
- Setting `external_api_key` en la BD de finanzas

### 2. URL base de la app de Finanzas

Por defecto `http://localhost:3000`. Modificable vía:
- Env var `LOANS_API_BASE_URL`
- Constante `LOANS_API_BASE_URL` en `agents/loans_api_client.php`

## Flujo: Solicitud de préstamo desde el portal del agente

1. Agente entra a `agents/request_loan.php`
2. El sistema localiza al empleado en `employees` por `user_id`
3. Carga los tipos de préstamo disponibles desde finanzas vía
   `GET /api/loans/external-types` (header `X-API-Key`)
4. Agente llena el formulario (monto, plazo, frecuencia, propósito, aval, consentimiento Art. 200)
5. POST a `/api/loans/external-request` con:
   - `employee_external_id` (= `employees.id`)
   - `loan_type_code`, `principal_amount`, `installment_count`,
     `installment_frequency`, `purpose`, `has_guarantor`, `employee_consent`
6. Finanzas:
   - Resuelve datos del empleado consultando directamente la BD de nómina
     (`employees + employment_contracts`) para calcular `monthly_salary` y
     validar Art. 201 CT (33.33%)
   - Valida límites del tipo, máximo de préstamos activos
   - Calcula amortización
   - Inserta préstamo en estado `pending`
   - Inserta la tabla de cuotas (`loan_installments`)
   - Registra en `loan_audit_log`
7. Agente recibe `loan_number`, monto de cuota, total a pagar y
   eventual `affordability_warning`
8. Finanzas (administrador) aprueba el préstamo desde su UI normal

## Flujo: Descuento automático en nómina

Cuando finanzas programa una cuota para un período de nómina (vía el
diálogo "Nómina" del módulo de préstamos):

1. `POST /api/loans/payroll-sync` en finanzas:
   - Inserta en `hhempeos_financial_system.loan_payroll_deductions` (estado `scheduled`)
   - **Inserta en `hhempeos_ponche.employee_deductions`** con:
     - `employee_id` = ID del empleado
     - `name` = "Préstamo PRES-2026-XXXX - Cuota N"
     - `type` = "FIXED"
     - `amount` = monto de la cuota
     - `is_active` = 1
     - `start_date` = inicio del período
     - `end_date` = fin del período

2. Cuando el motor de nómina del punch calcula el período
   (`calculateEmployeePayroll` en `hr/payroll_functions.php`):
   - Llama `getEmployeeCustomDeductions($pdo, $employeeId, $periodStart, $periodEnd)`
   - La función intersecta `[start_date, end_date]` del descuento con
     `[periodStart, periodEnd]` del período → la cuota cae dentro y se
     suma a `custom_deductions`
   - Se incluye en `total_deductions` y reduce `net_salary`

3. La cuota descontada aparece en `payroll_records.other_deductions` para
   ese empleado y período.

### Patch importante en `hr/payroll_functions.php`

`getEmployeeCustomDeductions` ahora acepta dos parámetros adicionales
(`$periodStart`, `$periodEnd`). El cambio es **backwards-compatible**: si
no se proveen, mantiene el filtro legacy basado en `CURDATE()`.

`calculateEmployeePayroll` ahora pasa las fechas del período actual a
`getEmployeeCustomDeductions`. Esto garantiza que las cuotas se apliquen
en el período correcto **aún si la nómina se procesa días después del
cierre del período**.

## Endpoints REST

### `GET /api/loans/external-types`
Headers: `X-API-Key`
Respuesta: `{ loan_types: [{ code, name, default_interest_rate, ... }] }`

### `POST /api/loans/external-request`
Headers: `X-API-Key`, `Content-Type: application/json`
Body:
```json
{
  "employee_external_id": 31,
  "loan_type_code": "EMP_PERSONAL",
  "principal_amount": 50000,
  "installment_count": 12,
  "installment_frequency": "biweekly",
  "currency": "DOP",
  "purpose": "Reparación de vivienda",
  "has_guarantor": false,
  "employee_consent": true,
  "source": "agent_portal"
}
```
Respuesta `201`:
```json
{
  "success": true,
  "id": 7,
  "loan_number": "PRES-2026-0007",
  "status": "pending",
  "installment_amount": 4416.67,
  "total_payable": 53000,
  "first_due_date": "2026-05-30",
  "last_due_date": "2026-11-15",
  "affordability_warning": null
}
```

### `GET /api/loans/external-request?employee_external_id=N`
Headers: `X-API-Key`
Respuesta: `{ loans: [{ loan_number, status, outstanding_balance, next_due_date, ... }] }`

## Archivos modificados / creados

### Finanzas (Next.js)
- `app/api/loans/external-request/route.ts` (nuevo)
- `app/api/loans/external-types/route.ts` (nuevo)
- `loan_settings.external_api_key` (insertado en BD)

### Ponche-xtreme (PHP)
- `agents/loans_api_client.php` (nuevo) — cliente HTTP a finanzas
- `agents/request_loan.php` (nuevo) — formulario de solicitud
- `agents/my_loans.php` (nuevo) — tracking de préstamos del agente
- `agents/index.php` (modificado) — agrega tarjetas de acceso rápido
- `header_agent.php` (modificado) — agrega items al menú
- `hr/payroll_functions.php` (modificado):
  - `getEmployeeCustomDeductions` ahora acepta `$periodStart, $periodEnd`
  - `calculateEmployeePayroll` pasa las fechas del período

## Permisos requeridos

El agente NO necesita permisos en la app de finanzas. Toda autorización
ocurre vía la API key compartida.

Para que un agente pueda solicitar préstamos, basta con que:
- Tenga `role IN ('AGENT', 'IT', 'Supervisor')` en sesión del punch
- Esté vinculado a un registro en `employees` (`employees.user_id = users.id`)

## Seguridad

- **API key obligatoria** en todas las requests externas
- **Sin autenticación de empleado** — la app del agente confía en la sesión
  del punch; la API key le da derecho a actuar a nombre de cualquier
  `employee_external_id`. Esto es razonable porque ambos sistemas son
  internos y la solicitud queda en `pending` hasta aprobación manual.
- **HTTPS recomendado** en producción (la API key viaja en clear text si no)
- **Auditoría completa**: cada solicitud queda en `loan_audit_log` con IP

## Pruebas manuales

1. Asegurarse de que la app de Finanzas está corriendo en `localhost:3000`
2. Login como agente en ponche-xtreme
3. Ir a `agents/request_loan.php`
4. Verificar que aparecen los tipos de préstamo (selector poblado)
5. Crear una solicitud de prueba
6. Ir a `agents/my_loans.php` y verificar que aparece como "Pendiente"
7. En la app de Finanzas, ver el préstamo en la lista de préstamos pendientes
8. Aprobar y desembolsar
9. Programar las cuotas para el siguiente período de nómina (botón "Nómina")
10. Verificar que aparecen en `hhempeos_ponche.employee_deductions`
11. Calcular la nómina del período y verificar que `other_deductions` del
    empleado incluye el monto de la cuota

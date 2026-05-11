# Integración Préstamos Finanzas ↔ Ponche-Xtreme

Documentación de la integración entre la app de Finanzas (Next.js) y
ponche-xtreme (PHP). Permite que los agentes soliciten préstamos desde el
portal del agente, sean aprobados desde Finanzas, y las cuotas se
descuenten automáticamente en la nómina del punch.

## Arquitectura (REVISADA — versión final)

> **Cambio crítico:** se eliminó la dependencia HTTP entre ponche y la app de
> Finanzas. Ahora la comunicación es **DB-direct**, así el portal de
> agentes funciona aún cuando la app local de Finanzas esté apagada.

```
┌─────────────────────────────────┐                         ┌──────────────────────────────┐
│  ponche-xtreme (PHP, cPanel)     │                         │  app de Finanzas (Next.js)   │
│  ─ online 24/7                   │                         │  ─ corre en local (dev)      │
│                                  │                         │                              │
│  • agents/request_loan.php       │                         │  • UI módulo préstamos       │
│  • agents/my_loans.php           │                         │  • aprueba / desembolsa      │
│  • agents/loans_helpers.php      │                         │  • programa deducciones      │
│  • agents/loans_api_client.php   │                         │                              │
│  • db_finanzas.php               │                         │                              │
│                                  │                         │                              │
│   ▼ PDO (puerto 3306)            │                         │   ▼ mysql2 pool              │
└──────────────────┬───────────────┘                         └─────────────────┬────────────┘
                   │                                                           │
                   │      ┌──────────────────────────────────────────┐         │
                   └────▶│  MySQL cPanel (192.185.46.27)              │◀────────┘
                          │                                            │
                          │  • hhempeos_ponche                         │
                          │    - employees, employee_deductions,       │
                          │      payroll_periods, etc.                 │
                          │                                            │
                          │  • hhempeos_financial_system               │
                          │    - loans, loan_installments,             │
                          │      loan_types, loan_audit_log,           │
                          │      loan_settings, automation_config      │
                          └────────────────────────────────────────────┘
```

### ¿Por qué DB-direct y no HTTP?

| Aspecto | HTTP (descartado) | DB-direct (actual) |
|---|---|---|
| Disponibilidad | requiere app local viva | independiente |
| Latencia | red + parsing JSON | una transacción SQL |
| Auth | API key + bearer | credenciales DB |
| Email notification | desde Next.js | desde PHP + Resend API |
| Punto único de falla | sí (Next.js local) | no |

Ambos esquemas (`hhempeos_ponche` y `hhempeos_financial_system`) viven en el
**mismo servidor MySQL de cPanel**, así que ponche puede conectarse a la BD
de finanzas exactamente como se conecta a la suya propia. La app de Finanzas
verá los nuevos préstamos en estado `pending` cuando se levante.

## Configuración

### Variables de entorno (cPanel, opcional)

```ini
FINANZAS_DB_HOST=192.185.46.27
FINANZAS_DB_NAME=hhempeos_financial_system
FINANZAS_DB_USER=hhempeos_finanzas
FINANZAS_DB_PASSWORD=Hacker#2002
FINANZAS_DB_PORT=3306

RESEND_API_KEY=re_xxxxxxxxxxxx
RESEND_FROM_EMAIL=notificaciones@send.evallishbpo.com
```

Si no se definen, ponche usa los valores por defecto codificados en
`db_finanzas.php`. **Recomendado** mover el password a env var en producción.

### Configuración de notificaciones

Desde la app de Finanzas → **Automatizaciones** → sección **"Configuración del Sistema"**:

| Key | Descripción |
|---|---|
| `LOAN_NOTIFICATION_CEO_EMAIL` | Destinatario principal de notificaciones |
| `LOAN_NOTIFICATION_EXTRA_EMAIL` | Segundo destinatario (opcional) |
| `AUTOMATIC_EMAILS_PAUSED` | Si está en `1`, omite envíos (kill-switch) |

PHP lee estos valores de `automation_config` directamente al momento del envío.

## Flujo: Solicitud desde el portal del agente

1. Agente entra a `agents/request_loan.php`
2. PHP busca al empleado en `employees` por `user_id`
3. Carga los tipos vía `getLoanTypesFromFinance()` → `SELECT * FROM loan_types WHERE is_active=1 AND borrower_type='employee'` (BD finanzas)
4. Agente llena el formulario (monto, plazo, frecuencia, propósito, aval, consentimiento Art. 200)
5. `createLoanRequestInFinance()`:
   - Resuelve datos del empleado (nombre, cédula, departamento, salario mensual desde `employment_contracts`)
   - Carga el tipo de préstamo
   - Valida máximo de préstamos activos (`loan_settings.max_active_loans_per_employee`)
   - Calcula amortización en PHP (`calculateAmortizationPHP`, porte fiel de `lib/loan-utils.ts`)
   - Valida Art. 201 CT (`validateAffordabilityPHP`)
   - Genera número correlativo (`generateLoanNumberPHP` → `PRES-YYYY-NNNN`)
   - **Transacción**:
     - `INSERT INTO hhempeos_financial_system.loans` (status='pending')
     - `INSERT INTO loan_installments` (cuadro completo)
     - `INSERT INTO loan_audit_log` (registro de auditoría)
   - Envía notificación al CEO + extra vía Resend (`sendLoanCreatedNotificationPHP`)
6. Agente ve confirmación con `loan_number`, cuota, total a pagar
7. Cuando se levante la app de Finanzas, verá la solicitud en `pending` y puede aprobar

## Flujo: Descuento automático en nómina

(Sin cambios respecto al diseño original)

1. Finanzas programa cuotas vía POST `/api/loans/payroll-sync`
   → `INSERT INTO hhempeos_ponche.employee_deductions` con `name='Préstamo PRES-XXXX - Cuota N'`, `type='FIXED'`, `amount`, `start_date`, `end_date`
2. HR genera la nómina del período → `calculateEmployeePayroll()` llama
   `getEmployeeCustomDeductions($pdo, $employeeId, $periodStart, $periodEnd)`
   → la cuota cae en `[start_date, end_date]` y se suma
3. La columna **"Préstamos"** del listado muestra el monto por empleado
4. En el slip individual aparece una fila por cada cuota con su número

## Archivos del lado de ponche-xtreme

### Nuevos

| Archivo | Función |
|---|---|
| `db_finanzas.php` | `getFinanzasPdo()` y `finanzasDbAvailable()` |
| `agents/loans_helpers.php` | `calculateAmortizationPHP`, `validateAffordabilityPHP`, `generateLoanNumberPHP`, `sendLoanCreatedNotificationPHP`, `getFinanzasConfig` |
| `agents/loans_integration_test.php` | Test rápido CLI: `php agents/loans_integration_test.php` |

### Modificados

| Archivo | Cambio |
|---|---|
| `agents/loans_api_client.php` | Reescrito: ya **no** usa HTTP. Las 3 funciones públicas (`getLoanTypesFromFinance`, `createLoanRequestInFinance`, `getEmployeeLoansFromFinance`) ahora consultan / escriben directamente a la BD de finanzas |
| `agents/request_loan.php` | Sin cambios funcionales — usa el mismo nombre de las funciones del client |
| `agents/my_loans.php` | Sin cambios — usa `getEmployeeLoansFromFinance` |
| `header_agent.php` | Items "Mis Préstamos" y "Solicitar Préstamo" en el menú |
| `hr/payroll_functions.php` | `getEmployeeCustomDeductions` acepta período, helpers `getLoanDeductionsForEmployees`, `getEmployeeLoanDeductionDetails` |
| `hr/payroll.php` | Columna "Préstamos" en listado |
| `hr/payroll_export_pdf.php` | Columna "Préstamos" en PDF |
| `hr/payroll_export_excel.php` | Columna "Préstamos" en Excel |
| `hr/payroll_slips_preview.php` | Fila por préstamo en slip |
| `lib/payroll_email_functions.php` | Mismo en email del slip |

## Archivos del lado de la app de Finanzas

### Endpoints REST (siguen activos como interfaz alternativa / testing)

| Endpoint | Uso |
|---|---|
| `POST /api/loans/external-request` | Mismo flujo de creación cuando se llama por HTTP. Sirve para integraciones futuras con apps que no compartan BD. |
| `GET /api/loans/external-request?employee_external_id=N` | Lista préstamos del empleado vía HTTP |
| `GET /api/loans/external-types` | Tipos disponibles vía HTTP |

> Estos endpoints **no son usados por el portal de agentes actualmente**.
> Quedan disponibles si en el futuro se decide separar las apps en hosts
> distintos con BDs independientes.

### Notificación

- `lib/loan-notifications.ts` → `sendLoanCreatedNotification()` para préstamos
  creados desde la propia UI de Finanzas (Next.js)
- `lib/loans_helpers.php` → `sendLoanCreatedNotificationPHP()` para préstamos
  creados desde ponche

Ambos envían el mismo HTML al CEO + email extra configurados.

## Cumplimiento Art. 200 / 201

- **Art. 200 CT** (autorización escrita): el form de solicitud incluye un
  checkbox obligatorio que registra `employee_consent_at` (timestamp) y
  `employee_consent_ip` (IP) en `loans`
- **Art. 201 CT** (33.33% del salario): `validateAffordabilityPHP` se ejecuta
  antes de insertar y, si excede, genera `affordability_warning` que viaja al
  préstamo y al correo de notificación
- **Auditoría completa**: cada acción en `loan_audit_log` con IP

## Test rápido

```bash
# En el servidor de ponche
php agents/loans_integration_test.php
```

Debe imprimir:
- ✅ Conexión a hhempeos_financial_system
- ✅ N tipos disponibles
- ✅ Empleado de prueba resuelto
- ✅ Simulación de amortización
- Configuración de notificaciones (CEO email)

## Notas de mantenimiento

- **Sincronización de lógica**: el cálculo de amortización vive duplicado en
  TypeScript (`lib/loan-utils.ts`) y PHP (`agents/loans_helpers.php`). Si se
  cambia uno, **debe replicarse al otro** para mantener consistencia. Tests
  cruzados son recomendables.
- **Resend en cPanel**: asegurarse de que `RESEND_API_KEY` esté disponible
  como env var del PHP (via `.htaccess SetEnv` o phprc). Si no, el correo
  se omitirá pero el préstamo se guardará igual.
- **Pool de conexiones**: PHP usa conexiones nuevas por request a la BD de
  finanzas. No hace pool — apropiado para tráfico bajo del portal de agentes.

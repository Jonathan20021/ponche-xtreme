# Despliegue — Ajustes y Sugerencias para el Sistema de Ponche

Cubre los 5 puntos del documento del cliente. Léelo completo antes de subir: hay
**un paso de base de datos obligatorio** y **una tarea programada nueva** sin la
cual dos de los puntos no funcionan.

---

## 0. Orden de despliegue (resumen)

1. Subir los archivos (lista al final).
2. Correr `run_notifications_migration.php` **una vez** (crea tablas y ajustes).
3. Re-instalar las tareas programadas: doble clic en `run_vicidial_sync.bat` →
   opción `install` (ahora registra 6 tareas, antes 4).
4. Revisar la pestaña **Notificaciones (Campana)** en `settings.php`.
5. Verificar con los comandos de la sección 7.

---

## 1. Cálculo de horas del panel de RRHH (punto 4) — **BUG DE DATOS, no cosmético**

### Qué pasaba

`hr_report.php` tenía su propia versión del recorrido de punches y solo sumaba el
tramo **entre** punches pagados, no el que va del último punch pagado a la
pausa/salida que lo cierra. Una jornada normal se caía casi entera:

| Día de Marcela | Panel de RRHH (antes) | Real (lo que paga la nómina) |
|---|---|---|
| 2026-07-23 | **0:00** | 7:58 |
| 2026-07-22 | **0:00** | 8:01 |
| 2026-07-24 | **2:11** | 8:09 |

En julio, el total de administrativos pasó de **68 h** (mal) a **974 h** (correcto).
El "pago correspondiente" salía igual de bajo porque se calculaba con esos segundos.

### Qué se hizo

Todo lo que muestra horas ahora usa `lib/work_hours_calculator.php`, el mismo
cálculo que paga la nómina (respeta pausas anidadas, el marcador `ENTRY` y los
punches "fantasma" de ediciones de supervisor). Se eliminaron **4 copias
divergentes** de la lógica:

- `hr_report.php` (dos bloques: resumen y detalle diario)
- `admin_report_excel.php` y `admin_daily_excel.php` (los dos Excel del panel)
- `adherencia_report_hr.php`, `wfm_report.php`, `api/productivity.php`
- `supervisor_agent_details_api.php` y `manager_admin_details_api.php` (el modal
  del monitor usaba un quinto cálculo, con un tope de 12 h que descartaba tramos
  en silencio)

Entrada para todo esto: `getPaidWorkSecondsByDateForUser()`.

### Exportación a Excel

Ya existía (botones "Exportar Excel" y "Diario Excel") pero traía los mismos
números malos. Ahora cuadra con la pantalla y con la nómina.

### Empleados por sueldo mensual: se muestra su valor mensual

Marcela cobra por **sueldo mensual** (RD$28,000) y no tiene tarifa por hora, así
que la columna de pago salía en **RD$0.00**. Multiplicar sus horas por una tarifa
inventada tampoco sirve: su pago no depende de las horas.

Ahora los reportes distinguen el **tipo de pago** usando `users.compensation_type`
(la misma regla que aplica la nómina: se respeta el campo y, si viene vacío o dice
`hourly` pero la persona no es agente y tiene sueldo mensual, se trata como fija):

| Tipo | Tarifa | Pago mostrado |
|---|---|---|
| **Mensual** (`fixed`, 14 personas) | — (no aplica) | **su sueldo mensual**, marcado `/mes` |
| **Por hora** (`hourly`) | tarifa horaria | horas × tarifa |

Esto corrigió además un caso que pasaba desapercibido: varios administrativos
están marcados como fijos **pero tienen también una tarifa horaria cargada**, y el
reporte usaba esa tarifa. Stephany mostraba RD$4,393 (39.94 h × RD$110) cuando en
realidad cobra RD$20,000/mes; Yelissa RD$5,650 cuando cobra RD$30,000/mes.

Cambios visibles:
- Columna nueva **"Tipo de pago"** en la tabla de colaboradores y en ambos Excel.
- En sueldo mensual, la columna de tarifa va vacía y el pago trae el valor mensual.
- La columna **Dif** queda en 0 para ellos, que es lo correcto: cobran su sueldo
  completo, no se les debe ni se les paga de más según las horas del período.
- En el **detalle diario** no se parte el sueldo por día (sería engañoso): se
  muestran sus horas y, en vez de un monto diario, su sueldo mensual de referencia.
  El Excel diario lleva una columna "Sueldo mensual DOP" para eso.
- El promedio de tarifa del encabezado ahora solo considera a quienes cobran por
  hora (antes dividía entre todos y salía bajo).

Las **horas** se muestran igual para todos: es el control de asistencia.

---

## 2. Reporte diario de reclutamiento (punto 1) — **el reporte ya existía; NADA lo disparaba**

El reporte estaba construido y habilitado (`recruitment_report_enabled = 1`, 08:30,
destinatarios puestos) y **sí** trae lo que pidió el cliente: cantidad de postulados
y distribución por cada estado del proceso.

Lo que faltaba era el disparador. En `activity_logs` solo había envíos
**"manualmente"** (desde el botón de Ajustes); el único automático era el de
ausencias y venía de un truco de carga de página que dejó de dispararse en enero.
**Los 13 reportes diarios estaban configurados y ninguno se enviaba.**

### Solución: `cron_daily_reports.php`

Un despachador que corre cada 5 minutos y, para cada reporte, mira en
`system_settings` si está habilitado, si hoy toca (fin de semana) y si es su hora
configurada (±5 min). Solo entonces ejecuta su script. La tabla
`daily_report_runs` garantiza **un envío por reporte por día**, así que da igual
que la ventana abarque dos corridas o que estén activos a la vez la tarea de
Windows y un cron de cPanel.

Cada reporte sigue siendo dueño de su lógica; el despachador solo decide "¿toca ahora?".

```bash
# Ver qué está pendiente hoy y a qué hora sale cada uno
php cron_daily_reports.php --status

# Probar uno sin esperar su hora (no consume el envío del día)
php cron_daily_reports.php --force=recruitment
```

Cubre los 13: `absence`, `activity_logs`, `executive_dashboard`, `ghl`,
`inventory`, `login_hours`, `login_logs`, `payroll`, `quality_alerts`,
`recruitment`, `tardiness`, `wasapi`, `workforce`.

---

## 3. Notificación de la disposición de IA a Reclutamiento (punto 2)

### Antes

Al recibir una postulación, si el score de la IA pasaba el mínimo, el sistema
movía al candidato a "Preseleccionado" **solo y sin avisar**. Nadie veía por qué.

### Ahora

La IA **propone** y Reclutamiento decide:

1. La IA evalúa y guarda la disposición sugerida (`ai_proposed_status`) en estado
   `PENDING`. **El candidato no se mueve.**
2. Llega una notificación a la campana con el resultado de la evaluación
   (ubicación, disponibilidad, perfil, score) y la **justificación** de la
   disposición sugerida.
3. En la ficha del candidato aparece un banner con **Aplicar disposición** /
   **Descartar sugerencia**. Al decidir, se registra en el historial de estados y
   se cierra la notificación.

Configurable en `settings.php` → Notificaciones:
- **Roles que reciben el aviso** (por defecto `HR,Admin`) y/o **IDs de usuario**
  concretos — así se dirige a Stephany aunque su rol cambie.
- **Pedir aprobación** se puede apagar: entonces vuelve al comportamiento anterior
  (preseleccionar por score alto). Incluso apagado, **nunca descarta a nadie
  automáticamente**: un "rechazar" de la IA siempre espera revisión humana.

---

## 4. Alertas de stock del inventario (punto 3)

Avisa en dos momentos:

- **Al instante**, en `inv_record_movement()`: la salida que deja el artículo bajo
  el mínimo o en cero avisa en ese momento.
- **En la revisión programada** (8:10 AM y 2:10 PM): para los que llevan días
  bajos y nadie mueve.

Tres niveles: **Agotado** (crítico), **Stock bajo** (≤ mínimo) y **Próximo a
agotarse** (dentro del margen configurado sobre el mínimo, 20% por defecto).

### Dos decisiones de diseño que importan

1. **Resumen agrupado.** En el inventario real hay ~42 artículos bajo el mínimo a
   la vez; una notificación por cada uno dejaría la campana inservible. Los bajos
   y los próximos a agotarse van en **un solo aviso** con el listado ordenado por
   déficit; los **agotados** van uno por uno. Se puede desactivar el agrupado.
2. **Ventana de silencio** (24 h por defecto): no se repite el aviso del mismo
   artículo en el mismo nivel. Un artículo con muchas salidas no llena la campana.

En Ajustes hay un botón **"Revisar el stock ahora"** para comprobar la
configuración sin esperar la tarea.

---

## 5. Histórico de disposiciones en el monitor (punto 5)

El monitor mostraba solo el estado actual y el tiempo en ese estado; al cambiar de
disposición se perdía lo anterior. En el modal del agente (Monitor en Tiempo Real
y Monitor Administrativos) se agregó **Histórico de Disposiciones**:

- Barra proporcional de la jornada completa, por color de estado.
- **Tiempo total en cada estado**, con % y cuántas veces entró en él.
- Secuencia cronológica del día con hora de inicio, fin y duración de cada tramo.
- El estado actual se marca **"en curso"** y cuenta hasta ahora.

Ejemplo real (Marcela, 2026-07-24):

```
08:01:11 → 08:01:29  Entry        18s
08:01:29 → 09:58:25  Disponible   1h 56m   (pagado)
09:58:25 → 10:01:48  Baño         3m 23s
10:01:48 → 12:13:34  Disponible   2h 11m   (pagado)
12:13:34 → 13:01:12  Break        47m 38s
13:01:12 → 17:02:11  Disponible   4h       (pagado)

Disponible 8h 9m (90.5%) · Break 47m 38s (8.8%) · Baño 3m 23s · Entry 18s
```

Las duraciones salen de `computeStateSegments()`, que aplica **las mismas reglas
que la nómina**. Hay una prueba automática que verifica que la suma de los tramos
pagados es idéntica al `work_seconds` que paga la nómina: si alguien toca una de
las dos máquinas de estados sin la otra, falla la prueba y no el pago de alguien.

No hizo falta ninguna tabla nueva: el ponche ya es el registro histórico real.

---

## 6. Pasos de instalación

### 6.1 Base de datos (obligatorio, una sola vez)

```bash
php run_notifications_migration.php
```

O por navegador: `https://punch.evallishbpo.com/run_notifications_migration.php`

Es **idempotente** (se puede correr varias veces). Crea:
- `system_notifications` + `system_notification_reads` (campana)
- 6 columnas `ai_propos*` en `job_applications` (revisión de la disposición)
- 16 ajustes en `system_settings`

> El servidor es **MySQL 5.7**, que no acepta `ADD COLUMN IF NOT EXISTS`. Por eso
> el instalador pregunta antes a `information_schema`. No uses los `.sql` de
> `migrations/` a mano en 5.7 si contienen `IF NOT EXISTS` en un `ALTER`.

`daily_report_runs` la crea sola el despachador en su primera corrida.

### 6.2 Tareas programadas (obligatorio)

Doble clic en **`run_vicidial_sync.bat`** → se auto-eleva a Admin → `install`.
Ahora registra **6** tareas (antes 4); las 4 de Vicidial se re-registran igual:

| Tarea | Cuándo | Para qué |
|---|---|---|
| `PoncheXtreme-VicidialSync` | 11:30 PM | (ya existía) |
| `PoncheXtreme-VicidialSync-Live` | cada 15 min, 8am-11pm | (ya existía) |
| `PoncheXtreme-VicidialSync-Refresh` | cada 1 min, 8am-11pm | (ya existía) |
| `PoncheXtreme-VicidialSync-Recordings` | cada 2 h, 8am-10pm | (ya existía) |
| **`PoncheXtreme-InventoryStockAlerts`** | 8:10 AM y 2:10 PM | **nueva** — alertas de stock |
| **`PoncheXtreme-DailyReports`** | cada 5 min, 5:55am-11:55pm | **nueva** — reportes diarios |

Sin la última, **los reportes diarios siguen sin enviarse** (punto 1).

Logs nuevos: `logs/inventory_stock_alerts.log` y `logs/daily_reports.log`.

### 6.3 Configuración

`settings.php` → pestaña **Notificaciones (Campana)**. Nada quedó hardcodeado:
destinatarios (roles y usuarios), umbrales, ventanas de silencio, agrupado,
copia por correo, intervalo de sondeo y retención.

Ajusta al menos:
- **Reclutamiento → IDs de usuario**: agrega el de Stephany para que le llegue
  directo, sin depender del rol.
- **Inventario → Roles**: por defecto `HR,Admin,IT`.

---

## 7. Verificación

```bash
# La nómina no se rompió (incluye las pruebas nuevas del histórico)
php tests/work_hours_calculator_test.php     # -> All tests passed.

# Qué reportes salen hoy y a qué hora
php cron_daily_reports.php --status

# Barrido de stock (crea los avisos de la campana)
php cron_inventory_stock_alerts.php
```

En la interfaz:
1. La **campana** aparece en el encabezado, junto a "Cerrar Sesión".
2. `hr_report.php` con el rango 16-24 de julio: Marcela debe dar **~8 h por día**
   (antes 0:00). "Exportar Excel" debe traer lo mismo.
3. Monitor en Tiempo Real → clic en un agente → **Histórico de Disposiciones**.
4. Ajustes → Notificaciones → **"Revisar el stock ahora"** → la campana se llena.

---

## 8. Archivos

### Nuevos (12)

```
lib/notifications.php                 Centro de notificaciones (crear, listar, leer, resolver)
lib/inventory_alerts.php              Clasificación de stock + avisos y resumen
lib/monitor_history.php               Histórico de disposiciones del monitor
api/notifications.php                 Endpoint de la campana
assets/css/notifications.css          Estilos de la campana (claro y oscuro)
assets/js/notifications.js            Sondeo + panel de la campana
hr/recruitment_ai_disposition.php     Aprobar / descartar la disposición sugerida
cron_daily_reports.php                Despachador de los 13 reportes diarios
cron_inventory_stock_alerts.php       Barrido programado de stock
run_notifications_migration.php       Instalador idempotente (MySQL 5.7)
migrations/add_system_notifications.sql
migrations/add_recruitment_ai_disposition_review.sql
```

### Modificados (21)

```
# Punto 4 — horas
hr_report.php, admin_report_excel.php, admin_daily_excel.php,
adherencia_report_hr.php, wfm_report.php, api/productivity.php,
lib/work_hours_calculator.php, db.php

# Punto 5 — histórico en el monitor
supervisor_dashboard.php, supervisor_agent_details_api.php,
manager_dashboard.php, manager_admin_details_api.php, hr/realtime_monitor_api.php

# Puntos 2 y 3 — notificaciones
header.php, settings.php, lib/recruitment_ai.php, submit_application.php,
hr/view_application.php, lib/inventory_functions.php

# Programación
run_vicidial_sync.bat

# Pruebas
tests/work_hours_calculator_test.php
```

> Recuerda que la oficina corre la **app local** contra la DB remota de HostGator:
> los archivos hay que subirlos a **los dos** (servidor de oficina y HostGator).
> La migración de BD se corre **una sola vez**, porque la base es la misma.

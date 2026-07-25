# Despliegue — Ajustes del módulo de Nómina

Cubre los 5 puntos del documento del cliente. **Un paso de base de datos
obligatorio.** No requiere tareas programadas nuevas: los dos reportes entran por
el despachador que ya existe.

---

## 0. Instalación

1. Subir los archivos (lista al final).
2. Correr **una vez**: `php run_payroll_module_migration.php` (idempotente).
3. Listo. Los reportes se despachan solos con `PoncheXtreme-DailyReports`.

---

## 1. Historial de modificaciones del ponche

### Qué faltaba

Vicidial ya guardaba `original_seconds` / `adjusted_seconds` / quién. El ponche
no guardaba nada: se editaba el punch y desaparecía el rastro de cómo estaba.

### Cómo quedó

Igual que Vicidial, más el detalle por estado que pediste:

```
2026-07-24 | Shamelly Patricia Curiel Torres | 8:03 → 8:00 | -0:03 pagadas
  Disponible: 483 min registrado en ponche → 480 min registrado por mrosario.evallish
  Baño:        21 min registrado en ponche →  24 min registrado por mrosario.evallish
  Hora: 08:47 → 08:50
  Marcela Altagracia Rosario Tavarez · 25/07/2026 · "El agente estuvo más tiempo en baño"
```

**La diferencia de fondo con Vicidial:** allá se ajusta un total de segundos; aquí
se corrige un punch y el efecto sobre las horas es indirecto. Por eso cada cambio
guarda una foto del día **antes y después** — el total pagado y la duración de
cada estado — calculada con la misma lógica que paga la nómina. De ahí salen
tanto la línea de totales como el detalle por estado.

Se registra en los **cuatro** puntos donde se modifican horas:

| Dónde | Acción |
|---|---|
| `edit_record.php` | Editar un registro |
| `supervisor_update_punch_api.php` | Editar punch desde el monitor |
| `supervisor_create_punch_api.php` | Agregar punch manual |
| `delete_record.php` | Eliminar un registro |

Si un punch se mueve a **otro día**, se registra en ambos días.

**Dónde se ve:** `hr/payroll_hours.php`, con un selector
**Horas de Vicidial / Horas del Ponche** para verlos lado a lado. Por defecto
solo muestra los cambios que movieron horas pagadas (lo que le importa a nómina);
el resto queda a un clic.

> El historial arranca vacío a propósito: registra desde el momento en que se
> instala. Los cambios anteriores no se pueden reconstruir porque nadie guardaba
> el estado previo.

---

## 2. Reporte semanal de horas extras

`cron_weekly_overtime_report.php` — sale los **lunes** (configurable) con la
semana completa anterior. Nunca reporta una semana a medias.

Trae por colaborador: días trabajados, horas regulares, **horas extra** y el
**costo estimado del recargo** (tarifa × horas × multiplicador), más un acumulado
por departamento.

Corrida real sobre la semana del 13 al 19 de julio:

```
Natacha Altagracia Marte Tejada   44:00 regulares   1:38 extra
Sucel Altagracia Báez             44:00 regulares   0:59 extra
Ashley Michelle García Peña       44:00 regulares   0:42 extra
...
7 colaboradores · 4:26 horas extra
```

Las horas salen de `computePeriodHoursForUser()` — la misma función que paga la
nómina — así que respeta automáticamente si el colaborador se mide por ponche o
por Vicidial, y aplica el corte semanal de 44 h de ley con el multiplicador
configurado (1.35).

> ⚠️ **Hallazgo:** **20 de 56 colaboradores activos no tienen ninguna tarifa ni
> sueldo configurado.** Para ellos el costo del recargo sale en 0 porque no hay
> con qué calcularlo. El reporte lo avisa explícitamente en un recuadro
> ("Sin tarifa configurada"), en vez de mostrar un RD$0.00 engañoso. Hay que
> cargarles la tarifa para que el costo sea real.

---

## 3. Reporte diario de jornadas de más de 8 horas

`cron_daily_over8h_report.php` — revisa el **día anterior**. El umbral es
configurable (`over8h_report_threshold_hours`, hoy 8).

Corrida real sobre el 23 de julio: **25 colaboradores** excedieron las 8 horas,
17:39 de exceso acumulado, la jornada más larga de 10:11.

```
Jessica Mercedes Almonte Pichardo  vicidial  10:11  +2:11
Felix Oscar Liriano Martinez       vicidial  10:09  +2:09
Christy Farah Occeus               vicidial   9:41  +1:41
Darielis Milagros Lora Capellán    ponche     9:01  +1:01
```

Muestra la fuente de cada uno (ponche o Vicidial) para que se pueda auditar.

**Ambos reportes ya están registrados en el despachador** (`cron_daily_reports.php`),
con su hora configurable y el marcador de un envío por día. Heredaron los
destinatarios del reporte de nómina que ya estaba configurado.

```bash
php cron_daily_reports.php --status                  # ver ambos en la lista
php cron_daily_reports.php --force=overtime          # probar el semanal
php cron_daily_reports.php --force=over8h            # probar el diario
php cron_daily_over8h_report.php --date=2026-07-23   # regenerar un día concreto
```

---

## 4. Historial de pagos y de cambios salariales en el perfil

Dos secciones nuevas en el perfil del colaborador.

**Historial de pagos** — de `payroll_records`: cada período con horas, horas
extra, bruto, neto y si está pagado, más el bruto y neto acumulados. Ejemplo real
(Marcela): 7 períodos, RD$98,000 bruto, RD$91,208.20 neto.

**Cambios salariales** — de qué monto a qué monto, en qué fecha y quién lo
autorizó, con flecha verde/roja según suba o baje.

> **Bug encontrado:** la tabla `salary_history` existía desde el módulo de RRHH
> pero estaba **vacía**. El formulario de "cambio de tarifa" sí guardaba (en
> `hourly_rate_history`), pero **la edición masiva de usuarios en Ajustes
> actualizaba sueldos y tarifas sin registrar nada** — que es como se cambian los
> sueldos en la práctica. Se enganchó ahí: ahora cada cambio queda con su valor
> anterior, el nuevo, la fecha y el usuario. El historial del perfil une las dos
> fuentes en una sola línea de tiempo.
>
> Los cambios hechos antes de esta instalación no se pueden recuperar.

---

## 5. Delivery — costo por restaurante

Página nueva: **RRHH → Delivery — Restaurantes** (`hr/delivery_restaurants.php`).

**La campaña de nómina sigue siendo UNA sola.** El restaurante vive en su propia
tabla y **no toca `employees.campaign_id`**, así que ni la nómina, ni los
monitores, ni los reportes cambian de comportamiento. Es exactamente lo que
pediste: dividir el costo contable sin fragmentar la campaña.

- Alta de restaurantes con código contable y color.
- Asignación de colaboradores con **porcentaje de reparto**: uno solo va al 100%,
  varios se reparten hasta sumar 100%.
- Reparto de costos por período, con el costo calculado desde las horas que ya
  paga la nómina.

Prueba real con un colaborador repartido 60/40:

```
Francheska Michelle Rodriguez Toribio   120.5 h   RD$15,056.25
    → Pollo Rey    60%   RD$ 9,033.75
    → Pizza Nova   40%   RD$ 6,022.50
```

Dos avisos automáticos que evitan errores contables:
- **Reparto incompleto:** colaboradores cuyos porcentajes vigentes no suman 100%.
- **Sin restaurante asignado:** quienes están en Delivery pero cuyo costo no se
  está repartiendo (en la prueba: 10 de 11).

Los restaurantes vigentes también aparecen en el perfil del colaborador, junto a
sus campañas.

---

## 6. Verificación

```bash
php tests/work_hours_calculator_test.php     # -> All tests passed.
php cron_daily_reports.php --status          # overtime y over8h en la lista
```

En la interfaz:
1. `hr/payroll_hours.php` → pestaña **Horas del Ponche**. Edita un punch desde el
   monitor y vuelve: el cambio aparece con su original, su modificado y quién.
2. Perfil de un colaborador → **Historial de Pagos** y **Cambios Salariales**.
3. **Delivery — Restaurantes** → crea uno, asigna a alguien y calcula el período.

---

## 7. Archivos

### Nuevos (7)

```
lib/attendance_audit.php              Historial de cambios del ponche (original vs modificado)
lib/overtime_reports.php              Los dos reportes de horas extra
lib/salary_history.php                Registro y consulta de cambios salariales y pagos
lib/delivery_restaurants.php          Reparto de costo por restaurante
hr/delivery_restaurants.php           Página de gestión de Delivery
cron_weekly_overtime_report.php       Reporte semanal de horas extra
cron_daily_over8h_report.php          Reporte diario de jornadas > 8 h
run_payroll_module_migration.php      Instalador idempotente (MySQL 5.7)
```

### Modificados (8)

```
edit_record.php                   Auditoría al editar un registro
supervisor_update_punch_api.php   Auditoría al editar punch desde el monitor
supervisor_create_punch_api.php   Auditoría al agregar punch manual
delete_record.php                 Auditoría al eliminar
hr/payroll_hours.php              Vista del historial del ponche junto a la de Vicidial
hr/employee_profile.php           Historial de pagos, cambios salariales y restaurantes
settings.php                      Registro de cambios salariales en la edición masiva
cron_daily_reports.php            Despacha los dos reportes nuevos
header.php                        Acceso a Delivery — Restaurantes
```

> Los archivos van al **servidor de oficina y a HostGator**; la migración se corre
> **una sola vez** (la base es la misma).

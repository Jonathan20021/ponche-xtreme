# Cambio de salario con FECHA EFECTIVA (prorrateo por quincena)

## El problema

Cuando a un colaborador le cambiaban la campaña a mitad de quincena y con eso el
salario, la nómina calculaba **toda** la quincena con el salario nuevo. Los días
que ya había trabajado con el salario viejo se repagaban a la tarifa nueva.

`users` guarda **una sola** compensación (la vigente), así que la nómina no tenía
forma de saber qué cobraba la persona el día 3 si el día 9 le cambiaron el sueldo.

## La solución

Cada cambio de compensación se registra en `employee_compensation_changes` con la
**fecha desde la cual aplica**, guardando la foto del salario **antes** (`prev_*`)
y **después** (`new_*`). La nómina parte el período en tramos y paga cada tramo
con su propia tarifa.

Ejemplo real (quincena 1–15, cambio de RD$100/h a RD$150/h desde el día 9, 80 h):

| Antes | Ahora |
|---|---|
| 80 h × RD$150 = **RD$12,000** | 40 h × RD$100 + 40 h × RD$150 = **RD$10,000** |

Aplica a los tres tipos de compensación:

- **Por hora**: horas de cada tramo × tarifa del tramo (extras incluidas, con su multiplicador).
- **Fijo (mensual)**: se reparte por **días calendario** de cada tramo dentro del período.
- **Diario**: días trabajados de cada tramo × sueldo diario del tramo.

## Dónde se elige la fecha

Tres puntos, todos desde la UI:

1. **Perfil del colaborador → Asignar campaña** — casilla *"Esta campaña cambia el
   salario"*, con la fecha desde la que aplica (por defecto, la de inicio de la
   campaña) y los montos nuevos. Campaña y salario en un solo paso.
2. **Perfil del colaborador → Compensación → Cambiar salario** — cambio suelto,
   con fecha efectiva y motivo. El panel muestra la línea de tiempo completa y los
   cambios programados (que se pueden anular mientras no entren en vigencia).
3. **Ajustes → Gestionar usuarios** — un campo *"Los cambios de salario aplican
   desde"* para todo el guardado, y **hr/employees.php** (editar ficha) tiene el
   suyo propio con motivo.

### Fechas futuras y pasadas

- **Futura**: el cambio queda **programado**. `users` NO se toca: hasta ese día se
  sigue pagando y mostrando el salario actual. La nómina de un período que cruce
  esa fecha ya paga los días correctos.
- **Hoy o pasada**: se aplica en el acto a `users` y la quincena queda prorrateada
  la próxima vez que se genere.

## Reglas de lectura

Para una fecha `d`:

1. Si existe un cambio con `effective_date > d`, manda el `prev_*` del **primero**
   de ellos.
2. Si no hay ninguno posterior, manda **`users`** (lo vigente) — salvo que el
   último cambio ya venciera y todavía no se haya volcado, en cuyo caso manda su
   `new_*`.

Con esa regla `users` sigue siendo la fuente de la verdad para "hoy": una edición
de sueldo hecha por fuera de este flujo **no se pierde ni se ignora**. Sin filas
registradas, la nómina se comporta **exactamente** como antes.

## Qué se toca

| Archivo | Qué hace |
|---|---|
| `lib/compensation_history.php` | **Nuevo.** Registro, resolución por fecha y tramos. |
| `hr/payroll_functions.php` | `calculateEmployeePayroll()` paga por tramos; `resolvePayrollCompensationAmounts()` unifica la regla de tarifas. |
| `hr/payroll.php` | Arma las horas **día por día** y guarda el desglose; badge "N salarios" en la revisión. |
| `hr/employee_profile.php` / `_actions.php` | Panel de Compensación, modal de cambio, salario dentro de "Asignar campaña". |
| `hr/employees.php` | Campo *"El salario aplica desde"* + motivo en la ficha. |
| `settings.php` | Fecha efectiva para la edición masiva de usuarios. |
| `hr/payroll_export_excel.php` | Avisa cuando el período tuvo más de un salario. |
| `cron_apply_salary_changes.php` | **Nuevo.** Vuelca a `users` los cambios vencidos. |
| `run_vicidial_sync.bat` | Registra la tarea `PoncheXtreme-SalaryChanges` (12:05 AM). |

## Instalación

```bash
php run_compensation_history_migration.php     # crea la tabla + la columna
run_vicidial_sync.bat install                  # registra la tarea (12:05 AM)
```

La tabla y la columna también se crean **solas** la primera vez que se abre la
nómina o el módulo de empleados (patrón `ensure*`), así que un deploy a otra base
no rompe nada aunque se olvide la migración. El cron tampoco es imprescindible: la
nómina, el listado de empleados y el perfil llaman a
`applyDueCompensationChanges()` al cargar.

## Verificación

```bash
php tests/compensation_proration_test.php      # 13 comprobaciones, sin dejar rastro
php cron_apply_salary_changes.php --status     # qué hay programado
```

## Ojo con esto

- **Regenerar una quincena vieja** ahora usa el salario que estaba vigente en esos
  días, no el de hoy. Es lo correcto, pero los montos pueden cambiar respecto a lo
  que se generó antes de esta función.
- **Horas corregidas a mano** ("Corregir Base"): se reparten sobre el calendario
  respetando la forma del ponche; si no hay marcaciones, parejo entre los días del
  período. Es la única forma de saber de qué lado del cambio caen.
- Un cambio **ya aplicado** no se puede anular (es historia y la nómina generada
  depende de él). Solo se anulan los **programados**.

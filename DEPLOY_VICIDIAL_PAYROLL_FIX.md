# Despliegue — Correcciones de nómina Vicidial (commits `7371f15` … `78efd0e`)

> **Urgente:** la base de datos ya está actualizada (es la compartida de HostGator), pero
> el Windows Server sigue corriendo el código viejo. Su tarea de las **23:30** vuelve a
> importar el día en curso y el anterior con el parser viejo, que **borra `SIN_CODIGO`**
> del desglose de pausas. Copiar los archivos antes de esa hora evita rehacer el backfill.

---

## 1. Ya hecho (base de datos compartida — no repetir)

- [x] Tablas `vicidial_payroll_adjustments` y `vicidial_payroll_adjustment_log` creadas.
- [x] Permiso `payroll_hours_adjust` para Admin, Desarrollador, DIRECTOR, HR, ENCARGADODEGESTIONHUMANA, IT.
- [x] `system_settings.vicidial_paid_pause_codes` = `["Coachi","ITRes","LAGGED","LOGIN","Digita","wasapi","SIN_CODIGO"]`.
- [x] `system_settings.vicidial_payroll_uncoded_cap_hours` = `2` (tope de pausa sin código pagada).
- [x] Backfill de `2026-07-01` a `2026-07-09` con el importador corregido.

## 2. Copiar archivos

A **`C:\xampp\htdocs\punch`** (Windows Server) **y** a HostGator, misma estructura.

**Modificados:**

```
lib\vicidial_api_client.php     <- el grueso de las correcciones
agent.php
agent_dashboard.php
records_vicidial.php
settings.php
hr\payroll.php                  <- botón "Ajuste de Horas"
```

**Nuevos:**

```
hr\payroll_hours.php
sql\create_vicidial_payroll_adjustments.sql   (referencia; ya corrida)
```

No hay cambios en `run_vicidial_sync.bat` ni en las tareas programadas.

## 3. Verificar (en el server, después de copiar)

```powershell
C:\xampp\php\php.exe -l C:\xampp\htdocs\punch\lib\vicidial_api_client.php
C:\xampp\php\php.exe -r "require 'C:\xampp\htdocs\punch\db.php'; require 'C:\xampp\htdocs\punch\lib\vicidial_api_client.php'; $r=$pdo->query(\"SELECT pause_breakdown FROM vicidial_agent_timesheet WHERE report_date='2026-07-08' AND vicidial_user='Sh_Cruzt'\")->fetchColumn(); echo $r;"
```

Debe aparecer `"SIN_CODIGO":15559`. Luego, en la app:

1. **Nómina → Ajuste de Horas** abre y lista los días de los agentes Vicidial.
2. Entrar como Marcela (rol HR) y confirmar que ve el botón.
3. Portal del agente de Elvis Rojas: la tarjeta de horas ya no muestra 0 en 07-07/07-08/07-09.

## 4. Pendiente de decisión humana (NO se hizo)

- **Regenerar la nómina del período #22** (1ra Quincena Julio, 2026-06-29 → 2026-07-13).
  Las horas corregidas solo entran al cálculo al regenerar. Hacerlo cuando cierre el
  período; regenerar **no** borra los ajustes manuales de `vicidial_payroll_adjustments`,
  pero sí sobrescribe incentivos manuales del período.
- **Quitar ENTRY / DISPONIBLE / EXIT del portal** (`settings.php` → tipos de marcación,
  `is_active = 0`). Ojo: agentes con `payroll_source = 'manual'` (p. ej. Joel Chala,
  usuario 24) dependen de esos botones para marcar. Pasarlos a `vicidial` primero.
- **Nombrar el código de pausa en blanco dentro de Vicidial.** Mientras siga sin nombre,
  no se distingue "el agente pausó sin elegir código" de "el agente eligió un código que
  no tiene nombre configurado".

## 5. Tope de pausa sin código (nuevo)

`SIN_CODIGO` se paga, pero solo hasta **2 h/día** (`settings.php` → Integración Vicidial).
Una sesión que el agente deja abierta acumula todo el tiempo inactivo ahí; sin el tope,
olvidar cerrar sesión valdría hasta 14 h. El tope aplica **solo** a la pausa sin código:
el tiempo productivo y los demás códigos pagados nunca se recortan. Un ajuste manual de
Gestión de Desempeño manda por encima del tope.

En la UI de Ajuste de Horas los días recortados salen con un icono de tijera.

## 6. Nota operativa importante

El `.bat` importa el **día en curso** a las 23:31, cuando todavía hay agentes logueados.
Esos números quedan **provisionales** hasta que la corrida de la noche siguiente vuelve a
importar ese día ya cerrado. Nunca generar nómina de un día antes de su segunda
importación. (Verificado: al reimportar un día cerrado, el resultado es idempotente.)

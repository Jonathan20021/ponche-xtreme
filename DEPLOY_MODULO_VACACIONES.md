# Despliegue — Vacaciones

Cubre los 4 puntos del documento del cliente. **Un paso de base de datos
obligatorio, en dos tiempos** (primero simula, después aplica).

---

## 0. Instalación

```bash
# 1. Instala columnas y ajustes, y MUESTRA qué cambiaría (no toca datos)
php run_vacations_module_migration.php

# 2. Revisa la salida y, si estás de acuerdo, aplica el recálculo
php run_vacations_module_migration.php --recalcular
```

Se hizo en dos pasos a propósito: el recálculo **modifica días ya registrados**,
y eso hay que verlo antes de aplicarlo.

---

## 1. Cálculo de los 14 días — corregido

### Causa raíz

El sistema contaba **días calendario**:

```php
$totalDays = $start->diff($end)->days + 1;   // ← contaba domingos y feriados
```

Así, dos semanas de vacaciones consumían 14 días aunque incluyeran 2 domingos
(que no se trabajan) y 2 sábados (media jornada de 4 horas). De ahí los saldos
negativos y los porcentajes de 129%, 107% y 143% de tu captura.

### Reglas que aplica ahora

| Día | Consume | Por qué |
|---|---|---|
| Lunes a viernes | **1 día** | jornada completa |
| Sábado | **0.5 día** | la jornada del sábado es de 4 horas |
| Domingo | **0** | no se trabaja |
| Feriado | **0** | aunque caiga entre semana |

Todo configurable desde `system_settings` (`vacation_saturday_value`,
`vacation_sunday_value`, `vacation_holiday_value`).

### El efecto

| Período | Calendario | Antes | Ahora |
|---|---|---|---|
| Lunes a viernes | 5 | 5 | **5.0** |
| Semana completa (lun-dom) | 7 | 7 | **5.5** |
| Dos semanas | 14 | 14 | **11.0** |
| Dos semanas con un feriado | 14 | 14 | **10.0** |
| Solo un domingo | 1 | 1 | **0.0** |

**Los 14 días ahora rinden ~3 semanas de descanso en vez de 2**, que es lo que
corresponde.

### Recálculo de lo ya registrado

4 de las 6 solicitudes existentes tenían días de más; se devolvieron **13 días**
en total. Los saldos negativos bajaron de 3 a 2.

| Colaborador | Antes | Ahora |
|---|---|---|
| Alan Yardiel Sanchez | 20.0 | 15.5 |
| Yerlin Del Carmen Santos | 18.0 | 14.0 |
| Yerlin Del Carmen Santos | 15.0 | 12.0 |
| Emely Maria Rodriguez | 4.0 | 2.5 |

> **Los 2 saldos que siguen negativos son excesos reales**, no errores de
> cálculo: Alan tomó 15.5 días de sus 14 y Francheska 15 de 14. Eso es
> información que RRHH necesita ver, no algo que el sistema deba esconder.

### Dato que hay que corregir a mano

La solicitud **#7 de Stephany Esther De Los Santos** tiene la fecha final
**anterior** a la inicial (01-jul a 18-jun) — un error de captura. El instalador
**no la tocó a propósito**: recalcularla daría 0 días y le quitaría 14 días de
vacaciones sin que nadie se entere. Hay que corregir las fechas desde el módulo
y volver a aprobarla.

### Además

- El balance ya no se calcula sumando a un total de 14 quemado: se **reconstruye**
  desde las solicitudes aprobadas y la antigüedad real (14 días al año, 18 desde
  el quinto — art. 177 del Código de Trabajo). Una corrección posterior se refleja sola.
- Se corrigió una división por cero que rompía la página cuando alguien tenía 0
  días asignados (quien aún no cumple el año).
- Cada solicitud guarda ahora su desglose (`9 completos, 2 medios, 2 domingos,
  1 feriado`), visible en el listado, para poder auditar el cálculo.

> ⚠️ **Solo hay 2 feriados cargados** en `payroll_holidays` (Día del Trabajador y
> Corpus Christi). El cálculo solo puede excluir los que estén registrados. Faltan
> los ~10 feriados nacionales restantes. **No los cargué automáticamente** porque
> esa misma tabla la usa la nómina para el pago doble de feriados: agregarlos
> cambiaría lo que se paga esos días. Es una decisión tuya, no un descuido.

---

## 2. Comprobante de pago

Al aprobar una solicitud, el formulario ahora acepta:

- **Monto pagado** y **fecha de pago**
- **Constancia o comprobante** (PDF o imagen, hasta 10 MB)
- **Referencia** (número de transferencia, cheque…)

El bloque solo aparece al **aprobar** — al rechazar no hay nada que pagar. Si no
se sube en ese momento, se puede cargar después sin volver a revisar la solicitud
(los campos usan `COALESCE`, así que una revisión posterior sin comprobante no
borra el que ya estaba).

En el listado, cada solicitud muestra el monto y un botón **Ver comprobante**; las
aprobadas sin comprobante salen marcadas en ámbar con **"Sin comprobante de pago"**.

---

## 3. Aviso al cumplir el año de antigüedad

Al cumplir el año nace el derecho a las vacaciones, así que RRHH necesita saberlo
**antes** para coordinar las fechas y no acumular a todo el mundo en el mismo mes.

El aviso llega a la campana **30 días antes** (configurable) e incluye la fecha de
ingreso, el aniversario, los días que le corresponderán y el departamento. Un
aviso por colaborador y aniversario: correrlo a diario no duplica.

Lo dispara `cron_employee_notices.php`, la tarea de RRHH que ya está registrada
(**no hace falta una tarea nueva**). Probado: detectó 3 colaboradores que cumplen
el año el 13 de agosto.

---

## 4. Calendario de vacaciones

**RRHH → Calendario de Vacaciones** (`hr/vacation_calendar.php`), con el mismo
formato del calendario de cumpleaños: selector de mes con el conteo y tarjetas por
colaborador.

Distingue dos cosas, que es lo que permite planificar:

- **Solicitadas** (verde) — fechas reales ya pedidas o aprobadas.
- **Previstas por aniversario** (ámbar) — quienes aún no han solicitado; se
  ubican en el mes de su aniversario de ingreso, que es cuando les corresponde el
  disfrute. Cada tarjeta tiene un botón **Programar**.

Datos reales al instalarlo: 3 solicitadas y **18 previstas** repartidas en 8 meses
— justo la carga que no se veía antes.

Abajo, un resumen del año y la tabla de **próximos a cumplir el año**, con los días
que faltan.

---

## 5. Verificación

```bash
php run_vacations_module_migration.php          # simulación, no toca nada
php cron_employee_notices.php                   # incluye los aniversarios
php tests/work_hours_calculator_test.php        # -> All tests passed.
```

En la interfaz:
1. **Vacaciones** → crea una solicitud de lunes a domingo: debe decir 5.5 días, no 7.
2. Apruébala y adjunta el comprobante → aparece el botón para verlo.
3. **Calendario de Vacaciones** → navega por los meses.
4. Corrige las fechas de la solicitud #7 de Stephany.

---

## 6. Archivos

### Nuevos (3)

```
lib/vacation_calculator.php            Cálculo de días, derecho por antigüedad, calendario
hr/vacation_calendar.php               Calendario de vacaciones
run_vacations_module_migration.php     Instalador + recálculo (con simulación previa)
```

### Modificados (5)

```
hr/vacations.php                  Cálculo correcto, balance reconstruido, comprobante de pago
agents/my_requests.php            Mismo cálculo en el portal del agente
lib/employee_notifications.php    Aviso de aniversario
cron_employee_notices.php         Dispara el aviso de aniversario
header.php                        Acceso al Calendario de Vacaciones
```

> Los archivos van al **servidor de oficina y a HostGator**; la migración se corre
> **una sola vez**.

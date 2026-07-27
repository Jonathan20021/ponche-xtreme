# Calculadora de Prestaciones Laborales

Preaviso, auxilio de cesantía, vacaciones y salario de Navidad (regalía pascual)
conforme al Código de Trabajo de la República Dominicana (Ley 16-92).

**Acceso:** Recursos Humanos → Prestaciones Laborales
(`hr/labor_benefits_calculator.php`), o la tarjeta del Panel RH.

---

## Por qué da lo mismo que el Ministerio de Trabajo

El motor (`lib/labor_benefits_calculator.php`) **no** es una interpretación
propia de la ley: es un puerto línea por línea del algoritmo que corre
<https://calculo.mt.gob.do> (la clase `Calculator` de su `js/site.min.js`).

Se replicaron incluso las reglas que parecen rarezas, porque el objetivo es que
ambos den el mismo número **al centavo**:

| | Regla del MT que se copió tal cual |
|---|---|
| 1 | La fecha de salida se corre **un día** antes de medir el tiempo, para que el último día laborado cuente. |
| 2 | La diferencia entre fechas usa su propia aritmética (`lbDifFechas`), no `DateTime::diff`. En meses de 31 días puede diferir en un día; manda la del MT. |
| 3 | Febrero es bisiesto con `año % 4 == 0`, sin la regla de los siglos. |
| 4 | El divisor quincenal ordinario es **11.91**, no `23.83 / 2` (= 11.915). |
| 5 | Los importes se redondean con la semántica de `Math.round(x*100)/100` de JavaScript, no con `round()` de PHP (difieren en RD$0.01 sobre el medio centavo). Ver `lbRound2()` y `lbFixed2()`. **Y `Math.round` no es `floor(x + 0.5)`**: con `x*100 = 0.49999999999999994`, sumarle `0.5` da exactamente `1.0` y el atajo devuelve 1 donde JavaScript devuelve 0. Por eso `lbRound2` separa parte entera y fraccionaria antes de decidir. |
| 6 | Las vacaciones **no** se pagan al promedio: se pagan al salario del último período registrado. |
| 7 | Un mes sin salario base no suma, aunque tenga comisión. |

**No tocar estas reglas "para arreglarlas".** Cada una está ahí porque sin ella
el resultado se separa del oficial, y el oficial es el que vale ante el
Ministerio.

### Verificación

`tests/labor_benefits_calculator_test.php` compara el motor contra 300 casos
dorados (bordes del Código + aleatorios) cuya respuesta se obtuvo **ejecutando
el propio JavaScript del MT**, más 8 casos de redondeo puro.

```bash
php tests/labor_benefits_calculator_test.php
```

Cobertura acumulada durante el desarrollo, toda contra el JS oficial:

| Barrido | Casos | Diferencias |
|---|---|---|
| Aleatorio (5 semillas × 6,208) | 31,040 | 0 |
| Aleatorio, tras corregir el redondeo (3 semillas) | 18,624 | 0 |
| Adversarial (6 semillas × 2,949) | 17,694 | 0 |

El barrido adversarial ataca lo que el aleatorio no tocaba: fechas de 1950 a
2050, años seculares (1900 y 2100), 29 de febrero, las 16 combinaciones de
interruptores × 4 períodos × 3 tipos, salarios de RD$0.01 y de RD$9,999,999.99,
meses solo con comisión, y **dos barridos día a día** (cada día de salida de un
año, y cada antigüedad de 0 a 6 años) que recorren todos los escalones de
preaviso, cesantía y vacaciones sin dejar hueco.

> Ese barrido destapó un fallo real de redondeo (ver la fila 5 de la tabla de
> arriba). Está corregido y con test propio. **La lección: los muestreos
> aleatorios no bastan; los bordes hay que atacarlos a propósito.**

---

## Escalas legales aplicadas

| Concepto | Regla | Artículo |
|---|---|---|
| Preaviso | 3–6 meses: 7 días · 6–12 meses: 14 días · desde 1 año: 28 días | 76 y 79 |
| Cesantía | 1–5 años: 21 días por año · desde 5 años: 23 días por año | 80 |
| Cesantía (fracción) | fracción de 3–6 meses: 6 días · fracción mayor de 6 meses: 13 días | 81 |
| Cesantía anterior a 1992 | 15 días por año trabajado antes del 17/06/1992 | — |
| Vacaciones | 1–5 años: 14 días · desde 5 años: 18 días | 177 |
| Vacaciones (fracción) | 5 meses: 6 días, y un día más por cada mes hasta 11 meses: 12 días | 177 párrafo |
| Salario de Navidad | 1/12 del salario ordinario devengado en el año calendario | 219 |
| Salario diario | salario mensual ÷ 23.83 (ordinario) o ÷ 26 (intermitente y doméstico) | Reglamento 258-93 |

Todas son **editables** desde Nómina → Configuración → *Escalas de Prestaciones
Laborales*, para no tener que tocar código si la ley cambia.

### Lo que la calculadora NO incluye

Igual que la del Ministerio, deja fuera:

- **Participación en los beneficios** (art. 223): depende de la utilidad neta
  del año, no del salario.
- **Salarios caídos** del art. 95 ordinal 3 en despido injustificado o dimisión
  justificada: dependen de la duración del proceso.

---

## Cómo se usa

0. Elige el **motivo de la terminación**. Es lo que decide si hay preaviso y
   cesantía, y evita tener que recordarlo de memoria:

   | Motivo | Preaviso | Cesantía |
   |---|---|---|
   | Desahucio del empleador · Despido injustificado · Dimisión justificada | sí | sí |
   | Renuncia · Despido justificado · Dimisión injustificada · Fin de contrato | no | no |

   Todos conservan vacaciones y salario de Navidad. Si mueves un interruptor a
   mano, el motivo pasa a *Personalizado*.

1. Elige un colaborador **escribiendo en el buscador** (filtra en vivo por nombre
   y por código, sin distinguir tildes: «hernandez» encuentra «Hernández»), o
   escribe los datos a mano. Trae nombre, cédula, fechas y salario.

   > El buscador es un combo propio en JavaScript sin librerías. El `<select>`
   > original sigue en el DOM y es quien manda: si el JavaScript fallara, el
   > desplegable nativo queda visible y la página sigue siendo usable.
2. Confirma las fechas y el salario. Si el salario fue el mismo todos los meses,
   escríbelo en *Salario del período* y pulsa **Aplicar a todos los meses**; si
   varió, llena la tabla mes por mes (el período 12 es el último antes de salir).
3. Ajusta los cuatro interruptores:
   - **¿Ha sido pre-avisado?** — si sí, no se paga la indemnización sustitutiva.
   - **¿Incluir cesantía?** — se apaga cuando la salida no la genera (renuncia,
     despido justificado).
   - **¿Incluir salario de Navidad?**
   - **¿Tomó las vacaciones del último año?** — si sí, solo se paga la fracción
     corrida desde el aniversario.
4. El resultado se recalcula solo. **Descargar PDF** saca el documento formal;
   **Guardar** lo deja en el historial con un número de referencia.

---

## El PDF de liquidación

`hr/labor_benefits_pdf.php`, con **dompdf 2.0** (ya estaba en `composer.json`).

Contiene: encabezado con el logo y los datos de la empresa, datos del
colaborador, base de cálculo (promedios y divisor aplicado), la tabla de los
períodos de salario, el desglose concepto por concepto con su **base legal** y
días × salario diario, subtotal, total destacado, el **importe en letras** y las
dos líneas de firma. Encabezado y pie se repiten en cada página, con
"Página X de Y".

Se genera de dos maneras:

- **Desde la pantalla** (POST): sale marcado `PRELIMINAR`.
- **Desde el historial** (`?id=N`): sale con su referencia `LIQ-AAAA-NNNNN`.

Añade `&descargar=1` para forzar la descarga en vez de abrirlo en el navegador.

Los datos de la empresa (razón social, RNC, dirección, firmante y su cargo) se
editan en **Nómina → Configuración**. El logo sale de `assets/logo.png`.

### Detalles de implementación que conviene no deshacer

| | |
|---|---|
| El logo se **reescala a 260 px** antes de incrustarlo. El original son 1600×1600 y en base64 pesa 414 KB **dentro de cada PDF**; reducido son 21 KB. Se cachea en `cache/logo_pdf_260.txt` (ignorado por git, se regenera solo). |
| `imagealphablending(false)` + `imagesavealpha(true)` al reescalar: sin eso el logo transparente sale con fondo negro. |
| La numeración de páginas se estampa con `page_text()` **después** de `render()`. Con `counter(pages)` en CSS dompdf imprime "de 0", porque mientras maqueta todavía no sabe cuántas páginas habrá. |
| `page_text()` recibe la fuente **DejaVu Sans** explícitamente; con la del núcleo, "Página" pierde la tilde. |
| Los bloques que no deben partirse llevan `.bloque { page-break-inside: avoid }`, si no el título de una sección se queda solo al pie de una página. |
| `isRemoteEnabled` va en **false** a propósito: todo está incrustado, así el PDF no depende de que el servidor salga a internet. |

Si el cálculo tiene períodos en blanco, el PDF lleva **el mismo aviso amarillo**
que la pantalla. Un documento que se va a firmar no puede ocultar que está
calculado por debajo.

### De dónde sale el salario del colaborador

**La pantalla solo se rellena sola cuando hay un salario FIJO** (`monthly_salary_dop`,
o `daily_salary_dop × 23.83`). A quien cobra por hora **no se le inventa un salario
mensual**, y esto es a propósito:

> Se probó estimarlo como `tarifa × 190.67 h` (44 h semanales). Contrastado contra
> la nómina real de mayo y junio de 2026, ese estimado se desvía **118% en promedio**
> y hasta **945%** en el peor caso, porque los agentes trabajan jornadas variables.
> Liquidar con esa cifra sería inflar las prestaciones de forma grosera.

En su lugar, al elegir un colaborador aparece el panel **“Devengado real según la
nómina”** con lo que devengó mes a mes, de dos fuentes:

1. **Nómina** (`payroll_records`), repartida a prorrata de días entre los meses
   que toca cada quincena.
2. **Ponche**, cuando la nómina no cubre los días trabajados de ese mes: tarifa ×
   horas marcadas, calculadas con `computePeriodHoursForUser()` — la **misma**
   función con la que se paga la nómina, así que respeta el corte semanal de 44 h,
   el recargo configurado y el doble de los feriados.

Una sola fuente por mes, para que ningún día se cuente dos veces. Cada mes se
marca **Completo · nómina**, **Completo · ponche**, **Parcial**, **Sin datos** o
**No trabajó ese mes**.

**La rejilla se llena sola** al elegir al colaborador — con el sueldo fijo si lo
tiene, y si no con el devengado real. El botón **Usar estos montos** solo hace
falta para volver a aplicarlo después de editar a mano.

### Cuando no se puede llenar solo

De los 63 colaboradores con salida registrada, **55 liquidan automáticamente**.
Los 8 restantes no son un fallo del cálculo sino datos que faltan en el sistema,
y la pantalla lo dice en ámbar con qué hay que corregir y dónde:

| Situación | Qué se muestra |
|---|---|
| Sin tarifa ni sueldo en la ficha (4 casos) | «No tiene tarifa por hora ni sueldo mensual… Cárgale la tarifa en Empleados» |
| Sin ningún marcaje en su período (2 casos) | «No tiene ningún marcaje entre el X y el Y, ni nómina generada» |
| Solo marcajes ENTRY/EXIT (1 caso) | «Tiene N marcajes, pero ninguno de un tipo pagado, así que la nómina también le pagaría cero» |
| Fechas corruptas, p. ej. salida `0001-01-01` (1 caso) | «Sus fechas están mal en la ficha… Corrígelas en Empleados» |

Antes todos ellos mostraban «Debes registrar al menos un salario mayor que cero»,
que parece un fallo del sistema. El motivo lo arma `lbDiagnosticoSinDatos()`.

### La cobertura se mide contra los días EMPLEADOS

Este fue un fallo real: se exigía que la nómina cubriera los **30 días del mes**
para dar un mes por completo. Quien entró el 3 de junio y salió el 16 no puede
cumplir eso nunca, así que salía siempre “Parcial”. Como casi todos los
colaboradores dados de baja cobran por hora, **43 de 63 abrían el formulario en
blanco**. Con la cobertura medida contra los días de empleo (y el respaldo del
ponche), bajaron a 5.

Los 5 restantes son datos incompletos del sistema, no del cálculo: tres no tienen
ninguna tarifa configurada, uno tiene la fecha de salida corrupta (`0001-01-01`)
y otro tiene marcajes pero ninguno de un tipo pagado (la nómina también le
pagaría 0). Para todos ellos el salario se escribe a mano.

### Ojo con el alineado de las casillas

La rejilla usa las casillas `0..mesesActivos-1`, y **la última ACTIVA es el mes de
salida** — no la casilla 12. Quien trabajó siete meses usa las casillas 1 a 7 y la
7 es su último mes. `laborBenefitsPayrollMonths()` ya devuelve los meses
desplazados a la casilla que les toca (`lbDesplazamientoMeses()`); si se toca eso,
los importes acaban en filas equivocadas para todo el que haya trabajado menos de
doce meses.

> **Rendimiento:** recorrer el ponche de un mes cuesta ~350 ms contra la base
> remota. Se consulta primero qué meses tienen algún marcaje para saltarse los
> vacíos, y aun así un caso de doce meses tarda ~2,5 s. Por eso la pantalla
> muestra “Buscando lo devengado…” y bloquea el botón hasta que llegan los datos.

### El aviso de meses en blanco

Si quedan períodos vacíos, la pantalla lo avisa en amarillo. **No es cosmético:**
el promedio se divide entre los meses que duró la relación (12 si hay un año o
más), **estén llenos o no**. Ocho meses en blanco de doce dan un promedio tres
veces menor y una liquidación tres veces menor. Es el mismo comportamiento del MT,
pero aquí se canta para que nadie liquide de menos sin darse cuenta.

---

## Archivos

| Archivo | Qué hace |
|---|---|
| `lib/labor_benefits_calculator.php` | Motor de cálculo, datos de la empresa, logo e importe en letras. |
| `hr/labor_benefits_calculator.php` | Página + endpoints AJAX (`calcular`, `empleado`, `guardar`). |
| `hr/labor_benefits_pdf.php` | Documento de liquidación en PDF (dompdf). |
| `hr/payroll_settings.php` | Cards de datos de la empresa y escalas legales editables. |
| `header.php`, `hr/index.php` | Accesos al módulo. |
| `run_labor_benefits_migration.php` | Instalador idempotente. |
| `tests/labor_benefits_calculator_test.php` | Test de paridad con el MT. |
| `tests/fixtures_labor_benefits_mt.json` | Los 300 casos dorados. |

La página calcula por **AJAX contra el motor de PHP**: no hay una segunda copia
de las fórmulas en JavaScript que se pueda desincronizar.

---

## Despliegue

Subir a **los dos servidores** (Windows Server de la oficina y HostGator):

```
lib/labor_benefits_calculator.php
hr/labor_benefits_calculator.php
hr/labor_benefits_pdf.php
hr/payroll_settings.php
hr/index.php
header.php
.gitignore
run_labor_benefits_migration.php
tests/labor_benefits_calculator_test.php
tests/fixtures_labor_benefits_mt.json
CALCULADORA_PRESTACIONES_LABORALES.md
```

El PDF necesita **dompdf** y la extensión **GD**; ambos ya están en el proyecto
(`vendor/dompdf`). El instalador comprueba las dos cosas al final.

La migración se corre **una sola vez** (la base de datos es la misma para ambos):

```bash
php run_labor_benefits_migration.php
```

Instala 15 ajustes en `system_settings`, la sección de permisos
`hr_labor_benefits` (Admin, Desarrollador, DIRECTOR, ENCARGADODEGESTIONHUMANA,
HR, IT) y la tabla `labor_benefit_calculations`. Es idempotente: lo que ya
existe se salta.

> Ya fue ejecutada el 27/07/2026 contra la base de producción.
> La página tiene un respaldo: si la sección `hr_labor_benefits` no estuviera
> configurada, cae al permiso de Nómina (`hr_payroll`) para no dejar a nadie fuera.

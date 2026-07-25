# Despliegue — Ajustes del módulo de Empleados

Cubre los 17 puntos del documento del cliente. **Hay un paso de base de datos
obligatorio y una tarea programada nueva.**

---

## 0. Instalación (en orden)

1. Subir los archivos (lista al final).
2. Correr **una vez**: `php run_employee_module_migration.php`
   (o abrirlo en el navegador). Es idempotente.
3. Reinstalar tareas: doble clic en `run_vicidial_sync.bat` → `install`.
   Ahora registra **7** tareas (antes 6); la nueva es `PoncheXtreme-EmployeeNotices`.
4. Revisar `settings.php` → pestaña **Notificaciones (Campana)**.

---

## 1. Correcciones del perfil

### Tarifa por hora en RD$0.00

El perfil pedía `u.hourly_rate` (la tarifa en **USD**), y como el personal cobra
en pesos ese campo está en 0 → todos aparecían con **RD$0.00**.

Ahora se lee la moneda real de la persona y su forma de pago:

| Antes | Ahora |
|---|---|
| `$0.00` para todos | **RD$250.00/hora** (Jessica) |
| — | **RD$28,000.00/mes** para quien cobra sueldo fijo |

A quien cobra mensual se le muestra su **sueldo mensual**, no una tarifa horaria
(coherente con el ajuste de los reportes de la tanda anterior). También aparece
de dónde salen sus horas: *ponche* o *Vicidial*.

### Tiempo laborando

De `143 días` a **`4 meses, 21 días`** (se conserva el total de días como dato
secundario). Si el colaborador ya salió, se calcula hasta su fecha de salida.

---

## 2. Historial de asistencia en el perfil

Sección nueva con **ausencias, tardanzas y permisos**, con selector de período
(30/60/90 días, 6 meses, 1 año) y un resumen: días programados, trabajados,
ausencias, ausencias justificadas y tardanzas con minutos acumulados.

**Importante — no se inventó un criterio nuevo.** La ausencia y la tardanza se
calculan igual que en los reportes diarios que ya usa la empresa. Se verificó
comparando fecha por fecha contra `generateDailyTardinessReport()`: coinciden
exactamente, incluidos los colaboradores ya terminados.

Durante esa verificación aparecieron **dos errores propios que se corrigieron**:

1. **Presencia por Vicidial.** Sin ella, Jessica (agente del discador) mostraba
   43 ausencias falsas; en realidad estaba presente y logueada. Se usa la misma
   fuente del reporte (`vicidial_agent_timesheet`).
2. **Hora de llegada.** Se tomaba el primer punch del día; si el único punch era
   un `EXIT` a las 16:00, inventaba una tardanza de 8 horas. Ahora la llegada es
   el primer **ENTRY**, como en `buildArrivalsForRange()`.

> **Nota de calidad de datos:** la tardanza se mide contra el horario del
> colaborador y, si no tiene uno vigente para esa fecha, contra el **horario
> global** — que es exactamente lo que hace el reporte oficial. En colaboradores
> con horarios viejos desactivados esto infla las tardanzas. No lo cambié porque
> haría que el perfil contradijera al reporte; se resuelve cargándoles el horario
> correcto.

---

## 3. Amonestaciones y licencias médicas

Dos secciones nuevas en el perfil, **con registro directo desde ahí** (modal), sin
ir a otro módulo.

- **Amonestaciones** (tabla nueva `employee_warnings`): tipo (verbal, escrita,
  suspensión, última), gravedad, asunto, descripción, medida correctiva, días de
  suspensión y documento de respaldo. Contador de activas en el encabezado.
- **Licencias médicas** (usa la tabla `medical_leaves` que ya existía sin uso):
  tipo, diagnóstico, fechas, médico, centro, certificado y con/sin goce.
  Al registrarse, **las ausencias de ese período quedan justificadas** en el
  historial y en el reporte diario.

---

## 4. Campañas múltiples e historial

Tabla nueva `employee_campaigns`. Un colaborador puede estar en **varias campañas
a la vez**, una marcada como principal.

- Se migraron las 91 asignaciones existentes como historial inicial.
- `employees.campaign_id` sigue siendo la **campaña principal**: los monitores, la
  nómina y los reportes leen esa columna y no cambian de comportamiento.
- El perfil muestra las campañas vigentes como etiquetas y el historial completo
  con fechas de inicio y fin y quién asignó.
- Al finalizar la relación laboral se cierran automáticamente.

---

## 5. Terminación con motivo y recontratación

Botón **Finalizar relación** en el perfil:

- **Motivo**: Desahucio, Despido, Abandono, Renuncia, Fin de contrato, Mutuo acuerdo.
- **Recontratación**: Elegible / Requiere evaluación previa / No debe ser considerado.
- Notas libres para ambos.

Al guardar: estado a `TERMINATED`, se cierran sus campañas vigentes y **se
desactiva su usuario** (si no, seguiría contando como activo en los monitores).
El motivo y la elegibilidad quedan visibles en el perfil y en el expediente.

---

## 6. Cálculo de horas al crear el empleado

`users.payroll_source` **ya existía** y la nómina ya lo usaba (127 manual / 22
vicidial), pero solo se podía cambiar desde la pestaña Nómina de
`vicidial_sync.php`. Ahora está en el formulario de alta:

> **Cálculo de horas**: Sistema de ponche (marcaciones) · Vicidial (tiempo logueado)

Sin esto, un agente nuevo del discador quedaba en "ponche" y sus horas salían del
ponche manual hasta que alguien lo notara.

---

## 7. Documentación requerida y firma electrónica

### Checklist de los 11 documentos

Tabla `required_document_types` con los 11 que pidió el cliente, **configurable**
(se pueden agregar, renombrar o marcar cuáles requieren firma).

El expediente **no arranca vacío**: cada documento tiene alias y patrones de
nombre que reconocen los 631 documentos ya cargados.

> Esto resolvió un problema real detectado en la prueba: RRHH archiva bajo tipos
> genéricos ("Otros Documentos", "Política de Empresa") con el nombre real en el
> archivo. Sin el reconocimiento por nombre, Jessica salía **6/11 (55%)** cuando
> en realidad tiene **9/11 (82%)** — y eso disparaba avisos falsos de expediente
> incompleto.

### Firma electrónica

1. En el perfil, botón **Solicitar firma** junto a cada documento que la requiera.
2. Se genera un **enlace único** (token de 48 caracteres, un solo uso, vence a los
   30 días) que se copia y se le envía al colaborador.
3. El colaborador abre `firmar_documento.php?t=...` desde el teléfono, lee la
   declaración, **dibuja su firma con el dedo** y confirma con su cédula (se
   valida contra la del expediente).
4. El sistema genera el **PDF firmado** con la firma, la cédula, fecha/hora, IP y
   una huella SHA-256 de evidencia, y lo **archiva solo en el expediente**.
   RRHH no tiene que hacer nada.

Probado de punta a punta: firma registrada → PDF de 21 KB generado → archivado
con su `doc_key` correspondiente.

---

## 8. Notificaciones automáticas (las 5)

Todas van a la campana ya instalada. Configurables en `settings.php`.

| Aviso | Cuándo | Cómo evita duplicados |
|---|---|---|
| **Período de prueba** | 10 días antes de cumplir 90 (ambos configurables) | uno por colaborador y fecha de fin |
| **Cumpleaños del mes** | día 1 de cada mes, con el listado | uno por mes |
| **Permiso registrado** | en el momento de crearse | uno por permiso |
| **Expedientes incompletos** | barrido diario, resumen agrupado | uno por semana |
| **Chat interno** | en vivo, al consultar la campana | no se duplica: se lee del chat |

El aviso de **chat** no copia las 5,773 filas de `chat_notifications` a la campana
(sería inservible y habría que mantener dos estados de "leído" en sincronía): se
lee en vivo y se muestra como **una** entrada agregada que enlaza al chat.

El de **expedientes incompletos** va en un resumen: con 54 incompletos, un aviso
por persona ahogaría la campana.

El de **permisos** se enganchó en los **cuatro** puntos donde se crean permisos
(portal del agente ×3 y RRHH), no solo en uno.

---

## 9. Exportación del expediente

Botón **Expediente** en el perfil → `employee_record_export.php?id=X&formato=excel|pdf`

Incluye: información general, historial laboral, compensación y datos bancarios,
resumen y detalle de asistencia, permisos, vacaciones, amonestaciones, licencias
médicas, historial de campañas, estado de la documentación obligatoria y la lista
de documentos del expediente. El PDF sale por Dompdf (ya estaba en el vendor).

---

## 10. Verificación

```bash
# Avisos de RRHH (crea las notificaciones en la campana)
php cron_employee_notices.php
php cron_employee_notices.php --force-birthdays   # probar cumpleaños fuera del día 1

# La nómina y los cálculos de horas siguen intactos
php tests/work_hours_calculator_test.php          # -> All tests passed.
```

En la interfaz:
1. Perfil de un agente: la tarifa ya **no** dice RD$0.00 y el tiempo sale en meses/días.
2. Historial de Asistencia con su resumen y el detalle por día.
3. Registrar una amonestación y una licencia desde el perfil.
4. Asignar una segunda campaña y ver el historial.
5. Solicitar firma → abrir el enlace → firmar → el PDF aparece en sus documentos.
6. Botón **Expediente** → Excel y PDF.

---

## 11. Archivos

### Nuevos (7)

```
lib/employee_record.php               Antigüedad, asistencia, campañas, amonestaciones, documentos
lib/employee_notifications.php        Los 4 avisos de RRHH
hr/employee_profile_actions.php       Acciones del perfil (amonestar, licencia, campaña, firma, salida)
hr/employee_record_export.php         Expediente completo en Excel y PDF
firmar_documento.php                  Página pública de firma electrónica
cron_employee_notices.php             Tarea de avisos de RRHH
run_employee_module_migration.php     Instalador idempotente (MySQL 5.7)
```

### Modificados (8)

```
hr/employee_profile.php     Tarifa, antigüedad, asistencia, amonestaciones, licencias,
                            campañas, documentación, modales y terminación
hr/new_employee.php         Selector de cálculo de horas (ponche/Vicidial)
hr/permissions.php          Aviso al registrar permiso
agents/my_requests.php      idem
agents/request_permission.php idem
agent_dashboard.php         idem
lib/notifications.php       Chat interno en la campana
api/notifications.php       idem
run_vicidial_sync.bat       Tarea PoncheXtreme-EmployeeNotices (7:30 AM)
```

> Recuerda: los archivos van al **servidor de oficina y a HostGator**; la
> migración se corre **una sola vez** (la base es la misma).

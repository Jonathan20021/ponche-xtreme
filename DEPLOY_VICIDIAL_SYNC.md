# Despliegue — Sincronización Vicidial (Fase 1) en el Windows Server de Evallish

App de producción local: **`C:\xampp\htdocs\punch`** (Windows Server, siempre encendido).
Origen (dev): `C:\xampp\htdocs\ponche-xtreme` (laptop).
La base de datos es **remota y compartida** (HostGator), así que las tablas, la configuración y los
14 días ya importados **ya están** — normalmente NO hay que tocar la BD.

---

## 1. Copiar archivos  (dev  →  `C:\xampp\htdocs\punch`)

Respetando la misma estructura de carpetas. **Nuevos:**

```
# Fase 1 — Sincronización de nómina
lib\vicidial_api_client.php
lib\cacert_vicidial.pem
cron_vicidial_sync.php
vicidial_sync.php
run_vicidial_sync.cmd
sql\create_vicidial_payroll_sync.sql
# Fase 2 — Monitor en vivo
lib\vicidial_live.php
sql\create_vicidial_live_status.sql
# Fase 2b — Registros Vicidial
records_vicidial.php
```

**Modificados (haz backup antes de reemplazar):**

```
settings.php                    # sección Integración Vicidial + config monitor
supervisor_realtime_api.php     # merge del estado en vivo
supervisor_dashboard.php        # monitor Vicidial-only
records.php                     # toggle Ponche/Vicidial
```

> Migraciones extra (solo si el server usa OTRA base de datos): además de `create_vicidial_payroll_sync.sql`, corre `create_vicidial_live_status.sql`. Si es la BD compartida (lo normal), ya están las 5 tablas.

> `run_vicidial_sync.cmd` es independiente de la ruta (usa su propia carpeta), así que funciona tal cual en `punch` sin editar. Solo ajusta la línea `set PHP=` si XAMPP no está en `C:\xampp`.

---

## 2. Base de datos  (casi siempre: NADA que hacer)

Abre `C:\xampp\htdocs\punch\db.php` y mira `$host` y `$dbname`.

- Si es **`192.185.46.27` / `hhempeos_ponche`** (la misma remota) → **listo, no hagas nada.** Tablas, config, credenciales y datos ya están.
- Si apunta a **otra base de datos** → corre la migración y vuelve a poner la config:
  ```
  C:\xampp\php\php.exe -r "require 'C:\\xampp\\htdocs\\punch\\db.php'; foreach(array_filter(array_map('trim',explode(';',preg_replace('/^\s*--.*$/m','',file_get_contents('C:\\xampp\\htdocs\\punch\\sql\\create_vicidial_payroll_sync.sql'))))) as $s){ if($s) $pdo->exec($s); } echo 'tablas ok';"
  ```
  Luego entra a la app → **Configuración → Integración Vicidial**, pon URL/usuario/contraseña, offset **0**, y marca **Activar**.

---

## 3. Registrar la tarea programada  (PowerShell **como Administrador**, en el server)

Corre de noche a la **1:30**, y como el server es Windows Server con admin, usa **S4U** → corre **aunque nadie tenga sesión iniciada**. `StartWhenAvailable` la ejecuta apenas el equipo esté disponible si estuvo apagado.

```powershell
$action    = New-ScheduledTaskAction -Execute 'C:\xampp\htdocs\punch\run_vicidial_sync.cmd'
$trigger   = New-ScheduledTaskTrigger -Daily -At 1:30AM
$settings  = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -MultipleInstances IgnoreNew -RestartCount 2 -RestartInterval (New-TimeSpan -Minutes 10)
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType S4U -RunLevel Highest
Register-ScheduledTask -TaskName 'PoncheXtreme-VicidialSync' -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description 'Sincronizacion nocturna Vicidial (Fase 1) - concilia login/logout con el ponche manual.' -Force
```

---

## 4. Verificar  (en el server)

Corre la tarea una vez y revisa el resultado:

```powershell
Start-ScheduledTask -TaskName 'PoncheXtreme-VicidialSync'
Start-Sleep -Seconds 15
Get-ScheduledTaskInfo -TaskName 'PoncheXtreme-VicidialSync' | Select-Object LastRunTime, LastTaskResult, NextRunTime
Get-Content 'C:\xampp\htdocs\punch\logs\vicidial_sync_cron.log' -Tail 12
```

`LastTaskResult = 0` = OK. En el log debe verse `Estado: ok ... Errores: 0`.

Luego abre la app local del ponche → **Conciliación Vicidial** (`vicidial_sync.php`) y confirma que ves los datos.

---

## 5. IP autorizada en Vicidial  (importante)

La API de Vicidial **solo acepta IPs autorizadas**. Verifica la IP pública **saliente del server**:

```powershell
Invoke-RestMethod https://api.ipify.org
```

- Si es la **misma** IP de la oficina que ya autorizaste → listo.
- Si es **distinta** → pídele al admin de Vicidial que agregue esa IP a la lista de "API allowed IPs" del usuario `jf0erreiras_27`. Sin eso, el cron dará error de conexión.

---

## Notas de precisión (por qué las horas son confiables)

- Las **horas trabajadas (Ponche)** se calculan con **el mismo motor que la nómina oficial** (`calculateWorkSecondsFromPunches`, mismos tipos pagados sanitizados, mismo cierre de intervalos). Verificado: **idénticas byte a byte** a la nómina en los 20 empleados de una jornada.
- El **offset de zona horaria = 0** (verificado comparando el reloj real del server Vicidial con la hora RD; NO fiarse del "TZ -5.00" que reporta la API).
- **Últ. actividad** de Vicidial **no** es el logout real (Vicidial no lo expone por API) → no se usa como salida. Para "¿trabajó su jornada?" el número preciso es **Δ Horas** (logueado vs trabajado).
- Es **modo sombra**: no modifica la nómina; solo compara.

---

## Migrar el cron a HostGator (opcional, más adelante)

No hace falta: el cron corre en el server de oficina cuya IP ya está autorizada. Si algún día se quiere en HostGator, hay que (1) subir estos archivos allá, (2) autorizar la IP saliente de HostGator en Vicidial, y (3) registrar en cPanel: `30 1 * * * php /home/hhempeos/public_html/cron_vicidial_sync.php`. Correr en ambos lados no duplica datos (el upsert es idempotente).

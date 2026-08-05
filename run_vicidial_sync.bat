@echo off
REM ============================================================================
REM  Sincronizacion Vicidial - TODO EN UNO (a prueba de errores)
REM
REM  MODOS:
REM   install  (una vez, doble clic): se auto-eleva a Admin, verifica zona horaria
REM            (GMT-4) y registra SIETE tareas de Windows como SYSTEM:
REM              - PoncheXtreme-VicidialSync            -> 11:30 PM diario, COMPLETA
REM                (importa hoy+ayer con login/logout + grabaciones: nomina y reportes).
REM              - PoncheXtreme-VicidialSync-Live       -> cada 15 min 8am-11pm, LIVIANA
REM                (actividad de HOY: llamadas, horas productivas, pausas, conversiones).
REM              - PoncheXtreme-VicidialSync-Refresh    -> cada 1 min 8am-11pm, REFRESCA
REM                el estado EN VIVO (en llamada/pausa) del portal del agente.
REM              - PoncheXtreme-VicidialSync-Recordings -> cada 2 h 8am-10pm, GRABACIONES
REM                (metadato de llamadas de hoy para "Mis Llamadas"; carga liviana).
REM              - PoncheXtreme-InventoryStockAlerts    -> 8:10 AM y 2:10 PM, ALERTAS
REM                de stock bajo / proximo a agotarse (notificacion en la campana).
REM              - PoncheXtreme-EmployeeNotices         -> 7:30 AM diario, AVISOS de
REM                RRHH: fin de periodo de prueba (10 dias antes), cumpleanos del
REM                mes (dia 1) y expedientes con documentacion incompleta.
REM              - PoncheXtreme-SalaryChanges           -> 12:05 AM diario, aplica los
REM                CAMBIOS DE SALARIO programados cuya fecha efectiva ya llego
REM                (cambio de campana con sueldo nuevo desde cierta fecha).
REM              - PoncheXtreme-DailyReports            -> cada 5 min 5:55am-11:55pm,
REM                REPORTES DIARIOS por correo (reclutamiento, inventario, nomina,
REM                tardanzas, ausencias...). Cada reporte se manda UNA vez al dia, a
REM                la hora que tenga configurada en settings.php. Sin esta tarea los
REM                reportes existen pero nadie los dispara.
REM   (sin args): corrida COMPLETA (--days=2). Lo que dispara la tarea nocturna.
REM   light     : corrida LIVIANA (--light, solo hoy). Dispara la intradia.
REM   liverefresh: refresco del estado en vivo (--live-refresh). Dispara el refrescador.
REM   recordings : importa grabaciones de hoy (--recordings). Dispara la de grabaciones.
REM   stockalerts: barrido de alertas de stock del inventario. Dispara la de alertas.
REM   reports    : despachador de reportes diarios. Dispara la de reportes.
REM   empnotices : avisos de RRHH (prueba, cumpleanos, expedientes). Dispara la suya.
REM   salarychanges: aplica los cambios de salario programados que ya vencieron.
REM
REM  Independiente de la ruta (%~dp0). Auto-detecta php.exe. Idempotente.
REM ============================================================================
setlocal EnableExtensions EnableDelayedExpansion
set "HERE=%~dp0"
set "TASK=PoncheXtreme-VicidialSync"
set "TASKLIVE=PoncheXtreme-VicidialSync-Live"
set "TASKREFRESH=PoncheXtreme-VicidialSync-Refresh"
set "TASKREC=PoncheXtreme-VicidialSync-Recordings"
set "TASKSTOCK=PoncheXtreme-InventoryStockAlerts"
set "TASKREPORTS=PoncheXtreme-DailyReports"
set "TASKEMPNOT=PoncheXtreme-EmployeeNotices"
set "TASKSALARY=PoncheXtreme-SalaryChanges"
set "CRON=%HERE%cron_vicidial_sync.php"

if /I "%~1"=="install" goto :INSTALL
if /I "%~1"=="light" ( set "MODE=liviano" & set "SYNCARGS=--light" & set "LOG=%HERE%logs\vicidial_sync_live.log" & goto :RUN )
if /I "%~1"=="liverefresh" ( set "MODE=refresco-vivo" & set "SYNCARGS=--live-refresh" & set "LOG=%HERE%logs\vicidial_live_refresh.log" & goto :RUN )
if /I "%~1"=="recordings" ( set "MODE=grabaciones" & set "SYNCARGS=--recordings" & set "LOG=%HERE%logs\vicidial_recordings.log" & goto :RUN )
REM Las alertas de stock NO usan cron_vicidial_sync.php: tienen su propio script.
if /I "%~1"=="stockalerts" ( set "MODE=alertas-stock" & set "CRON=%HERE%cron_inventory_stock_alerts.php" & set "SYNCARGS=" & set "LOG=%HERE%logs\inventory_stock_alerts.log" & goto :RUN )
if /I "%~1"=="reports" ( set "MODE=reportes-diarios" & set "CRON=%HERE%cron_daily_reports.php" & set "SYNCARGS=" & set "LOG=%HERE%logs\daily_reports.log" & goto :RUN )
if /I "%~1"=="empnotices" ( set "MODE=avisos-rrhh" & set "CRON=%HERE%cron_employee_notices.php" & set "SYNCARGS=" & set "LOG=%HERE%logs\employee_notices.log" & goto :RUN )
if /I "%~1"=="salarychanges" ( set "MODE=cambios-salario" & set "CRON=%HERE%cron_apply_salary_changes.php" & set "SYNCARGS=" & set "LOG=%HERE%logs\salary_changes.log" & goto :RUN )
set "MODE=completo" & set "SYNCARGS=--days=2" & set "LOG=%HERE%logs\vicidial_sync_cron.log"

REM ============================ MODO CORRIDA ==================================
:RUN
set "PHP=C:\xampp\php\php.exe"
if not exist "!PHP!" for %%D in (C D E F G) do if exist "%%D:\xampp\php\php.exe" set "PHP=%%D:\xampp\php\php.exe"
if not exist "!PHP!" for /f "delims=" %%P in ('where php 2^>nul') do if not defined _pf ( set "PHP=%%P" & set "_pf=1" )
if not exist "%HERE%logs" md "%HERE%logs" 2>nul
if exist "%LOG%" for %%S in ("%LOG%") do if %%~zS GTR 5242880 ( del /q "%LOG%.old" 2>nul & move /y "%LOG%" "%LOG%.old" >nul 2>nul )

echo ============================================================>> "%LOG%"
echo [%date% %time%] Iniciando tarea (!MODE!)>> "%LOG%"
if not exist "!PHP!" ( echo [%date% %time%] ERROR: no se hallo php.exe. Edita la linea PHP del .bat.>> "%LOG%" & endlocal & exit /b 2 )
if not exist "%CRON%" ( echo [%date% %time%] ERROR: falta el script PHP "%CRON%" junto al .bat.>> "%LOG%" & endlocal & exit /b 3 )

"!PHP!" "%CRON%" !SYNCARGS! >> "%LOG%" 2>&1
set "RC=!ERRORLEVEL!"
if "!RC!"=="0" ( echo [%date% %time%] OK - fin ^(exit 0^)>> "%LOG%" ) else ( echo [%date% %time%] FALLO - exit !RC! ^(ver error arriba / tabla vicidial_sync_log^)>> "%LOG%" )
endlocal & exit /b %RC%

REM ========================== MODO INSTALACION ===============================
:INSTALL
net session >nul 2>&1
if !errorlevel! NEQ 0 (
    echo Solicitando permisos de Administrador...
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -ArgumentList 'install' -Verb RunAs"
    exit /b
)
echo(
echo ==========================================================
echo   Instalar tareas programadas del Ponche (hora RD)
echo   - Completa   : 11:30 PM diario
echo   - En vivo    : cada 15 min, 8am a 11pm
echo   - Refresco   : cada 1 min,  8am a 11pm (estado en vivo)
echo   - Grabaciones: cada 2 h,    8am a 10pm (Mis Llamadas)
echo   - Stock      : 8:10 AM y 2:10 PM (alertas de inventario)
echo   - Reportes   : cada 5 min, 5:55am a 11:55pm (reportes diarios)
echo   - Avisos RRHH: 7:30 AM (prueba, cumpleanos, expedientes)
echo   - Salarios   : 12:05 AM (aplica cambios de salario programados)
echo ==========================================================
echo(
if not exist "%CRON%" ( echo ERROR: no se encontro cron_vicidial_sync.php junto a este .bat. & pause & exit /b 1 )

set "TZ="
for /f "delims=" %%Z in ('powershell -NoProfile -Command "(Get-TimeZone).Id" 2^>nul') do set "TZ=%%Z"
echo Zona horaria del server: !TZ!
echo Debe ser "SA Western Standard Time" (GMT-4) para que las horas sean hora RD.
choice /C SN /N /M "El reloj esta en hora RD (GMT-4)?  [S]=continuar  [N]=ajustar ahora: "
if !errorlevel! EQU 2 powershell -NoProfile -Command "Set-TimeZone -Id 'SA Western Standard Time'"
echo(

echo Registrando tarea COMPLETA (11:30 PM)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$a=New-ScheduledTaskAction -Execute '%~f0'; $t=New-ScheduledTaskTrigger -Daily -At ([datetime]'23:30'); $s=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -MultipleInstances IgnoreNew -RestartCount 2 -RestartInterval (New-TimeSpan -Minutes 10); $p=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest; Register-ScheduledTask -TaskName '%TASK%' -Action $a -Trigger $t -Settings $s -Principal $p -Description 'Sync Vicidial nocturna 11:30 PM RD (hoy+ayer, completo)' -Force | Out-Null"
if !errorlevel! NEQ 0 ( echo ERROR al registrar la tarea completa. & pause & exit /b 1 )

echo Registrando tarea EN VIVO (cada 15 min, 8am-11pm)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$a=New-ScheduledTaskAction -Execute '%~f0' -Argument 'light'; $t=New-ScheduledTaskTrigger -Daily -At ([datetime]'08:00'); $t.Repetition=(New-ScheduledTaskTrigger -Once -At ([datetime]'08:00') -RepetitionInterval (New-TimeSpan -Minutes 15) -RepetitionDuration (New-TimeSpan -Hours 15)).Repetition; $s=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -MultipleInstances IgnoreNew; $p=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest; Register-ScheduledTask -TaskName '%TASKLIVE%' -Action $a -Trigger $t -Settings $s -Principal $p -Description 'Sync Vicidial intradia cada 15 min 8am-11pm (liviano, actividad de hoy)' -Force | Out-Null"
if !errorlevel! NEQ 0 ( echo ERROR al registrar la tarea en vivo. & pause & exit /b 1 )

echo Registrando REFRESCADOR de estado en vivo (cada 1 min, 8am-11pm)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$a=New-ScheduledTaskAction -Execute '%~f0' -Argument 'liverefresh'; $t=New-ScheduledTaskTrigger -Daily -At ([datetime]'08:00'); $t.Repetition=(New-ScheduledTaskTrigger -Once -At ([datetime]'08:00') -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Hours 15)).Repetition; $s=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -MultipleInstances IgnoreNew; $p=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest; Register-ScheduledTask -TaskName '%TASKREFRESH%' -Action $a -Trigger $t -Settings $s -Principal $p -Description 'Refresca el estado EN VIVO de Vicidial cada 1 min 8am-11pm (portal del agente)' -Force | Out-Null"
if !errorlevel! NEQ 0 ( echo ERROR al registrar el refrescador. & pause & exit /b 1 )

echo Registrando GRABACIONES (cada 2 h, 8am-10pm)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$a=New-ScheduledTaskAction -Execute '%~f0' -Argument 'recordings'; $t=New-ScheduledTaskTrigger -Daily -At ([datetime]'08:00'); $t.Repetition=(New-ScheduledTaskTrigger -Once -At ([datetime]'08:00') -RepetitionInterval (New-TimeSpan -Hours 2) -RepetitionDuration (New-TimeSpan -Hours 14)).Repetition; $s=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 15) -MultipleInstances IgnoreNew; $p=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest; Register-ScheduledTask -TaskName '%TASKREC%' -Action $a -Trigger $t -Settings $s -Principal $p -Description 'Importa grabaciones de llamadas de hoy cada 2h 8am-10pm (Mis Llamadas del agente)' -Force | Out-Null"
if !errorlevel! NEQ 0 ( echo ERROR al registrar la tarea de grabaciones. & pause & exit /b 1 )

echo Registrando ALERTAS DE STOCK (8:10 AM y 2:10 PM)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$a=New-ScheduledTaskAction -Execute '%~f0' -Argument 'stockalerts'; $t1=New-ScheduledTaskTrigger -Daily -At ([datetime]'08:10'); $t2=New-ScheduledTaskTrigger -Daily -At ([datetime]'14:10'); $s=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -MultipleInstances IgnoreNew; $p=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest; Register-ScheduledTask -TaskName '%TASKSTOCK%' -Action $a -Trigger $t1,$t2 -Settings $s -Principal $p -Description 'Avisa en la campana los articulos agotados, bajo el minimo o proximos a agotarse' -Force | Out-Null"
if !errorlevel! NEQ 0 ( echo ERROR al registrar las alertas de stock. & pause & exit /b 1 )

echo Registrando REPORTES DIARIOS (cada 5 min, 5:55 AM a 11:55 PM)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$a=New-ScheduledTaskAction -Execute '%~f0' -Argument 'reports'; $t=New-ScheduledTaskTrigger -Daily -At ([datetime]'05:55'); $t.Repetition=(New-ScheduledTaskTrigger -Once -At ([datetime]'05:55') -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Hours 18)).Repetition; $s=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 20) -MultipleInstances IgnoreNew; $p=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest; Register-ScheduledTask -TaskName '%TASKREPORTS%' -Action $a -Trigger $t -Settings $s -Principal $p -Description 'Despacha los reportes diarios por correo a la hora configurada en settings.php (uno por dia por reporte)' -Force | Out-Null"
if !errorlevel! NEQ 0 ( echo ERROR al registrar los reportes diarios. & pause & exit /b 1 )

echo Registrando AVISOS DE RRHH (7:30 AM diario)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$a=New-ScheduledTaskAction -Execute '%~f0' -Argument 'empnotices'; $t=New-ScheduledTaskTrigger -Daily -At ([datetime]'07:30'); $s=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 15) -MultipleInstances IgnoreNew; $p=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest; Register-ScheduledTask -TaskName '%TASKEMPNOT%' -Action $a -Trigger $t -Settings $s -Principal $p -Description 'Avisos de RRHH: fin de periodo de prueba, cumpleanos del mes y expedientes incompletos' -Force | Out-Null"
if !errorlevel! NEQ 0 ( echo ERROR al registrar los avisos de RRHH. & pause & exit /b 1 )

echo Registrando CAMBIOS DE SALARIO programados (12:05 AM diario)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$a=New-ScheduledTaskAction -Execute '%~f0' -Argument 'salarychanges'; $t=New-ScheduledTaskTrigger -Daily -At ([datetime]'00:05'); $s=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -MultipleInstances IgnoreNew; $p=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest; Register-ScheduledTask -TaskName '%TASKSALARY%' -Action $a -Trigger $t -Settings $s -Principal $p -Description 'Aplica los cambios de salario programados cuya fecha efectiva ya llego' -Force | Out-Null"
if !errorlevel! NEQ 0 ( echo ERROR al registrar los cambios de salario. & pause & exit /b 1 )

echo(
echo Tareas registradas. Corriendo una prueba de cada una...
schtasks /Run /TN "%TASK%" >nul 2>&1
schtasks /Run /TN "%TASKLIVE%" >nul 2>&1
schtasks /Run /TN "%TASKREFRESH%" >nul 2>&1
schtasks /Run /TN "%TASKREC%" >nul 2>&1
schtasks /Run /TN "%TASKSTOCK%" >nul 2>&1
schtasks /Run /TN "%TASKREPORTS%" >nul 2>&1
schtasks /Run /TN "%TASKEMPNOT%" >nul 2>&1
schtasks /Run /TN "%TASKSALARY%" >nul 2>&1
timeout /t 20 /nobreak >nul
echo(
echo ===================== ESTADO =====================
powershell -NoProfile -Command "Get-ScheduledTask -TaskName '%TASK%','%TASKLIVE%','%TASKREFRESH%','%TASKREC%','%TASKSTOCK%','%TASKREPORTS%','%TASKEMPNOT%','%TASKSALARY%' | Get-ScheduledTaskInfo | Select-Object TaskName,LastTaskResult,NextRunTime | Format-Table -Auto"
echo Logs: %HERE%logs\vicidial_sync_cron.log       (completa nocturna)
echo       %HERE%logs\vicidial_sync_live.log       (actividad intradia)
echo       %HERE%logs\vicidial_live_refresh.log    (estado en vivo)
echo       %HERE%logs\vicidial_recordings.log      (grabaciones Mis Llamadas)
echo       %HERE%logs\inventory_stock_alerts.log   (alertas de stock)
echo       %HERE%logs\daily_reports.log            (reportes diarios por correo)
echo       %HERE%logs\employee_notices.log         (avisos de RRHH)
echo       %HERE%logs\salary_changes.log          (cambios de salario programados)
echo(
echo Para ver que reportes estan pendientes hoy:
echo   php "%HERE%cron_daily_reports.php" --status
echo(
echo Si LastTaskResult = 0 en todas, quedo listo.
echo(
pause
endlocal
exit /b

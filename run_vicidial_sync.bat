@echo off
REM ============================================================================
REM  Sincronizacion Vicidial - TODO EN UNO (a prueba de errores)
REM
REM  DOS MODOS:
REM   1) Instalar (una sola vez, doble clic o "run_vicidial_sync.bat install"):
REM        - se auto-eleva a Administrador
REM        - verifica la zona horaria (debe ser GMT-4 / hora RD)
REM        - registra la Tarea Programada de Windows a las 11:30 PM diario
REM          corriendo como SYSTEM (sin contrasena, aunque nadie inicie sesion)
REM        - hace una corrida de prueba y muestra el resultado
REM   2) Correr (sin argumentos): lo que dispara la tarea cada noche.
REM        Importa HOY + AYER (--days=2): dia fresco + reasegura el anterior.
REM        Es idempotente (upsert): re-correr no duplica nada.
REM
REM  Es INDEPENDIENTE DE LA RUTA (usa su propia carpeta %~dp0): el mismo archivo
REM  sirve en cualquier server. Auto-detecta php.exe (xampp en C:/D:/E:.. o el
REM  del PATH). Si aun asi no lo halla, escribe un ERROR claro en el log.
REM ============================================================================
setlocal EnableExtensions EnableDelayedExpansion
set "HERE=%~dp0"
set "TASK=PoncheXtreme-VicidialSync"
set "LOG=%HERE%logs\vicidial_sync_cron.log"
set "CRON=%HERE%cron_vicidial_sync.php"

if /I "%~1"=="install" goto :INSTALL

REM ============================ MODO CORRIDA ==================================
REM localizar php.exe
set "PHP=C:\xampp\php\php.exe"
if not exist "!PHP!" for %%D in (C D E F G) do if exist "%%D:\xampp\php\php.exe" set "PHP=%%D:\xampp\php\php.exe"
if not exist "!PHP!" for /f "delims=" %%P in ('where php 2^>nul') do if not defined _pf ( set "PHP=%%P" & set "_pf=1" )

if not exist "%HERE%logs" md "%HERE%logs" 2>nul

REM rotacion del log si supera ~5 MB
if exist "%LOG%" for %%S in ("%LOG%") do if %%~zS GTR 5242880 ( del /q "%LOG%.old" 2>nul & move /y "%LOG%" "%LOG%.old" >nul 2>nul )

echo ============================================================>> "%LOG%"
echo [%date% %time%] Iniciando sincronizacion Vicidial (--days=2)>> "%LOG%"

if not exist "!PHP!" ( echo [%date% %time%] ERROR: no se hallo php.exe. Edita la linea PHP del .bat.>> "%LOG%" & endlocal & exit /b 2 )
if not exist "%CRON%" ( echo [%date% %time%] ERROR: falta cron_vicidial_sync.php junto al .bat.>> "%LOG%" & endlocal & exit /b 3 )

"!PHP!" "%CRON%" --days=2 >> "%LOG%" 2>&1
set "RC=!ERRORLEVEL!"
if "!RC!"=="0" ( echo [%date% %time%] OK - fin exit 0>> "%LOG%" ) else ( echo [%date% %time%] FALLO - exit !RC! ^(ver error arriba / tabla vicidial_sync_log^)>> "%LOG%" )
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
echo   Instalar tarea "%TASK%"  -  11:30 PM diario (hora RD)
echo ==========================================================
echo(
if not exist "%CRON%" ( echo ERROR: no se encontro cron_vicidial_sync.php junto a este .bat. & pause & exit /b 1 )

set "TZ="
for /f "delims=" %%Z in ('powershell -NoProfile -Command "(Get-TimeZone).Id" 2^>nul') do set "TZ=%%Z"
echo Zona horaria actual del server: !TZ!
echo Para que "11:30 PM" sea hora RD, debe ser "SA Western Standard Time" (GMT-4, sin horario de verano).
echo(
choice /C SN /N /M "El reloj esta en hora RD (GMT-4)?  [S]=continuar  [N]=ajustar ahora: "
if !errorlevel! EQU 2 (
    echo Ajustando a "SA Western Standard Time"...
    powershell -NoProfile -Command "Set-TimeZone -Id 'SA Western Standard Time'"
)
echo(
echo Registrando la tarea...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$a=New-ScheduledTaskAction -Execute '%~f0'; $t=New-ScheduledTaskTrigger -Daily -At ([datetime]'23:30'); $s=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -MultipleInstances IgnoreNew -RestartCount 2 -RestartInterval (New-TimeSpan -Minutes 10); $p=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest; Register-ScheduledTask -TaskName '%TASK%' -Action $a -Trigger $t -Settings $s -Principal $p -Description 'Sync Vicidial 11:30 PM RD (hoy+ayer)' -Force | Out-Null"
if !errorlevel! NEQ 0 ( echo. & echo ERROR al registrar la tarea. Corre este .bat como Administrador. & pause & exit /b 1 )

echo Tarea registrada OK. Corriendo una prueba ahora...
schtasks /Run /TN "%TASK%" >nul 2>&1
timeout /t 20 /nobreak >nul
echo(
echo ===================== RESULTADO =====================
powershell -NoProfile -Command "Get-ScheduledTaskInfo -TaskName '%TASK%' | Select-Object LastRunTime,LastTaskResult,NextRunTime | Format-List"
echo Log: %LOG%
if exist "%LOG%" powershell -NoProfile -Command "Get-Content '%LOG%' -Tail 12"
echo(
echo Si LastTaskResult = 0 y ves 'Estado: ok ... Errores: 0', quedo listo.
echo(
pause
endlocal
exit /b

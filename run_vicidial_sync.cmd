@echo off
REM ============================================================
REM  Sincronizacion Vicidial (Fase 1) - wrapper para Task Scheduler
REM  Corre el cron de PHP e imprime la salida a un log.
REM  Es INDEPENDIENTE DE LA RUTA: usa su propia carpeta (%~dp0),
REM  asi que el MISMO archivo funciona tanto en la laptop de dev
REM  (ponche-xtreme) como en el server de produccion (punch) sin editar.
REM  La bitacora "oficial" queda ademas en la tabla vicidial_sync_log
REM  (visible en vicidial_sync.php -> Estado y Bitacora).
REM  Si XAMPP esta en otra unidad/carpeta, ajusta solo la linea PHP=.
REM ============================================================
setlocal
set PHP=C:\xampp\php\php.exe
set HERE=%~dp0
set LOG=%HERE%logs\vicidial_sync_cron.log

if not exist "%HERE%logs" mkdir "%HERE%logs"

echo ============================================================ >> "%LOG%"
echo [%date% %time%] Iniciando sincronizacion Vicidial >> "%LOG%"
"%PHP%" "%HERE%cron_vicidial_sync.php" >> "%LOG%" 2>&1
echo [%date% %time%] Fin (exit %ERRORLEVEL%) >> "%LOG%"
endlocal

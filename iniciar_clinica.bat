@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
title Clinica - Control local
color 0B
cd /d "%~dp0"

:: ======================================================================
::  CLINICA - SCRIPT UNICO DE CONTROL (TODO EN LOCAL)
::  Doble clic                   -> inicia: MySQL, Apache y Whisper
::  iniciar_clinica.bat detener  -> apaga TODO, incluso el vigilante
::
::  El sistema trae un vigilante oculto que arranca con Windows
::  (mantenimiento\stt_automatico.ps1): mantiene vivo el transcriptor
::  de voz. Este script lo verifica, lo levanta si falta, y ademas
::  levanta MySQL y Apache, que el vigilante no cubre.
:: ======================================================================

:: ---------------- CONFIGURACION ----------------
for %%I in ("%~dp0.") do set "PROYECTO=%%~fI"
for %%I in ("%PROYECTO%") do set "NOMBRE_PROYECTO=%%~nxI"
set "PHP=C:\xampp\php\php.exe"
set "VALIDADOR=%PROYECTO%\mantenimiento\validar_fuentes.php"
set "MYSQLD=C:\xampp\mysql\bin\mysqld.exe"
set "MYINI=C:\xampp\mysql\bin\my.ini"
set "HTTPD=C:\xampp\apache\bin\httpd.exe"
set "APACHEDIR=C:\xampp\apache"
set "PYTHON312=C:\Users\abelM\AppData\Local\Programs\Python\Python312\python.exe"
set "STT_PY=%PROYECTO%\app\servicios\ia\servicio_stt.py"
set "VIGILANTE=%PROYECTO%\mantenimiento\stt_automatico.ps1"
set "NGROK_SUPERVISOR=%PROYECTO%\mantenimiento\ngrok_supervisor.ps1"
set "URL_APP=http://localhost/%NOMBRE_PROYECTO%/"
set "MODO_AUTO=0"

set /a ERRORES=0
set /a AVISOS=0

if /i "%~1"=="detener" goto :detener
if /i "%~1"=="validar" (
    call :validar_fuentes
    exit /b !errorlevel!
)
if /i "%~1"=="auto" set "MODO_AUTO=1"

call :validar_fuentes
if errorlevel 1 (
    color 0C
    echo.
    echo  No se inicio ningun servicio porque hay archivos esenciales danados.
    echo  Restaura los archivos indicados y vuelve a ejecutar este BAT.
    if "%MODO_AUTO%"=="1" exit /b 1
    echo.
    pause
    exit /b 1
)

:: ======================================================================
::  MODO ARRANQUE
:: ======================================================================
echo.
echo  ======================================================================
echo     EXPEDIENTE CLINICO - ARRANQUE COMPLETO EN LOCAL
echo  ======================================================================

:: ---------------- 1. MYSQL ----------------
echo.
echo  [1/3] MySQL, base de datos, puerto 3306...
call :puerto_activo 3306
if not errorlevel 1 (
    echo        [OK] MySQL ya esta activo.
    goto :paso_apache
)
if not exist "%MYSQLD%" (
    echo        [ERROR] No se encontro %MYSQLD%
    echo                Revisa tu instalacion de XAMPP.
    set /a ERRORES+=1
    goto :paso_apache
)
call :proceso_ruta_activo "%MYSQLD%"
if errorlevel 1 (
    echo        [-] MySQL apagado. Iniciando...
    start "MySQL Daemon" /min "%MYSQLD%" --defaults-file="%MYINI%"
) else (
    echo        [-] MySQL ya esta iniciando; esperando sin abrir otro proceso...
)
call :esperar_puerto 3306 10
echo.
if errorlevel 1 (
    call :proceso_ruta_activo "%MYSQLD%"
    if errorlevel 1 (
        echo        [-] MySQL no sigue ejecutandose. Esperando antes de un segundo intento...
        ping -n 6 127.0.0.1 >nul
        call :proceso_ruta_activo "%MYSQLD%"
        if errorlevel 1 start "MySQL Daemon" /min "%MYSQLD%" --defaults-file="%MYINI%"
    ) else (
        echo        [-] MySQL sigue iniciando; se espera sin duplicar el daemon...
    )
    call :esperar_puerto 3306 30
    echo.
)
if errorlevel 1 (
    echo        [ERROR] MySQL no abrio el puerto 3306 tras dos intentos.
    echo                No se ejecutara una reparacion automatica sin permisos.
    echo                Revisa:
    echo                C:\xampp\mysql\data\mysql_error.log
    set /a ERRORES+=1
) else (
    echo        [OK] MySQL iniciado correctamente.
)

:: ---------------- 2. APACHE ----------------
:paso_apache
call :puerto_activo 3306
if errorlevel 1 goto :apache_sin_migracion
echo.
echo  [-] Comprobando actualizaciones automaticas de la base...
"%PHP%" -f "%PROYECTO%\mantenimiento\actualizar_busqueda_historial.php"
if errorlevel 1 (
    echo        [AVISO] La migracion automatica no se pudo completar.
    set /a AVISOS+=1
) else (
    echo        [OK] Base de datos actualizada.
)
goto :apache_continuar
:apache_sin_migracion
echo        [AVISO] Se omitio la migracion: MySQL no esta disponible.
set /a AVISOS+=1
:apache_continuar
echo.
echo  [2/3] Apache, pagina web, puerto 80...
call :puerto_activo 80
if not errorlevel 1 (
    echo        [OK] Apache ya esta activo.
    goto :paso_stt
)
if not exist "%HTTPD%" (
    echo        [ERROR] No se encontro %HTTPD%
    echo                Revisa tu instalacion de XAMPP.
    set /a ERRORES+=1
    goto :paso_stt
)
call :proceso_ruta_activo "%HTTPD%"
if errorlevel 1 (
    echo        [-] Apache apagado. Iniciando...
    start "Apache Daemon" /min "%HTTPD%" -d "%APACHEDIR%"
) else (
    echo        [-] Apache ya esta iniciando; esperando sin abrir otro proceso...
)
call :esperar_puerto 80 8
echo.
if errorlevel 1 (
    echo        [ERROR] Apache no abrio el puerto 80 en 16 segundos.
    echo                Causa comun: IIS, Skype u otro programa usa el puerto 80.
    echo                Revisa el log: C:\xampp\apache\logs\error.log
    set /a ERRORES+=1
) else (
    echo        [OK] Apache iniciado correctamente.
)

:: ---------------- 3. TRANSCRIPTOR WHISPER ----------------
:paso_stt
if "%MODO_AUTO%"=="1" goto :resumen
echo.
echo  [3/3] Transcriptor de voz Whisper, puerto 8766...
call :vigilante_activo
if errorlevel 1 (
    echo        [-] El vigilante automatico no esta corriendo. Levantandolo...
    if exist "%VIGILANTE%" (
        start "" /min powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%VIGILANTE%"
        echo        [OK] Vigilante iniciado. El se encarga del transcriptor.
    ) else (
        call :elegir_python
        if not defined PY_EXE (
            echo        [ERROR] No hay vigilante ni Python para iniciar el transcriptor.
            set /a ERRORES+=1
            goto :resumen
        )
        if exist "%STT_PY%" (
            start "Transcriptor STT" /min "%PYTHON312%" -u "%STT_PY%"
        ) else (
            echo        [ERROR] No se encontro el vigilante ni servicio_stt.py.
            set /a ERRORES+=1
            goto :resumen
        )
    )
) else (
    echo        [OK] Vigilante automatico ya esta corriendo.
)
echo        Esperando el modelo de voz, esto puede tardar medio minuto...
call :esperar_puerto 8766 60
echo.
if errorlevel 1 (
    echo        [ERROR] Whisper no abrio el puerto 8766 en 120 segundos.
    echo                Revisa el archivo: %%TEMP%%\expediente_stt.log
    set /a ERRORES+=1
) else (
    echo        [OK] Transcriptor de voz activo y cargado.
)

:: ---------------- RESUMEN ----------------
:resumen
echo.
echo  ======================================================================
echo     RESUMEN DEL ARRANQUE
echo  ======================================================================
echo.
call :estado_linea "MySQL base de datos  " 3306
call :estado_linea "Apache pagina web    " 80
call :estado_linea "Whisper transcriptor " 8766
echo.
if "%MODO_AUTO%"=="0" echo  Abriendo el expediente clínico en tu navegador...
if "%MODO_AUTO%"=="0" start "" "%URL_APP%"
if "%MODO_AUTO%"=="0" (
    schtasks /Run /TN "Clinica-Ngrok" >nul 2>&1
    if errorlevel 1 if exist "%NGROK_SUPERVISOR%" (
        start "" /min powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%NGROK_SUPERVISOR%"
    )
)
echo.
if !ERRORES! GTR 0 (
    color 0C
    echo  ATENCION: hubo !ERRORES! errores durante el arranque.
    if "%MODO_AUTO%"=="1" exit /b 1
    echo  Esta ventana se queda abierta para que puedas leer las causas.
    echo  Cierrala cuando quieras y vuelve a ejecutar este archivo.
    echo.
    pause
    exit /b 1
)
if !AVISOS! GTR 0 (
    color 0E
    echo  Sistema listo con !AVISOS! avisos. Todo lo principal funciona.
    if "%MODO_AUTO%"=="1" exit /b 0
    ping -n 9 127.0.0.1 >nul
    exit /b 0
)
color 0A
echo  TODO LISTO. Esta ventana se cerrara sola...
if "%MODO_AUTO%"=="1" exit /b 0
echo  El tunel remoto se verifica en segundo plano. El enlace validado quedara en el Escritorio.
ping -n 7 127.0.0.1 >nul
exit /b 0

:: ======================================================================
::  MODO DETENER
:: ======================================================================
:detener
color 0E
echo.
echo  ======================================================================
echo     EXPEDIENTE CLINICO - DETENIENDO TODOS LOS SERVICIOS
echo  ======================================================================
echo.
echo  [-] Deteniendo el tunel ngrok de esta aplicacion...
schtasks /End /TN "Clinica-Ngrok" >nul 2>&1
if exist "%NGROK_SUPERVISOR%" powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%NGROK_SUPERVISOR%" -Stop
echo  [-] Cerrando el vigilante automatico, es el que revive el transcriptor...
call :matar_vigilante
echo  [-] Cerrando transcriptor de voz, puerto 8766...
call :matar_stt
echo  [-] Cerrando Apache, puerto 80...
call :matar_proceso "%HTTPD%"
echo  [-] Cerrando MySQL, puerto 3306...
call :matar_proceso "%MYSQLD%"
echo        Esperando que los puertos queden libres...
ping -n 6 127.0.0.1 >nul
echo.
echo  Segunda pasada por si algo renacio...
call :matar_vigilante
call :matar_stt
call :matar_proceso "%HTTPD%"
call :matar_proceso "%MYSQLD%"
ping -n 4 127.0.0.1 >nul
echo.
echo  Verificacion final:
set "FALLO=0"
call :puerto_activo 8766
if not errorlevel 1 (set "FALLO=1" & echo   [X] El transcriptor sigue activo.)
call :puerto_activo 80
if not errorlevel 1 (set "FALLO=1" & echo   [X] Apache sigue activo.)
call :puerto_activo 3306
if not errorlevel 1 (set "FALLO=1" & echo   [X] MySQL sigue activo.)
call :vigilante_activo
if not errorlevel 1 (set "FALLO=1" & echo   [X] El vigilante sigue corriendo.)
echo.
if "%FALLO%"=="1" (
    color 0C
    echo  Algunos servicios no se cerraron. Vuelve a ejecutar este modo
    echo  detener o cierralos desde el Administrador de tareas.
    echo.
    pause
    exit /b 1
)
color 0A
echo  TODO DETENIDO CORRECTAMENTE.
echo  Nota: al reiniciar la PC el vigilante vuelve solo, asi esta configurado.
ping -n 6 127.0.0.1 >nul
exit /b 0

:: ======================================================================
::  SUBRUTINAS
:: ======================================================================

:: :validar_fuentes -> detiene el arranque antes de tocar servicios si PHP esta dañado
:validar_fuentes
if not exist "%PHP%" (
    echo [ERROR] No se encontro PHP en "%PHP%".
    exit /b 1
)
if not exist "%VALIDADOR%" (
    echo [ERROR] No se encontro el validador de archivos: "%VALIDADOR%".
    exit /b 1
)
"%PHP%" -l "%VALIDADOR%" >nul
if errorlevel 1 (
    echo [ERROR] El validador PHP esta danado o tiene errores de sintaxis.
    exit /b 1
)
"%PHP%" -f "%VALIDADOR%"
if errorlevel 1 exit /b 1
exit /b 0

:: :puerto_activo NUMERO  ->  errorlevel 0 si algo escucha en ese puerto
:puerto_activo
netstat -ano | findstr "LISTENING" | findstr /c:":%~1 " >nul 2>nul
if errorlevel 1 exit /b 1
exit /b 0

:: :proceso_activo NOMBRE  ->  errorlevel 0 si el proceso existe
:proceso_activo
tasklist /fi "imagename eq %~1" 2>nul | findstr /i "%~1" >nul 2>nul
if errorlevel 1 exit /b 1
exit /b 0

:: :proceso_ruta_activo RUTA -> errorlevel 0 si existe esa instancia exacta.
:proceso_ruta_activo
powershell -NoProfile -Command "$ruta='%~1'; if (Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath -and $_.ExecutablePath -ieq $ruta }) { exit 0 } else { exit 1 }" >nul 2>&1
if errorlevel 1 exit /b 1
exit /b 0

:: :esperar_puerto NUMERO INTENTOS  ->  espera x2 seg hasta que abra el puerto
:esperar_puerto
set /a EP_N=0
:ep_loop
call :puerto_activo %~1
if not errorlevel 1 exit /b 0
set /a EP_N+=1
if %EP_N% GEQ %~2 exit /b 1
<nul set /p "=."
ping -n 3 127.0.0.1 >nul
goto :ep_loop

:: :esperar_puerto_libre NUMERO INTENTOS  ->  espera x2 seg hasta que el puerto quede libre
:esperar_puerto_libre
set /a EPL_N=0
:epl_loop
call :puerto_activo %~1
if errorlevel 1 exit /b 0
set /a EPL_N+=1
if %EPL_N% GEQ %~2 exit /b 1
ping -n 3 127.0.0.1 >nul
goto :epl_loop

:: :vigilante_activo  ->  errorlevel 0 si stt_automatico.ps1 esta corriendo
:: OJO: se excluye $PID para que el verificador no se detecte a si mismo.
:vigilante_activo
powershell -NoProfile -Command "if (Get-CimInstance Win32_Process | Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -and $_.CommandLine.Contains('%VIGILANTE%') }) { exit 0 } else { exit 1 }" >nul 2>&1
if errorlevel 1 exit /b 1
exit /b 0

:: :matar_vigilante  ->  termina el powershell oculto del vigilante
:matar_vigilante
powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -and $_.CommandLine.Contains('%VIGILANTE%') } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }" >nul 2>&1
exit /b 0

:: Termina solo los procesos del proyecto, nunca cualquier proceso que use el puerto.
:matar_proceso
powershell -NoProfile -Command "$ruta='%~1'; Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath -and $_.ExecutablePath -ieq $ruta } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }" >nul 2>&1
exit /b 0

:matar_stt
powershell -NoProfile -Command "$script='%STT_PY%'; Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -and $_.CommandLine.Contains($script) } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }" >nul 2>&1
exit /b 0

:: :estado_linea TEXTO PUERTO  ->  imprime ACTIVO o APAGADO
:estado_linea
call :puerto_activo %~2
if errorlevel 1 (
    echo   %~1: APAGADO
    exit /b 0
)
echo   %~1: ACTIVO
exit /b 0

:: :elegir_python  ->  elige el mejor Python disponible
:elegir_python
set "PY_EXE="
if exist "%PYTHON312%" (
    set "PY_EXE=%PYTHON312%"
    exit /b 0
)
where py >nul 2>nul && set "PY_EXE=py" && exit /b 0
where python >nul 2>nul && set "PY_EXE=python" && exit /b 0
exit /b 1

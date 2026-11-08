@echo off
setlocal
title Transcriptor de voz - ExpedienteExpediente
cd /d C:\xampp\htdocs\ExpedienteExpediente

REM El modelo se carga desde la cache local; no se consulta internet al arrancar.
REM (Antes se quedaba colgado esperando a HuggingFace y parecia que no hacia nada.)

REM --- Elegir Python (primero el lanzador oficial, luego el del PATH) ---
set "PY="
where py >nul 2>nul && set "PY=py -3"
if not defined PY (
  where python >nul 2>nul && set "PY=python"
)
if not defined PY (
  echo.
  echo No se encontro Python. Instala Python 3.12 desde python.org
  echo y vuelve a ejecutar este archivo.
  echo.
  pause
  exit /b 1
)

REM --- Si ya hay algo escuchando en el puerto 8766, ofrecer reiniciarlo ---
set "PID_VIEJO="
for /f "tokens=5" %%P in ('netstat -ano ^| findstr ":8766" ^| findstr "LISTENING"') do set "PID_VIEJO=%%P"
if defined PID_VIEJO (
  echo.
  echo Ya hay un transcriptor abierto en el puerto 8766 ^(proceso %PID_VIEJO%^).
  choice /C SN /M "Quieres cerrarlo y reiniciarlo"
  if errorlevel 2 (
    echo.
    echo Se dejo el transcriptor actual. Cierra esta ventana.
    pause
    exit /b 0
  )
  taskkill /F /PID %PID_VIEJO% >nul 2>nul
  timeout /t 1 /nobreak >nul
)

echo.
echo Iniciando transcriptor local de voz...
echo La primera carga del modelo tarda unos segundos. No cierres esta ventana.
echo.
%PY% -u app\servicios\ia\servicio_stt.py

echo.
echo El transcriptor se detuvo. Copia el mensaje de arriba si hubo un error.
echo.
pause

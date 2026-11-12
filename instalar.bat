@echo off
REM Instalador local del Expediente Clínico para Windows (XAMPP)
setlocal

set "XAMPP=C:\xampp"
for %%I in ("%~dp0.") do set "PROYECTO=%%~fI"
for %%I in ("%PROYECTO%") do set "NOMBRE_PROYECTO=%%~nxI"
set "DB=clinica"

echo [1/6] Verificando XAMPP...
if not exist "%XAMPP%\mysql\bin\mysql.exe" (
    echo ERROR: XAMPP no encontrado en %XAMPP%
    pause
    exit /b 1
)
if not exist "%XAMPP%\php\php.exe" (
    echo ERROR: PHP no encontrado en %XAMPP%\php
    pause
    exit /b 1
)
if not exist "%PROYECTO%\mantenimiento\validar_fuentes.php" (
    echo ERROR: No se encontro el validador. No se modifico la base de datos.
    pause
    exit /b 1
)
"%XAMPP%\php\php.exe" -l "%PROYECTO%\mantenimiento\validar_fuentes.php" >nul
if errorlevel 1 (
    echo ERROR: El validador no compila. No se modifico la base de datos.
    pause
    exit /b 1
)
"%XAMPP%\php\php.exe" -f "%PROYECTO%\mantenimiento\validar_fuentes.php"
if errorlevel 1 (
    echo ERROR: Hay archivos PHP esenciales danados. No se modifico la base de datos.
    pause
    exit /b 1
)
if exist "%PROYECTO%\privado\bd.php" (
    echo ERROR: Ya existe una configuracion privada. Este instalador es solo para una instalacion nueva.
    pause
    exit /b 1
)

echo [2/6] Iniciando MySQL...
start "" "%XAMPP%\mysql\bin\mysqld.exe" --console
timeout /t 5 /nobreak >nul
"%XAMPP%\mysql\bin\mysql.exe" -u root -e "SELECT 1" >nul 2>nul
if errorlevel 1 goto :error
for /f "delims=" %%D in ('"%XAMPP%\mysql\bin\mysql.exe" -u root -Nse "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '%DB%'" 2^>nul') do set "DB_EXISTE=%%D"
if defined DB_EXISTE (
    echo ERROR: La base %DB% ya existe. No se modifico para proteger los datos.
    pause
    exit /b 1
)

echo [3/6] Creando base de datos e importando esquema...
"%XAMPP%\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE %DB% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 goto :error
"%XAMPP%\mysql\bin\mysql.exe" -u root %DB% < "%PROYECTO%\database\esquema.sql"
if errorlevel 1 goto :error
"%XAMPP%\mysql\bin\mysql.exe" -u root %DB% < "%PROYECTO%\database\migracion_preferencias_usuario.sql"
if errorlevel 1 goto :error

echo [4/6] Generando privado\bd.php...
if not exist "%PROYECTO%\privado" mkdir "%PROYECTO%\privado"
for /f "delims=" %%P in ('powershell -NoProfile -Command "$bytes = New-Object byte[] 32; $rng = [Security.Cryptography.RandomNumberGenerator]::Create(); $rng.GetBytes($bytes); [BitConverter]::ToString($bytes).Replace('-', '')"') do set "DB_CLAVE=%%P"
if not defined DB_CLAVE goto :error
set "SQL_TEMPLATE=%PROYECTO%\database\usuario_aplicacion.sql"
set "SQL_TEMP=%TEMP%\clinica-setup-%RANDOM%.sql"
powershell -NoProfile -Command "$sql = [IO.File]::ReadAllText($env:SQL_TEMPLATE); $respaldo = $sql.IndexOf('-- Respaldos (app/servicios/sistema/respaldos.php)'); if ($respaldo -lt 0) { throw 'No se encontro la seccion de respaldos.' }; $sql = $sql.Substring(0, $respaldo); $sql = $sql.Replace('__APP_DB_PASSWORD__', $env:DB_CLAVE); [IO.File]::WriteAllText($env:SQL_TEMP, $sql, [Text.UTF8Encoding]::new($false))"
if errorlevel 1 goto :error
"%XAMPP%\mysql\bin\mysql.exe" -u root < "%SQL_TEMP%"
if errorlevel 1 goto :error
del /q "%SQL_TEMP%" >nul 2>nul
(
    echo ^<?php
    echo return ['usuario' =^> 'clinica_app', 'clave' =^> '%DB_CLAVE%'];
) > "%PROYECTO%\privado\bd.php"
if errorlevel 1 goto :error
set "DB_CLAVE="

echo [5/7] Activando la busqueda clinica...
"%XAMPP%\mysql\bin\mysql.exe" -u root %DB% < "%PROYECTO%\database\migracion_busqueda_historial.sql"
if errorlevel 1 goto :error
"%XAMPP%\mysql\bin\mysql.exe" -u root -e "GRANT SELECT ON %DB%.historial_clinico_busqueda TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';"
if errorlevel 1 goto :error

echo [6/7] Iniciando Apache...
start "" "%XAMPP%\apache\bin\httpd.exe"
timeout /t 3 /nobreak >nul

echo [7/7] Listo.
echo Abre: http://localhost/%NOMBRE_PROYECTO%/
pause
exit /b 0

:error
if defined SQL_TEMP if exist "%SQL_TEMP%" del /q "%SQL_TEMP%" >nul 2>nul
set "DB_CLAVE="
echo ERROR: La instalación no se completó. Revisa la configuración de XAMPP y MySQL.
pause
exit /b 1
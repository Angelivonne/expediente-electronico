@echo off
REM Instalador local de ExpedienteExpediente para Windows (XAMPP)
setlocal

set XAMPP=C:\xampp
set PROYECTO=%XAMPP%\htdocs\ExpedienteExpediente
set DB=expediente_expediente

echo [1/6] Verificando XAMPP...
if not exist "%XAMPP%\mysql\bin\mysql.exe" (
    echo ERROR: XAMPP no encontrado en %XAMPP%
    pause
    exit /b 1
)

echo [2/6] Iniciando MySQL...
start "" "%XAMPP%\mysql\bin\mysqld.exe" --console
timeout /t 5 /nobreak >nul

echo [3/6] Creando base de datos e importando esquema...
"%XAMPP%\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS %DB% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"%XAMPP%\mysql\bin\mysql.exe" -u root %DB% < "%PROYECTO%\database\esquema.sql"
"%XAMPP%\mysql\bin\mysql.exe" -u root < "%PROYECTO%\database\usuario_aplicacion.sql"

echo [4/6] Generando privado\bd.php...
if not exist "%PROYECTO%\privado" mkdir "%PROYECTO%\privado"
(
    echo ^<?php
    echo return ['usuario' =^> 'expediente_app', 'clave' =^> 'spYFEdL2w3lxM1KVrCQ4'];
) > "%PROYECTO%\privado\bd.php"

echo [5/6] Iniciando Apache...
start "" "%XAMPP%\apache\bin\httpd.exe"
timeout /t 3 /nobreak >nul

echo [6/6] Listo.
echo Abre: http://localhost/ExpedienteExpediente/
pause
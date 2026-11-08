@echo off
rem Tarea diaria del proyecto para el Programador de tareas de Windows:
rem   1) respaldo de la base si hoy no hay uno (respaldar.php evita duplicados)
rem   2) verificacion general (motor, altas/cambios, conversion, catalogos y base)
rem Deja constancia en privado\registros\respaldos.log y privado\registros\verificacion.log.
rem Instrucciones de respaldo: docs\respaldos.md
cd /d "%~dp0"
set RESPALDO_MOTIVO=programado
"C:\xampp\php\php.exe" -f "%~dp0respaldar.php" >> "%~dp0..\privado\registros\respaldos.log" 2>&1
"C:\xampp\php\php.exe" -f "%~dp0verificar_todo.php" >> "%~dp0..\privado\registros\verificacion.log" 2>&1
exit /b %ERRORLEVEL%

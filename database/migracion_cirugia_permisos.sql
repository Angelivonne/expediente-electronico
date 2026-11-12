-- Ejecutar como administrador de MySQL después de migracion_preferencias_usuario.sql
-- al actualizar una instalación existente. No cambia contraseñas ni datos clínicos.
GRANT SELECT, INSERT, UPDATE ON clinica.cirugias_programadas
  TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT ON clinica.cirugias_eventos
  TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
FLUSH PRIVILEGES;

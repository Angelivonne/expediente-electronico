-- Ejecutar como administrador de MySQL después de que la aplicación haya creado
-- equipo_tiempo_activo y retroalimentacion_equipo mediante el reparador de esquema.
GRANT SELECT, INSERT, UPDATE ON clinica.equipo_tiempo_activo
  TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON clinica.retroalimentacion_equipo
  TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
FLUSH PRIVILEGES;

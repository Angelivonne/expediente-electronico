-- Desactiva la búsqueda y elimina solo el índice derivado; las notas originales no cambian.
DROP TRIGGER IF EXISTS historial_busqueda_ai;
DROP TRIGGER IF EXISTS historial_busqueda_au;
DROP TABLE IF EXISTS historial_clinico_busqueda;

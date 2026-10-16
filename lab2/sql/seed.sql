-- Fresh database only. Duplicate node IDs produce an error, never an update.
USE lab2;
START TRANSACTION;
INSERT INTO devices (node_id, name, token_hash) VALUES
    ('esp32-01', 'PIR de prueba 1', NULL),
    ('esp32-02', 'PIR de prueba 2', NULL),
    ('esp32-03', 'PIR de prueba 3', NULL);
COMMIT;

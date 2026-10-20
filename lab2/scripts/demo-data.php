<?php
declare(strict_types=1);

// Herramienta de observabilidad: consultas SELECT y auditoria MQTT filtrada.
// Nunca imprime contrasenas, tokens, el contenido JSON privado ni payloads completos.
// No altera la BD ni la cola MQTT.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

$mode = $argv[1] ?? 'full';
if (!in_array($mode, ['brief', 'full', 'audit', 'count'], true)) {
    fwrite(STDERR, "Uso: php demo-data.php [brief|full|audit|count]\n");
    exit(2);
}

try {
    if ($mode === 'audit') {
        // La configuracion de MQTT puede contener rutas privadas: NO imprimirla.
        $config = \Lab2\MqttRuntime::config();
        $path = $config['state_dir'] . '/audit.jsonl';
        echo "=== AUDITORIA REAL DEL SUSCRIPTOR MQTT (ULTIMOS 10 SUCESOS) ===\n";
        if (!is_file($path)) {
            echo "No hay auditoria; ejecute primero la prueba de integracion MQTT.\n";
            exit(0);
        }
        $file = fopen($path, 'rb');
        if (!$file) { throw new RuntimeException(); }
        // Limitar lecturas en ficheros grandes. Desechar la linea parcial inicial.
        $size = filesize($path);
        $from = max(0, $size - 65536);
        fseek($file, $from);
        if ($from > 0) { fgets($file); }
        $entries = [];
        while (($line = fgets($file)) !== false) {
            $item = json_decode($line, true);
            if (!is_array($item)) { continue; }
            // Solo campos de auditoria permitidos; no imprimir mensajes/payloads/credenciales.
            $allowed = [];
            foreach (['at', 'event', 'node_id', 'seq', 'code', 'telemetry_id'] as $key) {
                if (isset($item[$key]) && (is_int($item[$key]) || is_string($item[$key]))) {
                    $allowed[$key] = $item[$key];
                }
            }
            if ($allowed) {
                $entries[] = $allowed;
                if (count($entries) > 10) { array_shift($entries); }
            }
        }
        fclose($file);
        if (!$entries) { echo "No hay eventos de auditoria legibles.\n"; }
        foreach ($entries as $e) {
            printf("UTC=%s | evento=%s | nodo=%s | seq=%s | resultado=%s | fila=%s\n",
                $e['at'] ?? '-', $e['event'] ?? '-', $e['node_id'] ?? '-', $e['seq'] ?? '-',
                $e['code'] ?? '-', $e['telemetry_id'] ?? '-');
        }
        echo "Los eventos 'created', 'duplicate' y 'rejected' proceden del suscriptor, no del menu.\n";
        exit(0);
    }

    $pdo = \Lab2\Database::connect();
    if ($mode === 'count') {
        echo (int) $pdo->query('SELECT COUNT(*) FROM telemetry')->fetchColumn();
        exit(0);
    }
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($dbName !== 'lab2') { throw new RuntimeException(); }
    echo "=== DATOS CONSULTADOS DIRECTAMENTE EN MARIADB (BD: lab2) ===\n";
    echo "SQL: SELECT devices + COUNT(telemetry), agrupados por dispositivo\n";
    $rows = $pdo->query(
        'SELECT d.node_id, COUNT(t.id) AS total
         FROM devices d
         LEFT JOIN telemetry t ON t.device_id = d.id
         GROUP BY d.id, d.node_id
         ORDER BY d.id'
    )->fetchAll();
    foreach ($rows as $row) {
        printf("  %-14s | %d registros de telemetria\n", $row['node_id'], (int) $row['total']);
    }
    echo "\nSQL: SELECT ultimas mediciones JOIN devices ORDER BY telemetry.id DESC\n";
    $limit = $mode === 'brief' ? 3 : 10;
    $stmt = $pdo->prepare(
        'SELECT d.node_id, t.seq, t.value, t.rssi, t.ts, t.received_at
         FROM telemetry t JOIN devices d ON d.id = t.device_id
         ORDER BY t.id DESC LIMIT :max_rows'
    );
    $stmt->bindValue(':max_rows', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $latest = $stmt->fetchAll();
    if (!$latest) { echo "  Aun no hay telemetria registrada.\n"; }
    foreach ($latest as $row) {
        printf("  %s | seq=%s | valor_PIR=%d | RSSI=%s | ts=%s | guardado_UTC=%s\n",
            $row['node_id'], $row['seq'], (int) $row['value'],
            $row['rssi'] === null ? 'null' : (string) $row['rssi'],
            $row['ts'] === null ? 'null' : (string) $row['ts'],
            $row['received_at']);
    }
    if ($mode === 'full') {
        $events = $pdo->query(
            'SELECT severity, COUNT(*) AS total FROM events GROUP BY severity ORDER BY severity'
        )->fetchAll();
        echo "\nSQL: SELECT COUNT(*) FROM events GROUP BY severity\n";
        if (!$events) { echo "  Sin eventos.\n"; }
        foreach ($events as $row) {
            printf("  severidad=%s | %d eventos\n", $row['severity'], (int) $row['total']);
        }
        $configs = $pdo->query('SELECT COUNT(*) FROM configurations')->fetchColumn();
        printf("\nSQL: SELECT COUNT(*) FROM configurations => %d configuraciones\n", (int) $configs);
    }
    echo "\nAVISO: incluye fixtures generados por pruebas; no demuestra lectura de tres ESP32 fisicos.\n";
} catch (Throwable) {
    fwrite(STDERR, "ERROR: fallo en lectura SQL o auditoria. Verifique MariaDB, PDO, archivos privados y permisos.\n");
    exit(1);
}

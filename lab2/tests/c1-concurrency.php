<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

// Tests the shared service and InnoDB from independent processes, not PHP's
// single-threaded Windows development server. No tokens in process arguments.
try {
    $pdo = \Lab2\Database::connect();
    $query = $pdo->prepare('SELECT id FROM devices WHERE node_id = :node');
    $query->execute(['node' => 'esp32-01']);
    $id = $query->fetchColumn();
    if ($id === false) { throw new RuntimeException('Nodo de prueba ausente.'); }

    if (($argv[1] ?? '') === '--worker') {
        $seq = (int) $argv[2];
        $start = (float) $argv[3];
        while (microtime(true) < $start) { usleep(10000); }
        $payload = (object) ['node_id' => 'esp32-01', 'seq' => $seq, 'timestamp' => null,
            'value' => 1, 'rssi' => null, 'extensions' => (object) ['test' => 'C1-concurrent']];
        $result = (new \Lab2\TelemetryService($pdo))->receive($payload, (int) $id, 'esp32-01');
        echo json_encode(['created' => $result['created'], 'id' => $result['data']['telemetry_id'],
            'received_at' => $result['data']['received_at']], JSON_THROW_ON_ERROR);
        exit;
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM telemetry WHERE device_id = :id AND seq = :seq');
    do {
        $seq = random_int(1000000000000, 8000000000000000);
        $count->execute(['id' => $id, 'seq' => $seq]);
    } while ((int) $count->fetchColumn() !== 0);
    $start = sprintf('%.6f', microtime(true) + 2);
    $workers = [];
    for ($i = 0; $i < 4; ++$i) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', (string) $seq, $start],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('No se pudo iniciar un proceso de prueba.'); }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $created = 0;
    $ids = [];
    $times = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]); // Do not expose driver/worker diagnostics.
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('Fallo un proceso concurrente.'); }
        $result = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        $created += (int) $result['created'];
        $ids[] = $result['id'];
        $times[] = $result['received_at'];
    }
    $count->execute(['id' => $id, 'seq' => $seq]);
    if ($created !== 1 || count(array_unique($ids)) !== 1 || count(array_unique($times)) !== 1
        || (int) $count->fetchColumn() !== 1) {
        throw new RuntimeException('Idempotencia concurrente incorrecta.');
    }
    echo "PASS concurrencia: cuatro procesos, una creacion, tres duplicados y una fila conservada.\n";
} catch (Throwable) {
    fwrite(STDERR, "FAIL: concurrencia no verificada; revise PDO, nodos y disponibilidad de proc_open.\n");
    exit(1);
}

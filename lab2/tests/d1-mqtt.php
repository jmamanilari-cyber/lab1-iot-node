<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

use Lab2\MqttRuntime;

$subscriber = null;
$stop = null;
function stopSubscriber($process, string $stop): void
{
    file_put_contents($stop, 'stop');
    $until = microtime(true) + 7;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) { break; }
        usleep(100000);
    } while (microtime(true) < $until);
    if ($status['running']) { proc_terminate($process); }
    proc_close($process);
    unlink($stop);
}
try {
    $c = MqttRuntime::config();
    $pdo = Lab2\Database::connect();
    $device = (new Lab2\DeviceRepository($pdo))->findByNodeId('esp32-01');
    if (!$device) { throw new RuntimeException(); }
    $seq = random_int(1000000000000, 8000000000000000);
    $check = $pdo->prepare('SELECT COUNT(*) FROM telemetry WHERE device_id = :id AND seq IN (:a, :b)');
    $check->execute(['id' => $device['id'], 'a' => $seq, 'b' => $seq + 1]);
    if ((int) $check->fetchColumn() !== 0) { throw new RuntimeException(); }
    $query = $pdo->prepare('SELECT COUNT(*) FROM telemetry WHERE device_id = :id AND seq = :seq');
    $payload = ['node_id' => 'esp32-01', 'seq' => $seq, 'timestamp' => null, 'value' => 1, 'rssi' => -55,
        'extensions' => (object) ['test' => 'D1']];
    $audit = $c['state_dir'] . '/audit.jsonl';
    clearstatcache();
    $auditStart = is_file($audit) ? filesize($audit) : 0;
    $stop = $c['state_dir'] . '/test-' . $seq . '.stop';
    $subscriber = proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/mqtt-subscriber.php', '--timeout=25', '--stop-file=' . basename($stop)],
        [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
    if (!is_resource($subscriber)) { throw new RuntimeException(); }
    // Wait for the stable persistent subscription to be established. The first
    // publish below is repeated until it is observed, so scheduling is not evidence.
    $send = static function (array $data) use ($c): void {
        [$code] = MqttRuntime::command([$c['pub'], '-o', $c['publisher_options']['esp32-01'],
            '-t', 'lab2/esp32-01/telemetry', '-m', json_encode($data, JSON_THROW_ON_ERROR)]);
        if ($code !== 0) { throw new RuntimeException(); }
    };
    $until = microtime(true) + 10;
    do {
        $send($payload);
        usleep(300000);
        $query->execute(['id' => $device['id'], 'seq' => $seq]);
        if ((int) $query->fetchColumn() === 1) { break; }
    } while (microtime(true) < $until);
    $query->execute(['id' => $device['id'], 'seq' => $seq]);
    if ((int) $query->fetchColumn() !== 1) { throw new RuntimeException(); }
    $send($payload); // Identical content -> same TelemetryService duplicate path.
    $changed = $payload; $changed['value'] = 0; $send($changed);
    $wrong = $payload; $wrong['node_id'] = 'esp32-02'; $send($wrong);
    $found = [];
    $until = microtime(true) + 8;
    do {
        usleep(100000);
        $stream = fopen($audit, 'rb');
        if ($stream) {
            fseek($stream, $auditStart);
            while (($line = fgets($stream)) !== false) {
                $entry = json_decode($line, true);
                if (($entry['seq'] ?? null) === $seq) { $found[$entry['code'] ?? $entry['event']] = true; }
            }
            fclose($stream);
        }
    } while (!(isset($found['created'], $found['duplicate'], $found['sequence_conflict'], $found['identity_mismatch'])) && microtime(true) < $until);
    if (!isset($found['created'], $found['duplicate'], $found['sequence_conflict'], $found['identity_mismatch'])) { throw new RuntimeException(); }
    $row = $pdo->prepare('SELECT COUNT(*) AS total, MIN(value) AS value, MIN(ts) AS ts FROM telemetry WHERE device_id = :id AND seq = :seq');
    $row->execute(['id' => $device['id'], 'seq' => $seq]);
    $result = $row->fetch();
    if ((int) $result['total'] !== 1 || (int) $result['value'] !== 1 || $result['ts'] !== null) { throw new RuntimeException(); }
    stopSubscriber($subscriber, $stop); $subscriber = null;
    // Publish while PHP/subscriber is offline, then resume the same durable
    // MQTT session and local cursor. No broker or database restart is required.
    $resumed = $payload; $resumed['seq'] = $seq + 1; $send($resumed);
    $subscriber = proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/mqtt-subscriber.php', '--timeout=20', '--stop-file=' . basename($stop)],
        [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
    if (!is_resource($subscriber)) { throw new RuntimeException(); }
    $until = microtime(true) + 10;
    do {
        usleep(100000);
        $query->execute(['id' => $device['id'], 'seq' => $seq + 1]);
        $resumedCount = (int) $query->fetchColumn();
    } while ($resumedCount !== 1 && microtime(true) < $until);
    if ($resumedCount !== 1) { throw new RuntimeException(); }
    stopSubscriber($subscriber, $stop); $subscriber = null;
    echo "PASS D1: publicacion autenticada -> subscriber PHP -> TelemetryService -> MariaDB.\n";
    echo "PASS duplicado/conflicto/identidad: una fila, timestamp null y auditoria privada correlacionada.\n";
    echo "PASS reanudacion: publicacion con subscriber detenido recibida tras reiniciar su sesion persistente.\n";
    echo "Dos filas de prueba y la cola privada se conservan; no se eliminan datos.\n";
} catch (Throwable) {
    if (is_resource($subscriber) && is_string($stop)) { stopSubscriber($subscriber, $stop); }
    fwrite(STDERR, "FAIL: integracion D1 no verificada; revise broker privado, prueba de seguridad, PDO y auditoria local.\n");
    exit(1);
}

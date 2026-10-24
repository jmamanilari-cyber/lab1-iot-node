<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

use Lab2\MqttRuntime;

$process = null;
register_shutdown_function(static function () use (&$process): void {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
});
try {
    $config = MqttRuntime::config();
    $dir = $config['state_dir'];
    $proofPath = $dir . '/security.json';
    $proof = is_file($proofPath) ? json_decode(file_get_contents($proofPath), true, 8, JSON_THROW_ON_ERROR) : [];
    if (!hash_equals(MqttRuntime::fingerprint($config), $proof['fingerprint'] ?? '')) {
        throw new RuntimeException('Ejecute primero tests/d1-security.php contra el broker protegido.');
    }
    $args = getopt('', ['max-messages:', 'timeout:', 'stop-file:']);
    $stopName = $args['stop-file'] ?? 'stop';
    if (!is_string($stopName) || !preg_match('/\A[A-Za-z0-9_.-]{1,100}\z/', $stopName)) { throw new RuntimeException('Archivo de parada invalido.'); }
    $stopPath = $dir . '/' . $stopName;
    $max = isset($args['max-messages']) ? filter_var($args['max-messages'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
    $timeout = isset($args['timeout']) ? filter_var($args['timeout'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
    if ($max === false || $timeout === false) { throw new RuntimeException('Opciones CLI invalidas.'); }
    $lock = fopen($dir . '/subscriber.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Ya existe un subscriber D1.'); }
    $cursorPath = $dir . '/cursor';
    $offsetText = is_file($cursorPath) ? trim(file_get_contents($cursorPath)) : '0';
    if (!ctype_digit($offsetText)) { throw new RuntimeException('Cursor MQTT invalido.'); }
    $offset = (int) $offsetText;
    $spool = $dir . '/inbox.jsonl';
    $queue = fopen($spool, 'c+b');
    if (!$queue || fstat($queue)['size'] < $offset) { throw new RuntimeException('Cola MQTT truncada; requiere revision.'); }
    fseek($queue, $offset);
    $started = microtime(true);
    $handled = 0;
    $nextStart = 0.0;
    $retryDelay = 1;
    $pdo = null;
    $exit = 0;
    while (true) {
        clearstatcache(true, $stopPath);
        if (is_file($stopPath)) { break; }
        if ($timeout !== null && microtime(true) - $started >= $timeout) { $exit = 2; break; }
        if (!is_resource($process) && microtime(true) >= $nextStart) {
            $process = proc_open([$config['sub'], '-o', $config['subscriber_options'], '-t', 'lab2/+/telemetry',
                '-i', 'lab2-d1-backend', '-c', '-x', '86400', '-F', '%j'],
                [0 => ['file', 'NUL', 'r'], 1 => ['file', $spool, 'ab'], 2 => ['file', 'NUL', 'w']], $pipes);
            if (!is_resource($process)) { throw new RuntimeException('No se pudo iniciar mosquitto_sub.'); }
            MqttRuntime::audit($dir, ['event' => 'transport_started']);
        }
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                proc_close($process); $process = null;
                MqttRuntime::audit($dir, ['event' => 'transport_exited', 'exit_code' => $status['exitcode']]);
                $nextStart = microtime(true) + 2;
            }
        }
        $line = fgets($queue);
        if ($line === false || !str_ends_with($line, "\n")) {
            fseek($queue, $offset);
            usleep(100000);
            continue;
        }
        $endOffset = ftell($queue);
        $record = ['offset' => $offset, 'sha256' => hash('sha256', $line)];
        try {
            try { $envelope = json_decode($line, false, 32, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new Lab2\ApiException(400, 'invalid_envelope', 'Formato MQTT invalido.'); }
            if (!$envelope instanceof stdClass || !isset($envelope->topic, $envelope->payload)
                || !is_string($envelope->topic) || !is_string($envelope->payload)
                || !preg_match('~\Alab2/([A-Za-z0-9_-]{1,64})/telemetry\z~', $envelope->topic, $match)) {
                throw new Lab2\ApiException(422, 'invalid_topic', 'Topico MQTT invalido.');
            }
            $nodeId = $match[1];
            $record['node_id'] = $nodeId;
            if (strlen($envelope->payload) > 16384) { throw new Lab2\ApiException(422, 'payload_size', 'Cuerpo demasiado grande.'); }
            try { $payload = json_decode($envelope->payload, false, 32, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new Lab2\ApiException(400, 'malformed_json', 'JSON invalido.'); }
            if (!$payload instanceof stdClass) { throw new Lab2\ApiException(422, 'invalid_payload', 'Se requiere objeto.'); }
            if (isset($payload->seq) && is_int($payload->seq)) { $record['seq'] = $payload->seq; }
            $pdo ??= Lab2\Database::connect();
            $device = (new Lab2\DeviceRepository($pdo))->findByNodeId($nodeId);
            if ($device === null) { throw new Lab2\ApiException(403, 'unknown_device', 'Dispositivo no registrado.'); }
            // Trust derives from verified broker authentication and exact per-user
            // write ACLs. MQTT does not expose the original publisher username.
            $result = (new Lab2\TelemetryService($pdo))->receive($payload, $device['id'], $device['node_id'], 'mqtt');
            $record += ['event' => $result['created'] ? 'created' : 'duplicate', 'telemetry_id' => $result['data']['telemetry_id']];
        } catch (Lab2\ApiException $error) {
            $record += ['event' => 'rejected', 'code' => $error->errorCode];
        } catch (Throwable) {
            $pdo = null;
            MqttRuntime::audit($dir, $record + ['event' => 'retry_pending']);
            fseek($queue, $offset);
            sleep($retryDelay);
            $retryDelay = min(5, $retryDelay + 1);
            continue;
        }
        MqttRuntime::audit($dir, $record);
        // A crash after DB commit and before checkpoint causes safe duplicate replay.
        MqttRuntime::writeAtomic($cursorPath, (string) $endOffset);
        $offset = $endOffset;
        $retryDelay = 1;
        if ($max !== null && ++$handled >= $max) { break; }
    }
    if (is_resource($process)) { proc_terminate($process); proc_close($process); $process = null; }
    fclose($queue);
    fclose($lock);
    echo "Subscriber D1 detenido; consulte la trazabilidad privada.\n";
    exit($exit);
} catch (Throwable) {
    fwrite(STDERR, "ERROR: subscriber D1 no completado; revise configuracion privada y verificacion de seguridad.\n");
    exit(1);
}

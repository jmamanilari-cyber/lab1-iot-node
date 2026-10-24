<?php
declare(strict_types=1);

// Comprobaciones aisladas; no PDO, API real ni archivos en logs/evidence/LOAD.
require dirname(__DIR__) . '/scripts/http-load.php';

function unitCheck(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException($label); }
    echo "OK: {$label}\n";
}

try {
    unitCheck(loadPercentile([], 0.5) === null && loadPercentile([42], 0.95) === 42.0, 'Poblaciones vacia y unitaria');
    unitCheck(loadPercentile(range(1, 100), 0.5) === 50.5
        && abs(loadPercentile(range(1, 100), 0.95) - 95.05) < 0.00000001
        && loadPercentile([20, 10], 0.95) === 19.5, 'Percentiles inclusivos con interpolacion');
    foreach ([-0.1, 1.1, NAN] as $q) {
        $blocked = false; try { loadPercentile([1], $q); } catch (LoadFailure) { $blocked = true; }
        unitCheck($blocked, 'Cuantil invalido rechazado');
    }
    unitCheck(loadEndpoint('http://127.0.0.1:18082/api/v1/telemetry')['port'] === 18082
        && loadEndpoint('http://[::1]:18082/api/v1/telemetry')['host'] === '::1', 'Literales loopback admitidos');
    foreach (['http://example.com/api/v1/telemetry', 'http://127.0.0.1.example.com/api/v1/telemetry',
        'http://127.1/api/v1/telemetry', 'http://2130706433/api/v1/telemetry',
        'http://localhost/api/v1/telemetry', 'http://user:UN_SECRET_DE_PRUEBA@127.0.0.1/api/v1/telemetry',
        'http://127.0.0.1/api/v1/telemetry?secret=valor', 'http://127.0.0.1/api/v1/telemetry#x',
        'https://127.0.0.1/api/v1/telemetry', 'http://127.0.0.1/otra',
        "http://127.0.0.1/api/v1/telemetry\r\nX: x"] as $url) {
        $blocked = false;
        try { loadEndpoint($url); } catch (LoadFailure $e) { $blocked = !str_contains($e->getMessage(), 'UN_SECRET_DE_PRUEBA'); }
        unitCheck($blocked, 'Destino o URL insegura bloqueada sin repetir sus datos');
    }
    $plan = loadPlan(1000000000000, 'unit-only');
    $counts = array_count_values(array_column($plan, 'scenario'));
    unitCheck(count($plan) === 401 && count(array_unique(array_column($plan, 'seq'))) === 401
        && $counts === ['warmup' => 1, 'C1' => 100, 'C5' => 100, 'C10' => 100, 'C20' => 100], 'Plan fijo, secuencias unicas y calentamiento separado');
    foreach ($plan as $p) {
        $body = json_decode($p['body'], true, 32, JSON_THROW_ON_ERROR);
        if ($body['seq'] !== $p['seq'] || $body['node_id'] !== $p['node_id']
            || $body['extensions']['campaign'] !== 'unit-only' || preg_match('/authorization|bearer|[a-f0-9]{64}/i', $p['body'])) {
            throw new RuntimeException('Plan o sanitizacion incorrectos.');
        }
    }
    unitCheck(count(array_unique(array_column($plan, 'node_id'))) === 3, 'Fixtures distribuidos entre tres identidades sin credenciales en JSON');
    $devices = ['esp32-01' => 1, 'esp32-02' => 2, 'esp32-03' => 3];
    $sqlRows = []; $attempts = [];
    foreach (array_slice($plan, 0, 3) as $p) {
        $id = 100 + $p['ordinal'];
        $sqlRows[] = ['id' => $id, 'device_id' => $devices[$p['node_id']], 'node_id' => $p['node_id'], 'seq' => $p['seq'],
            'ts' => null, 'received_at' => '2026-01-01 00:00:00.000000', 'value' => $p['value'], 'rssi' => -60,
            'test_marker' => 'HTTP-LOAD', 'campaign' => 'unit-only', 'scenario' => $p['scenario'], 'ordinal' => (string) $p['ordinal']];
        $attempts[] = ['seq' => $p['seq'], 'node_id' => $p['node_id'], 'outcome' => 'created',
            'telemetry_id' => $id, 'received_at' => '2026-01-01T00:00:00.000000Z', 'curl_code' => CURLE_OK,
            'http_status' => 201, 'latency_ms' => $p['ordinal'] === 1 ? 1.0 : 3.0];
    }
    $snapshot = ['utc' => '2026-01-01T00:00:01Z', 'campaign_count' => 3,
        'campaign_rows' => $sqlRows, 'block_rows' => $sqlRows, 'queries' => []];
    $proof = loadReconcile($plan, $attempts, $snapshot, $devices);
    unitCheck(!$proof['discrepancies'] && $proof['by_scenario']['C1']['rows'] === 2
        && $proof['by_scenario']['C1']['confirmed_201'] === 2, 'Conciliacion de claves, identidades, marcadores y contenido');
    $run = ['requests' => array_slice($attempts, 1), 'duration_ns' => 2000000000,
        'peak_inflight' => 2, 'deadline_hit' => false, 'internal_failure' => false];
    $s = loadSummary('C1', 2, 2, $run, $proof);
    unitCheck($s['status'] === 'PASS' && abs($s['p50_ms'] - 2.0) < 0.00000001 && abs($s['p95_ms'] - 2.9) < 0.00000001
        && abs($s['completed_rps'] - 1.0) < 0.00000001 && abs($s['confirmed_insertions_rps'] - 1.0) < 0.00000001,
        'Resumen y denominadores monotonicamente medidos');
    $missing = $snapshot; $missing['block_rows'] = []; $missing['campaign_rows'] = []; $missing['campaign_count'] = 0;
    $bad = loadReconcile($plan, $attempts, $missing, $devices);
    unitCheck(loadSummary('C1', 2, 2, $run, $bad)['status'] === 'FAIL'
        && count($bad['by_scenario']['C1']['missing_201']) === 2, 'HTTP 201 sin filas SQL no acredita PASS');
    $wrongId = $attempts; $wrongId[1]['telemetry_id'] = 9999;
    unitCheck((bool) loadReconcile($plan, $wrongId, $snapshot, $devices)['discrepancies'], 'Identificador HTTP discordante con SQL se detecta');
    $duplicate = $snapshot;
    $duplicate['block_rows'][] = $sqlRows[1]; $duplicate['campaign_rows'][] = $sqlRows[1]; $duplicate['campaign_count']++;
    $bad = loadReconcile($plan, $attempts, $duplicate, $devices);
    unitCheck((bool) $bad['discrepancies'] && $bad['by_scenario']['C1']['rows'] === 3,
        'Duplicados contabilizados como filas reales, sin reducirlos a claves unicas');
    $late = $attempts; $late[1]['outcome'] = 'transport_error'; $late[1]['curl_code'] = CURLE_OPERATION_TIMEDOUT; $late[1]['http_status'] = null;
    $bad = loadReconcile($plan, $late, $snapshot, $devices);
    unitCheck($bad['by_scenario']['C1']['confirmed_201'] === 1 && count($bad['by_scenario']['C1']['without_valid_201']) === 1,
        'Insert tras timeout conservado sin convertirlo en HTTP 201');
    $stop = true; $csv = tmpfile();
    $cancel = loadScenario([$plan[0]], 1, loadEndpoint('http://127.0.0.1:1/api/v1/telemetry'),
        array_fill_keys(LOAD_NODES, str_repeat('a', 64)), 100, 5, $csv, $stop);
    fclose($csv);
    unitCheck(!$cancel['requests'] && !$cancel['internal_failure'], 'Cancelacion previa no envia solicitudes');

    // Un socket local sin responder permite probar el timeout real de cURL sin backend.
    $errno = 0; $message = '';
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
    if ($socket === false) { throw new RuntimeException('No se pudo preparar el socket local de prueba.'); }
    $csv = tmpfile(); $stop = false;
    try {
        $address = stream_socket_get_name($socket, false);
        $timed = loadScenario([$plan[0]], 1, loadEndpoint('http://' . $address . '/api/v1/telemetry'),
            array_fill_keys(LOAD_NODES, str_repeat('a', 64)), 100, 5, $csv, $stop);
        $r = $timed['requests'][0] ?? [];
        unitCheck(count($timed['requests']) === 1 && $r['curl_code'] === CURLE_OPERATION_TIMEDOUT
            && $r['outcome'] === 'transport_error' && $r['latency_ms'] >= 50, 'Timeout de transporte real, acotado y registrado');
        rewind($csv); $text = stream_get_contents($csv);
        unitCheck(!str_contains($text, str_repeat('a', 64)) && !preg_match('/authorization|bearer/i', $text), 'CSV sin credenciales ni cabeceras privadas');
        $stats = loadSummary('warmup', 1, 1, $timed, null);
        unitCheck($stats['latency_samples'] === 0 && $stats['p95_ms'] === null && $stats['status'] === 'FAIL', 'Timeout no transformado en latencia cero ni PASS');
    } finally { fclose($socket); fclose($csv); }
    echo "PASS unitarias HTTP LOAD. Sin datos experimentales ni cambios SQL.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL unitarias: ' . ($error instanceof RuntimeException ? $error->getMessage() : 'Fallo interno, detalles omitidos.') . "\n");
    exit(1);
}

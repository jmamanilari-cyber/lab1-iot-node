<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

function check(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException($label); }
}

function callApi(string $method, string $path, string $token, ?string $body = null): array
{
    global $base;
    $options = ['http' => ['method' => $method, 'header' => "Content-Type: application/json\r\nAuthorization: Bearer $token\r\n",
        'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0]];
    if ($body !== null) { $options['http']['content'] = $body; }
    $raw = @file_get_contents($base . $path, false, stream_context_create($options));
    check($raw !== false && isset($http_response_header[0]), 'Servidor local no disponible.');
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    $json = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    check(isset($json['ok'], $json['meta']['api_version']), 'Respuesta inconsistente.');
    check(!str_contains($raw, $token) || $token === '', 'La respuesta contiene un token.');
    return [(int) ($match[1] ?? 0), $json];
}

function status(string $method, string $path, string $token, int $expected, ?string $body = null): array
{
    [$code, $json] = callApi($method, $path, $token, $body);
    check($code === $expected, "Estado inesperado para $method $path (esperado $expected, recibido $code).");
    check($json['ok'] === ($expected < 400), 'Valor ok inconsistente.');
    check(isset($json[$expected < 400 ? 'data' : 'error']), 'Falta data/error.');
    return $json;
}

try {
    $base = rtrim($argv[1] ?? 'http://127.0.0.1:18082', '/');
    $parts = parse_url($base);
    check(is_array($parts) && ($parts['scheme'] ?? '') === 'http'
        && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true)
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
        && !isset($parts['path']), 'Utilice una URL HTTP local sin ruta.');
    $adminPath = dirname(__DIR__) . '/config/admin.local.json';
    $devicePath = dirname(__DIR__) . '/config/test-tokens.local.json';
    check(is_file($adminPath) && is_file($devicePath), 'Faltan tokens privados de administrador o dispositivos.');
    $admin = json_decode(file_get_contents($adminPath), true, 8, JSON_THROW_ON_ERROR)['token'] ?? '';
    $tokens = json_decode(file_get_contents($devicePath), true, 8, JSON_THROW_ON_ERROR);
    $token = $tokens['esp32-01'] ?? '';
    check(is_string($admin) && preg_match('/\A[a-f0-9]{64}\z/', $admin) === 1
        && is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1, 'Tokens locales invalidos.');
    $pdo = \Lab2\Database::connect();
    $device = (new \Lab2\DeviceRepository($pdo))->authenticate($token);
    check($device !== null && $device['node_id'] === 'esp32-01', 'Token de dispositivo no coincide.');
    $id = $device['id'];
    $other = $pdo->prepare('SELECT id FROM devices WHERE id <> :id ORDER BY id LIMIT 1');
    $other->execute(['id' => $id]);
    $otherId = $other->fetchColumn();
    check($otherId !== false, 'Se requieren dos dispositivos registrados para probar autorizacion.');
    $missingId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM devices')->fetchColumn();

    $list = status('GET', '/api/v1/devices?limit=1', $admin, 200);
    $next = status('GET', '/api/v1/devices?limit=1&offset=1', $admin, 200);
    $expected = $pdo->query('SELECT id FROM devices ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    check(count($list['data']) === 1 && $list['meta']['has_more'] === true
        && $list['data'][0]['id'] === (int) $expected[0] && $next['data'][0]['id'] === (int) $expected[1]
        && !isset($list['data'][0]['token_hash']), 'Paginacion o privacidad de dispositivos incorrecta.');
    $empty = status('GET', '/api/v1/devices?offset=1000000', $admin, 200);
    check($empty['data'] === [] && !$empty['meta']['has_more'], 'Pagina vacia incorrecta.');
    echo "PASS devices: consulta, paginacion estable y privacidad.\n";

    foreach ([['GET', '/api/v1/devices'], ['GET', '/api/v1/events'], ['PUT', "/api/v1/devices/$id/config"]] as [$method, $path]) {
        status($method, $path, '', 401);
        status($method, $path, bin2hex(random_bytes(32)), 401);
        status($method, $path, $token, 403);
    }
    status('GET', "/api/v1/devices/$id/telemetry", $token, 200);
    status('GET', "/api/v1/devices/$otherId/telemetry", $token, 403);
    status('GET', "/api/v1/devices/$missingId/telemetry", $admin, 404);
    status('PUT', "/api/v1/devices/$missingId/config", $admin, 404, '{"key":"sample","value":1}');
    status('GET', "/api/v1/events?device_id=$missingId", $admin, 404);
    // The entry-point routing changed: only this C1 authentication smoke check is needed.
    status('POST', '/api/v1/telemetry', $admin, 401, '{}');
    echo "PASS autorizacion: 401/403, historial propio, recursos 404 y token administrativo separado de C1.\n";

    // Isolated additive fixtures: never assume empty tables or overwrite existing keys.
    $run = bin2hex(random_bytes(8));
    $key = 'c2_' . $run;
    $seq = random_int(1000000000000, 8000000000000000);
    $exists = $pdo->prepare('SELECT COUNT(*) FROM telemetry WHERE device_id = :id AND seq IN (:a, :b)');
    $exists->execute(['id' => $id, 'a' => $seq, 'b' => $seq + 1]);
    check((int) $exists->fetchColumn() === 0, 'Colision de fixtures; ejecute de nuevo.');
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO telemetry (device_id, seq, ts, value, rssi, extensions) VALUES (:id, :seq, :ts, 1, NULL, :extensions)');
    foreach ([1700000000, null] as $i => $timestamp) {
        $insert->execute(['id' => $id, 'seq' => $seq + $i, 'ts' => $timestamp, 'extensions' => json_encode(['test' => 'C2', 'run' => $run])]);
    }
    $event = $pdo->prepare('INSERT INTO events (device_id, type, severity, detail) VALUES (:id, :type, :severity, :detail)');
    foreach (['info', 'warning', 'error'] as $severity) {
        $event->execute(['id' => $id, 'type' => $key, 'severity' => $severity, 'detail' => json_encode(['test' => 'C2', 'run' => $run])]);
    }
    $pdo->commit();

    $history = status('GET', "/api/v1/devices/$id/telemetry?limit=1", $admin, 200);
    $historyNext = status('GET', "/api/v1/devices/$id/telemetry?limit=1&offset=1", $token, 200);
    $query = $pdo->prepare('SELECT id FROM telemetry WHERE device_id = :id ORDER BY received_at, id LIMIT 2');
    $query->execute(['id' => $id]);
    $expected = $query->fetchAll(PDO::FETCH_COLUMN);
    check($history['data'][0]['telemetry_id'] === (int) $expected[0]
        && $historyNext['data'][0]['telemetry_id'] === (int) $expected[1] && $history['meta']['has_more'], 'Paginacion del historial incorrecta.');
    $filtered = status('GET', "/api/v1/devices/$id/telemetry?time_field=timestamp&from=1700000000&to=1700000001&limit=100", $token, 200);
    $query = $pdo->prepare('SELECT id FROM telemetry WHERE device_id = :id AND ts >= 1700000000 AND ts < 1700000001 ORDER BY ts, id LIMIT 100');
    $query->execute(['id' => $id]);
    check(array_column($filtered['data'], 'telemetry_id') === array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN)), 'Filtro por timestamp incorrecto.');
    $filtered = status('GET', "/api/v1/devices/$id/telemetry?time_field=received_at&from=0&to=1", $token, 200);
    $query = $pdo->prepare("SELECT id FROM telemetry WHERE device_id = :id AND received_at >= '1970-01-01 00:00:00' AND received_at < '1970-01-01 00:00:01' ORDER BY received_at, id LIMIT 25");
    $query->execute(['id' => $id]);
    check(array_column($filtered['data'], 'telemetry_id') === array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN)), 'Filtro received_at incorrecto.');
    echo "PASS historial: admin/dispositivo, paginacion y filtros de ambas fuentes temporales.\n";

    $configPath = "/api/v1/devices/$id/config";
    $saved = status('PUT', $configPath, $admin, 200, json_encode(['key' => $key, 'value' => ['interval_ms' => 1000]]));
    check($saved['data']['persisted'] === true && $saved['data']['value']['interval_ms'] === 1000
        && str_ends_with($saved['data']['updated_at'], 'Z'), 'Respuesta de configuracion incorrecta.');
    status('PUT', $configPath, $admin, 200, json_encode(['key' => $key, 'value' => null]));
    $query = $pdo->prepare('SELECT `value` FROM configurations WHERE device_id = :id AND `key` = :key');
    $query->execute(['id' => $id, 'key' => $key]);
    $rows = $query->fetchAll(PDO::FETCH_COLUMN);
    check(count($rows) === 1 && json_decode($rows[0]) === null, 'Configuracion no persistida o duplicada.');
    echo "PASS config: insercion/actualizacion, valor JSON null y una fila por dispositivo/key.\n";

    $events = status('GET', "/api/v1/events?device_id=$id&type=$key&limit=1", $admin, 200);
    $eventsNext = status('GET', "/api/v1/events?device_id=$id&type=$key&limit=1&offset=1", $admin, 200);
    check(count($events['data']) === 1 && $events['meta']['has_more']
        && $events['data'][0]['id'] !== $eventsNext['data'][0]['id'], 'Paginacion de eventos incorrecta.');
    $events = status('GET', "/api/v1/events?type=$key&severity=warning&from=0&to=253402300799", $admin, 200);
    check(count($events['data']) === 1 && $events['data'][0]['severity'] === 'warning', 'Filtros de eventos incorrectos.');
    $emptyType = 'empty_' . bin2hex(random_bytes(8));
    $exists = $pdo->prepare('SELECT COUNT(*) FROM events WHERE type = :type');
    $exists->execute(['type' => $emptyType]);
    check((int) $exists->fetchColumn() === 0, 'Colision de filtro vacio; ejecute de nuevo.');
    $empty = status('GET', '/api/v1/events?type=' . $emptyType, $admin, 200);
    check($empty['data'] === [] && $empty['meta']['count'] === 0, 'Lista vacia de eventos incorrecta.');
    echo "PASS events: registros existentes, filtros, paginacion y lista vacia.\n";

    foreach (['/api/v1/devices?limit=101', '/api/v1/devices?offset=-1', '/api/v1/devices?limit[]=1',
        '/api/v1/devices?unknown=1', '/api/v1/devices/abc/telemetry', '/api/v1/devices/99999999999999999999999/telemetry',
        "/api/v1/devices/$id/telemetry?from=20&to=10", "/api/v1/devices/$id/telemetry?time_field=invalid",
        '/api/v1/events?severity=invalid', '/api/v1/events?device_id=abc', '/api/v1/events?from[]=0'] as $path) {
        status('GET', $path, $admin, 422);
    }
    foreach (['{}', '{"key":"bad key","value":1}', '{"key":"sample"}', '[]',
        json_encode(['key' => $key, 'value' => str_repeat('x', 4097)]),
        '{"key":"sample","value":1,"extra":true}'] as $body) {
        status('PUT', $configPath, $admin, 422, $body);
    }
    status('PUT', $configPath, $admin, 400, '{');
    status('POST', '/api/v1/devices', $admin, 405);
    echo "PASS errores: parametros/JSON invalidos, limites 422, JSON malformado 400 y metodo 405.\n";
    echo "C2 OK. Se conservan fixtures aislados: dos telemetrias, tres eventos y una configuracion por ejecucion.\n";
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    $message = get_class($error) === RuntimeException ? $error->getMessage() : 'Error interno de verificacion.';
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

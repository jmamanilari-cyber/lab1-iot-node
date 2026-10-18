<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

function expect(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException($label); }
}

function request(string $url, string $token, string $body): array
{
    $options = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
        'content' => $body, 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0]];
    $result = @file_get_contents($url, false, stream_context_create($options));
    if ($result === false || !isset($http_response_header[0])) {
        throw new RuntimeException('Servidor HTTP no disponible.');
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    $decoded = json_decode($result, true, 32, JSON_THROW_ON_ERROR);
    expect(isset($decoded['ok'], $decoded['meta']['api_version']), 'Respuesta JSON inconsistente.');
    return [(int) ($match[1] ?? 0), $decoded];
}

try {
    $base = $argv[1] ?? 'http://127.0.0.1:8000';
    // Prevent sending test secrets to an unintended external server.
    $parts = parse_url($base);
    expect(is_array($parts) && ($parts['scheme'] ?? '') === 'http'
        && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true)
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
        && in_array($parts['path'] ?? '', ['', '/'], true), 'Use una URL HTTP local sin ruta.');
    $path = dirname(__DIR__) . '/config/test-tokens.local.json';
    expect(is_file($path), 'Falta aprovisionar tokens de prueba.');
    $tokens = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    $token = $tokens['esp32-01'] ?? '';
    expect(is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1, 'Token local no disponible.');
    $pdo = \Lab2\Database::connect();
    $device = (new \Lab2\DeviceRepository($pdo))->authenticate($token);
    expect($device !== null && $device['node_id'] === 'esp32-01', 'Token local no coincide con MariaDB.');
    $count = $pdo->prepare('SELECT COUNT(*) FROM telemetry WHERE device_id = :device AND seq = :seq');
    do {
        $seq = random_int(1000000000000, 8000000000000000);
        $count->execute(['device' => $device['id'], 'seq' => $seq]);
    } while ((int) $count->fetchColumn() !== 0);
    $url = rtrim($base, '/') . '/api/v1/telemetry';
    $payload = ['node_id' => 'esp32-01', 'seq' => $seq, 'timestamp' => null, 'value' => 1,
        'rssi' => -60, 'extensions' => (object) ['test' => 'C1', 'run' => (string) $seq]];
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    [$status, $created] = request($url, $token, $json);
    expect($status === 201 && $created['ok'] === true && $created['data']['timestamp'] === null
        && str_ends_with($created['data']['received_at'], 'Z'), 'T01: creacion o timestamp incorrecto.');
    $count->execute(['device' => $device['id'], 'seq' => $seq]);
    expect((int) $count->fetchColumn() === 1, 'T01: no existe exactamente un registro.');
    echo "PASS T01: 201, registro unico, timestamp null y received_at UTC.\n";

    $rejected = $payload;
    $rejected['seq'] = $seq + 1;
    $rejectedJson = json_encode($rejected, JSON_THROW_ON_ERROR);
    [$status] = request($url, bin2hex(random_bytes(32)), $rejectedJson);
    expect($status === 401, 'T02: token invalido no devuelve 401.');
    [$status] = request($url, '', $rejectedJson);
    expect($status === 401, 'T02: token ausente no devuelve 401.');
    echo "PASS T02: tokens invalido y ausente rechazados (401).\n";

    $missing = $rejected;
    unset($missing['rssi']);
    [$status, $body] = request($url, $token, json_encode($missing));
    expect($status === 422 && isset($body['error']['details']['rssi']), 'T03: campo ausente sin detalle 422.');
    echo "PASS T03: campo ausente rechazado con detalle (422).\n";

    [$status, $duplicate] = request($url, $token, $json);
    expect($status === 200 && $duplicate['data'] === $created['data'], 'T04: duplicado no devuelve el registro original.');
    $conflict = $payload;
    $conflict['value'] = 0;
    [$status] = request($url, $token, json_encode($conflict));
    expect($status === 409, 'T04: contenido diferente sin conflicto 409.');
    $count->execute(['device' => $device['id'], 'seq' => $seq]);
    expect((int) $count->fetchColumn() === 1, 'T04: persistencia duplicada.');
    echo "PASS T04: duplicado identico 200, conflicto 409, una sola fila.\n";

    [$status] = request($url, $token, '{');
    expect($status === 400, 'JSON malformado sin 400.');
    $wrong = $rejected;
    $wrong['node_id'] = 'esp32-02';
    [$status] = request($url, $token, json_encode($wrong));
    expect($status === 403, 'Identidad diferente sin 403.');
    foreach (['seq' => -1, 'timestamp' => '2026', 'value' => true, 'rssi' => -128, 'extensions' => []] as $field => $invalid) {
        $bad = $rejected;
        $bad[$field] = $invalid;
        [$status] = request($url, $token, json_encode($bad));
        expect($status === 422, 'Validacion de limites incorrecta.');
    }
    $count->execute(['device' => $device['id'], 'seq' => $rejected['seq']]);
    expect((int) $count->fetchColumn() === 0, 'Los casos rechazados insertaron datos.');
    echo "PASS adicionales: 400, 403 y limites/tipos 422.\n";
    echo "La fila de prueba se conserva; cada ejecucion utiliza una secuencia nueva.\n";
} catch (Throwable $error) {
    // Never print transport warnings, response bodies, tokens or database exceptions.
    $message = get_class($error) === RuntimeException ? $error->getMessage() : 'Error interno de verificacion.';
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

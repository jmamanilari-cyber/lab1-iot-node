<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/HttpEvidence.php';
ini_set('display_errors', '0');

final class C1Failure extends RuntimeException {}
$evidence = null;

function expect(bool $condition, string $label): void
{
    global $evidence;
    $evidence?->check($condition, $label);
    if (!$condition) { throw new C1Failure($label); }
}

function request(string $url, string $token, string $body, string $name = '', string $mode = 'valid'): array
{
    global $evidence;
    $evidence?->rememberSecret($token);
    $evidence?->startRequest($name, $url, $body, $mode);
    // Ausencia real de la cabecera, no un Bearer vacio.
    $header = "Content-Type: application/json\r\n";
    if ($token !== '') { $header .= "Authorization: Bearer {$token}\r\n"; }
    $options = ['http' => ['method' => 'POST', 'header' => $header,
        'content' => $body, 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0]];
    $result = @file_get_contents($url, false, stream_context_create($options));
    $status = null;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
        $status = (int) $match[1];
    }
    if ($result === false || !isset($http_response_header[0])) {
        $evidence?->response($name, $status, null, 'HTTP_NO_RESPONSE');
        expect(false, 'Servidor HTTP no disponible.');
    }
    try { $decoded = json_decode($result, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) {
        $evidence?->response($name, $status, null, 'INVALID_JSON');
        expect(false, 'La respuesta HTTP no contiene JSON valido.');
    }
    $evidence?->response($name, $status, is_array($decoded) ? $decoded : null,
        is_array($decoded) ? null : 'INVALID_JSON_STRUCTURE');
    expect(is_array($decoded), 'Respuesta JSON no estructurada.');
    expect(isset($decoded['ok'], $decoded['meta']['api_version']), 'Respuesta JSON inconsistente.');
    return [$status ?? 0, $decoded];
}

/** Solo SELECT; todas las consultas se ligan al nodo y secuencia de esta ejecucion. */
function sqlSnapshot(PDO $pdo, string $node, int $seq, string $label): array
{
    global $evidence;
    $where = ' FROM telemetry t JOIN devices d ON d.id = t.device_id WHERE d.node_id = :node_id AND t.seq = :seq';
    $countSql = 'SELECT COUNT(*)' . $where;
    $rowsSql = 'SELECT t.id, d.node_id, t.seq, t.ts, t.received_at, t.value, t.rssi, t.extensions' . $where . ' ORDER BY t.id';
    $params = ['node_id' => $node, 'seq' => $seq];
    $count = $pdo->prepare($countSql);
    $count->execute($params);
    $observed = (int) $count->fetchColumn();
    $query = $pdo->prepare($rowsSql);
    $query->execute($params);
    $rows = $query->fetchAll();
    $evidence?->sql($label, $countSql, $rowsSql, $params, $observed, $rows);
    expect($observed === count($rows), 'SQL: conteo y filas observadas coinciden.');
    return ['count' => $observed, 'rows' => $rows];
}

$exitCode = 0;
$stage = 'preparacion';
try {
    $args = array_slice($argv, 1);
    $capture = in_array('--evidence', $args, true);
    if ($capture) { $evidence = new HttpEvidence(dirname(__DIR__) . '/logs/evidence/T01-T04'); }
    $urls = array_values(array_filter($args, static fn(string $arg): bool => $arg !== '--evidence'));
    expect(count($urls) <= 1 && (!isset($urls[0]) || !str_starts_with($urls[0], '--')),
        'Uso: php tests/c1-http.php [URL HTTP local] [--evidence].');
    $base = $urls[0] ?? ($capture ? 'http://127.0.0.1:18082' : 'http://127.0.0.1:8000');
    // Prevent sending test secrets to an unintended external server.
    $parts = parse_url($base);
    expect(is_array($parts) && ($parts['scheme'] ?? '') === 'http'
        && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true)
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
        && in_array($parts['path'] ?? '', ['', '/'], true), 'Use una URL HTTP local sin ruta.');
    $url = rtrim($base, '/') . '/api/v1/telemetry';
    $evidence?->endpoint($url);
    $stage = 'credenciales locales';
    $path = dirname(__DIR__) . '/config/test-tokens.local.json';
    expect(is_file($path), 'Falta aprovisionar tokens de prueba.');
    $tokens = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    foreach ($tokens as $secret) { if (is_string($secret)) { $evidence?->rememberSecret($secret); } }
    $token = $tokens['esp32-01'] ?? '';
    expect(is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1, 'Token local no disponible.');
    $stage = 'conexion SQL';
    $pdo = \Lab2\Database::connect();
    $device = (new \Lab2\DeviceRepository($pdo))->authenticate($token);
    expect($device !== null && $device['node_id'] === 'esp32-01', 'Token local no coincide con MariaDB.');
    // Bloque alto y libre para T01/T04, T02, T03 y adicionales. No tocar contadores ESP32.
    $count = $pdo->prepare('SELECT COUNT(*) FROM telemetry WHERE seq BETWEEN :first AND :last');
    do {
        $seq = random_int(1000000000000, 8000000000000000);
        $count->execute(['first' => $seq, 'last' => $seq + 3]);
    } while ((int) $count->fetchColumn() !== 0);

    $stage = 'T01';
    $evidence?->begin('T01');
    expect(sqlSnapshot($pdo, 'esp32-01', $seq, 'before')['count'] === 0, 'T01: secuencia inicialmente libre.');
    $payload = ['node_id' => 'esp32-01', 'seq' => $seq, 'timestamp' => null, 'value' => 1,
        'rssi' => -60, 'extensions' => (object) ['test' => 'C1', 'run' => (string) $seq]];
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    [$status, $created] = request($url, $token, $json, 'valid');
    expect($status === 201 && $created['ok'] === true && $created['data']['timestamp'] === null
        && str_ends_with($created['data']['received_at'], 'Z'), 'T01: creacion o timestamp incorrecto.');
    $original = sqlSnapshot($pdo, 'esp32-01', $seq, 'after_create');
    expect($original['count'] === 1, 'T01: no existe exactamente un registro.');
    $row = $original['rows'][0];
    expect((int) $row['seq'] === $seq && $row['ts'] === null && (int) $row['value'] === $payload['value']
        && (int) $row['rssi'] === $payload['rssi']
        && \Lab2\TelemetryService::canonical(json_decode($row['extensions'])) === \Lab2\TelemetryService::canonical($payload['extensions']),
        'T01: SQL conserva el contenido enviado.');
    $evidence?->pass('HTTP 201 y una fila SQL con el contenido enviado; timestamp null y recepcion UTC.');
    echo "PASS T01: 201, registro unico, timestamp null y received_at UTC.\n";

    $rejected = $payload;
    $rejected['seq'] = $seq + 1;
    $rejectedJson = json_encode($rejected, JSON_THROW_ON_ERROR);
    $stage = 'T02';
    $evidence?->begin('T02');
    expect(sqlSnapshot($pdo, 'esp32-01', $rejected['seq'], 'before')['count'] === 0, 'T02: secuencia inicialmente libre.');
    [$status] = request($url, bin2hex(random_bytes(32)), $rejectedJson, 'invalid_credential', 'invalid');
    expect($status === 401, 'T02: token invalido no devuelve 401.');
    expect(sqlSnapshot($pdo, 'esp32-01', $rejected['seq'], 'after_invalid')['count'] === 0, 'T02: token invalido no inserta filas.');
    [$status] = request($url, '', $rejectedJson, 'missing_credential', 'absent');
    expect($status === 401, 'T02: token ausente no devuelve 401.');
    expect(sqlSnapshot($pdo, 'esp32-01', $rejected['seq'], 'after_missing')['count'] === 0, 'T02: sin token no se insertan filas.');
    $evidence?->pass('Ambas peticiones devolvieron HTTP 401 y SQL confirmo cero filas.');
    echo "PASS T02: tokens invalido y ausente rechazados (401).\n";

    $missing = $rejected;
    $missing['seq'] = $seq + 2;
    unset($missing['rssi']);
    $stage = 'T03';
    $evidence?->begin('T03');
    expect(sqlSnapshot($pdo, 'esp32-01', $missing['seq'], 'before')['count'] === 0, 'T03: secuencia inicialmente libre.');
    [$status, $body] = request($url, $token, json_encode($missing, JSON_THROW_ON_ERROR), 'missing_rssi');
    expect($status === 422 && isset($body['error']['details']['rssi']), 'T03: campo ausente sin detalle 422.');
    expect(sqlSnapshot($pdo, 'esp32-01', $missing['seq'], 'after_validation')['count'] === 0, 'T03: el JSON incompleto no inserta filas.');
    $evidence?->pass('HTTP 422 con detalle rssi; SQL confirmo cero filas.');
    echo "PASS T03: campo ausente rechazado con detalle (422).\n";

    $stage = 'T04';
    $evidence?->begin('T04');
    [$status, $duplicate] = request($url, $token, $json, 'duplicate');
    expect($status === 200 && $duplicate['data'] === $created['data'], 'T04: duplicado no devuelve el registro original.');
    expect(sqlSnapshot($pdo, 'esp32-01', $seq, 'after_duplicate') === $original, 'T04: SQL conserva la fila original tras el duplicado.');
    $conflict = $payload;
    $conflict['value'] = 0;
    [$status] = request($url, $token, json_encode($conflict, JSON_THROW_ON_ERROR), 'conflict');
    expect($status === 409, 'T04: contenido diferente sin conflicto 409.');
    expect(sqlSnapshot($pdo, 'esp32-01', $seq, 'after_conflict') === $original, 'T04: una sola fila SQL, sin alterar datos originales.');
    $evidence?->pass('Duplicado HTTP 200, conflicto HTTP 409; ambas consultas SQL conservaron la unica fila original.');
    echo "PASS T04: duplicado identico 200, conflicto 409, una sola fila.\n";

    $stage = 'adicionales';
    $evidence?->begin('ADDITIONAL');
    [$status] = request($url, $token, '{', 'malformed_json');
    expect($status === 400, 'JSON malformado sin 400.');
    $extra = $rejected;
    $extra['seq'] = $seq + 3;
    $wrong = $extra;
    $wrong['node_id'] = 'esp32-02';
    [$status] = request($url, $token, json_encode($wrong, JSON_THROW_ON_ERROR), 'identity_mismatch');
    expect($status === 403, 'Identidad diferente sin 403.');
    foreach (['seq' => -1, 'timestamp' => '2026', 'value' => true, 'rssi' => -128, 'extensions' => []] as $field => $invalid) {
        $bad = $extra;
        $bad[$field] = $invalid;
        [$status] = request($url, $token, json_encode($bad, JSON_THROW_ON_ERROR), 'invalid_' . $field);
        expect($status === 422, 'Validacion de limites incorrecta.');
    }
    expect(sqlSnapshot($pdo, 'esp32-01', $extra['seq'], 'after_rejections')['count'] === 0, 'Los casos rechazados insertaron datos.');
    expect(sqlSnapshot($pdo, 'esp32-02', $extra['seq'], 'other_node')['count'] === 0, 'El rechazo de identidad no inserto datos del otro nodo.');
    $evidence?->pass('Casos adicionales separados: HTTP 400, 403 y cinco variantes 422; sin filas nuevas para sus secuencias.');
    echo "PASS adicionales: 400, 403 y limites/tipos 422.\n";
    echo "La fila de prueba se conserva; cada ejecucion utiliza una secuencia nueva.\n";
    $evidence?->finish();
} catch (Throwable $error) {
    // Never print transport warnings, response bodies, tokens or database exceptions.
    $exitCode = 1;
    $message = $error instanceof C1Failure ? $error->getMessage() : "Fallo en {$stage}; revise dependencias o escritura local sin publicar detalles privados.";
    fwrite(STDERR, "FAIL: {$message}\n");
    if ($evidence !== null) {
        try { $evidence->fail($message); }
        catch (Throwable) { fwrite(STDERR, "No se pudo actualizar la evidencia; se conserva el ultimo checkpoint disponible.\n"); }
    }
}
if ($evidence !== null) { $evidence->console($exitCode); }
exit($exitCode);

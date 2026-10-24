<?php
declare(strict_types=1);

// Entrada recomendada: scripts/test-mqtt-d2.ps1 (verifica instancia/-c antes y despues).
// Transporte nativo PHP/OpenSSL: IP de loopback para TCP, peer_name para CA/SAN/SNI.
// Credenciales solo en memoria; no argv, temporales, volcados ni pruebas de ingesta.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
ini_set('display_errors', '0');
ini_set('log_errors', '0');

function d2String(string $value): string {
    if (strlen($value) > 65535) { throw new RuntimeException('Campo MQTT demasiado grande.'); }
    return pack('n', strlen($value)) . $value;
}
function d2Length(int $value): string {
    if ($value < 0 || $value > 65535) { throw new RuntimeException('Longitud MQTT fuera del limite de prueba.'); }
    $bytes = '';
    do {
        $digit = $value % 128;
        $value = intdiv($value, 128);
        $bytes .= chr($digit | ($value ? 128 : 0));
    } while ($value);
    return $bytes;
}

final class D2Mqtt {
    private $stream;
    public function __construct(string $ca) {
        $context = stream_context_create(['ssl' => [
            'cafile' => $ca, 'verify_peer' => true, 'verify_peer_name' => true,
            'peer_name' => 'telemetry.lab.test', 'SNI_enabled' => true,
            'allow_self_signed' => false, 'disable_compression' => true,
        ]]);
        // No DNS ni IP fija de laptop. TLS usa el nombre, no la IP, como identidad.
        $this->stream = @stream_socket_client('tcp://127.0.0.1:8883', $errno, $error,
            5, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($this->stream)) { throw new RuntimeException('Listener 8883 no accesible.'); }
        stream_set_timeout($this->stream, 5);
        if (@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT |
            STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
            fclose($this->stream);
            throw new RuntimeException('BLOQUEO TLS: CA, certificado o nombre telemetry.lab.test no verificado.');
        }
    }
    public function __destruct() {
        if (is_resource($this->stream)) { fclose($this->stream); }
    }
    private function read(int $length): string {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($this->stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') { throw new RuntimeException('Respuesta MQTT incompleta o timeout.'); }
            $data .= $chunk;
        }
        return $data;
    }
    private function send(int $header, string $body): void {
        $packet = chr($header) . d2Length(strlen($body)) . $body;
        $offset = 0;
        while ($offset < strlen($packet)) {
            $sent = @fwrite($this->stream, substr($packet, $offset));
            if ($sent === false || $sent === 0) { throw new RuntimeException('Envio MQTT incompleto.'); }
            $offset += $sent;
        }
    }
    private function packet(int $expected): string {
        $header = ord($this->read(1));
        $length = 0;
        $multiplier = 1;
        for ($i = 0; $i < 4; ++$i) {
            $digit = ord($this->read(1));
            $length += ($digit & 127) * $multiplier;
            if (!($digit & 128)) {
                if ($length > 65535 || $header !== $expected) {
                    throw new RuntimeException('Tipo/longitud de respuesta MQTT inesperados.');
                }
                return $length ? $this->read($length) : '';
            }
            $multiplier *= 128;
        }
        throw new RuntimeException('Remaining Length MQTT invalido.');
    }
    private function properties(string $body, int $offset): void {
        $length = 0;
        $multiplier = 1;
        for ($i = 0; $i < 4 && $offset < strlen($body); ++$i) {
            $digit = ord($body[$offset++]);
            $length += ($digit & 127) * $multiplier;
            if (!($digit & 128)) {
                if ($offset + $length === strlen($body)) { return; }
                break;
            }
            $multiplier *= 128;
        }
        throw new RuntimeException('Longitud de propiedades MQTT invalida.');
    }
    public function connect(?array $credentials): int {
        $flags = $credentials === null ? 0x02 : 0xC2; // Clean Start; sin Will ni sesion persistente.
        $body = d2String('MQTT') . chr(5) . chr($flags) . pack('n', 15) . "\0"
              . d2String('d2-security-' . bin2hex(random_bytes(8)));
        if ($credentials !== null) { $body .= d2String($credentials[0]) . d2String($credentials[1]); }
        $this->send(0x10, $body);
        unset($body);
        $ack = $this->packet(0x20); // CONNACK: flags, reason code, propiedades.
        if (strlen($ack) < 3 || ord($ack[0]) !== 0) { throw new RuntimeException('CONNACK invalido.'); }
        $this->properties($ack, 2);
        return ord($ack[1]);
    }
    public function publish(string $topic): int {
        // Texto no JSON: mqtt-subscriber.php lo rechaza ANTES de Database::connect().
        // QoS 1, retain=false y sin Will: no queda telemetria retenida ni sesion duradera.
        $payload = 'D2-security-probe:' . bin2hex(random_bytes(8));
        $this->send(0x32, d2String($topic) . pack('n', 1) . "\0" . $payload);
        $ack = $this->packet(0x40); // PUBACK: packet id, reason code opcional, propiedades.
        if (strlen($ack) < 2 || unpack('nid', substr($ack, 0, 2))['id'] !== 1) {
            throw new RuntimeException('PUBACK no corresponde al paquete enviado.');
        }
        if (strlen($ack) > 3) { $this->properties($ack, 3); }
        return strlen($ack) === 2 ? 0 : ord($ack[2]);
    }
    public function disconnect(): void { $this->send(0xE0, ''); }
}

function d2Credentials(string $path, string $user): array {
    $text = @file_get_contents($path);
    if ($text === false) { throw new RuntimeException('No se pudo cargar archivo privado de opciones D1.'); }
    $options = [];
    foreach (preg_split('/\r?\n/', trim($text)) as $line) {
        if (!preg_match('/\A(-[hpuPVq])\s+(.+)\z/', $line, $match) || isset($options[$match[1]])) {
            throw new RuntimeException('Formato privado de opciones D1 no compatible.');
        }
        $options[$match[1]] = $match[2];
    }
    if (($options['-u'] ?? '') !== $user || ($options['-P'] ?? '') === '') {
        throw new RuntimeException('Identidad privada D1 distinta de la esperada.');
    }
    return [$user, $options['-P']];
}

try {
    if (!extension_loaded('openssl') || !defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
        throw new RuntimeException('BLOQUEO: PHP/OpenSSL no ofrece el transporte TLS requerido.');
    }
    $config = Lab2\MqttRuntime::config(); // Reutilizar rutas locales, no regenerar credenciales.
    $ca = getenv('LOCALAPPDATA') . '/Lab2TLS/ca.crt';
    if (!is_file($ca)) { throw new RuntimeException('BLOQUEO: falta CA local D2.'); }
    $nodes = ['esp32-01', 'esp32-02', 'esp32-03'];
    if (array_keys($config['publisher_options']) !== $nodes) { throw new RuntimeException('Identidades D1 inesperadas.'); }
    $failed = 0;
    $count = 0;
    $result = static function (string $label, string $kind, int $code, array $expected) use (&$failed, &$count): void {
        ++$count;
        $ok = in_array($code, $expected, true);
        if (!$ok) { ++$failed; }
        printf("[%s] %s | %s=%d | esperado=%s\n", $ok ? 'OK' : 'FAIL', $label, $kind, $code, implode('/', $expected));
    };
    foreach ($nodes as $node) {
        $credentials = d2Credentials($config['publisher_options'][$node], $node);
        foreach (array_merge($nodes, ['outside', 'suffix']) as $target) {
            $client = new D2Mqtt($ca);
            $connack = $client->connect($credentials);
            $result("$node autenticacion", 'CONNACK', $connack, [0]);
            if ($connack !== 0) { throw new RuntimeException('Control positivo de autenticacion fallido.'); }
            $topic = $target === 'outside' ? "lab2/$node/status"
                : ($target === 'suffix' ? "lab2/$node/telemetry/extra" : "lab2/$target/telemetry");
            $result("$node -> $topic", 'PUBACK', $client->publish($topic), $target === $node ? [0, 16] : [135]);
            $client->disconnect();
            unset($client);
        }
        unset($credentials);
    }
    foreach (['anonymous', 'bad-password'] as $negative) {
        $credentials = null;
        if ($negative === 'bad-password') {
            $credentials = d2Credentials($config['publisher_options']['esp32-01'], 'esp32-01');
            do { $wrong = bin2hex(random_bytes(32)); } while (hash_equals($credentials[1], $wrong));
            $credentials[1] = $wrong;
            unset($wrong);
        }
        $client = new D2Mqtt($ca);
        $connack = $client->connect($credentials);
        $result($negative, 'CONNACK', $connack, [134, 135]);
        if ($connack === 0) { $client->disconnect(); }
        unset($client, $credentials);
    }
    $credentials = d2Credentials($config['subscriber_options'], 'lab2-subscriber');
    foreach ($nodes as $node) {
        $client = new D2Mqtt($ca);
        $connack = $client->connect($credentials);
        $result('subscriber autenticacion', 'CONNACK', $connack, [0]);
        if ($connack !== 0) { throw new RuntimeException('Control positivo subscriber fallido.'); }
        $result("subscriber escribe $node", 'PUBACK', $client->publish("lab2/$node/telemetry"), [135]);
        $client->disconnect();
        unset($client);
    }
    unset($credentials);
    printf("TOTAL D2: %d comprobaciones CONNACK/PUBACK, %d discrepancias. CA/nombre verificados en cada conexion.\n", $count, $failed);
    // No tocar ni leer security.json D1; no se emite evidencia persistente.
    exit($failed ? 1 : 0);
} catch (Throwable $error) {
    // Solo errores propios sanitizados: nunca trazas, argv, opciones ni contexto SSL.
    $safe = $error instanceof RuntimeException && get_class($error) === RuntimeException::class;
    fwrite(STDERR, 'FAIL D2: ' . ($safe ? $error->getMessage() : 'prueba incompleta; revisar archivos privados sin publicarlos.') . "\n");
    exit(1);
}

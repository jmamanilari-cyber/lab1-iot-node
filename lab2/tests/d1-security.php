<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

use Lab2\MqttRuntime;

try {
    $c = MqttRuntime::config();
    $proof = $c['state_dir'] . '/security.json';
    if (is_file($proof)) { unlink($proof); }
    $publish = static function (string $options, string $topic) use ($c): int {
        [$code, $output] = MqttRuntime::command([$c['pub'], '-o', $options, '-t', $topic, '-m', 'D1-security-probe', '-d']);
        if ($code !== 0) { return $code; }
        // mosquitto_pub can exit 0 even when a MQTT 5 PUBACK rejects publication.
        if (!preg_match('/received PUBACK .*RC:(\d+)/', $output, $match)) { throw new RuntimeException(); }
        return (int) $match[1];
    };
    $subscribe = static function (string $options, string $topic) use ($c): int {
        [$code] = MqttRuntime::command([$c['sub'], '-o', $options, '-t', $topic, '-E']);
        return $code;
    };
    // A positive control rules out treating a completely unavailable broker as secure.
    if ($subscribe($c['subscriber_options'], 'lab2/+/telemetry') !== 0) { throw new RuntimeException(); }
    if ($publish($c['anonymous_options'], 'lab2/esp32-01/telemetry') !== 135) { throw new RuntimeException(); }
    if ($publish($c['invalid_options'], 'lab2/esp32-01/telemetry') !== 135) { throw new RuntimeException(); }
    foreach ($c['publisher_options'] as $node => $options) {
        if ($publish($options, "lab2/$node/telemetry") >= 128) { throw new RuntimeException(); }
        foreach (array_keys($c['publisher_options']) as $other) {
            if ($other !== $node && $publish($options, "lab2/$other/telemetry") !== 135) { throw new RuntimeException(); }
        }
    }
    if ($publish($c['subscriber_options'], 'lab2/esp32-01/telemetry') !== 135) { throw new RuntimeException(); }
    MqttRuntime::writeAtomic($proof, json_encode(['fingerprint' => MqttRuntime::fingerprint($c),
        'verified_at' => gmdate('c')], JSON_THROW_ON_ERROR));
    echo "PASS seguridad D1: acceso autorizado, anonimo/clave incorrecta rechazados, ACL de escritura por nodo y subscriber sin escritura.\n";
} catch (Throwable) {
    fwrite(STDERR, "FAIL: seguridad MQTT no verificada; no iniciar subscriber. Revise autenticacion y ACL del broker local.\n");
    exit(1);
}

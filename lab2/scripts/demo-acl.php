<?php
declare(strict_types=1);

// Diagnostico observable de las ACL: cada fila corresponde a una publicacion
// MQTT real; solo se muestran codigos de resultado, no opciones ni credenciales.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $c = \Lab2\MqttRuntime::config();
    $cases = [];
    foreach ($c['publisher_options'] as $node => $options) {
        $cases[] = ["$node publica en su propio topico", $options, "lab2/$node/telemetry", 0];
        foreach (array_keys($c['publisher_options']) as $other) {
            if ($other !== $node) {
                $cases[] = ["$node intenta publicar como $other", $options, "lab2/$other/telemetry", 135];
            }
        }
    }
    $cases[] = ['Publicador anonimo', $c['anonymous_options'], 'lab2/esp32-01/telemetry', 135];
    $cases[] = ['Publicador con clave incorrecta', $c['invalid_options'], 'lab2/esp32-01/telemetry', 135];
    $cases[] = ['Suscriptor intenta publicar', $c['subscriber_options'], 'lab2/esp32-01/telemetry', 135];

    echo "=== PETICIONES MQTT REALES AL BROKER 1884 ===\n";
    echo "Motivo MQTT 5: 0=aceptado, 135=no autorizado (ACL/autenticacion).\n";
    $failed = 0;
    foreach ($cases as [$label, $options, $topic, $expected]) {
        [$exit, $output] = \Lab2\MqttRuntime::command([
            $c['pub'], '-o', $options, '-t', $topic, '-m', 'D1-security-probe', '-d'
        ]);
        if ($exit === 0 && preg_match('/received PUBACK .*RC:(\d+)/', $output, $matches)) {
            $code = (int) $matches[1];
            $origin = 'PUBACK';
        } elseif ($exit !== 0) {
            // El cliente tambien devuelve 135 ante CONNECT rechazado.
            $code = $exit;
            $origin = 'salida del cliente';
        } else {
            $code = -1;
            $origin = 'PUBACK no encontrado';
        }
        $ok = $code === $expected;
        if (!$ok) { $failed++; }
        printf("[%s] %-47s | %s=%d | esperado=%d\n",
            $ok ? 'OK' : 'ERROR', $label, $origin, $code, $expected);
    }
    printf("TOTAL: %d comprobaciones MQTT, %d discrepancias.\n", count($cases), $failed);
    if ($failed !== 0) { exit(1); }
} catch (Throwable) {
    fwrite(STDERR, "ERROR: no se pudieron ejecutar las comprobaciones ACL. Verifique broker y archivos MQTT privados.\n");
    exit(1);
}

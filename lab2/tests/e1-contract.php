<?php
declare(strict_types=1);
// Sin HTTP, tokens ni PDO: validar la salida REAL de e1-state.cpp contra el dominio existente.
require dirname(__DIR__) . '/src/bootstrap.php';
try {
    $payload = json_decode(stream_get_contents(STDIN), false, 16, JSON_THROW_ON_ERROR);
    if (!$payload instanceof stdClass) { throw new RuntimeException('Se esperaba objeto JSON.'); }
    $data = \Lab2\TelemetryService::validate($payload);
    if ($data['node_id'] !== 'esp32-02' || $data['seq'] !== 1234 || $data['timestamp'] !== null
        || $data['value'] !== 0 || $data['rssi'] !== -61
        || $payload->extensions->pir_simulated !== true
        || $payload->extensions->pir_source !== 'sequence_parity') {
        throw new RuntimeException('Fixture E1 incorrecto.');
    }
    echo "PASS E1: JSON de firmware aceptado por TelemetryService; PIR simulado y RSSI conservados.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL E1: ' . $error->getMessage() . "\n");
    exit(1);
}

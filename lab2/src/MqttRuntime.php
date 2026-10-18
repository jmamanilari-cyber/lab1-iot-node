<?php
declare(strict_types=1);

namespace Lab2;

final class MqttRuntime
{
    public static function config(): array
    {
        $path = dirname(__DIR__) . '/config/mqtt.local.php';
        if (!is_file($path)) { throw new \RuntimeException('Falta configuracion MQTT privada.'); }
        $config = require $path;
        if (!is_array($config) || ($config['host'] ?? '') !== '127.0.0.1') {
            throw new \RuntimeException('D1 requiere listener privado en loopback.');
        }
        return $config;
    }

    public static function fingerprint(array $config): string
    {
        $hash = hash_init('sha256');
        foreach (['broker_config', 'acl_file', 'password_file', 'subscriber_options', 'anonymous_options', 'invalid_options'] as $key) {
            if (!is_file($config[$key])) { throw new \RuntimeException('Falta archivo MQTT privado.'); }
            hash_update_file($hash, $config[$key]);
        }
        foreach ($config['publisher_options'] as $path) { hash_update_file($hash, $path); }
        hash_update($hash, json_encode($config, JSON_THROW_ON_ERROR));
        return hash_final($hash);
    }

    public static function command(array $args, int $timeout = 8): array
    {
        $capture = tempnam(dirname(__DIR__) . '/config', 'mqtt-command-');
        if ($capture === false) { throw new \RuntimeException('No se pudo preparar captura MQTT.'); }
        $process = proc_open($args, [0 => ['file', 'NUL', 'r'], 1 => ['file', $capture, 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('No se pudo ejecutar el cliente MQTT.'); }
        // Windows anonymous pipes can block despite stream_set_blocking(false).
        // Capture into a private file and poll process state instead.
        $end = microtime(true) + $timeout;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            usleep(20000);
        } while (microtime(true) < $end);
        if ($status['running']) { proc_terminate($process); }
        $closed = proc_close($process);
        $output = file_get_contents($capture);
        unlink($capture);
        return [$status['running'] ? 124 : ($status['exitcode'] >= 0 ? $status['exitcode'] : $closed), $output];
    }

    public static function writeAtomic(string $path, string $contents): void
    {
        $temporary = tempnam(dirname($path), 'd1-');
        if ($temporary === false || file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
            throw new \RuntimeException('No se pudo persistir el estado MQTT.');
        }
    }

    public static function audit(string $dir, array $record): void
    {
        $record = ['at' => gmdate('Y-m-d\TH:i:s\Z')] + $record;
        if (file_put_contents($dir . '/audit.jsonl', json_encode($record, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo registrar la trazabilidad MQTT.');
        }
    }
}

<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
try {
    $configPath = dirname(__DIR__) . '/config/mqtt.local.php';
    if (is_file($configPath)) { echo "OK: configuracion privada existente conservada.\n"; exit; }
    $config = require dirname(__DIR__) . '/config/mqtt.example.php';
    $dir = dirname($config['broker_config']);
    if (is_dir($dir)) { throw new RuntimeException('Directorio privado ya existe; revisar manualmente.'); }
    if (!mkdir($config['state_dir'], 0700, true)) { throw new RuntimeException(); }
    $common = "-h 127.0.0.1\n-p 1884\n-V mqttv5\n-q 1\n";
    $passwords = '';
    $acl = '';
    foreach (['esp32-01', 'esp32-02', 'esp32-03', 'lab2-subscriber'] as $user) {
        $password = bin2hex(random_bytes(32));
        $passwords .= "$user:$password\n";
        $options = $common . "-u $user\n-P $password\n";
        if ($user === 'lab2-subscriber') {
            $file = $config['subscriber_options'];
            $acl .= "user $user\ntopic read lab2/+/telemetry\n\n";
        } else {
            $file = $config['publisher_options'][$user];
            $acl .= "user $user\ntopic write lab2/$user/telemetry\n\n";
        }
        \Lab2\MqttRuntime::writeAtomic($file, $options);
    }
    \Lab2\MqttRuntime::writeAtomic($config['anonymous_options'], $common);
    \Lab2\MqttRuntime::writeAtomic($config['invalid_options'], $common . "-u esp32-01\n-P " . bin2hex(random_bytes(32)) . "\n");
    \Lab2\MqttRuntime::writeAtomic($config['password_file'], $passwords);
    [$exit] = \Lab2\MqttRuntime::command(['C:/Program Files/mosquitto/mosquitto_passwd.exe', '-U', $config['password_file']]);
    if ($exit !== 0) { throw new RuntimeException('No se pudo convertir el archivo privado de passwords a hashes.'); }
    \Lab2\MqttRuntime::writeAtomic($config['acl_file'], $acl);
    $path = static fn (string $path) => str_replace('\\', '/', $path);
    $broker = "listener 1884 127.0.0.1\nallow_anonymous false\npassword_file " . $path($config['password_file'])
        . "\nacl_file " . $path($config['acl_file']) . "\npersistence true\npersistence_location "
        . $path($config['state_dir']) . "/\npersistence_file broker.db\nautosave_interval 10\n"
        . "log_dest file " . $path($config['state_dir']) . "/broker.log\nlog_type error\nlog_type warning\n";
    \Lab2\MqttRuntime::writeAtomic($config['broker_config'], $broker);
    \Lab2\MqttRuntime::writeAtomic($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");
    echo "OK: instancia aislada preparada en 127.0.0.1:1884; sin cambios al broker 1883. Secretos solo en config/mqtt-private/.\n";
} catch (Throwable) {
    fwrite(STDERR, "ERROR: preparacion MQTT no completada; revisar archivos privados sin publicarlos.\n");
    exit(1);
}

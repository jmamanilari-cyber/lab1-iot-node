<?php
declare(strict_types=1);

// Example only. setup-mqtt.php generates private files for an isolated broker
// on 127.0.0.1:1884; the existing/global broker on 1883 is not changed.
// options files hold -h, -p, -u, -P, -V mqttv5, -q 1. Never put passwords in argv.
return [
    'host' => '127.0.0.1', 'port' => 1884,
    'sub' => 'C:/Program Files/mosquitto/mosquitto_sub.exe',
    'pub' => 'C:/Program Files/mosquitto/mosquitto_pub.exe',
    'subscriber_options' => __DIR__ . '/mqtt-private/subscriber.options',
    'publisher_options' => [
        'esp32-01' => __DIR__ . '/mqtt-private/esp32-01.options',
        'esp32-02' => __DIR__ . '/mqtt-private/esp32-02.options',
        'esp32-03' => __DIR__ . '/mqtt-private/esp32-03.options',
    ],
    'anonymous_options' => __DIR__ . '/mqtt-private/anonymous.options',
    'invalid_options' => __DIR__ . '/mqtt-private/invalid.options',
    'broker_config' => __DIR__ . '/mqtt-private/broker.conf',
    'acl_file' => __DIR__ . '/mqtt-private/broker.acl',
    'password_file' => __DIR__ . '/mqtt-private/passwords',
    'state_dir' => __DIR__ . '/mqtt-private/runtime',
];

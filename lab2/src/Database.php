<?php
declare(strict_types=1);

namespace Lab2;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    public static function connect(): PDO
    {
        $path = dirname(__DIR__) . '/config/database.local.php';
        if (!is_file($path)) {
            throw new RuntimeException('Falta config/database.local.php; copie el ejemplo y configure sus credenciales.');
        }

        $config = require $path;
        if (!is_array($config)) {
            throw new RuntimeException('Configuracion de base de datos invalida.');
        }
        foreach (['host', 'database', 'username', 'password'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                throw new RuntimeException('Configuracion de base de datos incompleta.');
            }
        }
        if (!preg_match('/\A[A-Za-z0-9.-]+\z/', $config['host'])
            || !preg_match('/\A[A-Za-z0-9_]+\z/', $config['database'])
            || !isset($config['port']) || !is_int($config['port'])
            || $config['port'] < 1 || $config['port'] > 65535
            || str_starts_with($config['username'], 'REPLACE_')
            || str_starts_with($config['password'], 'REPLACE_')) {
            throw new RuntimeException('Configuracion de base de datos invalida o con marcadores de ejemplo.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'], $config['port'], $config['database']);

        try {
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->exec("SET time_zone = '+00:00'");
            return $pdo;
        } catch (PDOException) {
            // Do not expose driver errors, DSNs or credentials to callers.
            throw new RuntimeException('No se pudo conectar a MariaDB; revise el servicio, la base y la configuracion privada.');
        }
    }
}

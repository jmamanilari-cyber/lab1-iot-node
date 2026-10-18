<?php
declare(strict_types=1);

namespace Lab2;

use PDO;

final class RestAuth
{
    public static function authenticate(PDO $pdo): array
    {
        if (!preg_match('/\ABearer ([a-f0-9]{64})\z/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $match)) {
            throw new ApiException(401, 'unauthorized', 'Token ausente o invalido.');
        }
        $digest = 'sha256:' . hash('sha256', $match[1]);
        $path = dirname(__DIR__) . '/config/admin.local.json';
        if (is_file($path)) {
            $admin = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($admin) || !isset($admin['token_hash']) || !is_string($admin['token_hash'])) {
                throw new \RuntimeException('Invalid private admin configuration.');
            }
            if (hash_equals($admin['token_hash'], $digest)) {
                return ['role' => 'admin'];
            }
        }
        $device = (new DeviceRepository($pdo))->authenticate($match[1]);
        if ($device !== null) {
            return ['role' => 'device'] + $device;
        }
        throw new ApiException(401, 'unauthorized', 'Token ausente o invalido.');
    }

    public static function requireAdmin(array $principal): void
    {
        if ($principal['role'] !== 'admin') {
            throw new ApiException(403, 'forbidden', 'Se requiere autenticacion administrativa.');
        }
    }

    public static function requireDeviceAccess(array $principal, int $id): void
    {
        if ($principal['role'] !== 'admin' && $principal['id'] !== $id) {
            throw new ApiException(403, 'forbidden', 'No tiene acceso a este dispositivo.');
        }
    }
}

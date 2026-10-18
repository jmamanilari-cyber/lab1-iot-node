<?php
declare(strict_types=1);

namespace Lab2;

use PDO;
use stdClass;

final class TelemetryService
{
    private DeviceRepository $devices;
    private TelemetryRepository $telemetry;

    public function __construct(private PDO $pdo)
    {
        $this->devices = new DeviceRepository($pdo);
        $this->telemetry = new TelemetryRepository($pdo);
    }

    /** trustedNodeId must come from an authenticated adapter, never the payload. */
    public function receive(stdClass $payload, int $deviceId, string $trustedNodeId): array
    {
        $data = self::validate($payload);
        if ($data['node_id'] !== $trustedNodeId) {
            throw new ApiException(403, 'identity_mismatch', 'El token no pertenece al nodo solicitado.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->devices->lockForIngestion($deviceId, $trustedNodeId);
            [$created, $row] = $this->telemetry->insertOrExisting($deviceId, $data);
            if (!$created && !self::sameContent($row, $data)) {
                throw new ApiException(409, 'sequence_conflict', 'La secuencia ya existe con otro contenido.');
            }
            $this->devices->seen($deviceId);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return ['created' => $created, 'data' => [
            'telemetry_id' => (int) $row['id'], 'node_id' => $trustedNodeId, 'seq' => (int) $row['seq'],
            'timestamp' => $row['ts'] === null ? null : (int) $row['ts'],
            'received_at' => str_replace(' ', 'T', $row['received_at']) . 'Z',
            'value' => (int) $row['value'], 'rssi' => $row['rssi'] === null ? null : (int) $row['rssi'],
            'extensions' => $row['extensions'] === null ? new stdClass() : json_decode($row['extensions']),
        ]];
    }

    public static function validate(stdClass $payload): array
    {
        $data = get_object_vars($payload);
        $errors = [];
        $required = ['node_id', 'seq', 'timestamp', 'value', 'rssi'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                $errors[$field] = 'Campo obligatorio.';
            }
        }
        foreach (array_diff(array_keys($data), [...$required, 'extensions']) as $field) {
            $errors[$field] = 'Campo desconocido; utilice extensions.';
        }
        if (!isset($data['node_id']) || !is_string($data['node_id'])
            || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $data['node_id'])) {
            $errors['node_id'] = 'Identificador invalido.';
        }
        if (!isset($data['seq']) || !is_int($data['seq']) || $data['seq'] < 0 || $data['seq'] > 9007199254740991) {
            $errors['seq'] = 'Debe ser entero entre 0 y 9007199254740991.';
        }
        if (array_key_exists('timestamp', $data) && $data['timestamp'] !== null
            && (!is_int($data['timestamp']) || $data['timestamp'] < 0 || $data['timestamp'] > 253402300799)) {
            $errors['timestamp'] = 'Debe ser Unix UTC entero entre 0 y 253402300799, o null.';
        }
        if (!isset($data['value']) || (!is_int($data['value']) && !is_float($data['value']))
            || !in_array($data['value'], [0, 1, 0.0, 1.0], true)) {
            $errors['value'] = 'PIR requiere el numero 0 o 1.';
        }
        if (array_key_exists('rssi', $data) && $data['rssi'] !== null
            && (!is_int($data['rssi']) || $data['rssi'] < -127 || $data['rssi'] > 0)) {
            $errors['rssi'] = 'Debe ser entero entre -127 y 0, o null.';
        }
        $extensions = $data['extensions'] ?? new stdClass();
        if ((array_key_exists('extensions', $data) && !$data['extensions'] instanceof stdClass)
            || !self::validExtension($extensions)
            || strlen(json_encode($extensions, JSON_THROW_ON_ERROR)) > 4096) {
            $errors['extensions'] = 'Objeto JSON: maximo 4096 bytes, profundidad 8 y claves de 1 a 64 caracteres.';
        }
        if ($errors) {
            throw new ApiException(422, 'validation_error', 'Telemetria invalida.', $errors);
        }
        $data['extensions'] = self::canonical($extensions);
        $data['value'] = (int) $data['value'];
        return $data;
    }

    private static function validExtension(mixed $value, int $depth = 0): bool
    {
        if ($depth > 8) {
            return false;
        }
        if ($value instanceof stdClass || is_array($value)) {
            foreach ($value as $key => $child) {
                if ($value instanceof stdClass && !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', (string) $key)) {
                    return false;
                }
                if (!self::validExtension($child, $depth + 1)) {
                    return false;
                }
            }
        }
        return !is_float($value) || is_finite($value);
    }

    public static function canonical(mixed $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if ($item instanceof stdClass) {
                $values = get_object_vars($item);
                ksort($values, SORT_STRING);
                $out = new stdClass();
                foreach ($values as $key => $child) {
                    $out->{$key} = $normalize($child);
                }
                return $out;
            }
            return is_array($item) ? array_map($normalize, $item) : $item;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function sameContent(array $row, array $data): bool
    {
        return ($row['ts'] === null ? null : (int) $row['ts']) === $data['timestamp']
            && (int) $row['value'] === $data['value']
            && ($row['rssi'] === null ? null : (int) $row['rssi']) === $data['rssi']
            && self::canonical($row['extensions'] === null ? new stdClass() : json_decode($row['extensions'])) === $data['extensions'];
    }
}

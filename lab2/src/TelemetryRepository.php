<?php
declare(strict_types=1);

namespace Lab2;

use PDO;
use PDOException;

final class TelemetryRepository
{
    public function __construct(private PDO $pdo) {}

    /** Must be called inside the service transaction. */
    public function insertOrExisting(int $deviceId, array $data): array
    {
        try {
            $query = $this->pdo->prepare('INSERT INTO telemetry (device_id, seq, ts, value, rssi, extensions)
                VALUES (:device_id, :seq, :ts, :value, :rssi, :extensions)');
            $query->execute(['device_id' => $deviceId, 'seq' => $data['seq'], 'ts' => $data['timestamp'],
                'value' => $data['value'], 'rssi' => $data['rssi'], 'extensions' => $data['extensions']]);
            $created = true;
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) !== 1062) {
                throw $error;
            }
            $created = false;
        }
        // A locking/current read sees a concurrent winner even under REPEATABLE READ.
        $query = $this->pdo->prepare('SELECT id, seq, ts, received_at, value, rssi, extensions
            FROM telemetry WHERE device_id = :device_id AND seq = :seq FOR UPDATE');
        $query->execute(['device_id' => $deviceId, 'seq' => $data['seq']]);
        $row = $query->fetch();
        if (!$row) {
            throw new \RuntimeException('Telemetry row unavailable.');
        }
        return [$created, $row];
    }
}

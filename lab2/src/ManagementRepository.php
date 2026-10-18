<?php
declare(strict_types=1);

namespace Lab2;

use PDO;

final class ManagementRepository
{
    public function __construct(private PDO $pdo) {}

    public function device(int $id): array
    {
        $query = $this->pdo->prepare('SELECT id, node_id, name, last_seen FROM devices WHERE id = :id');
        $query->execute(['id' => $id]);
        $row = $query->fetch();
        if (!$row) { throw new ApiException(404, 'not_found', 'Dispositivo inexistente.'); }
        return $row;
    }

    public function devices(int $limit, int $offset): array
    {
        return array_map(static fn ($row) => ['id' => (int) $row['id'], 'node_id' => $row['node_id'],
            'name' => $row['name'], 'last_seen' => self::utc($row['last_seen'])],
            $this->rows('SELECT id, node_id, name, last_seen FROM devices ORDER BY id ASC', [], $limit, $offset));
    }

    public function history(int $id, string $timeField, ?int $from, ?int $to, int $limit, int $offset): array
    {
        $this->device($id);
        $column = $timeField === 'timestamp' ? 'ts' : 'received_at';
        $where = ['device_id = :device_id'];
        $params = ['device_id' => $id];
        self::timeWhere($where, $params, $column, $from, $to, $column === 'ts');
        $rows = $this->rows('SELECT id, device_id, seq, ts, received_at, value, rssi, extensions FROM telemetry WHERE '
            . implode(' AND ', $where) . " ORDER BY $column ASC, id ASC", $params, $limit, $offset);
        return array_map(static fn ($row) => ['telemetry_id' => (int) $row['id'], 'device_id' => (int) $row['device_id'],
            'seq' => (int) $row['seq'], 'timestamp' => $row['ts'] === null ? null : (int) $row['ts'],
            'received_at' => self::utc($row['received_at']), 'value' => (float) $row['value'],
            'rssi' => $row['rssi'] === null ? null : (int) $row['rssi'],
            'extensions' => $row['extensions'] === null ? new \stdClass() : json_decode($row['extensions'], false, 32, JSON_THROW_ON_ERROR)], $rows);
    }

    public function events(array $filters, ?int $from, ?int $to, int $limit, int $offset): array
    {
        $where = ['1=1'];
        $params = [];
        foreach (['device_id', 'type', 'severity'] as $field) {
            if (isset($filters[$field])) { $where[] = "$field = :$field"; $params[$field] = $filters[$field]; }
        }
        if (isset($filters['device_id'])) { $this->device($filters['device_id']); }
        self::timeWhere($where, $params, 'ts', $from, $to, false);
        return array_map(static fn ($row) => ['id' => (int) $row['id'],
            'device_id' => $row['device_id'] === null ? null : (int) $row['device_id'],
            'type' => $row['type'], 'severity' => $row['severity'], 'timestamp' => self::utc($row['ts']),
            'detail' => $row['detail'] === null ? null : json_decode($row['detail'], false, 32, JSON_THROW_ON_ERROR)],
            $this->rows('SELECT id, device_id, type, severity, ts, detail FROM events WHERE '
                . implode(' AND ', $where) . ' ORDER BY ts ASC, id ASC', $params, $limit, $offset));
    }

    public function putConfiguration(int $id, string $key, string $json): array
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM devices WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $id]);
            if (!$lock->fetch()) { throw new ApiException(404, 'not_found', 'Dispositivo inexistente.'); }
            $query = $this->pdo->prepare('INSERT INTO configurations (device_id, `key`, `value`)
                VALUES (:device_id, :key, :value) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = UTC_TIMESTAMP(6)');
            $query->execute(['device_id' => $id, 'key' => $key, 'value' => $json]);
            $query = $this->pdo->prepare('SELECT `value`, updated_at FROM configurations WHERE device_id = :id AND `key` = :key');
            $query->execute(['id' => $id, 'key' => $key]);
            $row = $query->fetch();
            $this->pdo->commit();
            return ['device_id' => $id, 'key' => $key, 'value' => json_decode($row['value'], false, 32, JSON_THROW_ON_ERROR),
                'updated_at' => self::utc($row['updated_at']), 'persisted' => true];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private function rows(string $sql, array $params, int $limit, int $offset): array
    {
        $query = $this->pdo->prepare($sql . ' LIMIT :page_limit OFFSET :page_offset');
        foreach ($params as $key => $value) {
            $query->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $query->bindValue(':page_limit', $limit + 1, PDO::PARAM_INT);
        $query->bindValue(':page_offset', $offset, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    private static function timeWhere(array &$where, array &$params, string $column, ?int $from, ?int $to, bool $unix): void
    {
        foreach (['from' => $from, 'to' => $to] as $key => $value) {
            if ($value !== null) {
                $where[] = $column . ($key === 'from' ? ' >= ' : ' < ') . ':' . $key;
                $params[$key] = $unix ? $value : gmdate('Y-m-d H:i:s', $value);
            }
        }
    }

    private static function utc(?string $date): ?string
    {
        return $date === null ? null : str_replace(' ', 'T', $date) . 'Z';
    }
}

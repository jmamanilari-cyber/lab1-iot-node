<?php
declare(strict_types=1);

namespace Lab2;

use PDO;

final class DeviceRepository
{
    public function __construct(private PDO $pdo) {}

    public function authenticate(string $token): ?array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return null;
        }
        // Random 256-bit tokens: SHA-256 is appropriate; no passwords here.
        $digest = 'sha256:' . hash('sha256', $token);
        $query = $this->pdo->prepare('SELECT id, node_id, token_hash FROM devices WHERE token_hash = :hash');
        $query->execute(['hash' => $digest]);
        $device = $query->fetch();
        if (!$device || !hash_equals($device['token_hash'], $digest)) {
            return null;
        }
        return ['id' => (int) $device['id'], 'node_id' => $device['node_id']];
    }

    public function seen(int $id): void
    {
        $query = $this->pdo->prepare('UPDATE devices SET last_seen = UTC_TIMESTAMP(6) WHERE id = :id');
        $query->execute(['id' => $id]);
    }

    public function lockForIngestion(int $id, string $nodeId): void
    {
        // Serialize ingestion per node; avoids duplicate-key lock upgrade deadlocks.
        $query = $this->pdo->prepare('SELECT id FROM devices WHERE id = :id AND node_id = :node_id FOR UPDATE');
        $query->execute(['id' => $id, 'node_id' => $nodeId]);
        if (!$query->fetch()) {
            throw new ApiException(403, 'identity_mismatch', 'Identidad autenticada no disponible.');
        }
    }
}

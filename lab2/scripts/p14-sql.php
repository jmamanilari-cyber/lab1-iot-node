<?php
declare(strict_types=1);

// JSON por stdin; consultas fijas SELECT, sin datos de configuracion en la salida.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
require dirname(__DIR__) . '/src/bootstrap.php';
const P14_NODES = ['esp32-01', 'esp32-02', 'esp32-03'];
function p14Utc(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); }

function p14Snapshot(array $input): array
{
    $out = ['status' => 'INCOMPLETO', 'utc_started' => p14Utc(), 'utc_finished' => null,
        'read_only' => false, 'nodes' => [], 'queries' => [], 'errors' => []];
    $pdo = null; $transaction = false;
    try {
        $pdo = \Lab2\Database::connect();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $transaction = true; $out['read_only'] = true;
        foreach (P14_NODES as $node) {
            $identitySql = 'SELECT id,node_id FROM devices WHERE node_id=:node';
            $q = $pdo->prepare($identitySql); $q->execute(['node' => $node]); $identity = $q->fetchAll();
            if (count($identity) !== 1) { throw new RuntimeException(); }
            $countSql = 'SELECT COUNT(*) AS row_count,MAX(id) AS max_id,MAX(received_at) AS last_received_at FROM telemetry WHERE device_id=:device';
            $q = $pdo->prepare($countSql); $q->execute(['device' => $identity[0]['id']]); $count = $q->fetch();
            $result = ['identity' => $identity[0], 'totals' => $count, 'rows' => [], 'truncated' => false];
            $out['queries'][] = ['sql' => $identitySql, 'parameters' => ['node' => $node]];
            $out['queries'][] = ['sql' => $countSql, 'parameters' => ['device' => $identity[0]['id']]];
            $keys = $input['observed'][$node] ?? [];
            if (!is_array($keys) || count($keys) > 2000) { throw new RuntimeException(); }
            foreach ($keys as $key) { if (!is_int($key) || $key < 0 || $key > 9007199254740991) { throw new RuntimeException(); } }
            $keys = array_values(array_unique($keys));
            $window = isset($input['start_utc'], $input['end_utc']);
            $params = ['device' => (int) $identity[0]['id']]; $where = [];
            if ($window) {
                foreach (['start_utc','end_utc'] as $field) {
                    if (!is_string($input[$field]) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/', $input[$field])) { throw new RuntimeException(); }
                }
                if ($input['start_utc'] > $input['end_utc']) { throw new RuntimeException(); }
                $where[] = '(t.received_at BETWEEN :start AND :end)';
                $params['start'] = str_replace('T', ' ', substr($input['start_utc'], 0, -1));
                $params['end'] = str_replace('T', ' ', substr($input['end_utc'], 0, -1));
            }
            if ($keys) {
                $names = [];
                foreach ($keys as $i => $key) { $names[] = ':s' . $i; $params['s' . $i] = $key; }
                $where[] = 't.seq IN (' . implode(',', $names) . ')';
                $low = min($keys); $high = max($keys);
                // Rango limitado para encontrar huecos sin usar MAX(seq) historico (hay fixtures LOAD).
                if ($high - $low <= 2000) {
                    $where[] = 't.seq BETWEEN :low AND :high'; $params['low'] = $low; $params['high'] = $high;
                } else { $result['wide_sequence_range'] = true; }
            }
            if ($where) {
                $sql = "SELECT t.id,t.device_id,d.node_id,t.seq,t.ts,t.received_at,t.value,t.rssi," .
                    "JSON_UNQUOTE(JSON_EXTRACT(t.extensions,'$.test')) AS fixture_marker " .
                    'FROM telemetry t JOIN devices d ON d.id=t.device_id WHERE t.device_id=:device AND (' .
                    implode(' OR ', $where) . ') ORDER BY t.seq,t.id LIMIT 5001';
                $q = $pdo->prepare($sql); $q->execute($params); $rows = $q->fetchAll();
                if (count($rows) > 5000) { $result['truncated'] = true; $rows = array_slice($rows, 0, 5000); }
                foreach ($rows as &$row) {
                    foreach (['id','device_id','seq'] as $field) { $row[$field] = (int) $row[$field]; }
                    $row['value'] = (float) $row['value']; $row['rssi'] = $row['rssi'] === null ? null : (int) $row['rssi'];
                    $row['fixture_marker'] = $row['fixture_marker'] === null ? null :
                        (in_array($row['fixture_marker'], ['C1','C1-concurrent','HTTP-LOAD'], true) ? $row['fixture_marker'] : '[other]');
                }
                unset($row);
                $result['rows'] = $rows;
                $out['queries'][] = ['sql' => $sql, 'parameters' => $params];
            }
            $out['nodes'][$node] = $result;
        }
        $pdo->exec('ROLLBACK'); $transaction = false; $out['status'] = 'OK';
    } catch (Throwable) {
        if ($transaction && $pdo !== null) { try { $pdo->exec('ROLLBACK'); } catch (Throwable) {} }
        $out['errors'][] = 'No se completo la consulta de solo lectura; detalles privados omitidos.';
    }
    $out['utc_finished'] = p14Utc();
    return $out;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $raw = stream_get_contents(STDIN, 131073);
        if ($raw === false || strlen($raw) > 131072) { throw new RuntimeException(); }
        $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input)) { throw new RuntimeException(); }
        $out = p14Snapshot($input);
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
        exit($out['status'] === 'OK' ? 0 : 2);
    } catch (Throwable) { echo "{\"status\":\"INCOMPLETO\",\"errors\":[\"Entrada o salida no valida; detalles omitidos.\"]}\n"; exit(2); }
}

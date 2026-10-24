<?php
declare(strict_types=1);

// Generador HTTP acotado. Solo la API inserta fixtures; PDO se utiliza para SELECT.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/src/bootstrap.php';

const LOAD_LEVELS = [1, 5, 10, 20];
const LOAD_REQUESTS = 100;
const LOAD_NODES = ['esp32-01', 'esp32-02', 'esp32-03'];
const LOAD_MAX_RESPONSE = 16384;
const LOAD_REQUEST_FIELDS = ['campaign', 'scenario', 'concurrency', 'ordinal', 'node_id', 'seq',
    'started_utc', 'elapsed_ns', 'latency_ms', 'curl_total_ms', 'http_status', 'curl_code',
    'outcome', 'response_problem', 'telemetry_id', 'received_at', 'response_bytes'];
const LOAD_SUMMARY_FIELDS = ['scenario', 'concurrency', 'planned', 'attempted', 'completed_http',
    'http_201', 'valid_201', 'rejected', 'unexpected', 'transport_errors', 'cancelled',
    'duration_ns', 'duration_seconds', 'latency_samples', 'min_ms', 'mean_ms', 'p50_ms', 'p95_ms', 'max_ms',
    'completed_rps', 'http_201_rps', 'confirmed_insertions_rps', 'sql_rows', 'sql_confirmed_201',
    'sql_without_valid_201', 'sql_missing_201', 'sql_discrepancies', 'peak_inflight', 'deadline_hit', 'internal_failure', 'status'];

final class LoadFailure extends RuntimeException {}

function loadUtc(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
}

/** Literales loopback, ruta exacta y sin proxy/redirects: no resolver nombres arbitrarios. */
function loadEndpoint(string $url): array
{
    try { $parts = parse_url($url); } catch (ValueError) { $parts = false; }
    if (!is_array($parts) || preg_match('/[\x00-\x20\x7f]/', $url)
        || ($parts['scheme'] ?? '') !== 'http'
        || !in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]'], true)
        || ($parts['path'] ?? '') !== '/api/v1/telemetry'
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        throw new LoadFailure('Use HTTP loopback literal, ruta /api/v1/telemetry y sin credenciales ni parametros en la URL.');
    }
    $port = $parts['port'] ?? 80;
    if ($port < 1 || $port > 65535) { throw new LoadFailure('Puerto local no valido.'); }
    return ['url' => $url, 'host' => trim($parts['host'], '[]'), 'port' => $port];
}

/** Cuantil inclusivo: h=(N-1)*q, interpolacion lineal entre floor(h) y ceil(h). */
function loadPercentile(array $samples, float $q): ?float
{
    if (!is_finite($q) || $q < 0 || $q > 1) { throw new LoadFailure('Cuantil no valido.'); }
    if (!$samples) { return null; }
    foreach ($samples as $sample) { if (!is_numeric($sample) || !is_finite((float) $sample)) { throw new LoadFailure('Muestra no finita.'); } }
    sort($samples, SORT_NUMERIC);
    $h = (count($samples) - 1) * $q;
    $low = (int) floor($h); $high = (int) ceil($h);
    return (float) $samples[$low] + ($samples[$high] - $samples[$low]) * ($h - $low);
}

function loadPlan(int $base, string $campaign): array
{
    if ($base < 1000000000000 || $base + 400 > 9007199254740991) { throw new LoadFailure('Bloque de secuencias fuera de limites.'); }
    $plan = [];
    for ($ordinal = 0; $ordinal <= 400; $ordinal++) {
        $scenario = $ordinal === 0 ? 'warmup' : 'C' . LOAD_LEVELS[intdiv($ordinal - 1, LOAD_REQUESTS)];
        $node = LOAD_NODES[$ordinal % count(LOAD_NODES)];
        $payload = ['node_id' => $node, 'seq' => $base + $ordinal, 'timestamp' => null,
            'value' => $ordinal % 2, 'rssi' => -60,
            'extensions' => (object) ['test' => 'HTTP-LOAD', 'campaign' => $campaign, 'scenario' => $scenario, 'ordinal' => $ordinal]];
        $plan[] = ['campaign' => $campaign, 'scenario' => $scenario, 'ordinal' => $ordinal,
            'node_id' => $node, 'seq' => $base + $ordinal, 'value' => $payload['value'],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR)];
    }
    return $plan;
}

function loadCsvRow($file, array $fields, array $row): void
{
    $values = [];
    foreach ($fields as $field) {
        $value = $row[$field] ?? null;
        $values[] = is_float($value) ? sprintf('%.9f', $value) : $value;
    }
    if (fputcsv($file, $values, ',', '"', '') === false || !fflush($file)) { throw new LoadFailure('No se pudo conservar CSV.'); }
}

/** Nunca propagar errores, cuerpos o cabeceras arbitrarias de cURL al registro. */
function loadResponse(stdClass $state, CurlHandle $handle, int $curlCode, int $endNs): array
{
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $problem = '';
    $data = null;
    if ($curlCode === CURLE_OK && $status > 0) {
        try { $body = json_decode($state->body, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { $body = null; $problem = 'invalid_json'; }
        $data = is_array($body['data'] ?? null) ? $body['data'] : null;
        if ($status === 201 && $problem === '') {
            $extensions = $data['extensions'] ?? [];
            $valid = ($body['ok'] ?? null) === true && ($body['meta']['api_version'] ?? null) === 'v1'
                && ($body['meta']['created'] ?? null) === true && is_int($data['telemetry_id'] ?? null)
                && $data['telemetry_id'] > 0 && ($data['node_id'] ?? null) === $state->item['node_id']
                && ($data['seq'] ?? null) === $state->item['seq'] && array_key_exists('timestamp', $data)
                && $data['timestamp'] === null && (is_int($data['value'] ?? null) || is_float($data['value'] ?? null))
                && $data['value'] == $state->item['value']
                && ($data['rssi'] ?? null) === -60 && is_string($data['received_at'] ?? null)
                && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/', $data['received_at'])
                && ($extensions['test'] ?? null) === 'HTTP-LOAD'
                && ($extensions['campaign'] ?? null) === $state->item['campaign']
                && ($extensions['scenario'] ?? null) === $state->item['scenario']
                && ($extensions['ordinal'] ?? null) === $state->item['ordinal'];
            if (!$valid) { $problem = 'invalid_created_response'; }
        }
    }
    $peer = curl_getinfo($handle, CURLINFO_PRIMARY_IP);
    if ($peer !== '' && !in_array($peer, ['127.0.0.1', '::1'], true)) { $problem = 'unexpected_peer'; }
    if ($state->tooLarge) { $problem = 'response_too_large'; }
    $outcome = $curlCode !== CURLE_OK || $status === 0 || $problem === 'unexpected_peer' ? 'transport_error'
        : ($status >= 400 && $status < 500 ? 'rejected' : ($status === 201 && $problem === '' ? 'created' : 'unexpected_response'));
    $elapsed = $endNs - $state->startNs;
    return ['campaign' => $state->item['campaign'], 'scenario' => $state->item['scenario'],
        'concurrency' => $state->concurrency, 'ordinal' => $state->item['ordinal'],
        'node_id' => $state->item['node_id'], 'seq' => $state->item['seq'], 'started_utc' => $state->startedUtc,
        'elapsed_ns' => $elapsed, 'latency_ms' => $elapsed / 1000000,
        'curl_total_ms' => curl_getinfo($handle, CURLINFO_TOTAL_TIME_T) / 1000,
        'http_status' => $status ?: null, 'curl_code' => $curlCode, 'outcome' => $outcome,
        'response_problem' => $problem,
        'telemetry_id' => is_int($data['telemetry_id'] ?? null) ? $data['telemetry_id'] : null,
        'received_at' => is_string($data['received_at'] ?? null)
            && preg_match('/\A[0-9TZ:.\-]+\z/', $data['received_at']) ? $data['received_at'] : null,
        'response_bytes' => (int) curl_getinfo($handle, CURLINFO_SIZE_DOWNLOAD_T)];
}

function loadScenario(array $items, int $concurrency, array $endpoint, array $tokens,
                      int $timeoutMs, int $maxSeconds, $csv, bool &$stop): array
{
    $multi = curl_multi_init();
    curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, $concurrency);
    curl_multi_setopt($multi, CURLMOPT_MAX_HOST_CONNECTIONS, $concurrency);
    $active = []; $results = []; $next = 0; $peak = 0;
    $started = hrtime(true); $deadline = $started + $maxSeconds * 1000000000;
    $deadlineHit = false; $internalFailure = false;
    try {
        while (true) {
            while (!$stop && hrtime(true) < $deadline && count($active) < $concurrency && $next < count($items)) {
                $item = $items[$next++];
                $state = (object) ['item' => $item, 'body' => '', 'tooLarge' => false,
                    'concurrency' => $concurrency, 'startedUtc' => loadUtc(), 'startNs' => 0];
                $handle = curl_init($endpoint['url']);
                $remainingMs = max(1, (int) ceil(($deadline - hrtime(true)) / 1000000));
                curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $item['body'],
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:', 'Authorization: Bearer ' . $tokens[$item['node_id']]],
                    CURLOPT_TIMEOUT_MS => min($timeoutMs, $remainingMs), CURLOPT_CONNECTTIMEOUT_MS => min(1000, $timeoutMs, $remainingMs),
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
                    CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_NOSIGNAL => true,
                    CURLOPT_IPRESOLVE => $endpoint['host'] === '::1' ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4,
                    CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($state): int {
                        if (strlen($state->body) + strlen($chunk) > LOAD_MAX_RESPONSE) { $state->tooLarge = true; return 0; }
                        $state->body .= $chunk;
                        return strlen($chunk);
                    }]);
                $state->startedUtc = loadUtc();
                $state->startNs = hrtime(true);
                if (curl_multi_add_handle($multi, $handle) !== CURLM_OK) { curl_close($handle); throw new LoadFailure('No se pudo programar HTTP.'); }
                $active[spl_object_id($handle)] = [$handle, $state];
                $peak = max($peak, count($active));
            }
            do { $status = curl_multi_exec($multi, $running); } while ($status === CURLM_CALL_MULTI_PERFORM);
            if ($status !== CURLM_OK) { throw new LoadFailure('Fallo del planificador HTTP.'); }
            // Tomar el instante antes de decodificar o escribir el resultado.
            while (($info = curl_multi_info_read($multi)) !== false) {
                $end = hrtime(true); $handle = $info['handle']; $key = spl_object_id($handle);
                [$handle, $state] = $active[$key];
                $row = loadResponse($state, $handle, $info['result'], $end);
                curl_multi_remove_handle($multi, $handle); curl_close($handle); unset($active[$key]);
                $results[] = $row;
                loadCsvRow($csv, LOAD_REQUEST_FIELDS, $row);
            }
            if (!$active && $next === count($items)) { break; }
            if ($stop || hrtime(true) >= $deadline) { $deadlineHit = !$stop; break; }
            if (count($active) < $concurrency && $next < count($items)) { continue; }
            $wait = min(0.1, max(0.001, ($deadline - hrtime(true)) / 1000000000));
            if ($active && curl_multi_select($multi, $wait) === -1) { usleep(1000); }
        }
    } catch (Throwable) { $internalFailure = true; }
    finally {
        foreach ($active as [$handle, $state]) {
            $row = loadResponse($state, $handle, CURLE_ABORTED_BY_CALLBACK, hrtime(true));
            $row['outcome'] = 'cancelled';
            $row['response_problem'] = $stop ? 'user_interrupt' : ($deadlineHit ? 'scenario_deadline' : 'scheduler_error');
            curl_multi_remove_handle($multi, $handle); curl_close($handle);
            $results[] = $row;
            try { loadCsvRow($csv, LOAD_REQUEST_FIELDS, $row); } catch (Throwable) { $internalFailure = true; }
        }
        curl_multi_close($multi);
    }
    return ['requests' => $results, 'duration_ns' => hrtime(true) - $started,
        'peak_inflight' => $peak, 'deadline_hit' => $deadlineHit, 'internal_failure' => $internalFailure];
}

/** Consultas solo de lectura; buscar TODA la marca de campana y tambien todo el bloque reservado. */
function loadSqlSnapshot(PDO $pdo, string $campaign, int $base): array
{
    $select = "SELECT t.id,t.device_id,d.node_id,t.seq,t.ts,t.received_at,t.value,t.rssi, " .
        "JSON_UNQUOTE(JSON_EXTRACT(t.extensions,'$.test')) AS test_marker, " .
        "JSON_UNQUOTE(JSON_EXTRACT(t.extensions,'$.campaign')) AS campaign, " .
        "JSON_UNQUOTE(JSON_EXTRACT(t.extensions,'$.scenario')) AS scenario, " .
        "JSON_UNQUOTE(JSON_EXTRACT(t.extensions,'$.ordinal')) AS ordinal FROM telemetry t LEFT JOIN devices d ON d.id=t.device_id";
    $countSql = "SELECT COUNT(*) FROM telemetry WHERE JSON_UNQUOTE(JSON_EXTRACT(extensions,'$.campaign'))=:campaign";
    $q = $pdo->prepare($countSql); $q->execute(['campaign' => $campaign]); $count = (int) $q->fetchColumn();
    $campaignSql = $select . " WHERE JSON_UNQUOTE(JSON_EXTRACT(t.extensions,'$.campaign'))=:campaign ORDER BY t.seq,t.id LIMIT 402";
    $q = $pdo->prepare($campaignSql); $q->execute(['campaign' => $campaign]); $campaignRows = $q->fetchAll();
    $blockSql = $select . ' WHERE t.seq BETWEEN :first AND :last ORDER BY t.seq,t.id LIMIT 402';
    $q = $pdo->prepare($blockSql); $q->execute(['first' => $base, 'last' => $base + 400]); $blockRows = $q->fetchAll();
    return ['utc' => loadUtc(), 'campaign_count' => $count, 'campaign_rows' => $campaignRows,
        'block_rows' => $blockRows, 'queries' => [
            ['sql' => $countSql, 'parameters' => ['campaign' => $campaign]],
            ['sql' => $campaignSql, 'parameters' => ['campaign' => $campaign]],
            ['sql' => $blockSql, 'parameters' => ['first' => $base, 'last' => $base + 400]]]];
}

function loadReconcile(array $plan, array $attempts, array $snapshot, array $devices): array
{
    $expected = []; $sent = []; $valid = []; $present = []; $discrepancies = []; $physicalCounts = [];
    foreach ($plan as $item) { $expected[$item['seq']] = $item; }
    foreach ($attempts as $row) { $sent[$row['seq']] = true; if ($row['outcome'] === 'created') { $valid[$row['seq']] = $row; } }
    $public = [];
    foreach ($snapshot['block_rows'] as $row) {
        $seq = (int) $row['seq']; $item = $expected[$seq] ?? null;
        $scenario = $item['scenario'] ?? 'unexpected';
        $physicalCounts[$scenario] = ($physicalCounts[$scenario] ?? 0) + 1;
        $ok = $item !== null && isset($sent[$seq]) && !isset($present[$seq])
            && $row['node_id'] === $item['node_id'] && (int) $row['device_id'] === ($devices[$item['node_id']] ?? -1)
            && $row['test_marker'] === 'HTTP-LOAD' && $row['campaign'] === $item['campaign']
            && $row['scenario'] === $item['scenario'] && (string) $row['ordinal'] === (string) $item['ordinal']
            && $row['ts'] === null && (float) $row['value'] === (float) $item['value'] && (int) $row['rssi'] === -60;
        if (isset($valid[$seq]) && ((int) $row['id'] !== $valid[$seq]['telemetry_id']
            || str_replace(' ', 'T', $row['received_at']) . 'Z' !== $valid[$seq]['received_at'])) { $ok = false; }
        if (!$ok) { $discrepancies[] = ['seq' => $seq, 'problem' => 'unexpected_or_mismatching_sql_row']; }
        $present[$seq] = ['scenario' => $item['scenario'] ?? 'unexpected', 'valid' => $ok, 'id' => (int) $row['id']];
        $public[] = ['id' => (int) $row['id'], 'device_id' => (int) $row['device_id'],
            'node_id' => in_array($row['node_id'], LOAD_NODES, true) ? $row['node_id'] : '[unexpected]',
            'seq' => $seq, 'received_at' => $row['received_at'], 'value' => (float) $row['value'],
            'rssi' => $row['rssi'] === null ? null : (int) $row['rssi'],
            'scenario' => in_array($row['scenario'], ['warmup','C1','C5','C10','C20'], true) ? $row['scenario'] : '[unexpected]',
            'markers_and_payload_match' => $ok];
    }
    if ($snapshot['campaign_count'] !== count($snapshot['block_rows']) || count($snapshot['campaign_rows']) !== $snapshot['campaign_count']) {
        $discrepancies[] = ['problem' => 'campaign_count_differs_from_reserved_block_or_exceeds_limit',
            'campaign_count' => $snapshot['campaign_count'], 'block_count' => count($snapshot['block_rows']),
            'campaign_returned_rows' => count($snapshot['campaign_rows'])];
    }
    $campaignKeys = array_map(static fn(array $r): string => $r['node_id'] . ':' . $r['seq'], $snapshot['campaign_rows']);
    $blockKeys = array_map(static fn(array $r): string => $r['node_id'] . ':' . $r['seq'], $snapshot['block_rows']);
    sort($campaignKeys); sort($blockKeys);
    if ($campaignKeys !== $blockKeys) { $discrepancies[] = ['problem' => 'campaign_keys_differ_from_reserved_block']; }
    $perScenario = [];
    foreach (['warmup', 'C1', 'C5', 'C10', 'C20'] as $scenario) {
        $rows = $physicalCounts[$scenario] ?? 0; $confirmed = 0; $without = []; $missing = [];
        foreach ($present as $seq => $info) {
            if ($info['scenario'] !== $scenario) { continue; }
            if ($info['valid'] && isset($valid[$seq])) { $confirmed++; }
            else { $without[] = $seq; }
        }
        foreach ($valid as $seq => $_) {
            if ($expected[$seq]['scenario'] === $scenario && (!isset($present[$seq]) || !$present[$seq]['valid'])) { $missing[] = $seq; }
        }
        $perScenario[$scenario] = ['rows' => $rows, 'confirmed_201' => $confirmed,
            'without_valid_201' => $without, 'missing_201' => $missing];
    }
    return ['utc' => $snapshot['utc'], 'campaign_count' => $snapshot['campaign_count'],
        'by_scenario' => $perScenario, 'discrepancies' => $discrepancies, 'records' => $public, 'queries' => $snapshot['queries']];
}

function loadSummary(string $scenario, int $concurrency, int $planned, array $run, ?array $sql): array
{
    $rows = $run['requests']; $duration = $run['duration_ns'] / 1000000000;
    $outcomes = array_count_values(array_column($rows, 'outcome'));
    $http = array_values(array_filter($rows, static fn(array $r): bool => $r['curl_code'] === CURLE_OK && $r['http_status'] !== null
        && !in_array($r['outcome'], ['cancelled', 'transport_error'], true)));
    $samples = array_column($http, 'latency_ms');
    $raw201 = count(array_filter($rows, static fn(array $r): bool => $r['http_status'] === 201));
    $proof = $sql['by_scenario'][$scenario] ?? null;
    $badSql = $sql === null || $proof === null || $sql['discrepancies'] || $proof['missing_201'] || $proof['without_valid_201'];
    $complete = count($rows) === $planned && ($outcomes['created'] ?? 0) === $planned
        && !$run['deadline_hit'] && !$run['internal_failure'] && !$badSql && $proof['rows'] === $planned;
    $status = $complete ? 'PASS' : (($run['deadline_hit'] || ($outcomes['cancelled'] ?? 0) > 0) && !$run['internal_failure'] ? 'INCOMPLETO' : 'FAIL');
    return ['scenario' => $scenario, 'concurrency' => $concurrency, 'planned' => $planned,
        'attempted' => count($rows), 'completed_http' => count($http), 'http_201' => $raw201,
        'valid_201' => $outcomes['created'] ?? 0, 'rejected' => $outcomes['rejected'] ?? 0,
        'unexpected' => $outcomes['unexpected_response'] ?? 0, 'transport_errors' => $outcomes['transport_error'] ?? 0,
        'cancelled' => $outcomes['cancelled'] ?? 0, 'duration_ns' => $run['duration_ns'], 'duration_seconds' => $duration,
        'latency_samples' => count($samples), 'min_ms' => $samples ? min($samples) : null,
        'mean_ms' => $samples ? array_sum($samples) / count($samples) : null,
        'p50_ms' => loadPercentile($samples, 0.5), 'p95_ms' => loadPercentile($samples, 0.95), 'max_ms' => $samples ? max($samples) : null,
        'completed_rps' => $duration > 0 ? count($http) / $duration : null,
        'http_201_rps' => $duration > 0 ? $raw201 / $duration : null,
        'confirmed_insertions_rps' => $proof !== null && $duration > 0 ? $proof['confirmed_201'] / $duration : null,
        'sql_rows' => $proof['rows'] ?? null, 'sql_confirmed_201' => $proof['confirmed_201'] ?? null,
        'sql_without_valid_201' => $proof !== null ? count($proof['without_valid_201']) : null,
        'sql_missing_201' => $proof !== null ? count($proof['missing_201']) : null,
        'sql_discrepancies' => $sql !== null ? count($sql['discrepancies']) : null,
        'peak_inflight' => $run['peak_inflight'], 'deadline_hit' => (int) $run['deadline_hit'],
        'internal_failure' => (int) $run['internal_failure'], 'status' => $status];
}

function loadArchitecture(int $port, string $directory, bool &$stop): array
{
    if (PHP_OS_FAMILY !== 'Windows') { return ['status' => 'UNKNOWN', 'reason' => 'Consulta de procesos implementada solo en Windows.']; }
    $shell = (getenv('SystemRoot') ?: 'C:\\Windows') . '/System32/WindowsPowerShell/v1.0/powershell.exe';
    $code = '$ErrorActionPreference="Stop"; $s=@(Get-NetTCPConnection -State Listen -LocalPort ' . $port . '); ' .
        '$ids=@($s | Select-Object -ExpandProperty OwningProcess -Unique); $a=@(); foreach($serverId in $ids){' .
        '$p=Get-CimInstance Win32_Process -Filter "ProcessId = $serverId"; ' .
        '$children=@(Get-CimInstance Win32_Process -Filter "ParentProcessId = $serverId AND Name = \'php.exe\'"); ' .
        '$a+=[pscustomobject]@{pid=[int]$p.ProcessId;name=$p.Name;php_builtin=($p.Name -eq \'php.exe\' -and $p.CommandLine -match \'(?:^|\s)-S\s+\');php_child_processes=$children.Count}}; ' .
        '[pscustomobject]@{status="OBSERVED";listeners=$a}|ConvertTo-Json -Compress -Depth 4';
    $file = tempnam($directory, '.architecture-');
    $process = null;
    try {
        $process = @proc_open([$shell, '-NoProfile', '-NonInteractive', '-Command', $code],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', $file, 'w'], 2 => ['file', 'NUL', 'w']], $pipes,
            null, null, ['bypass_shell' => true, 'create_no_window' => true]);
        if (!is_resource($process)) { throw new LoadFailure('Consulta no disponible.'); }
        $deadline = hrtime(true) + 8000000000;
        do { $state = proc_get_status($process); if (!$state['running']) { break; } usleep(20000); }
        while (!$stop && hrtime(true) < $deadline);
        if ($state['running']) { proc_terminate($process); throw new LoadFailure('Consulta no disponible.'); }
        $result = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        if (($result['status'] ?? '') !== 'OBSERVED' || !isset($result['listeners'])) { throw new LoadFailure('Consulta no disponible.'); }
        $result['single_php_builtin_process_observed'] = count($result['listeners']) === 1
            && $result['listeners'][0]['php_builtin'] === true && $result['listeners'][0]['php_child_processes'] === 0;
        return $result;
    } catch (Throwable) { return ['status' => 'UNKNOWN', 'reason' => 'Sin acceso a CIM/sockets o consulta agotada; no se supone un PID.']; }
    finally { if (is_resource($process)) { proc_close($process); } if (is_string($file)) { @unlink($file); } }
}

function loadArtifacts(string $directory, array $metadata): void
{
    $temp = tempnam($directory, '.metadata-');
    try {
        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !rename($temp, $directory . '/metadata.json')) { throw new LoadFailure('No se pudo guardar metadata.'); }
    } finally { if (is_string($temp) && is_file($temp)) { @unlink($temp); } }
    $csv = fopen($directory . '/summary.csv', 'wb');
    if ($csv === false) { throw new LoadFailure('No se pudo guardar resumen.'); }
    try {
        loadCsvRow($csv, LOAD_SUMMARY_FIELDS, array_combine(LOAD_SUMMARY_FIELDS, LOAD_SUMMARY_FIELDS));
        foreach ($metadata['scenarios'] as $row) { loadCsvRow($csv, LOAD_SUMMARY_FIELDS, $row); }
    } finally { fclose($csv); }
    $md = "# Carga HTTP local\n\nCampana: {$metadata['campaign']}\n\nInicio UTC: {$metadata['started_utc']}\n\n" .
        "Endpoint: {$metadata['endpoint']}\n\nEstado: **{$metadata['status']}**, salida: " . ($metadata['exit_code'] ?? 'pendiente') . "\n\n";
    $md .= "## Metodologia\n\n" . implode("\n\n", $metadata['methodology']) . "\n\n";
    $md .= "| C | Previstas | Intentadas | HTTP completas | HTTP 201 | 201 validas | Rechazos | Inesperadas | Transporte | Canceladas | s | Min ms | Media ms | p50 ms | p95 ms | Max ms | HTTP/s | Inserts confirmados/s | SQL | Estado |\n" .
        "|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|\n";
    foreach ($metadata['scenarios'] as $row) {
        $values = [];
        foreach (['concurrency','planned','attempted','completed_http','http_201','valid_201','rejected','unexpected','transport_errors','cancelled',
            'duration_seconds','min_ms','mean_ms','p50_ms','p95_ms','max_ms','completed_rps','confirmed_insertions_rps','sql_rows','status'] as $field) {
            $v = $row[$field] ?? null; $values[] = $v === null ? 'ND' : (is_float($v) ? sprintf('%.3f', $v) : (string) $v);
        }
        $md .= '| ' . implode(' | ', $values) . " |\n";
    }
    $md .= "\n## Entorno observado\n\n```json\n" . json_encode($metadata['environment'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
    $md .= "## Calentamiento y conciliacion SQL\n\n```json\n" . json_encode([
        'warmup' => $metadata['warmup'], 'sequence_block' => $metadata['sequence_block'],
        'sql' => isset($metadata['sql']) ? array_diff_key($metadata['sql'], ['records' => true]) : null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
    $md .= "Las filas SQL individuales de esta campana se conservan en metadata.json. Los fixtures permanecen en MariaDB.\n\n";
    $md .= "## Interpretacion y limites\n\n" . implode("\n\n", $metadata['limitations']) . "\n\n";
    $md .= "Ensayo HTTPS separado propuesto: hostname del certificado fijado a loopback, CA local de confianza, verificacion de nombre y cadena TLS activa y permisos Apache ya existentes. No se ejecuta ni se cambia Apache. Si Apache proxifica a 18082, conserva ese cuello de botella.\n";
    if (isset($metadata['error'])) { $md .= "\nError: {$metadata['error']}\n"; }
    if (file_put_contents($directory . '/summary.md', $md, LOCK_EX) !== strlen($md)) { throw new LoadFailure('No se pudo guardar Markdown.'); }
}

function loadMain(array $args): int
{
    $url = 'http://127.0.0.1:18082/api/v1/telemetry'; $timeout = 5000; $seconds = 60;
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === '--help') { echo "Uso: php lab2/scripts/http-load.php [--url HTTP_LOOPBACK] [--timeout-ms 100..10000] [--scenario-seconds 5..120]\n401 peticiones maximas: 1 calentamiento + 100 por concurrencia 1/5/10/20. Sin reintentos.\n"; return 0; }
        if (!in_array($args[$i], ['--url','--timeout-ms','--scenario-seconds'], true) || !isset($args[$i + 1])) { throw new LoadFailure('Argumentos no validos; use --help.'); }
        $name = $args[$i++]; $value = $args[$i];
        if ($name === '--url') { $url = $value; }
        elseif (!ctype_digit($value)) { throw new LoadFailure('Limite numerico no valido.'); }
        elseif ($name === '--timeout-ms') { $timeout = (int) $value; }
        else { $seconds = (int) $value; }
    }
    $endpoint = loadEndpoint($url);
    if ($timeout < 100 || $timeout > 10000 || $seconds < 5 || $seconds > 120) { throw new LoadFailure('Limites fuera del intervalo permitido.'); }
    if (!extension_loaded('curl') || !extension_loaded('pdo_mysql')) { throw new LoadFailure('Se requieren PHP cURL y PDO MySQL existentes.'); }
    $root = dirname(__DIR__); $campaign = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
    $directory = $root . '/logs/evidence/LOAD/' . $campaign;
    if (!mkdir($directory, 0700, true)) { throw new LoadFailure('No se pudo crear evidencia nueva.'); }
    $metadata = ['format_version' => 1, 'campaign' => $campaign, 'started_utc' => loadUtc(), 'finished_utc' => null,
        'endpoint' => $endpoint['url'], 'status' => 'RUNNING', 'exit_code' => null, 'timeout_ms' => $timeout,
        'scenario_deadline_seconds' => $seconds, 'max_campaign_transfer_seconds' => 5 * $seconds,
        'planned_measured_requests' => 400, 'planned_warmup_requests' => 1, 'request_retry_count' => 0,
        'environment' => ['php' => PHP_VERSION, 'os_family' => PHP_OS_FAMILY,
            'curl' => curl_version()['version'], 'architecture' => ['status' => 'NOT_CHECKED'],
            'guide_expected_path' => 'docs/Laboratior 2 guia.pdf', 'guide_available' => is_file(dirname($root) . '/docs/Laboratior 2 guia.pdf')],
        'sequence_block' => null, 'warmup' => ['status' => 'NOT_RUN', 'planned' => 1], 'scenarios' => [],
        'methodology' => [
            'Orden fijo C=1,5,10,20, 100 solicitudes por escenario; un calentamiento previo registrado aparte. Maximo 401 solicitudes, sin reintentos ni redirecciones.',
            'curl_multi mantiene hasta C solicitudes activas y repone cada plaza terminada: carga de lazo cerrado, sin barreras por lotes. HTTP/1.1 con conexion TCP nueva por solicitud; proxies deshabilitados y destino loopback literal.',
            'Distribucion round-robin entre tres identidades locales existentes. Son fixtures de cliente con PIR 0/1 sintetico, RSSI fijo -60 y timestamp null, identificados por extensions.test=HTTP-LOAD y extensions.campaign. No son muestras fisicas.',
            'Se elige un bloque alto de 401 secuencias y se comprueba por SQL que ninguna exista, para ningun dispositivo, antes de enviar. Cada escenario y ordinal quedan marcados en extensions.',
            'hrtime(true) mide cada solicitud desde su alta en curl_multi hasta observar su finalizacion y cada escenario desde el primer despacho hasta recoger/cancelar todos sus resultados. Incluye el cliente y la escritura incremental CSV; excluye preautenticacion, preparacion del plan y verificaciones SQL.',
            'requests.csv conserva duraciones de todas las solicitudes intentadas, incluidos errores/cancelaciones. Min/media/p50/p95/max del resumen usan solo respuestas HTTP completas (cURL OK, codigo HTTP presente, no canceladas), incluidos rechazos. Los timeouts no se convierten en latencias cero.',
            'Cuantiles inclusivos: ordenar las N latencias; h=(N-1)*q; interpolar entre floor(h) y ceil(h), q=0.50/0.95. curl_total_ms adicional procede de libcurl; no se usan tiempos internos PHP de P15.',
            'HTTP/s = respuestas HTTP completas / segundos monotonicamente medidos. Inserts confirmados/s = claves con HTTP 201 y contrato valido, encontradas individualmente en SQL con nodo, contenido y marcadores correctos / los mismos segundos. Una insercion tras un timeout se informa aparte y no se convierte en HTTP 201.',
            'SQL se consulta fuera del intervalo cronometrado, por marca global de campana y por bloque reservado. Se conservan claves y conteos; discrepancias o muestras incompletas impiden PASS.'
        ], 'limitations' => [
            'Estos resultados son throughput observado de este ensayo, no capacidad maxima de Apache ni del sistema completo. El servidor PHP -S de Windows atiende en un unico proceso; aumentar C puede aumentar principalmente la cola y la latencia.',
            'Apache HTTPS no se mide. Los resultados HTTP loopback no incluyen Wi-Fi, TLS ni ESP32 y no sustituyen la campana fisica T05 ni P15.',
            'Un recorrido de 100 solicitudes por nivel, orden fijo, sin replicas ni intervalos de confianza. Generador, PHP y MariaDB comparten laptop; su coste y el trafico de fondo pueden condicionar los resultados. No se detienen servicios ni nodos fisicos.',
            'El backend serializa transacciones por dispositivo; se distribuyen los fixtures entre tres nodos, pero esto no acredita capacidad para otras distribuciones o cargas.',
            'Tras errores de transporte puede haber commits posteriores al cierre del cliente. SQL se verifica en el instante UTC registrado; una discrepancia se conserva y se detiene la campana. No se borran filas para reintentar.',
            'La guia PDF citada por el roadmap no estaba disponible en su ruta durante la preparacion; la matriz y criterios usados son los solicitados para este ensayo.'
        ]];
    foreach (LOAD_LEVELS as $c) { $metadata['scenarios']['C' . $c] = ['scenario' => 'C' . $c, 'concurrency' => $c, 'planned' => LOAD_REQUESTS, 'status' => 'NOT_RUN']; }
    $csv = fopen($directory . '/requests.csv', 'xb');
    if ($csv === false) { throw new LoadFailure('No se pudo crear CSV nuevo.'); }
    loadCsvRow($csv, LOAD_REQUEST_FIELDS, array_combine(LOAD_REQUEST_FIELDS, LOAD_REQUEST_FIELDS));
    $stop = false; $handler = null; $all = []; $plan = []; $devices = []; $tokens = []; $stage = 'preparacion'; $code = 1;
    if (function_exists('sapi_windows_set_ctrl_handler')) {
        $handler = static function (int $event) use (&$stop): bool { $stop = true; return true; };
        $metadata['ctrl_c_handler_installed'] = @sapi_windows_set_ctrl_handler($handler);
        if (!$metadata['ctrl_c_handler_installed']) { $handler = null; }
    }
    try {
        loadArtifacts($directory, $metadata);
        $stage = 'autenticacion SQL';
        $local = json_decode((string) @file_get_contents($root . '/config/test-tokens.local.json'), true, 16, JSON_THROW_ON_ERROR);
        $pdo = \Lab2\Database::connect(); $repo = new \Lab2\DeviceRepository($pdo);
        foreach (LOAD_NODES as $node) {
            $token = $local[$node] ?? null;
            if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) { throw new LoadFailure('Faltan credenciales locales de los tres nodos.'); }
            $device = $repo->authenticate($token);
            if ($device === null || $device['node_id'] !== $node) { throw new LoadFailure('Credencial local no coincide con el dispositivo.'); }
            $tokens[$node] = $token; $devices[$node] = $device['id'];
        }
        $metadata['devices'] = $devices;
        $metadata['environment']['database'] = $pdo->query('SELECT VERSION() AS version, @@session.time_zone AS time_zone')->fetch();
        $stage = 'reserva de secuencias';
        $probeSql = 'SELECT COUNT(*) FROM telemetry WHERE seq BETWEEN :first AND :last';
        $probe = $pdo->prepare($probeSql);
        $base = null;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = random_int(1000000000000, 8000000000000000);
            $probe->execute(['first' => $candidate, 'last' => $candidate + 400]);
            if ((int) $probe->fetchColumn() === 0) { $base = $candidate; break; }
        }
        if ($base === null) { throw new LoadFailure('No se encontro un bloque libre; no se enviaron solicitudes.'); }
        $plan = loadPlan($base, $campaign);
        $metadata['sequence_block'] = ['first' => $base, 'last' => $base + 400, 'unique_planned_sequences' => count(array_unique(array_column($plan, 'seq'))),
            'observed_rows_before' => 0, 'query' => $probeSql, 'parameters' => ['first' => $base, 'last' => $base + 400]];
        $metadata['environment']['architecture'] = loadArchitecture($endpoint['port'], $directory, $stop);
        loadArtifacts($directory, $metadata);
        foreach (['warmup' => 1, 'C1' => 1, 'C5' => 5, 'C10' => 10, 'C20' => 20] as $scenario => $c) {
            if ($stop) { break; }
            $stage = $scenario;
            $items = array_values(array_filter($plan, static fn(array $p): bool => $p['scenario'] === $scenario));
            $run = loadScenario($items, $c, $endpoint, $tokens, $timeout, $seconds, $csv, $stop);
            $all = array_merge($all, $run['requests']);
            try { $sql = loadReconcile($plan, $all, loadSqlSnapshot($pdo, $campaign, $base), $devices); }
            catch (Throwable) { $sql = null; }
            $summary = loadSummary($scenario, $c, count($items), $run, $sql);
            if ($scenario === 'warmup') { $metadata['warmup'] = $summary; }
            else { $metadata['scenarios'][$scenario] = $summary; }
            $metadata['sql'] = $sql;
            $metadata['attempted_so_far'] = count($all);
            loadArtifacts($directory, $metadata);
            echo "{$scenario}: {$summary['valid_201']}/{$summary['planned']} HTTP 201 validas, SQL=" . ($summary['sql_rows'] ?? 'ND') .
                ', ' . sprintf('%.3f', $summary['duration_seconds']) . " s, {$summary['status']}\n";
            if ($summary['status'] !== 'PASS') { break; }
        }
        $complete = $metadata['warmup']['status'] === 'PASS'
            && count(array_filter($metadata['scenarios'], static fn(array $r): bool => $r['status'] === 'PASS')) === 4
            && count($all) === 401 && ($metadata['sql']['campaign_count'] ?? null) === 401;
        $metadata['status'] = $stop ? 'CANCELLED' : ($complete ? 'PASS' : 'FAIL');
        $code = $stop ? 130 : ($complete ? 0 : 1);
    } catch (Throwable $error) {
        $metadata['status'] = 'FAIL';
        $metadata['error'] = $error instanceof LoadFailure ? $error->getMessage() : 'Fallo local; detalles de excepciones omitidos.';
        $metadata['error_stage'] = $stage; $code = 1;
    } finally {
        fclose($csv);
        if ($handler !== null) { sapi_windows_set_ctrl_handler($handler, false); }
        $metadata['finished_utc'] = loadUtc(); $metadata['exit_code'] = $code;
        try {
            loadArtifacts($directory, $metadata);
            foreach (['metadata.json','requests.csv','summary.csv','summary.md'] as $file) {
                $text = @file_get_contents($directory . '/' . $file);
                if ($text === false || $text === '' || preg_match('/authorization|bearer\s|[a-f0-9]{64}/i', $text)) { throw new LoadFailure('Archivos incompletos o material sensible bloqueado.'); }
                foreach ($tokens as $secret) { if (str_contains($text, $secret)) { throw new LoadFailure('Material sensible bloqueado.'); } }
            }
        } catch (Throwable) {
            $code = 1; $metadata['status'] = 'FAIL'; $metadata['exit_code'] = 1;
            $metadata['error'] = 'Fallo al verificar o conservar archivos; no interpretar como PASS.';
            try { loadArtifacts($directory, $metadata); } catch (Throwable) {}
            fwrite(STDERR, "ERROR: evidencia no completada; se conserva lo disponible.\n");
        }
    }
    echo "LOAD {$metadata['status']}; solicitudes registradas=" . count($all) . "; salida={$code}\nDirectorio: {$directory}\n";
    return $code;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try { exit(loadMain(array_slice($argv, 1))); }
    catch (Throwable $error) { fwrite(STDERR, 'ERROR: ' . ($error instanceof LoadFailure ? $error->getMessage() : 'Fallo local; detalles privados omitidos.') . "\n"); exit(1); }
}

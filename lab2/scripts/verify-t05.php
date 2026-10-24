<?php
declare(strict_types=1);

// T05/P15: exclusivamente SELECT y control de una transaccion READ ONLY.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
require dirname(__DIR__) . '/src/bootstrap.php';

const T05_START = '2026-10-24T04:57:02.2565928Z';
const T05_END = '2026-10-24T05:17:02.4552156Z';
const T05_NODES = ['esp32-01' => [304, 411, 'HTTPS', 'HTTP_201_ACK_1'],
    'esp32-02' => [491, 598, 'HTTPS', 'HTTP_201_ACK_1'],
    'esp32-03' => [433, 552, 'MQTT QoS 1/TLS', 'BROKER_PUBACK']];
const T05_FILES = ['duracion.log', 'sql-inicio.log', 'sql-final.log',
    'esp32-01.log', 'esp32-02.log', 'esp32-03.log'];

function utcNow(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
}

/** Enteros de 100 ns: preservar los siete decimales de la ventana original. */
function utcTicks(string $value): int
{
    if (!preg_match('/\A(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d{1,7}))?Z?\z/', $value, $m)) {
        throw new RuntimeException('Fecha UTC no interpretable.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1] . ' ' . $m[2], new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d H:i:s') !== $m[1] . ' ' . $m[2]) {
        throw new RuntimeException('Fecha UTC no valida.');
    }
    return $date->getTimestamp() * 10000000 + (int) str_pad($m[3] ?? '', 7, '0');
}

function isoUtc(string $value): string { return str_replace(' ', 'T', rtrim($value, 'Z')) . 'Z'; }
function secondsBetween(int $first, int $last): float { return ($last - $first) / 10000000; }

function readSourceText(string $file): string
{
    $raw = @file_get_contents($file);
    if ($raw === false) { throw new RuntimeException('Fuente no legible.'); }
    if (str_starts_with($raw, "\xff\xfe")) { $raw = iconv('UTF-16LE', 'UTF-8', substr($raw, 2)); }
    elseif (str_starts_with($raw, "\xfe\xff")) { $raw = iconv('UTF-16BE', 'UTF-8', substr($raw, 2)); }
    elseif (!preg_match('//u', $raw)) { $raw = iconv('Windows-1252', 'UTF-8', $raw); }
    if (!is_string($raw)) { throw new RuntimeException('Codificacion de fuente no interpretable.'); }
    return ltrim($raw, "\xef\xbb\xbf");
}

function locateSources(string $root, ?string $override): array
{
    if ($override !== null) {
        $path = realpath($override);
        return ['directory' => $path !== false && is_dir($path) ? $path : null,
                'selection' => 'explicit', 'candidates' => $path === false ? [] : [$path]];
    }
    $found = [];
    foreach (glob($root . '/logs/serial/*/duracion.log') ?: [] as $file) {
        $text = readSourceText($file);
        if (str_contains($text, T05_START) && str_contains($text, T05_END)) { $found[] = dirname($file); }
    }
    sort($found);
    return ['directory' => count($found) === 1 ? realpath($found[0]) : null,
            'selection' => 'duration_matches_historical_window', 'candidates' => $found];
}

/** Acuses originales: se preservan todas las ocurrencias, sin copiar texto arbitrario. */
function serialConfirmations(string $path, string $node): array
{
    $out = ['kind' => T05_NODES[$node][3], 'lines_total' => 0, 'outside_window' => 0,
        'lines_in_window' => 0, 'unparsed_lines' => 0, 'label_conflicts' => [],
        'other_http_results' => [], 'unexpected_sequences' => [], 'confirmations' => [], 'measurements' => []];
    $file = @fopen($path, 'rb');
    if ($file === false) { throw new RuntimeException('Captura serie no legible.'); }
    try {
        while (($line = fgets($file)) !== false) {
            $out['lines_total']++;
            if (!preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{1,7}Z) \[(esp32-0[123])\] \[(COM\d+)\] (.*)\r?\n?\z/', $line, $m)) {
                $out['unparsed_lines']++;
                continue;
            }
            $ticks = utcTicks($m[1]);
            if ($ticks < utcTicks(T05_START) || $ticks > utcTicks(T05_END)) { $out['outside_window']++; continue; }
            $out['lines_in_window']++;
            if ($m[2] !== $node) {
                $out['label_conflicts'][] = ['line' => $out['lines_total'], 'utc' => $m[1], 'observed_node' => $m[2]];
                continue;
            }
            $message = rtrim($m[4], "\r\n");
            $event = null;
            if (T05_NODES[$node][3] === 'HTTP_201_ACK_1'
                && preg_match('/\bHTTP=(\d{3})\s+seq=(\d{1,16})\s+ack=(\d+)\b/', $message, $ack)) {
                if ($ack[1] === '201' && $ack[3] === '1') { $event = (int) $ack[2]; }
                else { $out['other_http_results'][] = ['line' => $out['lines_total'], 'utc' => $m[1],
                    'seq' => (int) $ack[2], 'http_status' => (int) $ack[1], 'ack' => (int) $ack[3]]; }
            } elseif (T05_NODES[$node][3] === 'BROKER_PUBACK'
                && preg_match('/\bPUBACK broker seq=(\d{1,16})\b/', $message, $ack)) { $event = (int) $ack[1]; }
            if ($event !== null) {
                if ($event < T05_NODES[$node][0] || $event > T05_NODES[$node][1]) {
                    $out['unexpected_sequences'][] = $event;
                } else {
                    $out['confirmations'][$event][] = ['line' => $out['lines_total'], 'utc' => $m[1],
                        'node_id' => $m[2], 'port_during_P15' => $m[3], 'kind' => $out['kind'],
                        'mariadb_unconfirmed_marker' => str_contains($message, 'MariaDB=NO_CONFIRMADA')];
                }
            }
            if ($node === 'esp32-01' && preg_match('/\bMuestra esp32-01 \| PIR=([01]) RSSI=(-?\d+) seq=(\d{1,16})\b/', $message, $sample)) {
                $seq = (int) $sample[3];
                if ($seq >= T05_NODES[$node][0] && $seq <= T05_NODES[$node][1]) {
                    $out['measurements'][$seq][] = ['line' => $out['lines_total'], 'utc' => $m[1],
                        'pir' => (int) $sample[1], 'rssi' => (int) $sample[2]];
                }
            }
        }
        if (!feof($file)) { throw new RuntimeException('Lectura serie incompleta.'); }
    } finally { fclose($file); }
    ksort($out['confirmations']);
    $out['unique_keys'] = count($out['confirmations']);
    $out['occurrences'] = array_sum(array_map('count', $out['confirmations']));
    $out['repeated_occurrences'] = $out['occurrences'] - $out['unique_keys'];
    return $out;
}

function packageIntegrity(string $root, string $directory, array $files): array
{
    $out = ['status' => 'NOT_AVAILABLE', 'archive' => null, 'checks' => []];
    foreach (glob($root . '/logs/evidencias-p15/*.zip') ?: [] as $path) {
        try {
            $zip = new PharData($path);
            $manifestName = 'P15_Lab2/SHA256SUMS.txt';
            if (!isset($zip[$manifestName])) { continue; }
            $out['archive'] = $path;
            $out['archive_sha256'] = hash_file('sha256', $path);
            $manifest = $zip[$manifestName]->getContent();
            $out['manifest_entry'] = $manifestName;
            $out['manifest_sha256'] = hash('sha256', $manifest);
            $hashes = [];
            foreach (preg_split('/\r?\n/', $manifest) as $line) {
                if (preg_match('/\A([a-f0-9]{64})\s+(.+)\z/i', trim($line), $m)) { $hashes[$m[2]] = strtolower($m[1]); }
            }
            foreach ($files as $name) {
                $entry = 'P15_Lab2/fuentes/' . $name;
                $expected = $hashes['fuentes/' . $name] ?? null;
                $actual = is_file($directory . '/' . $name) ? (@hash_file('sha256', $directory . '/' . $name) ?: null) : null;
                $archived = isset($zip[$entry]) ? hash('sha256', $zip[$entry]->getContent()) : null;
                $out['checks'][$name] = ['manifest_sha256' => $expected, 'file_sha256' => $actual,
                    'archive_entry_sha256' => $archived,
                    'matches' => $expected === null || $actual === null || $archived === null ? null : $actual === $expected && $archived === $expected];
            }
            $matches = array_column($out['checks'], 'matches');
            $out['status'] = in_array(false, $matches, true) ? 'FAIL' : (in_array(null, $matches, true) ? 'NOT_AVAILABLE' : 'PASS');
            return $out;
        } catch (Throwable) { $out['status'] = 'UNREADABLE'; }
    }
    return $out;
}

function overlap(array $intervals): ?array
{
    if (count($intervals) !== 3 || in_array(null, $intervals, true)) { return null; }
    $start = max(array_column($intervals, 'first'));
    $end = min(array_column($intervals, 'last'));
    return ['start_utc' => $start, 'end_utc' => $end,
        'seconds' => secondsBetween(utcTicks($start), utcTicks($end)), 'positive' => utcTicks($end) > utcTicks($start)];
}

function markdown(array $report): string
{
    $text = "# T05 - Verificacion retrospectiva P15\n\n" .
        "Ejecucion: {$report['run_id']}\n\nFecha UTC: {$report['started_utc']}\n\n" .
        "Estado: **{$report['status']}**; salida: {$report['exit_code']}\n\n" .
        "Ventana historica: `" . T05_START . '` a `' . T05_END . "`.\n\n" .
        "## Metodologia\n\n" . implode("\n\n", $report['methodology']) . "\n\n" .
        "## Tabla consolidada\n\n| Nodo | Filas / esperadas | Rango SQL | Faltantes | Duplicados SQL | Fuera de ventana | Acuses unicos | Claves contrastadas | PIR | RSSI | Estado |\n" .
        "|---|---:|---|---:|---:|---:|---:|---:|---|---|---|\n";
    foreach ($report['nodes'] as $node => $result) {
        $stats = $result['sql_stats'] ?? [];
        $known = $result['sql_stats'] !== null;
        $text .= '| ' . $node . ' | ' . ($stats['rows'] ?? 'ND') . '/' . $result['expected_count'] . ' | ' .
            ($stats['seq_min'] ?? 'ND') . '-' . ($stats['seq_max'] ?? 'ND') . ' | ' . ($known ? count($result['missing_sequences']) : 'ND') . ' | ' .
            ($known ? count($result['duplicates']) : 'ND') . ' | ' . ($known ? count($result['outside_window']) : 'ND') . ' | ' . ($result['serial']['unique_keys'] ?? 'ND') . ' | ' .
            ($result['individually_verified_keys'] ?? 'ND') . ' | ' . json_encode($stats['pir_counts'] ?? []) . ' | ' .
            ($stats['rssi_min'] ?? 'ND') . '..' . ($stats['rssi_max'] ?? 'ND') . ' | ' . $result['status'] . " |\n";
    }
    $text .= "\nPersistencia SQL: **" . ($report['sql_unique_keys'] ?? 'ND') . '/336** claves. Contraste individual SQL/serie: **' .
        ($report['verified_keys'] ?? 'ND') . "/336**.\n\n";
    $concurrencySummary = $report['concurrency'];
    unset($concurrencySummary['bins']); // Los 40 bins completos se conservan en evidence.json.
    $text .= "## Concurrencia temporal\n\n```json\n" . json_encode($concurrencySummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
    foreach ($report['nodes'] as $node => $result) {
        $text .= "## {$node}\n\nTransporte declarado en P15: {$result['expected_transport']}. Tipo de acuse: {$result['expected_confirmation']}.\n\n";
        $text .= "```json\n" . json_encode(['device' => $result['device'], 'sql_stats' => $result['sql_stats'],
            'missing' => $result['missing_sequences'], 'duplicates' => $result['duplicates'],
            'outside_window' => $result['outside_window'], 'invalid_values' => $result['invalid_values'],
            'serial_missing' => $result['missing_serial_sequences'], 'failures' => $result['failures'],
            'limitations' => $result['limitations']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
        $text .= "| seq | telemetry.id | device_id | received_at UTC | PIR | RSSI | Primer acuse UTC | Tipo | Linea fuente |\n|---:|---:|---:|---|---:|---:|---|---|---:|\n";
        foreach ($result['records'] as $row) {
            $ack = $row['serial_confirmations'][0] ?? null;
            $text .= "| {$row['seq']} | {$row['id']} | {$row['device_id']} | {$row['received_at_utc']} | {$row['value']} | " .
                ($row['rssi'] ?? 'NULL') . ' | ' . ($ack['utc'] ?? 'ND') . ' | ' . ($ack['kind'] ?? 'ND') . ' | ' . ($ack['line'] ?? 'ND') . " |\n";
        }
        $text .= "\n";
    }
    $text .= "## Fuentes e integridad\n\n```json\n" . json_encode($report['sources'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n\n";
    $text .= "## Consultas de lectura\n\n";
    foreach ($report['queries'] as $query) { $text .= "```sql\n{$query['sql']};\n```\nParametros: `" . json_encode($query['parameters']) . "`\n\n"; }
    $text .= "## Conclusion y limitaciones\n\n" . $report['conclusion'] . "\n\n";
    foreach (array_merge($report['failures'], $report['limitations'], $report['scope_notes']) as $issue) { $text .= '- ' . $issue . "\n"; }
    $text .= "\nHTTP 201, PUBACK y persistencia SQL se verifican como evidencias distintas. Un PUBACK no confirma una escritura en MariaDB.\n";
    return $text;
}

function saveEvidence(string $directory, array $report): void
{
    foreach (['summary.md' => markdown($report), 'evidence.json' => json_encode($report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"] as $name => $text) {
        $file = @fopen($directory . '/' . $name, 'xb');
        if ($file === false) { throw new RuntimeException('No se pudo crear evidencia nueva.'); }
        try { if (fwrite($file, $text) !== strlen($text)) { throw new RuntimeException('Escritura incompleta.'); } }
        finally { fclose($file); }
    }
}

function runT05(array $args): int
{
    $root = dirname(__DIR__);
    $override = null;
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === '--help') { echo "Uso: php lab2/scripts/verify-t05.php [--sources DIRECTORIO_ORIGINAL]\n"; return 0; }
        if ($args[$i] !== '--sources' || !isset($args[$i + 1]) || $override !== null) {
            fwrite(STDERR, "Uso: php lab2/scripts/verify-t05.php [--sources DIRECTORIO_ORIGINAL]\n"); return 1;
        }
        $override = $args[++$i];
    }
    $id = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
    $directory = $root . '/logs/evidence/T05/' . $id;
    if (!@mkdir($directory, 0700, true)) { throw new RuntimeException('No se pudo crear el directorio de evidencia.'); }
    $report = ['format_version' => 1, 'run_id' => $id, 'started_utc' => utcNow(), 'finished_utc' => null,
        'php_version' => PHP_VERSION, 'status' => 'INCOMPLETO', 'exit_code' => 2,
        'historical_window' => ['start_utc' => T05_START, 'end_utc' => T05_END,
            'duration_utc_seconds' => secondsBetween(utcTicks(T05_START), utcTicks(T05_END)),
            'boundary_note' => 'Los snapshots P15 no delimitan atomicamente dos commits. Se conservan los limites UTC declarados.'],
        'methodology' => [
            'Conexion PDO existente, zona UTC y snapshot REPEATABLE READ en transaccion READ ONLY. Solo SELECT; no se invocan pruebas generadoras de telemetria.',
            'Cada clave esperada se contrasta con telemetry JOIN devices, sus valores y received_at. La base no almacena el transporte; HTTPS/MQTT se documenta con P15 y el tipo de acuse serie.',
            'Se filtra por la ventana UTC historica con precision de 100 ns. Los acuses se agrupan por clave conservando todas las ocurrencias y lineas originales en JSON. Los conteos y bins usan la primera confirmacion por clave.',
            'HTTP=201 ack=1 acredita la respuesta HTTP observada. PUBACK acredita recepcion QoS 1 por el broker. La persistencia se acredita exclusivamente con las filas SQL consultadas.',
            'Concurrencia operacional: intervalos de actividad superpuestos y al menos un bin completo de 30 s con claves de los tres nodos en SQL y serie. No significa paquetes transmitidos en el mismo instante ni mide latencia de commit.',
            'La identidad fisica y el origen real/simulado del PIR proceden del montaje documentado en P15; esta ejecucion no abre USB ni vuelve a medir los sensores.'
        ], 'sources' => [], 'queries' => [], 'nodes' => [], 'concurrency' => [],
        'sql_unique_keys' => null, 'verified_keys' => null, 'sql_verification_complete' => false,
        'failures' => [], 'limitations' => [], 'scope_notes' => [
            'La tabla telemetry no identifica transporte ni hardware USB. El montaje fisico y los transportes se sustentan en P15 y sus capturas originales; no se ejecuta una nueva campana.',
            'Solo esp32-01 tiene muestras PIR/RSSI etiquetadas explicitamente con seq en los logs. Para los otros dos nodos se verifican los valores almacenados y su dominio, sin afirmar una comparacion fisica independiente por clave.',
            'Los tiempos serie son recepcion en la laptop; PUBACK no confirma MariaDB y received_at no mide el instante del commit. La ventana original no es una frontera atomica entre snapshots.'
        ], 'conclusion' => 'Verificacion no completada.'];
    foreach (T05_NODES as $node => [$low, $high, $transport, $ack]) {
        $report['nodes'][$node] = ['status' => 'INCOMPLETO', 'range' => [$low, $high], 'expected_count' => $high - $low + 1,
            'expected_transport' => $transport, 'expected_confirmation' => $ack, 'device' => null,
            'sql_stats' => null, 'records' => [], 'missing_sequences' => [], 'duplicates' => [], 'outside_window' => [],
            'invalid_values' => [], 'missing_serial_sequences' => [], 'serial' => null,
            'individually_verified_keys' => null, 'failures' => [], 'limitations' => []];
    }
    $pdo = null;
    $transaction = false;
    try {
        $selection = locateSources($root, $override);
        $report['sources']['selection'] = $selection;
        $source = $selection['directory'];
        $report['sources']['files'] = [];
        if ($source === null) { $report['limitations'][] = 'No se selecciono un unico directorio original P15; indique --sources.'; }
        else {
            foreach (T05_FILES as $name) {
                $path = $source . '/' . $name;
                $report['sources']['files'][$name] = is_file($path) ? ['path' => $path, 'bytes' => filesize($path),
                    'sha256' => hash_file('sha256', $path)] : null;
                if (!is_file($path)) { $report['limitations'][] = 'Falta fuente original: ' . $name; }
            }
            $report['sources']['package_integrity'] = packageIntegrity($root, $source, T05_FILES);
            if ($report['sources']['package_integrity']['status'] === 'FAIL') { $report['failures'][] = 'Fuentes distintas de las huellas del paquete original.'; }
            if ($report['sources']['package_integrity']['status'] !== 'PASS') { $report['limitations'][] = 'No se acredito completamente la integridad contra el paquete P15.'; }
            $timing = $source . '/duracion.log';
            if (is_file($timing)) {
                $text = readSourceText($timing);
                if (!str_contains($text, T05_START) || !str_contains($text, T05_END)) { $report['failures'][] = 'duracion.log no coincide con la ventana historica.'; }
                if (preg_match('/\(s\):\s*([0-9.]+)/', $text, $m)) { $report['sources']['duration_monotonic_seconds'] = (float) $m[1]; }
            }
            foreach (['inicio', 'final'] as $phase) {
                $path = $source . '/sql-' . $phase . '.log';
                if (!is_file($path)) { continue; }
                $text = readSourceText($path);
                foreach (array_keys(T05_NODES) as $node) {
                    if (preg_match('/' . preg_quote($node, '/') . '\s*\|\s*(\d+) registros de telemetria/', $text, $m)) {
                        $report['sources']['sql_snapshots'][$phase][$node] = (int) $m[1];
                    }
                }
            }
        }
        $pdo = \Lab2\Database::connect();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $transaction = true;
        $report['read_only_transaction'] = ['statement' => 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY', 'started_utc' => utcNow()];
        $devicesSql = "SELECT id, node_id FROM devices WHERE node_id IN ('esp32-01','esp32-02','esp32-03') ORDER BY id";
        $devices = $pdo->query($devicesSql)->fetchAll();
        $report['queries'][] = ['sql' => $devicesSql, 'parameters' => [], 'rows' => $devices];
        $rowsSql = 'SELECT t.id, t.device_id, d.node_id, t.seq, t.ts, t.received_at, t.value, t.rssi FROM telemetry t JOIN devices d ON d.id = t.device_id WHERE d.node_id = :node AND t.seq BETWEEN :low AND :high ORDER BY t.seq, t.id';
        $duplicateSql = 'SELECT d.node_id, t.seq, COUNT(*) AS occurrences FROM telemetry t JOIN devices d ON d.id = t.device_id WHERE d.node_id = :node AND t.seq BETWEEN :low AND :high GROUP BY d.node_id, t.seq HAVING COUNT(*) > 1';
        foreach (T05_NODES as $node => [$low, $high]) {
            $result = &$report['nodes'][$node];
            $identity = array_values(array_filter($devices, static fn(array $d): bool => $d['node_id'] === $node));
            $result['device'] = count($identity) === 1 ? $identity[0] : null;
            if ($result['device'] === null) { $result['failures'][] = 'devices no resuelve una identidad unica.'; }
            $params = ['node' => $node, 'low' => $low, 'high' => $high];
            $query = $pdo->prepare($rowsSql); $query->execute($params); $rows = $query->fetchAll();
            $report['queries'][] = ['sql' => $rowsSql, 'parameters' => $params, 'row_count' => count($rows), 'records_at' => 'nodes.' . $node . '.records'];
            $query = $pdo->prepare($duplicateSql); $query->execute($params); $result['duplicates'] = $query->fetchAll();
            $report['queries'][] = ['sql' => $duplicateSql, 'parameters' => $params, 'rows' => $result['duplicates']];
            $serialPath = $source !== null ? $source . '/' . $node . '.log' : null;
            if ($serialPath !== null && is_file($serialPath)) { $result['serial'] = serialConfirmations($serialPath, $node); }
            else { $result['limitations'][] = 'No disponible captura serie original para este nodo.'; }
            $seqs = []; $pir = []; $rssi = []; $times = []; $verifiedSeqs = [];
            foreach ($rows as $row) {
                foreach (['id', 'device_id', 'seq'] as $key) { $row[$key] = (int) $row[$key]; }
                $row['value'] = (float) $row['value'];
                $row['rssi'] = $row['rssi'] === null ? null : (int) $row['rssi'];
                $row['ts'] = $row['ts'] === null ? null : (int) $row['ts'];
                $row['received_at_utc'] = isoUtc($row['received_at']);
                $ticks = utcTicks($row['received_at']);
                $inside = $ticks >= utcTicks(T05_START) && $ticks <= utcTicks(T05_END);
                $row['within_window'] = $inside;
                $row['identity_matches_devices'] = $result['device'] !== null && $row['device_id'] === (int) $result['device']['id'] && $row['node_id'] === $node;
                if (!$inside) { $result['outside_window'][] = $row['seq']; }
                if (!$row['identity_matches_devices']) { $result['failures'][] = 'Identidad SQL discordante para seq ' . $row['seq']; }
                if (!in_array($row['value'], [0.0, 1.0], true) || ($row['rssi'] !== null && ($row['rssi'] < -127 || $row['rssi'] > 0))) {
                    $result['invalid_values'][] = $row['seq'];
                }
                if ($row['rssi'] === null) { $result['limitations'][] = 'RSSI SQL ausente en seq ' . $row['seq']; }
                $seqs[] = $row['seq']; $times[] = $row['received_at_utc'];
                $pir[(string) $row['value']] = ($pir[(string) $row['value']] ?? 0) + 1;
                if ($row['rssi'] !== null) { $rssi[] = $row['rssi']; }
                $row['serial_confirmations'] = $result['serial']['confirmations'][$row['seq']] ?? [];
                $row['serial_measurements'] = $result['serial']['measurements'][$row['seq']] ?? [];
                foreach ($row['serial_measurements'] as $sample) {
                    if ($sample['pir'] !== (int) $row['value'] || $sample['rssi'] !== $row['rssi']) {
                        $result['failures'][] = 'PIR/RSSI SQL no coinciden con muestra serie explicita de seq ' . $row['seq'];
                    }
                }
                if ($row['serial_confirmations'] && $inside && $row['identity_matches_devices']) { $verifiedSeqs[$row['seq']] = true; }
                $result['records'][] = $row;
            }
            $unique = array_values(array_unique($seqs)); sort($unique); sort($times); ksort($pir);
            $result['missing_sequences'] = array_values(array_diff(range($low, $high), $unique));
            $result['sql_stats'] = ['rows' => count($rows), 'unique_keys' => count($unique),
                'seq_min' => $unique ? min($unique) : null, 'seq_max' => $unique ? max($unique) : null,
                'first_received_utc' => $times[0] ?? null, 'last_received_utc' => $times ? $times[count($times) - 1] : null,
                'pir_counts' => (object) $pir, 'rssi_min' => $rssi ? min($rssi) : null, 'rssi_max' => $rssi ? max($rssi) : null];
            $result['individually_verified_keys'] = count($verifiedSeqs);
            if (count($rows) !== $result['expected_count'] || $result['missing_sequences'] || $result['duplicates'] || $result['outside_window'] || $result['invalid_values']) {
                $result['failures'][] = 'Cantidad, continuidad, unicidad, valores o ventana SQL no conformes.';
            }
            if ($result['serial'] !== null) {
                $result['missing_serial_sequences'] = array_values(array_diff(range($low, $high), array_keys($result['serial']['confirmations'])));
                if ($result['missing_serial_sequences'] || $result['serial']['label_conflicts'] || $result['serial']['unexpected_sequences']) {
                    $result['failures'][] = 'Acuses serie originales incompletos o discordantes con las claves esperadas.';
                }
            }
            $before = $report['sources']['sql_snapshots']['inicio'][$node] ?? null;
            $after = $report['sources']['sql_snapshots']['final'][$node] ?? null;
            $result['historical_count_delta'] = $before !== null && $after !== null ? $after - $before : null;
            if ($result['historical_count_delta'] === null) { $result['limitations'][] = 'Sin comparacion de snapshots SQL originales.'; }
            elseif ($result['historical_count_delta'] !== count($unique)) { $result['failures'][] = 'El incremento historico no coincide con las claves SQL encontradas.'; }
            $result['status'] = $result['failures'] ? 'FAIL' : ($result['limitations'] ? 'INCOMPLETO' : 'PASS');
            $report['sql_unique_keys'] = ($report['sql_unique_keys'] ?? 0) + count($unique);
            $report['verified_keys'] = ($report['verified_keys'] ?? 0) + $result['individually_verified_keys'];
            unset($result);
        }
        $pdo->exec('ROLLBACK'); $transaction = false;
        $report['sql_verification_complete'] = true;
        $report['read_only_transaction']['ended_utc'] = utcNow();
        $sqlIntervals = []; $serialIntervals = [];
        $binTicks = 30 * 10000000;
        $fullBins = intdiv(utcTicks(T05_END) - utcTicks(T05_START), $binTicks);
        $bins = [];
        for ($i = 0; $i < $fullBins; $i++) {
            $bins[$i] = ['index' => $i, 'seconds_from_start' => [$i * 30, ($i + 1) * 30], 'sql' => [], 'serial' => []];
            foreach (array_keys(T05_NODES) as $node) { $bins[$i]['sql'][$node] = 0; $bins[$i]['serial'][$node] = 0; }
        }
        foreach ($report['nodes'] as $node => $result) {
            $stats = $result['sql_stats'];
            $sqlIntervals[$node] = $stats['first_received_utc'] === null ? null : ['first' => $stats['first_received_utc'], 'last' => $stats['last_received_utc']];
            $acks = [];
            foreach ($result['records'] as $row) {
                if ($row['within_window']) {
                    $bin = intdiv(utcTicks($row['received_at']) - utcTicks(T05_START), $binTicks);
                    if (isset($bins[$bin])) { $bins[$bin]['sql'][$node]++; }
                }
            }
            foreach ($result['serial']['confirmations'] ?? [] as $events) {
                $event = $events[0]; $acks[] = $event['utc'];
                $bin = intdiv(utcTicks($event['utc']) - utcTicks(T05_START), $binTicks);
                if (isset($bins[$bin])) { $bins[$bin]['serial'][$node]++; }
            }
            sort($acks);
            $serialIntervals[$node] = $acks ? ['first' => $acks[0], 'last' => $acks[count($acks) - 1]] : null;
        }
        $joint = 0;
        foreach ($bins as &$bin) { $bin['all_three_sql_and_serial'] = min($bin['sql']) > 0 && min($bin['serial']) > 0; $joint += (int) $bin['all_three_sql_and_serial']; }
        unset($bin);
        $sqlOverlap = overlap($sqlIntervals); $serialOverlap = overlap($serialIntervals);
        $accredited = ($sqlOverlap['positive'] ?? false) && ($serialOverlap['positive'] ?? false) && $joint > 0;
        $report['concurrency'] = ['sql_activity_intervals' => $sqlIntervals, 'serial_activity_intervals' => $serialIntervals,
            'sql_common_interval' => $sqlOverlap, 'serial_common_interval' => $serialOverlap,
            'bin_seconds' => 30, 'full_bins' => $fullBins, 'bins_with_all_three_sql_and_serial' => $joint,
            'partial_tail_seconds_not_binned' => secondsBetween(utcTicks(T05_START) + $fullBins * $binTicks, utcTicks(T05_END)),
            'bins' => array_values($bins), 'operational_concurrency_accredited' => $accredited,
            'scope' => 'Solapamiento e intercalado de actividad a escala de 30 s, no simultaneidad fisica exacta de paquetes. received_at no es el instante del commit.'];
        if (!$accredited) { $report['limitations'][] = 'No se acredito concurrencia temporal con SQL y acuses de los tres nodos.'; }
    } catch (Throwable) {
        if ($transaction && $pdo !== null) { try { $pdo->exec('ROLLBACK'); } catch (Throwable) {} }
        $report['limitations'][] = 'Verificacion interrumpida por error de lectura, conexion o formato; detalles privados omitidos. No inferir ceros de campos no disponibles.';
    }
    $states = array_column($report['nodes'], 'status');
    $hasFail = $report['failures'] || in_array('FAIL', $states, true);
    $complete = !$report['limitations'] && $report['sql_verification_complete'] && $report['verified_keys'] === 336 && $report['sql_unique_keys'] === 336
        && !in_array('INCOMPLETO', $states, true) && ($report['concurrency']['operational_concurrency_accredited'] ?? false);
    $report['status'] = $hasFail ? 'FAIL' : ($complete ? 'PASS' : 'INCOMPLETO');
    $report['exit_code'] = $report['status'] === 'PASS' ? 0 : ($report['status'] === 'FAIL' ? 1 : 2);
    $report['finished_utc'] = utcNow();
    $report['conclusion'] = $report['status'] === 'PASS'
        ? 'T05 acreditado para P15: las 336 claves originales tienen persistencia SQL individual con identidad correcta, valores validos, fechas en ventana y acuses serie correspondientes; los tres intervalos de actividad se superponen.'
        : 'T05 no cerrado: revisar fallos y limitaciones. El conteo SQL y los acuses disponibles se conservan por separado; no se reemplazan fuentes ausentes por estimaciones.';
    saveEvidence($directory, $report);
    foreach ($report['nodes'] as $node => $result) {
        $n = $result['sql_stats']['rows'] ?? 'ND';
        $verified = $result['individually_verified_keys'] ?? 'ND';
        echo "{$node}: SQL={$n}/{$result['expected_count']}, claves SQL/serie={$verified}, {$result['status']}\n";
    }
    $sqlCount = $report['sql_unique_keys'] ?? 'ND'; $verifiedCount = $report['verified_keys'] ?? 'ND';
    echo "T05 {$report['status']}: SQL={$sqlCount}/336; contraste individual={$verifiedCount}/336; salida={$report['exit_code']}\nEvidencia: {$directory}\n";
    return $report['exit_code'];
}

try { exit(runT05(array_slice($argv, 1))); }
catch (Throwable) { fwrite(STDERR, "ERROR: no se pudo conservar la evidencia T05; detalles privados omitidos.\n"); exit(1); }

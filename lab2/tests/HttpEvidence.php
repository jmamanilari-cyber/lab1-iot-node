<?php
declare(strict_types=1);

/** Solo registra observaciones; no realiza peticiones ni implementa pruebas HTTP. */
final class HttpEvidence
{
    private const REQUIRED = [
        'T01' => [['valid'], ['before', 'after_create']],
        'T02' => [['invalid_credential', 'missing_credential'], ['before', 'after_invalid', 'after_missing']],
        'T03' => [['missing_rssi'], ['before', 'after_validation']],
        'T04' => [['duplicate', 'conflict'], ['after_duplicate', 'after_conflict']],
        'ADDITIONAL' => [['malformed_json', 'identity_mismatch', 'invalid_seq', 'invalid_timestamp',
            'invalid_value', 'invalid_rssi', 'invalid_extensions'], ['after_rejections', 'other_node']],
    ];
    private array $data;
    private array $secrets = [];
    private string $active = 'setup';
    public readonly string $directory;

    public static function utc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    public function __construct(string $root)
    {
        $id = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
        $this->directory = $root . '/' . $id;
        if (!@mkdir($this->directory, 0700, true)) { throw new RuntimeException('No se pudo crear evidencia.'); }
        $this->data = ['format_version' => 1, 'run_id' => $id, 'started_utc' => self::utc(),
            'finished_utc' => null, 'endpoint' => null, 'php_version' => PHP_VERSION,
            'status' => 'RUNNING', 'exit_code' => null, 'setup' => ['assertions' => []], 'tests' => []];
        foreach (self::REQUIRED as $name => $_) {
            $this->data['tests'][$name] = ['status' => 'NOT_RUN', 'explanation' => 'No ejecutada.',
                'requests' => [], 'sql' => [], 'assertions' => []];
        }
        $this->persist();
    }

    public function endpoint(string $url): void { $this->data['endpoint'] = $url; $this->persist(); }
    public function rememberSecret(string $value): void { if ($value !== '') { $this->secrets[$value] = true; } }

    public function begin(string $name): void
    {
        if (!isset(self::REQUIRED[$name]) || $this->data['tests'][$name]['status'] !== 'NOT_RUN') {
            throw new RuntimeException('Caso de evidencia no disponible.');
        }
        $this->active = $name;
        $this->data['tests'][$name]['status'] = 'RUNNING';
        $this->data['tests'][$name]['started_utc'] = self::utc();
        $this->data['tests'][$name]['explanation'] = 'En ejecucion.';
        $this->persist();
    }

    public function check(bool $passed, string $label): void
    {
        $target = &$this->data['setup'];
        if ($this->active !== 'setup') { $target = &$this->data['tests'][$this->active]; }
        $target['assertions'][] = ['utc' => self::utc(), 'passed' => $passed, 'description' => $label];
        $this->persist();
    }

    public function startRequest(string $name, string $url, string $body, string $mode): void
    {
        if (!in_array($mode, ['valid', 'invalid', 'absent'], true)) { throw new RuntimeException('Modo no valido.'); }
        $this->data['tests'][$this->active]['requests'][$name] = [
            'started_utc' => self::utc(), 'method' => 'POST', 'url' => $url,
            'content_type' => 'application/json', 'credential_mode' => $mode,
            // El cuerpo procede exclusivamente de los fixtures C1, nunca de la configuracion.
            'body' => $body, 'json_body' => json_decode($body),
            'status' => 'RUNNING', 'http_status' => null, 'response' => null];
        $this->persist();
    }

    public function response(string $name, ?int $status, ?array $body, ?string $problem): void
    {
        $request = &$this->data['tests'][$this->active]['requests'][$name];
        $request['finished_utc'] = self::utc();
        $request['http_status'] = $status;
        $request['response'] = $body === null ? null : self::safeResponse($body);
        $request['status'] = $problem === null ? 'OBSERVED' : 'ERROR';
        $request['problem'] = $problem;
        $this->persist();
    }

    public function sql(string $name, string $countSql, string $rowsSql, array $params,
                        int $count, array $rows): void
    {
        $this->data['tests'][$this->active]['sql'][$name] = ['utc' => self::utc(),
            'count_query' => $countSql, 'rows_query' => $rowsSql, 'parameters' => $params,
            'observed_count' => $count, 'rows' => array_map(static function (array $row): array {
                $safe = self::safeData($row);
                if (isset($row['id'])) { $safe['id'] = (int) $row['id']; }
                if (array_key_exists('ts', $row)) { $safe['ts'] = $row['ts'] === null ? null : (int) $row['ts']; }
                return $safe;
            }, $rows)];
        $this->persist();
    }

    public function pass(string $explanation): void
    {
        $case = &$this->data['tests'][$this->active];
        [$requests, $queries] = self::REQUIRED[$this->active];
        foreach ($requests as $name) {
            $request = $case['requests'][$name] ?? [];
            if (($request['status'] ?? '') !== 'OBSERVED' || !is_int($request['http_status'] ?? null)
                || !is_array($request['response'] ?? null)) { throw new RuntimeException('Falta evidencia HTTP obligatoria.'); }
        }
        foreach ($queries as $name) {
            $sql = $case['sql'][$name] ?? [];
            if (!is_int($sql['observed_count'] ?? null) || !isset($sql['parameters']['node_id'], $sql['parameters']['seq'])
                || !isset($sql['rows']) || count($sql['rows']) !== $sql['observed_count']) {
                throw new RuntimeException('Falta evidencia SQL obligatoria.');
            }
        }
        if ($this->active === 'T01' || $this->active === 'T04') {
            $name = $this->active === 'T01' ? 'valid' : 'duplicate';
            foreach (['telemetry_id', 'node_id', 'seq', 'timestamp', 'received_at', 'value', 'rssi', 'extensions'] as $field) {
                if (!array_key_exists($field, $case['requests'][$name]['response']['data'] ?? [])) {
                    throw new RuntimeException('Faltan campos de respuesta en la evidencia.');
                }
            }
        }
        if ($this->active === 'T03' && in_array(
            $case['requests']['missing_rssi']['response']['error']['details']['rssi'] ?? '[omitido]',
            ['', '[omitido]'], true)) { throw new RuntimeException('Falta detalle verificable del campo rechazado.'); }
        if (!$case['assertions'] || in_array(false, array_column($case['assertions'], 'passed'), true)) {
            throw new RuntimeException('Las comprobaciones no acreditan PASS.');
        }
        $case['status'] = 'PASS';
        $case['explanation'] = $explanation;
        $case['finished_utc'] = self::utc();
        $this->persist();
    }

    public function finish(): void
    {
        if (count(array_filter($this->data['tests'], static fn(array $case): bool => $case['status'] === 'PASS')) !== 5) {
            throw new RuntimeException('Evidencia incompleta.');
        }
        $this->data['status'] = 'PASS';
        $this->data['exit_code'] = 0;
        $this->data['finished_utc'] = self::utc();
        $this->persist();
        foreach (['evidence.json', 'summary.md'] as $file) {
            if (!is_file($this->directory . '/' . $file) || filesize($this->directory . '/' . $file) === 0) {
                throw new RuntimeException('Falta un archivo de evidencia.');
            }
        }
        $saved = json_decode((string) @file_get_contents($this->directory . '/evidence.json'), true, 64, JSON_THROW_ON_ERROR);
        if (($saved['status'] ?? '') !== 'PASS' || ($saved['exit_code'] ?? null) !== 0) {
            throw new RuntimeException('No se pudo verificar el archivo final.');
        }
    }

    public function fail(string $reason): void
    {
        $this->data['status'] = 'FAIL';
        $this->data['exit_code'] = 1;
        $this->data['finished_utc'] = self::utc();
        $this->data['failure'] = $reason;
        if ($this->active !== 'setup') {
            $case = &$this->data['tests'][$this->active];
            $case['status'] = 'FAIL'; $case['explanation'] = $reason; $case['finished_utc'] = self::utc();
        }
        $this->persist();
    }

    public function console(int $code): void
    {
        $main = 0;
        foreach (['T01', 'T02', 'T03', 'T04'] as $name) { $main += (int) ($this->data['tests'][$name]['status'] === 'PASS'); }
        $extra = $this->data['tests']['ADDITIONAL']['status'] === 'PASS' ? 'PASS' : $this->data['tests']['ADDITIONAL']['status'];
        $extraCount = $extra === 'PASS' ? 7 : 0;
        echo "Evidencia: {$this->directory}\nT01-T04 correctas: {$main}/4; adicionales: {$extraCount}/7 ({$extra}); salida={$code}.\n";
    }

    private static function safeData(array $data): array
    {
        $out = [];
        foreach (['telemetry_id', 'seq', 'timestamp', 'value', 'rssi'] as $field) {
            if (array_key_exists($field, $data) && (is_int($data[$field]) || is_float($data[$field]) || $data[$field] === null)) {
                $out[$field] = $data[$field];
            }
        }
        if (in_array($data['node_id'] ?? null, ['esp32-01', 'esp32-02', 'esp32-03'], true)) { $out['node_id'] = $data['node_id']; }
        if (is_string($data['received_at'] ?? null) && preg_match('/\A[0-9TZ :.\-]+\z/', $data['received_at'])) {
            $out['received_at'] = $data['received_at'];
        }
        $extensions = $data['extensions'] ?? null;
        if (is_string($extensions)) { $extensions = json_decode($extensions, true); }
        if (is_object($extensions)) { $extensions = (array) $extensions; }
        if (is_array($extensions)) {
            $out['extensions'] = [];
            if (($extensions['test'] ?? '') === 'C1') { $out['extensions']['test'] = 'C1'; }
            if (is_string($extensions['run'] ?? null) && preg_match('/\A[0-9]{1,16}\z/', $extensions['run'])) {
                $out['extensions']['run'] = $extensions['run'];
            }
        }
        return $out;
    }

    private static function safeResponse(array $body): array
    {
        $safe = [];
        if (is_bool($body['ok'] ?? null)) { $safe['ok'] = $body['ok']; }
        if (($body['meta']['api_version'] ?? '') === 'v1') { $safe['meta']['api_version'] = 'v1'; }
        if (is_bool($body['meta']['created'] ?? null)) { $safe['meta']['created'] = $body['meta']['created']; }
        if (is_array($body['data'] ?? null)) { $safe['data'] = self::safeData($body['data']); }
        if (is_array($body['error'] ?? null)) {
            $error = $body['error'];
            $safe['error']['code'] = in_array($error['code'] ?? '', ['unauthorized', 'validation_error', 'malformed_json',
                'identity_mismatch', 'sequence_conflict', 'internal_error'], true) ? $error['code'] : '[omitido]';
            // Conservar solo textos publicos conocidos. Nunca volcar un error arbitrario del servidor.
            $texts = ['Token ausente o invalido.', 'Telemetria invalida.', 'Campo obligatorio.',
                'JSON malformado o demasiado profundo.', 'El token no pertenece al nodo solicitado.',
                'La secuencia ya existe con otro contenido.', 'Error interno.', 'Identificador invalido.',
                'Debe ser entero entre 0 y 9007199254740991.',
                'Debe ser Unix UTC entero entre 0 y 253402300799, o null.', 'PIR requiere el numero 0 o 1.',
                'Debe ser entero entre -127 y 0, o null.',
                'Objeto JSON: maximo 4096 bytes, profundidad 8 y claves de 1 a 64 caracteres.'];
            $safe['error']['message'] = in_array($error['message'] ?? '', $texts, true) ? $error['message'] : '[omitido]';
            foreach (['node_id', 'seq', 'timestamp', 'value', 'rssi', 'extensions'] as $field) {
                if (isset($error['details'][$field])) {
                    $detail = $error['details'][$field];
                    $safe['error']['details'][$field] = in_array($detail, $texts, true) ? $detail : '[omitido]';
                }
            }
        }
        return $safe;
    }

    private function persist(): void
    {
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $md = "# Evidencia HTTP T01-T04\n\nEjecucion: {$this->data['run_id']}\n\n" .
            "UTC: {$this->data['started_utc']}\n\nEndpoint: " . ($this->data['endpoint'] ?? 'No verificado') .
            "\n\nPHP: {$this->data['php_version']}\n\nEstado global: {$this->data['status']}\n\n";
        foreach ($this->data['tests'] as $name => $case) {
            $md .= "## {$name}\n\n**{$case['status']}**: {$case['explanation']}\n\n";
            foreach ($case['requests'] as $label => $request) {
                $md .= "### POST {$label}\n\n```json\n" . json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n```\n\n";
            }
            foreach ($case['sql'] as $label => $sql) {
                $md .= "### SQL {$label}\n\n```sql\n{$sql['count_query']};\n{$sql['rows_query']};\n```\n\n```json\n" .
                    json_encode(array_diff_key($sql, ['count_query' => true, 'rows_query' => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n```\n\n";
            }
            foreach ($case['assertions'] as $check) {
                $md .= '- ' . ($check['passed'] ? 'PASS' : 'FAIL') . ': ' . $check['description'] . "\n";
            }
            $md .= "\n";
        }
        if (isset($this->data['failure'])) { $md .= "Fallo: {$this->data['failure']}\n"; }
        foreach ([$json, $md] as $text) {
            if (preg_match('/authorization|bearer\s|[a-f0-9]{64}/i', $text)) { throw new RuntimeException('Contenido sensible bloqueado.'); }
            foreach ($this->secrets as $secret => $_) {
                if (str_contains($text, (string) $secret)) { throw new RuntimeException('Contenido sensible bloqueado.'); }
            }
        }
        // JSON se publica al final; el estado final solo se anuncia tras guardar ambos archivos.
        foreach (['summary.md' => $md, 'evidence.json' => $json] as $file => $content) {
            $temporary = @tempnam($this->directory, '.capture-');
            if ($temporary === false) { throw new RuntimeException('No se pudo guardar evidencia.'); }
            try {
                if (@file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)
                    || !@rename($temporary, $this->directory . '/' . $file)) {
                    throw new RuntimeException('No se pudo guardar evidencia.');
                }
            } finally { if (is_file($temporary)) { @unlink($temporary); } }
        }
    }
}

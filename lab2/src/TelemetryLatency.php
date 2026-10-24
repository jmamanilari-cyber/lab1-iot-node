<?php
declare(strict_types=1);

namespace Lab2;

/** Optional, non-fatal, server-side timing of TelemetryService::receive(). */
final class TelemetryLatency
{
    private ?int $startedNs = null;
    private ?string $csv = null;
    private string $transport;
    private string $nodeId;
    private ?int $seq;
    private string $result = 'error';

    public function __construct(string $transport, string $nodeId, mixed $seq)
    {
        if (!in_array($transport, ['http', 'mqtt'], true)) {
            return;
        }
        $root = dirname(__DIR__) . '/logs/latency';
        $name = @file_get_contents($root . '/active');
        if (!is_string($name)) {
            return;
        }
        $name = trim($name);
        if (!preg_match('/\A[0-9A-Za-z_-]{8,80}\z/', $name)) {
            return;
        }
        $csv = $root . '/' . $name . '/measurements.csv';
        if (!is_file($csv)) {
            return;
        }
        $this->csv = $csv;
        $this->transport = $transport;
        $this->nodeId = $nodeId;
        $this->seq = is_int($seq) ? $seq : null;
        $this->startedNs = hrtime(true);
    }

    public function markSuccess(bool $created): void
    {
        $this->result = $created ? 'created' : 'duplicate';
    }

    public function __destruct()
    {
        if ($this->startedNs === null || $this->csv === null) {
            return;
        }
        try {
            $elapsedMs = (hrtime(true) - $this->startedNs) / 1000000;
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s.v\Z');
            // Trusted node IDs cannot contain commas; no payloads or secrets stored.
            $row = implode(',', [
                $now, $this->transport, $this->nodeId, $this->seq ?? '',
                $this->result, number_format($elapsedMs, 3, '.', ''),
            ]) . "\n";
            @file_put_contents($this->csv, $row, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Observability must never change ingestion results.
        }
    }
}

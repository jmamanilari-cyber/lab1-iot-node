<?php
declare(strict_types=1);

// CLI only. Never print tokens or accept them as command-line arguments.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = null;
$temporary = null;
$original = null;
$replaced = false;
$path = dirname(__DIR__) . '/config/test-tokens.local.json';
try {
    $args = array_slice($argv, 1);
    $rotate = in_array('--rotate', $args, true);
    $nodes = array_values(array_filter($args, static fn ($arg) => $arg !== '--rotate'));
    if ($rotate && count($nodes) !== 1) {
        throw new RuntimeException('Renovacion: indique exactamente un node_id y --rotate.');
    }
    if (!$nodes) { $nodes = ['esp32-01', 'esp32-02', 'esp32-03']; }
    foreach ($nodes as $node) {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $node)) {
            throw new RuntimeException('node_id invalido.');
        }
    }
    $original = is_file($path) ? file_get_contents($path) : null;
    $tokens = $original === null ? [] : json_decode($original, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($tokens)) { throw new RuntimeException('Archivo privado invalido.'); }
    $pdo = \Lab2\Database::connect();
    $pdo->beginTransaction();
    $changed = 0;
    // Stable lock order when provisioning several nodes concurrently.
    sort($nodes, SORT_STRING);
    foreach (array_unique($nodes) as $node) {
        $query = $pdo->prepare('SELECT id, token_hash FROM devices WHERE node_id = :node FOR UPDATE');
        $query->execute(['node' => $node]);
        $device = $query->fetch();
        if (!$device) { throw new RuntimeException('Dispositivo no registrado; no se realizaron cambios.'); }
        if ($device['token_hash'] !== null && !$rotate) {
            $existing = $tokens[$node] ?? '';
            if (is_string($existing) && preg_match('/\A[a-f0-9]{64}\z/', $existing)
                && hash_equals($device['token_hash'], 'sha256:' . hash('sha256', $existing))) {
                continue;
            }
            throw new RuntimeException('Ya existe un token sin copia local coincidente. Renueve explicitamente ese nodo con --rotate.');
        }
        $tokens[$node] = bin2hex(random_bytes(32));
        $query = $pdo->prepare('UPDATE devices SET token_hash = :hash WHERE id = :id');
        $query->execute(['hash' => 'sha256:' . hash('sha256', $tokens[$node]), 'id' => $device['id']]);
        ++$changed;
    }
    if ($changed > 0) {
        $temporary = tempnam(dirname($path), 'tokens-');
        if ($temporary === false || file_put_contents($temporary,
            json_encode($tokens, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar la copia privada de tokens.');
        }
        if (!rename($temporary, $path)) { throw new RuntimeException('No se pudo reemplazar el archivo privado.'); }
        $temporary = null;
        $replaced = true;
    }
    $pdo->commit();
    fwrite(STDOUT, "OK: tokens aprovisionados o conservados; copia privada en config/test-tokens.local.json. No se muestran secretos.\n");
} catch (Throwable) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
    if ($temporary !== null && is_file($temporary)) { unlink($temporary); }
    if ($replaced) {
        if ($original === null) { unlink($path); }
        else { file_put_contents($path, $original, LOCK_EX); }
    }
    fwrite(STDERR, "ERROR: aprovisionamiento no completado. Revise configuracion, nodos y copia privada; use --rotate solo para renovar un nodo.\n");
    exit(1);
}

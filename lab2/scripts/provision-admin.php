<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$path = dirname(__DIR__) . '/config/admin.local.json';
$temp = null;
try {
    if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--rotate')) { throw new RuntimeException(); }
    if (is_file($path) && !isset($argv[1])) {
        $config = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (!isset($config['token'], $config['token_hash']) || !is_string($config['token'])
            || !is_string($config['token_hash']) || !preg_match('/\A[a-f0-9]{64}\z/', $config['token'])
            || !hash_equals($config['token_hash'], 'sha256:' . hash('sha256', $config['token']))) { throw new RuntimeException(); }
        echo "OK: token administrativo existente conservado.\n";
        exit;
    }
    $token = bin2hex(random_bytes(32));
    $json = json_encode(['token' => $token, 'token_hash' => 'sha256:' . hash('sha256', $token)], JSON_THROW_ON_ERROR);
    $temp = tempnam(dirname($path), 'admin-');
    if ($temp === false || file_put_contents($temp, $json, LOCK_EX) === false || !rename($temp, $path)) { throw new RuntimeException(); }
    echo "OK: token administrativo guardado en config/admin.local.json (privado); no se muestra.\n";
} catch (Throwable) {
    if (is_string($temp) && is_file($temp)) { unlink($temp); }
    fwrite(STDERR, "ERROR: aprovisionamiento administrativo no completado; revise archivo privado o use --rotate explicitamente.\n");
    exit(1);
}

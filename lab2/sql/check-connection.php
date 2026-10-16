<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Database.php';

try {
    $pdo = \Lab2\Database::connect();
    $statement = $pdo->prepare('SELECT :probe AS probe, @@session.time_zone AS timezone');
    $statement->bindValue(':probe', 1, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch();
    if (!$row || (int) $row['probe'] !== 1 || $row['timezone'] !== '+00:00') {
        throw new RuntimeException('La comprobacion PDO o UTC no coincide.');
    }
    fwrite(STDOUT, "OK: conexion PDO, consulta preparada nativa y sesion UTC.\n");
} catch (Throwable $error) {
    $message = $error instanceof RuntimeException
        ? $error->getMessage() : 'Error interno durante la comprobacion PDO.';
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

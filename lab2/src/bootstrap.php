<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Lab2\\')) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

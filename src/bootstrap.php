<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'SitemapScout\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

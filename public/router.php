<?php

declare(strict_types=1);

// Router für den PHP Built-in Server: php -S localhost:8080 -t public public/router.php

if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = __DIR__ . $path;
    if (is_string($path) && $path !== '/' && is_file($file)) {
        return false;
    }
}

require __DIR__ . '/index.php';

<?php

declare(strict_types=1);

// Front controller. Every request that is not a real file inside public/ ends up here.
// Keep this file free of PHP 8 syntax so the version check below can run on older PHP.

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "GFH needs PHP 8.2 or newer.\n";
    exit(1);
}

$app = require dirname(__DIR__) . '/core/bootstrap.php';
$app->run();

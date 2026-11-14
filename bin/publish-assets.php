#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$app = require dirname(__DIR__) . '/core/bootstrap.php';

exit((new Gfh\Core\Console\PublishAssetsCommand($app))->run($argv));

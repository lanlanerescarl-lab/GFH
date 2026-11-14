<?php

declare(strict_types=1);

// Test bootstrap. The project's own autoloader is used, so tests run with either Composer's PHPUnit or phpunit.phar.

require_once dirname(__DIR__) . '/core/Autoloader.php';

$autoloader = new Gfh\Core\Autoloader();
$autoloader->addNamespace('Gfh\\Core', dirname(__DIR__) . '/core');
$autoloader->addNamespace('Gfh\\Tests', __DIR__);
$autoloader->register();

date_default_timezone_set(Gfh\Core\Config\Config::TIMEZONE);

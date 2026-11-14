<?php

declare(strict_types=1);

use Gfh\Core\App;
use Gfh\Core\Autoloader;

// Loaded by public/index.php and, later, by the scripts in bin/.
// Returns the booted application object.

require_once __DIR__ . '/Autoloader.php';

$autoloader = new Autoloader();
$autoloader->addNamespace('Gfh\\Core', __DIR__);
$autoloader->register();

return App::boot(dirname(__DIR__));

<?php

declare(strict_types=1);

use Gfh\Core\App;
use Gfh\Seeds\Demo\DemoCatalogSeeder;

require_once __DIR__ . '/support/DemoProductPhoto.php';
require_once __DIR__ . '/support/DemoCatalogSeeder.php';

/**
 * Batch 8.1a: grows the demo catalog to 24 products across three lines (food and beverage, home appliances,
 * furniture and decor), stocks the new ones, and gives every product a generated photo.
 *
 * The fourth argument is the organization slug (SeedRunner passes it). The seed refuses anything but "demo".
 * It is repeatable: when its anchor product already exists it does nothing, and it then writes no photo files either.
 * Run it after 0001, which it builds on. It stores no password or secret.
 */
return static function (\PDO $organization, \PDO $core, App $app, ?string $slug = null): void {
    (new DemoCatalogSeeder($organization, $core, $app->path('storage/uploads')))->run($slug);
};

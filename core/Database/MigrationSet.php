<?php

declare(strict_types=1);

namespace Gfh\Core\Database;

/**
 * A folder of numbered .sql files that belong together.
 * The scope names the set in schema_migrations: "core", "org", or an extension key.
 */
final class MigrationSet
{
    public function __construct(
        public readonly string $scope,
        public readonly string $directory,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $scope) !== 1) {
            throw new \InvalidArgumentException('A migration scope must be lower-case letters, digits and underscores.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Gfh\Core\Database;

/** Thrown when migrations cannot be applied safely: a changed or missing file, a bad file name, or a failing statement. */
final class MigrationException extends \RuntimeException
{
}

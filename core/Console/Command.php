<?php

declare(strict_types=1);

namespace Gfh\Core\Console;

use Gfh\Core\App;

/** Base class for the scripts in bin/. Each returns a process exit code: 0 success, 1 failure, 2 wrong usage. */
abstract class Command
{
    public function __construct(protected readonly App $app)
    {
    }

    /** @param list<string> $argv */
    abstract public function run(array $argv): int;

    protected function out(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    protected function err(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}

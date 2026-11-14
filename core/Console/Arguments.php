<?php

declare(strict_types=1);

namespace Gfh\Core\Console;

/** Reads command-line options of the form --name=value and --flag. */
final class Arguments
{
    /**
     * @param list<string> $argv    the full $argv, including the script name
     * @param list<string> $allowed option names this command accepts
     * @return array<string, string|true>
     * @throws \InvalidArgumentException for anything that is not an allowed option
     */
    public static function parse(array $argv, array $allowed): array
    {
        $options = [];

        foreach (array_slice($argv, 1) as $argument) {
            if (!str_starts_with($argument, '--')) {
                // Only the part before "=" is repeated, in case the value is a password.
                throw new \InvalidArgumentException(sprintf('Unexpected argument "%s". Options look like --name=value.', (string) strtok($argument, '=')));
            }

            $body = substr($argument, 2);
            $separator = strpos($body, '=');
            $name = $separator === false ? $body : substr($body, 0, $separator);
            $value = $separator === false ? true : substr($body, $separator + 1);

            if (!in_array($name, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown option --%s.', $name));
            }

            $options[$name] = $value;
        }

        return $options;
    }
}

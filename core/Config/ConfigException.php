<?php

declare(strict_types=1);

namespace Gfh\Core\Config;

/** Thrown when the .env file or a required setting is missing or malformed. */
final class ConfigException extends \RuntimeException
{
}

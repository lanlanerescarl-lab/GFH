<?php

declare(strict_types=1);

namespace Gfh\Core\Extension;

/** An extension is broken (bad manifest, unknown hook, failing entry file). The loader catches it and skips that extension. */
final class ExtensionException extends \RuntimeException
{
}

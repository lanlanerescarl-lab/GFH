<?php

declare(strict_types=1);

namespace Gfh\Core\Routing;

/** One registered route. Created by the Router; not used directly. */
final class Route
{
    /**
     * @param list<\Closure> $middleware outermost first
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly string $regex,
        public readonly \Closure $handler,
        public readonly array $middleware = [],
    ) {
    }
}

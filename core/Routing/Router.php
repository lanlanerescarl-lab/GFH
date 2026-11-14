<?php

declare(strict_types=1);

namespace Gfh\Core\Routing;

use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/**
 * A small path router.
 *
 * Patterns use {name} for one path segment or {name:regex} for a custom match
 * (the regex cannot contain curly braces), for example "/{org:[a-z0-9-]+}/shop".
 * A handler is any callable taking a Request and returning a Response; read route
 * parameters with $request->param('org'). Middleware are callables taking
 * (Request $request, callable $next) and returning a Response.
 * Routes are tried in the order they were registered.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];
    private string $prefix = '';
    /** @var list<\Closure> */
    private array $groupMiddleware = [];

    /** @param list<callable> $middleware */
    public function get(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function post(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function put(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $pattern, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function patch(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $pattern, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function delete(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $joined = (string) preg_replace('#/{2,}#', '/', trim($this->prefix . '/' . $pattern, '/'));
        $full = '/' . $joined;

        $this->routes[] = new Route(
            strtoupper($method),
            $full,
            self::compile($full),
            \Closure::fromCallable($handler),
            array_merge($this->groupMiddleware, array_map(\Closure::fromCallable(...), $middleware)),
        );
    }

    /**
     * Registers several routes under one prefix and, optionally, shared middleware.
     *
     * @param callable(Router): void $define
     * @param list<callable>         $middleware
     */
    public function group(string $prefix, callable $define, array $middleware = []): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->prefix = $previousPrefix . '/' . trim($prefix, '/');
        $this->groupMiddleware = array_merge($previousMiddleware, array_map(\Closure::fromCallable(...), $middleware));

        try {
            $define($this);
        } finally {
            $this->prefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }
    }

    /**
     * Finds the matching route and runs it.
     *
     * @throws HttpException 404 when nothing matches, 405 when the path exists for other methods only
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route->regex, $request->path(), $matches) !== 1) {
                continue;
            }

            if ($route->method !== $lookup) {
                $allowed[$route->method] = true;
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return $this->run($route, $request->withParams($params));
        }

        if ($allowed !== []) {
            if (isset($allowed['GET'])) {
                $allowed['HEAD'] = true;
            }

            throw HttpException::methodNotAllowed(array_keys($allowed));
        }

        throw HttpException::notFound();
    }

    private function run(Route $route, Request $request): Response
    {
        $next = static function (Request $request) use ($route): Response {
            $result = ($route->handler)($request);
            if (!$result instanceof Response) {
                throw new \LogicException(sprintf('The handler for %s %s must return a Response.', $route->method, $route->pattern));
            }

            return $result;
        };

        // Wrap from the inside out so the first middleware in the list runs first.
        foreach (array_reverse($route->middleware) as $middleware) {
            $next = static fn (Request $request): Response => $middleware($request, $next);
        }

        return $next($request);
    }

    private static function compile(string $pattern): string
    {
        $parts = preg_split('#(\{[^{}]+\})#', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $regex = '';

        foreach ($parts as $part) {
            if ($part[0] === '{' && preg_match('#^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::(.+))?\}$#', $part, $match) === 1) {
                $regex .= '(?P<' . $match[1] . '>' . ($match[2] ?? '[^/]+') . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        return '#^' . $regex . '$#D';
    }
}

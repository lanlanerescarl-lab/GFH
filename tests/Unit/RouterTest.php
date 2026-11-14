<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesStaticPathsAndParameters(): void
    {
        $router = new Router();
        $router->get('/health', static fn (Request $r) => Response::text('health'));
        $router->get('/{org:[a-z][a-z0-9-]*}/shop/{slug}', static fn (Request $r) => Response::text($r->param('org') . '|' . $r->param('slug')));

        $this->assertSame('health', $router->dispatch(new Request('GET', '/health'))->body());
        $this->assertSame('fnb|green-tea', $router->dispatch(new Request('GET', '/fnb/shop/green-tea/?q=1'))->body());
    }

    public function testPathsAreNormalisedAndDecoded(): void
    {
        $request = new Request('GET', '//demo///shop//a%20b/', [], [], [], [], ['SCRIPT_NAME' => '/index.php']);
        $this->assertSame('/demo/shop/a b', $request->path());

        $installed = new Request('GET', '/gfh/public/health', [], [], [], [], ['SCRIPT_NAME' => '/gfh/public/index.php']);
        $this->assertSame('/health', $installed->path());
        $this->assertSame('/gfh/public', $installed->basePath());

        $this->assertSame('/_invalid', (new Request('GET', "/bad%00path"))->path());
    }

    public function testUnknownPathsAre404AndWrongMethodsAre405WithAnAllowHeader(): void
    {
        $router = new Router();
        $router->get('/thing', static fn (Request $r) => Response::text('ok'));
        $router->post('/thing', static fn (Request $r) => Response::text('ok'));

        try {
            $router->dispatch(new Request('GET', '/missing'));
            $this->fail('Expected a 404.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->status());
        }

        try {
            $router->dispatch(new Request('DELETE', '/thing'));
            $this->fail('Expected a 405.');
        } catch (HttpException $exception) {
            $this->assertSame(405, $exception->status());
            $this->assertSame('GET, POST, HEAD', $exception->headers()['Allow']);
        }
    }

    public function testHeadUsesTheGetRoute(): void
    {
        $router = new Router();
        $router->get('/thing', static fn (Request $r) => Response::text('ok'));

        $this->assertSame(200, $router->dispatch(new Request('HEAD', '/thing'))->status());
    }

    public function testGroupsAddPrefixesAndMiddlewareRunsOutsideIn(): void
    {
        $order = [];
        $outer = static function (Request $request, callable $next) use (&$order): Response {
            $order[] = 'outer';

            return $next($request->withAttribute('seen', 'yes'));
        };
        $inner = static function (Request $request, callable $next) use (&$order): Response {
            $order[] = 'inner';

            return $next($request);
        };

        $router = new Router();
        $router->group('/{org}', static function (Router $r) use ($inner): void {
            $r->get('/api/v1/ping', static fn (Request $request) => Response::text((string) $request->attribute('seen') . ':' . $request->param('org')), [$inner]);
        }, [$outer]);

        $response = $router->dispatch(new Request('GET', '/demo/api/v1/ping'));

        $this->assertSame('yes:demo', $response->body());
        $this->assertSame(['outer', 'inner'], $order);
    }

    public function testAHandlerMustReturnAResponse(): void
    {
        $router = new Router();
        $router->get('/bad', static fn (Request $r) => 'just a string');

        $this->expectException(\LogicException::class);
        $router->dispatch(new Request('GET', '/bad'));
    }

    public function testErrorEnvelopeHasAnObjectForFields(): void
    {
        $this->assertSame(
            '{"ok":false,"error":{"code":"not_found","message":"Nope","fields":{}}}',
            Response::error('not_found', 'Nope', 404)->body(),
        );
    }
}

<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Auth\Csrf;
use Gfh\Core\Auth\Session;
use Gfh\Core\Config\Config;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Middleware\CsrfMiddleware;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $_SESSION = [];
        // The session is never started here; the token logic only uses its get and put methods.
        $this->session = new Session('staff', new Config([]), sys_get_temp_dir(), false);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheTokenIsStableWithinASessionAndChecksOut(): void
    {
        $csrf = new Csrf($this->session);
        $token = $csrf->token();

        $this->assertSame(64, strlen($token));
        $this->assertSame($token, $csrf->token());
        $this->assertTrue($csrf->verify($token));
    }

    public function testWrongMissingAndEmptyTokensFail(): void
    {
        $csrf = new Csrf($this->session);
        $token = $csrf->token();

        $this->assertFalse($csrf->verify(null));
        $this->assertFalse($csrf->verify(''));
        $this->assertFalse($csrf->verify($token . 'x'));
        $this->assertFalse($csrf->verify(strrev($token)));
    }

    public function testRotatingIssuesANewToken(): void
    {
        $csrf = new Csrf($this->session);
        $old = $csrf->token();
        $new = $csrf->rotate();

        $this->assertNotSame($old, $new);
        $this->assertFalse($csrf->verify($old));
        $this->assertTrue($csrf->verify($new));
    }

    public function testMiddlewareBlocksUnsafeRequestsWithoutAToken(): void
    {
        $token = (new Csrf($this->session))->token();
        $middleware = new CsrfMiddleware();
        $next = static fn (Request $request): Response => Response::text('reached');

        $without = (new Request('POST', '/demo/api/v1/auth/staff/login'))->withAttribute('session', $this->session);
        try {
            $middleware($without, $next);
            $this->fail('A POST without a token must be refused.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->status());
            $this->assertSame('csrf_mismatch', $exception->errorCode());
        }

        $wrong = (new Request('DELETE', '/demo/api/v1/x', [], [], [], [], ['HTTP_X_CSRF_TOKEN' => 'nope']))->withAttribute('session', $this->session);
        $this->expectException(HttpException::class);
        $middleware($wrong, $next);
    }

    public function testMiddlewareAllowsTheHeaderTheFormFieldAndSafeMethods(): void
    {
        $token = (new Csrf($this->session))->token();
        $middleware = new CsrfMiddleware();
        $next = static fn (Request $request): Response => Response::text('reached');

        $viaHeader = (new Request('POST', '/x', [], [], [], [], ['HTTP_X_CSRF_TOKEN' => $token]))->withAttribute('session', $this->session);
        $viaForm = (new Request('POST', '/x', [], ['_csrf' => $token]))->withAttribute('session', $this->session);
        $safe = (new Request('GET', '/x'))->withAttribute('session', $this->session);

        $this->assertSame('reached', $middleware($viaHeader, $next)->body());
        $this->assertSame('reached', $middleware($viaForm, $next)->body());
        $this->assertSame('reached', $middleware($safe, $next)->body());
    }
}

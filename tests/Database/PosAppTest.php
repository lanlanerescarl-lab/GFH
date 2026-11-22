<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\AuthService;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Organization;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Batch 7.1: the POS app's pages (/{org}/pos, /{org}/pos/login) and the terminal list the app starts from.
 *
 * Every request goes through the real App + Router + core/routes.php + middleware stack, so a controller left out of
 * the route group, a sign-in without CSRF, or a page that skips the permission check is caught here.
 */
final class PosAppTest extends DatabaseTestCase
{
    private App $app;
    private Organization $org1;
    private StaffUser $admin;
    private StaffUser $manager;
    private StaffUser $cashier;
    private StaffUser $staff;
    private string $branch = '';
    /** @var list<string> folders created under orgs/ by a test, removed in tearDown */
    private array $madeFolders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 2));
        $this->org1 = $this->kit->organization('pos-a-' . bin2hex(random_bytes(3)));
        $this->admin = $this->person('admin@pos-a.test', 'admin', 'Alma Admin');
        $this->manager = $this->person('manager@pos-a.test', 'manager', 'Mara Manager');
        $this->cashier = $this->person('casey@pos-a.test', 'cashier', 'Casey Cashier');
        $this->staff = $this->person('sam@pos-a.test', 'staff', 'Sam Staff');
        $this->branch = $this->shop->branch('S1');
    }

    protected function tearDown(): void
    {
        foreach ($this->madeFolders as $folder) {
            $this->removeTree($folder);
        }
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function person(string $email, string $role, string $name): StaffUser
    {
        $user = $this->kit->staff($email, TestKit::PASSWORD, null, 'active', $name);
        $this->kit->join($this->org1, $user, $role);

        return $user;
    }

    /** @return array{services: Services, session: Session} one browser: a session (signed in as $actor, or signed out) and the services using it */
    private function desk(?StaffUser $actor): array
    {
        $session = Session::inMemory('staff', $this->app->config(), $actor === null ? [] : ['user_id' => $actor->id]);

        return [
            'services' => new Services($this->app, false, $this->core, $this->org, testSessions: ['staff' => $session]),
            'session' => $session,
        ];
    }

    /**
     * @param array{services: Services, session: Session} $desk
     * @param array<string, string> $post
     */
    private function call(array $desk, string $method, string $path, array $post = [], bool $withCsrf = true, string $ip = '198.51.100.20', bool $json = false): Response
    {
        if ($method === 'POST' && $withCsrf) {
            $post['_csrf'] = $desk['services']->csrf($desk['session'])->token();
        }
        $server = ['REMOTE_ADDR' => $ip] + ($json ? ['HTTP_ACCEPT' => 'application/json'] : []);

        return $this->app->handle(new Request($method, $path, post: $post, server: $server), $desk['services']);
    }

    private function pos(string $path = ''): string
    {
        return '/' . $this->org1->slug . '/pos' . $path;
    }

    private function api(string $path): string
    {
        return '/' . $this->org1->slug . '/api/v1/pos' . $path;
    }

    /** @return array<string, mixed> the JSON config block of the shell page */
    private function config(Response $response): array
    {
        $this->assertSame(1, preg_match('~<script type="application/json" id="pos-config">(.*?)</script>~s', $response->body(), $match), 'the shell has exactly one config block');

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    // ------------------------------------------------------------------ the shell page

    /** @return array<string, array{0: string}> */
    public static function allowedRoles(): array
    {
        return ['cashier' => ['cashier'], 'manager' => ['manager'], 'admin' => ['admin']];
    }

    #[DataProvider('allowedRoles')]
    public function testCashierManagerAndAdminLoadThePos(string $role): void
    {
        $response = $this->call($this->desk($this->{$role}), 'GET', $this->pos());

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('id="pos-app"', $response->body());
        $this->assertStringContainsString('<template id="receipt-template">', $response->body());
        $this->assertStringContainsString('assets/pos/js/app.js', $response->body());
        $this->assertStringContainsString('type="module"', $response->body());
    }

    public function testStaffWithoutPosAccessGetsTheForbiddenPage(): void
    {
        $response = $this->call($this->desk($this->staff), 'GET', $this->pos());

        $this->assertSame(403, $response->status());
        $this->assertStringNotContainsString('id="pos-app"', $response->body());
        $this->assertStringNotContainsString('pos-config', $response->body());
    }

    public function testASignedOutVisitorIsSentToThePosSignIn(): void
    {
        $response = $this->call($this->desk(null), 'GET', $this->pos());

        $this->assertSame(302, $response->status());
        $this->assertSame($this->pos('/login'), $response->header('Location'));
    }

    public function testThePosSignInPageLoadsForASignedOutVisitor(): void
    {
        $response = $this->call($this->desk(null), 'GET', $this->pos('/login'));

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('name="_csrf"', $response->body());
        $this->assertStringContainsString('name="email"', $response->body());
        $this->assertStringContainsString('name="password"', $response->body());
    }

    public function testTheConfigBlockHoldsTheCsrfTokenAndNoSecret(): void
    {
        $desk = $this->desk($this->cashier);
        $response = $this->call($desk, 'GET', $this->pos());
        $config = $this->config($response);

        $this->assertSame($desk['services']->csrf($desk['session'])->token(), $config['csrf']);
        $this->assertNotSame('', $config['csrf']);
        $this->assertSame(['apiBase', 'csrf', 'loginUrl', 'mediaBase', 'organization', 'posBase', 'receiptTemplate', 'user'], $this->sortedKeys($config));
        $this->assertSame(['id', 'name'], $this->sortedKeys($config['user']));
        $this->assertSame('Casey Cashier', $config['user']['name']);
        $this->assertSame('/' . $this->org1->slug . '/api/v1', $config['apiBase']);

        // Nothing that could be a secret: no password hash, no database address, no application key, no email.
        $row = $this->kit->userRow($this->cashier);
        $block = (string) json_encode($config);
        foreach ([(string) $row['password_hash'], 'postgresql://', 'APP_KEY', 'password', 'casey@pos-a.test', TestKit::PASSWORD] as $secret) {
            $this->assertStringNotContainsString($secret, $block, "the config block must not hold: {$secret}");
        }
    }

    public function testAMarkupInAPersonsNameCannotEndTheConfigBlock(): void
    {
        $evil = $this->kit->staff('evil@pos-a.test', TestKit::PASSWORD, null, 'active', '</script><b>x</b>');
        $this->kit->join($this->org1, $evil, 'cashier');

        $response = $this->call($this->desk($evil), 'GET', $this->pos());

        $this->assertSame(200, $response->status());
        $this->assertSame(0, substr_count($response->body(), '</script><b>'), 'a raw "</script>" inside the data would end the block and run markup');
        $this->assertSame('</script><b>x</b>', $this->config($response)['user']['name'], 'the name still arrives intact once the JSON is read');
    }

    public function testNoPosPageContainsAUrlToAnotherHost(): void
    {
        foreach ([[$this->cashier, $this->pos()], [null, $this->pos('/login')]] as [$person, $path]) {
            $body = $this->call($this->desk($person), 'GET', $path)->body();
            $this->assertSame(0, preg_match('~(?:https?:)?//[a-z0-9][a-z0-9.-]*\.[a-z]{2,}~i', $body, $found), "{$path} links to another host: " . ($found[0] ?? ''));
            $this->assertSame(0, preg_match('~\b(?:src|href|action)\s*=\s*["\']\s*https?:~i', $body), "{$path} has an absolute src, href or action");
        }
    }

    public function testNoPosScriptOrStylesheetMentionsAnotherHost(): void
    {
        $files = array_merge(glob(dirname(__DIR__, 2) . '/templates/pos/assets/js/*.js') ?: [], glob(dirname(__DIR__, 2) . '/templates/pos/assets/css/*.css') ?: [], glob(dirname(__DIR__, 2) . '/public/assets/pos/js/*.js') ?: []);
        $this->assertGreaterThan(15, count($files));
        foreach ($files as $file) {
            $this->assertSame(0, preg_match('~https?://|//cdn\.|@import\s+url|fonts\.googleapis~i', (string) file_get_contents($file), $found), basename($file) . ' reaches outside: ' . ($found[0] ?? ''));
        }
    }

    public function testAnOverrideOfTheReceiptPartialReplacesTheBuiltInOne(): void
    {
        $desk = $this->desk($this->cashier);
        $builtIn = $this->call($desk, 'GET', $this->pos())->body();
        $this->assertStringContainsString('Thank you for your purchase.', $builtIn);
        $this->assertStringContainsString('data-bind="receiptNumber"', $builtIn);

        $folder = dirname(__DIR__, 2) . '/orgs/' . $this->org1->slug;
        $this->madeFolders[] = $folder;
        mkdir($folder . '/pos/partials', 0775, true);
        file_put_contents($folder . '/pos/partials/receipt.php', '<article class="receipt"><p data-bind="receiptNumber"></p><p class="receipt__footer">Salamat po! Balik kayo.</p></article>');

        $overridden = $this->call($desk, 'GET', $this->pos())->body();

        $this->assertStringContainsString('Salamat po! Balik kayo.', $overridden);
        $this->assertStringNotContainsString('Thank you for your purchase.', $overridden);
        $this->assertStringNotContainsString('data-bind="terminalCode"', $overridden, 'the built-in partial is replaced, not extended');
    }

    // ------------------------------------------------------------------ the POS sign-in

    public function testSigningInReturnsToThePos(): void
    {
        $desk = $this->desk(null);
        $response = $this->call($desk, 'POST', $this->pos('/login'), ['email' => 'casey@pos-a.test', 'password' => TestKit::PASSWORD]);

        $this->assertSame(302, $response->status());
        $this->assertSame($this->pos(), $response->header('Location'));
        $this->assertSame(200, $this->call($desk, 'GET', $this->pos())->status(), 'the same browser is now signed in');
        $this->assertSame($this->pos(), $this->call($desk, 'GET', $this->pos('/login'))->header('Location'), 'the sign-in page sends a signed-in person on');
    }

    public function testAWrongEmailAndAWrongPasswordGetTheSameMessage(): void
    {
        $wrongPassword = $this->call($this->desk(null), 'POST', $this->pos('/login'), ['email' => 'casey@pos-a.test', 'password' => 'not-the-password'], ip: '203.0.113.31');
        $wrongEmail = $this->call($this->desk(null), 'POST', $this->pos('/login'), ['email' => 'nobody@pos-a.test', 'password' => 'not-the-password'], ip: '203.0.113.32');

        $this->assertSame(401, $wrongPassword->status());
        $this->assertSame(401, $wrongEmail->status());
        $this->assertStringContainsString(htmlspecialchars(AuthService::INVALID_MESSAGE, ENT_QUOTES), $wrongPassword->body());
        $this->assertStringContainsString(htmlspecialchars(AuthService::INVALID_MESSAGE, ENT_QUOTES), $wrongEmail->body());
        $this->assertSame($this->visibleError($wrongPassword), $this->visibleError($wrongEmail));
        $this->assertStringContainsString('value="casey@pos-a.test"', $wrongPassword->body(), 'the email stays in the form');
        $this->assertStringNotContainsString('not-the-password', $wrongPassword->body());
    }

    public function testRepeatedFailuresLockThePosSignInAndEvenTheRightPasswordIsRefused(): void
    {
        $limit = max(1, $this->app->config()->int('LOGIN_MAX_ATTEMPTS', 5));
        for ($i = 0; $i < $limit; $i++) {
            $this->assertSame(401, $this->call($this->desk(null), 'POST', $this->pos('/login'), ['email' => 'casey@pos-a.test', 'password' => 'wrong-' . $i], ip: '203.0.113.41')->status());
        }

        $locked = $this->call($this->desk(null), 'POST', $this->pos('/login'), ['email' => 'casey@pos-a.test', 'password' => TestKit::PASSWORD], ip: '203.0.113.41');

        $this->assertSame(429, $locked->status());
        $this->assertStringContainsString(htmlspecialchars(AuthService::LOCKED_MESSAGE, ENT_QUOTES), $locked->body());
    }

    public function testSigningInAndOutNeedsACsrfToken(): void
    {
        $signedOut = $this->desk(null);
        $this->assertSame(403, $this->call($signedOut, 'POST', $this->pos('/login'), ['email' => 'casey@pos-a.test', 'password' => TestKit::PASSWORD], withCsrf: false)->status());
        $this->assertSame(302, $this->call($this->desk(null), 'GET', $this->pos())->status(), 'no session was started by the refused sign-in');

        $signedIn = $this->desk($this->cashier);
        $this->assertSame(403, $this->call($signedIn, 'POST', $this->pos('/logout'), withCsrf: false)->status());
        $this->assertSame(200, $this->call($signedIn, 'GET', $this->pos())->status(), 'a refused sign-out leaves the person signed in');
    }

    public function testSigningOutEndsTheSessionAndShowsTheSignInPage(): void
    {
        $desk = $this->desk($this->cashier);
        $response = $this->call($desk, 'POST', $this->pos('/logout'));

        $this->assertSame(302, $response->status());
        $this->assertSame($this->pos('/login'), $response->header('Location'));
        $this->assertSame($this->pos('/login'), $this->call($desk, 'GET', $this->pos())->header('Location'));
    }

    public function testThePosSignInCarriesTheSamePasswordRulesAsTheAdminOne(): void
    {
        // A person with no membership here is refused with a reason, and no session is made.
        $other = $this->kit->staff('elsewhere@pos-a.test', TestKit::PASSWORD, null, 'active', 'Elsa Elsewhere');
        $desk = $this->desk(null);
        $response = $this->call($desk, 'POST', $this->pos('/login'), ['email' => 'elsewhere@pos-a.test', 'password' => TestKit::PASSWORD], ip: '203.0.113.51');

        $this->assertSame(403, $response->status());
        $this->assertSame($this->pos('/login'), $this->call($desk, 'GET', $this->pos())->header('Location'));
        $this->assertNotNull($other);
    }

    // ------------------------------------------------------------------ GET /api/v1/pos/terminals

    public function testTheTerminalListHoldsOnlyActiveTerminalsWithTheirBranchAndShiftState(): void
    {
        $open = $this->shop->terminal($this->branch, 'T3');
        $idle = $this->shop->terminal($this->branch, 'T4');
        $off = $this->shop->terminal($this->branch, 'T9');
        $this->shop->scalar("UPDATE terminals SET status = 'inactive' WHERE id = ? RETURNING id", [$off]);
        $this->shop->shift($open, '1000.00');

        $response = $this->call($this->desk($this->cashier), 'GET', $this->api('/terminals'), json: true);

        $this->assertSame(200, $response->status());
        $items = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR)['data']['items'];
        $byCode = [];
        foreach ($items as $item) {
            $byCode[$item['code']] = $item;
        }
        $this->assertSame(['T3', 'T4'], array_keys($byCode), 'the inactive terminal is not offered');
        $this->assertSame(['branch_name', 'code', 'has_open_shift', 'id', 'name'], $this->sortedKeys($byCode['T3']));
        $this->assertSame('Branch S1', $byCode['T3']['branch_name']);
        $this->assertTrue($byCode['T3']['has_open_shift']);
        $this->assertFalse($byCode['T4']['has_open_shift']);
        $this->assertSame($open, $byCode['T3']['id']);
        $this->assertSame($idle, $byCode['T4']['id']);
    }

    public function testAClosedShiftIsNotAnOpenShiftInTheTerminalList(): void
    {
        $terminal = $this->shop->terminal($this->branch, 'T3');
        $shift = $this->shop->shift($terminal, '1000.00');
        $this->shop->services->pos()->closeShift($shift, '1000.00', $this->cashier->id);

        $items = json_decode($this->call($this->desk($this->manager), 'GET', $this->api('/terminals'), json: true)->body(), true, 512, JSON_THROW_ON_ERROR)['data']['items'];

        $this->assertFalse($items[0]['has_open_shift']);
    }

    public function testTheTerminalListIsRefusedWithoutPosViewAndWithoutASession(): void
    {
        $this->shop->terminal($this->branch, 'T3');

        $this->assertSame(403, $this->call($this->desk($this->staff), 'GET', $this->api('/terminals'), json: true)->status());
        $this->assertSame(401, $this->call($this->desk(null), 'GET', $this->api('/terminals'), json: true)->status());
        foreach (['cashier', 'manager', 'admin'] as $role) {
            $this->assertSame(200, $this->call($this->desk($this->{$role}), 'GET', $this->api('/terminals'), json: true)->status(), $role);
        }
    }

    // ------------------------------------------------------------------ what the app reads from the existing endpoints

    public function testTheSnapshotCarriesTheBranchAddressForTheReceipt(): void
    {
        $this->shop->scalar("UPDATE branches SET address = '12 Lacson St', city = 'Bacolod City', province = 'Negros Occidental' WHERE id = ? RETURNING id", [$this->branch]);
        $terminal = $this->shop->terminal($this->branch, 'T3');

        $snapshot = json_decode($this->call($this->desk($this->cashier), 'GET', $this->api("/terminals/{$terminal}/snapshot"), json: true)->body(), true, 512, JSON_THROW_ON_ERROR)['data'];

        $this->assertSame('12 Lacson St, Bacolod City, Negros Occidental', $snapshot['terminal']['branch_address']);
        $this->assertSame('Branch S1', $snapshot['terminal']['branch_name']);

        $this->shop->scalar('UPDATE branches SET address = NULL, city = NULL, province = NULL WHERE id = ? RETURNING id', [$this->branch]);
        $bare = json_decode($this->call($this->desk($this->cashier), 'GET', $this->api("/terminals/{$terminal}/snapshot"), json: true)->body(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertSame('', $bare['terminal']['branch_address']);
    }

    public function testTheCurrentShiftAnswerCarriesTheNextReceiptNumberWhenAShiftIsOpen(): void
    {
        $terminal = $this->shop->terminal($this->branch, 'T3');
        $desk = $this->desk($this->cashier);

        $none = json_decode($this->call($desk, 'GET', $this->api("/terminals/{$terminal}/shift"), json: true)->body(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertNull($none['shift']);
        $this->assertNull($none['next_receipt_number']);

        $shift = $this->shop->shift($terminal, '500.00');
        $open = json_decode($this->call($desk, 'GET', $this->api("/terminals/{$terminal}/shift"), json: true)->body(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertSame($shift, $open['shift']['id']);
        $this->assertSame('T3-000001', $open['next_receipt_number']);
    }

    // ------------------------------------------------------------------ small helpers

    /**
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private function sortedKeys(array $values): array
    {
        $keys = array_keys($values);
        sort($keys);

        return $keys;
    }

    /** The error box of a sign-in page, as text. */
    private function visibleError(Response $response): string
    {
        $this->assertSame(1, preg_match('~<div class="pos-notice pos-notice--error" role="alert">(.*?)</div>~s', $response->body(), $match));

        return $match[1];
    }
}

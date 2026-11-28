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
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Batch SA.1a end-to-end tests through the real App + Router + core/routes.php + middleware stack.
 * Covers access matrix on all six pages, sign-in/sign-out/CSRF/lockout/session regeneration,
 * /superadmin route isolation from /{org}, output escaping, and no secret leaks.
 * Never passes an organization database connection to Services (proving Super Admin never opens one).
 */
final class SuperAdminBatch1aTest extends DatabaseTestCase
{
    private const IP = '198.51.100.42';

    private App $app;
    private bool $booted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 2));
        $this->booted = true;
    }

    protected function tearDown(): void
    {
        if ($this->booted) {
            restore_error_handler();
            restore_exception_handler();
        }
        parent::tearDown();
    }

    /** @return array{services: Services, staff: Session, customer: Session} */
    private function browser(?string $staffId = null, ?string $customerId = null): array
    {
        $staffSession = Session::inMemory('staff', $this->app->config(), $staffId === null ? [] : ['user_id' => $staffId]);
        $customerSession = Session::inMemory('customer', $this->app->config(), $customerId === null ? [] : ['user_id' => $customerId]);
        // Pass null for $orgConnection so any attempt to open an organization database fails immediately.
        $services = new Services(
            $this->app,
            false,
            $this->core,
            null,
            testSessions: ['staff' => $staffSession, 'customer' => $customerSession],
        );

        return ['services' => $services, 'staff' => $staffSession, 'customer' => $customerSession];
    }

    private function csrf(array $browser): string
    {
        return $browser['services']->csrf($browser['staff'])->token();
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     */
    private function request(
        array $browser,
        string $method,
        string $path,
        array $post = [],
        array $query = [],
        bool|string $csrf = true,
    ): Response {
        if ($method === 'POST' && $csrf !== false) {
            $post['_csrf'] = $csrf === true ? $this->csrf($browser) : $csrf;
        }

        return $this->app->handle(
            new Request($method, $path, query: $query, post: $post, server: ['REMOTE_ADDR' => self::IP]),
            $browser['services'],
        );
    }

    /** @return array<string, array{string, string, string}> */
    public static function sixPages(): array
    {
        return [
            'overview' => ['/superadmin', 'Group Overview', 'Consolidated performance and operational health across GFH organizations.'],
            'organizations' => ['/superadmin/organizations', 'Organizations', 'Registered organizations, reporting contracts and data freshness. Existing records are read-only.'],
            'reports' => ['/superadmin/reports', 'Consolidated Reports', 'Group reporting built only from documented organization reporting views.'],
            'users' => ['/superadmin/users', 'Users and Access', 'Group-level roles are held on the account; organization roles are held per membership.'],
            'audit' => ['/superadmin/audit', 'Audit Log', 'Append-only history across group governance and organization registry actions.'],
            'settings' => ['/superadmin/settings', 'System Settings', 'Organization role-permission matrix, organization controls and platform reference.'],
        ];
    }

    // ------------------------------------------------------------------ access matrix

    #[DataProvider('sixPages')]
    public function testSuperAdminAndGroupViewerCanOpenEveryPageWithCorrectChromeAndNoStore(
        string $path,
        string $title,
        string $subtitle,
    ): void {
        $superAdmin = $this->kit->staff('sa-' . bin2hex(random_bytes(3)) . '@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Maria Santos');
        $viewer = $this->kit->staff('gv-' . bin2hex(random_bytes(3)) . '@example.test', TestKit::PASSWORD, 'group_viewer', 'active', 'Carlo Reyes');

        $saResponse = $this->request($this->browser($superAdmin->id), 'GET', $path);
        $this->assertSame(200, $saResponse->status(), $path);
        $this->assertSame('no-store', $saResponse->header('Cache-Control'));
        $saBody = $saResponse->body();
        $this->assertStringContainsString($title, $saBody);
        $this->assertStringContainsString($subtitle, $saBody);
        $this->assertStringContainsString('This page is built in a later batch.', $saBody);
        $this->assertStringContainsString('Maria Santos', $saBody);
        $this->assertStringContainsString('Super Admin', $saBody);
        $this->assertStringNotContainsString('View only', $saBody);
        $this->assertStringContainsString('href="' . $path . '" aria-current="page"', $saBody);
        $this->assertStringContainsString('action="/superadmin/sign-out"', $saBody);
        $this->assertStringContainsString('src="/assets/superadmin/js/shell.js"', $saBody);

        if ($path === '/superadmin/organizations') {
            $this->assertStringNotContainsString('New organization', $saBody);
        }

        $gvResponse = $this->request($this->browser($viewer->id), 'GET', $path);
        $this->assertSame(200, $gvResponse->status(), $path);
        $this->assertSame('no-store', $gvResponse->header('Cache-Control'));
        $gvBody = $gvResponse->body();
        $this->assertStringContainsString($title, $gvBody);
        $this->assertStringContainsString('Carlo Reyes', $gvBody);
        $this->assertStringContainsString('Group Viewer', $gvBody);
        $this->assertStringContainsString('View only', $gvBody);
        $this->assertStringContainsString('This page is built in a later batch.', $gvBody);
    }

    #[DataProvider('sixPages')]
    public function testOrganizationRolesWithoutGroupRoleAreRefusedWith403OnEveryPage(string $path, string $title, string $subtitle): void
    {
        $org = $this->kit->organization('org-' . bin2hex(random_bytes(3)));

        foreach (['admin', 'manager', 'staff', 'cashier'] as $role) {
            $member = $this->kit->member($org, $role . '-' . bin2hex(random_bytes(3)) . '@example.test', $role);
            $response = $this->request($this->browser($member->id), 'GET', $path);

            $this->assertSame(403, $response->status(), "$role on $path must receive 403");
            $this->assertSame('no-store', $response->header('Cache-Control'));
            $this->assertStringNotContainsString('This page is built in a later batch.', $response->body());
        }
    }

    #[DataProvider('sixPages')]
    public function testGuestIsRedirectedToSignInWithSafeNextOnEveryPage(string $path, string $title, string $subtitle): void
    {
        $response = $this->request($this->browser(), 'GET', $path);

        $this->assertSame(302, $response->status(), $path);
        $this->assertSame('/superadmin/sign-in?next=' . rawurlencode($path), $response->header('Location'));
        $this->assertSame('no-store', $response->header('Cache-Control'));
    }

    #[DataProvider('sixPages')]
    public function testSignedInCustomerIsRefusedOnEveryPage(string $path, string $title, string $subtitle): void
    {
        $customer = $this->kit->auth->registerCustomer('Ana Customer', 'cust-' . bin2hex(random_bytes(3)) . '@example.test', TestKit::PASSWORD);

        // Customer signed in to customer session only: cannot enter Super Admin.
        $response = $this->request($this->browser(null, $customer->id), 'GET', $path);
        $this->assertSame(302, $response->status(), $path);
        $this->assertSame('/superadmin/sign-in?next=' . rawurlencode($path), $response->header('Location'));
        $this->assertStringNotContainsString('This page is built in a later batch.', $response->body());

        // Even if a customer UUID were placed into the staff session, SessionMiddleware ignores it.
        $tampered = $this->request($this->browser($customer->id, $customer->id), 'GET', $path);
        $this->assertSame(302, $tampered->status(), $path);
        $this->assertSame('/superadmin/sign-in?next=' . rawurlencode($path), $tampered->header('Location'));
    }

    // ------------------------------------------------------------------ sign-in, lockout, sign-out, CSRF

    public function testSignInPageRendersCleanlyWithLabelsNoForgotOrRegisterLinksAndRedirectsSignedInGroupUsers(): void
    {
        $guest = $this->browser();
        $response = $this->request($guest, 'GET', '/superadmin/sign-in', query: ['next' => '/superadmin/audit']);

        $this->assertSame(200, $response->status());
        $this->assertSame('no-store', $response->header('Cache-Control'));
        $body = $response->body();
        $this->assertStringContainsString('GFH Super Admin', $body);
        $this->assertStringContainsString('Group oversight console', $body);
        $this->assertStringContainsString('<label class="field__label" for="email">Email</label>', $body);
        $this->assertStringContainsString('<label class="field__label" for="password">Password</label>', $body);
        $this->assertStringContainsString('name="_csrf"', $body);
        $this->assertStringContainsString('name="next" value="/superadmin/audit"', $body);
        $this->assertStringNotContainsStringIgnoringCase('forgot password', $body);
        $this->assertStringNotContainsStringIgnoringCase('register', $body);

        // Unsafe next in query string is dropped from the hidden field.
        $unsafe = $this->request($guest, 'GET', '/superadmin/sign-in', query: ['next' => 'https://evil.example/']);
        $this->assertStringContainsString('name="next" value=""', $unsafe->body());

        // Already signed-in group user is redirected straight on.
        $sa = $this->kit->staff('already-sa@example.test', TestKit::PASSWORD, 'super_admin');
        $already = $this->request($this->browser($sa->id), 'GET', '/superadmin/sign-in', query: ['next' => '/superadmin/users']);
        $this->assertSame(302, $already->status());
        $this->assertSame('/superadmin/users', $already->header('Location'));
    }

    public function testAllSignInFailuresGiveOneIdenticalMessageAndNeverEchoPassword(): void
    {
        $org = $this->kit->organization('org-signin-' . bin2hex(random_bytes(3)));
        $sa = $this->kit->staff('sa-fail@example.test', TestKit::PASSWORD, 'super_admin');
        $orgOnly = $this->kit->member($org, 'org-only@example.test', 'admin');
        $inactive = $this->kit->staff('inactive-sa@example.test', TestKit::PASSWORD, 'super_admin', 'inactive');
        $pending = $this->kit->staff('pending-sa@example.test', null, 'super_admin', 'pending');
        $customer = $this->kit->auth->registerCustomer('Shopper', 'shopper-fail@example.test', TestKit::PASSWORD);

        $cases = [
            'wrong password' => [$sa->email, 'definitely-wrong-password'],
            'org-only account with right password' => [$orgOnly->email, TestKit::PASSWORD],
            'inactive group account' => [$inactive->email, TestKit::PASSWORD],
            'pending group account' => [$pending->email, TestKit::PASSWORD],
            'unknown email' => ['nobody-' . bin2hex(random_bytes(3)) . '@example.test', TestKit::PASSWORD],
            'customer account' => [$customer->email, TestKit::PASSWORD],
        ];

        foreach ($cases as $label => [$email, $password]) {
            $browser = $this->browser();
            $response = $this->request($browser, 'POST', '/superadmin/sign-in', [
                'email' => $email,
                'password' => $password,
            ]);

            $this->assertSame(401, $response->status(), $label);
            $this->assertSame('no-store', $response->header('Cache-Control'), $label);
            $this->assertStringContainsString(AuthService::INVALID_MESSAGE, $response->body(), $label);
            $this->assertStringContainsString('value="' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '"', $response->body(), $label);
            $this->assertStringNotContainsString($password, $response->body(), "$label must never echo password");
            $this->assertNull($browser['staff']->get('user_id'), "$label must not sign user in");
        }
    }

    public function testRepeatedFailedSignInsTriggerLockoutWithRetryAfterHeader(): void
    {
        $sa = $this->kit->staff('sa-lock@example.test', TestKit::PASSWORD, 'super_admin');
        $max = max(1, $this->app->config()->int('LOGIN_MAX_ATTEMPTS', 5));

        for ($i = 0; $i < $max; $i++) {
            $attempt = $this->request($this->browser(), 'POST', '/superadmin/sign-in', [
                'email' => $sa->email,
                'password' => 'wrong-attempt-' . $i,
            ]);
            $this->assertSame(401, $attempt->status());
        }

        // Now even the right password is locked out with 429 and Retry-After.
        $locked = $this->request($this->browser(), 'POST', '/superadmin/sign-in', [
            'email' => $sa->email,
            'password' => TestKit::PASSWORD,
        ]);
        $this->assertSame(429, $locked->status());
        $this->assertSame('no-store', $locked->header('Cache-Control'));
        $this->assertNotNull($locked->header('Retry-After'));
        $this->assertGreaterThan(0, (int) $locked->header('Retry-After'));
        $this->assertStringContainsString(AuthService::LOCKED_MESSAGE, $locked->body());
    }

    public function testSuccessfulSignInRegeneratesSessionIdRotatesCsrfAndHonorsSafeNext(): void
    {
        foreach (['super_admin', 'group_viewer'] as $role) {
            $user = $this->kit->staff($role . '-ok@example.test', TestKit::PASSWORD, $role);
            $browser = $this->browser();
            $idBefore = $browser['staff']->id();
            $csrfBefore = $this->csrf($browser);

            $response = $this->request($browser, 'POST', '/superadmin/sign-in', [
                'email' => $user->email,
                'password' => TestKit::PASSWORD,
                'next' => '/superadmin/reports',
            ]);

            $this->assertSame(302, $response->status(), $role);
            $this->assertSame('/superadmin/reports', $response->header('Location'), $role);
            $this->assertNotSame($idBefore, $browser['staff']->id(), "$role session id must change on sign-in");
            $this->assertNotSame($csrfBefore, $this->csrf($browser), "$role CSRF token must rotate on sign-in");
            $this->assertSame($user->id, $browser['staff']->get('user_id'), $role);

            // Unsafe next falls back to /superadmin.
            $browser2 = $this->browser();
            $unsafeNext = $this->request($browser2, 'POST', '/superadmin/sign-in', [
                'email' => $user->email,
                'password' => TestKit::PASSWORD,
                'next' => '//evil.example/steal',
            ]);
            $this->assertSame(302, $unsafeNext->status());
            $this->assertSame('/superadmin', $unsafeNext->header('Location'));
        }
    }

    public function testSignInAndSignOutEnforceCsrfAndPostOnlySignOutEndsSession(): void
    {
        $sa = $this->kit->staff('sa-csrf@example.test', TestKit::PASSWORD, 'super_admin');

        // Missing or wrong CSRF on sign-in is refused (403).
        foreach ([false, '', 'wrong-token'] as $badCsrf) {
            $guest = $this->browser();
            $response = $this->request($guest, 'POST', '/superadmin/sign-in', [
                'email' => $sa->email,
                'password' => TestKit::PASSWORD,
            ], csrf: $badCsrf);
            $this->assertSame(403, $response->status());
            $this->assertNull($guest['staff']->get('user_id'));
        }

        // GET /superadmin/sign-out is 405 Method Not Allowed.
        $signedIn = $this->browser($sa->id);
        $getSignOut = $this->request($signedIn, 'GET', '/superadmin/sign-out');
        $this->assertSame(405, $getSignOut->status());
        $this->assertSame($sa->id, $signedIn['staff']->get('user_id'));

        // POST /superadmin/sign-out with missing/wrong CSRF is 403 and keeps session alive.
        foreach ([false, '', 'bad-token'] as $badCsrf) {
            $bad = $this->request($signedIn, 'POST', '/superadmin/sign-out', csrf: $badCsrf);
            $this->assertSame(403, $bad->status());
            $this->assertSame($sa->id, $signedIn['staff']->get('user_id'));
        }

        // POST /superadmin/sign-out with valid CSRF destroys session and redirects to sign-in.
        $ok = $this->request($signedIn, 'POST', '/superadmin/sign-out');
        $this->assertSame(302, $ok->status());
        $this->assertSame('/superadmin/sign-in', $ok->header('Location'));
        $this->assertNull($signedIn['staff']->get('user_id'));

        // Subsequent access to /superadmin redirects to sign-in.
        $after = $this->request($signedIn, 'GET', '/superadmin');
        $this->assertSame(302, $after->status());
        $this->assertSame('/superadmin/sign-in?next=%2Fsuperadmin', $after->header('Location'));
    }

    // ------------------------------------------------------------------ route isolation & output safety

    public function testSuperAdminPathsAreNeverHandledAsAnOrganization(): void
    {
        $guest = $this->browser();

        foreach ([
            '/superadmin/admin',
            '/superadmin/admin/login',
            '/superadmin/shop',
            '/superadmin/pos',
            '/superadmin/api/v1/catalog/products',
            '/superadmin/unknown-page',
        ] as $path) {
            $response = $this->request($guest, 'GET', $path);
            $this->assertSame(404, $response->status(), "$path must answer 404 directly, never as an organization");
            $this->assertNull($response->header('Location'), "$path must not redirect to an organization login");
        }
    }

    public function testMarkupInUserNameAndEmailIsEscapedAndNoSecretsAppearInAnyResponse(): void
    {
        $hostileName = 'Alex <script>alert(1)</script> "Boss"';
        $sa = $this->kit->staff('sa-escape@example.test', TestKit::PASSWORD, 'super_admin', 'active', $hostileName);
        $userRow = $this->kit->userRow($sa);
        $passwordHash = (string) $userRow['password_hash'];
        $org = $this->kit->organizations->create(
            'secret-check-' . bin2hex(random_bytes(3)),
            'Secret Check Org',
            'SEC',
            'postgresql://secret_user:secret_pass@db.internal:5432/secret_db',
            'public',
            [],
            [],
        );
        $this->assertNotSame('', $org->id);

        $browser = $this->browser($sa->id);
        foreach (self::sixPages() as [$path]) {
            $body = $this->request($browser, 'GET', $path)->body();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $body, $path);
            $this->assertStringContainsString('Alex &lt;script&gt;alert(1)&lt;/script&gt; &quot;Boss&quot;', $body, $path);
            $this->assertStringNotContainsString($passwordHash, $body, $path);
            $this->assertStringNotContainsString('connection_encrypted', $body, $path);
            $this->assertStringNotContainsString('postgresql://', $body, $path);
            $this->assertStringNotContainsString('secret_pass', $body, $path);
        }

        // Failed sign-in escapes hostile email input too.
        $failed = $this->request($this->browser(), 'POST', '/superadmin/sign-in', [
            'email' => '"><script>alert(2)</script>@example.test',
            'password' => 'wrong-pass-secret-marker',
        ]);
        $this->assertSame(401, $failed->status());
        $this->assertStringNotContainsString('<script>alert(2)</script>', $failed->body());
        $this->assertStringNotContainsString('wrong-pass-secret-marker', $failed->body());
        $this->assertStringNotContainsString('postgresql://', $failed->body());
    }

    public function testSidebarOnRenderedPageListsAllSixPagesInOrder(): void
    {
        $sa = $this->kit->staff('sa-nav@example.test', TestKit::PASSWORD, 'super_admin');
        $body = $this->request($this->browser($sa->id), 'GET', '/superadmin/organizations')->body();

        $links = [
            'href="/superadmin">Overview</a>',
            'href="/superadmin/organizations" aria-current="page">Organizations</a>',
            'href="/superadmin/reports">Consolidated reports</a>',
            'href="/superadmin/users">Users and access</a>',
            'href="/superadmin/audit">Audit log</a>',
            'href="/superadmin/settings">System settings</a>',
        ];

        $lastPos = -1;
        foreach ($links as $needle) {
            $pos = strpos($body, $needle);
            $this->assertNotFalse($pos, 'Missing sidebar link: ' . $needle);
            $this->assertGreaterThan($lastPos, $pos, 'Sidebar link out of order: ' . $needle);
            $lastPos = $pos;
        }
    }
}

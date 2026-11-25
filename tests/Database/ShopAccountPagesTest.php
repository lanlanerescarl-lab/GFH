<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Auth\PasswordPolicy;
use Gfh\Core\Auth\Session;
use Gfh\Core\Database\Database;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\AuthService;
use Gfh\Core\Service\CustomerAccountService;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Tenant;
use Gfh\Tests\Support\ShopTestCase;
use Gfh\Tests\Support\TestKit;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Batch 6.3b: the customer account pages (home, profile, addresses, password) through the real App, Router and
 * middleware: the guards, CSRF on every write, ownership of addresses, the rules shared with the JSON API, escaping,
 * no-store, the header link and the account.menu hook on a real page.
 */
final class ShopAccountPagesTest extends ShopTestCase
{
    private const IP = '203.0.113.7';
    private const MARKUP = '<script>alert(1)</script>';

    private CustomerAccount $ana;
    private CustomerAccount $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootShop();
        $this->ana = $this->account('ana@pages.test', 'Ana Reyes');
        $this->ben = $this->account('ben@pages.test', 'Ben Santos');
    }

    // ------------------------------------------------------------------ helpers

    private function account(string $email, string $name): CustomerAccount
    {
        return $this->kit->auth->registerCustomer($name, $email, TestKit::PASSWORD, null, $this->kit->orgCustomers);
    }

    /** @return array{services: Services, session: Session} one browser, signed in as $account or signed out */
    private function browser(?CustomerAccount $account = null, ?string $staffId = null): array
    {
        $session = Session::inMemory('customer', $this->app->config(), $account === null ? [] : ['user_id' => $account->id]);
        $sessions = ['customer' => $session];
        if ($staffId !== null) {
            $sessions['staff'] = Session::inMemory('staff', $this->app->config(), ['user_id' => $staffId]);
        }

        return ['services' => new Services($this->app, false, $this->core, $this->org, testSessions: $sessions), 'session' => $session];
    }

    private function token(array $browser): string
    {
        return $browser['services']->csrf($browser['session'])->token();
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $query
     */
    private function page(array $browser, string $method, string $path, array $form = [], array $query = [], bool|string $csrf = true): Response
    {
        if ($method === 'GET' && $query === []) {
            [$query, $form] = [$form, []];
        }
        if ($method === 'POST' && $csrf !== false) {
            $form['_csrf'] = $csrf === true ? $this->token($browser) : $csrf;
        }

        return $this->app->handle(
            new Request($method, '/' . $this->slug . '/shop' . $path, query: $query, post: $form, cookies: ['gfh_customer' => 'test-session'], server: ['REMOTE_ADDR' => self::IP]),
            $browser['services'],
        );
    }

    private function url(string $path): string
    {
        return '/' . $this->slug . '/shop' . $path;
    }

    private function location(Response $response): string
    {
        $this->assertContains($response->status(), [301, 302, 303], 'Expected a redirect, got ' . $response->status() . $this->debug($response));

        return (string) $response->header('Location');
    }

    /** @return array<string, string> */
    private function address(array $override = []): array
    {
        return $override + ['label' => 'Home', 'line1' => '12 Mabini Street', 'barangay' => 'Poblacion', 'city' => 'Bacolod City', 'province' => 'Negros Occidental', 'postal_code' => '6100'];
    }

    /** Saves an address through the service (fast set-up); the tests then use the pages. */
    private function seedAddress(CustomerAccount $account, array $override = []): string
    {
        return $this->browser($account)['services']->customerAccounts()->createAddress($account, $this->address($override))['address']['id'];
    }

    /** @return list<array<string, mixed>> the customer's rows, oldest first */
    private function rows(CustomerAccount $account): array
    {
        $statement = $this->core->prepare('SELECT id, label, line1, city, is_default FROM customer_addresses WHERE customer_id = ? ORDER BY created_at, id');
        $statement->execute([$account->id]);

        return array_map(static fn (array $row): array => $row + ['default' => $row['is_default'] === true || $row['is_default'] === 't'], $statement->fetchAll());
    }

    /** @return array<string, mixed> */
    private function customerRow(CustomerAccount $account): array
    {
        return $this->kit->customers->findById($account->id) ?? throw new \RuntimeException('Customer row missing.');
    }

    private function auditCount(string $action): int
    {
        $statement = $this->core->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ?');
        $statement->execute([$action]);

        return (int) $statement->fetchColumn();
    }

    private function formErrorLinks(Response $response): string
    {
        return preg_match('/id="form-errors".*?<\/div>/s', $response->body(), $matches) === 1 ? $this->text($matches[0]) : '';
    }

    private function missingId(): string
    {
        return '00000000-0000-4000-8000-0000000000aa';
    }

    // ------------------------------------------------------------------ guards

    /** @return list<array{string}> */
    public static function guestPages(): array
    {
        return [['/account'], ['/account/profile'], ['/account/addresses'], ['/account/addresses/00000000-0000-4000-8000-0000000000aa/edit'], ['/account/password']];
    }

    #[DataProvider('guestPages')]
    public function testAGuestIsSentToSignInWithNextOnEveryAccountPage(string $path): void
    {
        $response = $this->page($this->browser(), 'GET', $path);

        $this->assertSame($this->url('/sign-in?next=' . rawurlencode($this->url($path))), $this->location($response));
        $this->assertSame('no-store', $response->header('Cache-Control'));
    }

    #[DataProvider('guestPages')]
    public function testAStaffSessionWithoutACustomerSignInDoesNotOpenAccountPages(string $path): void
    {
        $staff = $this->kit->staff('staff@pages.test');
        $browser = $this->browser(null, (string) $staff->id);

        $this->assertSame($this->url('/sign-in?next=' . rawurlencode($this->url($path))), $this->location($this->page($browser, 'GET', $path)));
    }

    public function testAWriteFromASignedOutBrowserChangesNothingAndGoesToSignIn(): void
    {
        $staff = $this->kit->staff('staff2@pages.test');
        foreach ([$this->browser(), $this->browser(null, (string) $staff->id)] as $browser) {
            foreach (['/account/profile' => ['full_name' => 'Hacked Name'], '/account/addresses' => $this->address(), '/account/password' => ['current_password' => TestKit::PASSWORD, 'new_password' => 'another-long-passphrase', 'new_password_confirmation' => 'another-long-passphrase']] as $path => $form) {
                $this->assertSame($this->url('/sign-in?next=' . rawurlencode($this->url('/account'))), $this->location($this->page($browser, 'POST', $path, $form)), $path);
            }
        }

        $this->assertSame('Ana Reyes', $this->customerRow($this->ana)['full_name']);
        $this->assertSame([], $this->rows($this->ana));
        $this->assertTrue(password_verify(TestKit::PASSWORD, (string) $this->customerRow($this->ana)['password_hash']));
    }

    public function testAnAccountHomeSentToSignInComesBackThroughSafeNext(): void
    {
        $guest = $this->browser();
        $next = $this->url('/account');
        $this->assertSame($this->url('/sign-in?next=' . rawurlencode($next)), $this->location($this->page($guest, 'GET', '/account')));

        $response = $this->page($this->browser(), 'POST', '/sign-in', ['email' => 'ana@pages.test', 'password' => TestKit::PASSWORD, 'next' => $next]);
        $this->assertSame($next, $this->location($response));
    }

    // ------------------------------------------------------------------ pages for a customer

    public function testEachPageOpensForACustomerWithItsContentTheMenuAndNoStore(): void
    {
        $browser = $this->browser($this->ana);
        $pages = [
            '/account' => ['My account', 'Signed in as Ana Reyes (ana@pages.test)'],
            '/account/profile' => ['Profile', 'Save profile', 'ana@pages.test'],
            '/account/addresses' => ['Addresses', '0 of 10 addresses saved.', 'Add an address'],
            '/account/password' => ['Password', 'Current password', 'Change password'],
        ];

        foreach ($pages as $path => $expected) {
            $response = $this->page($browser, 'GET', $path);
            $this->assertSame(200, $response->status(), $path . $this->debug($response));
            $this->assertSame('no-store', $response->header('Cache-Control'), $path);
            $text = $this->text($response->body());
            foreach ($expected as $needle) {
                $this->assertStringContainsString($needle, $text, $path);
            }
            $this->assertStringContainsString('aria-label="Account"', $response->body(), $path);
            foreach (['/account/profile', '/account/addresses', '/account/password', '/orders'] as $link) {
                $this->assertStringContainsString('href="' . $this->url($link) . '"', $response->body(), $path . ' menu ' . $link);
            }
            if ($path !== '/account') {
                $this->assertStringContainsString('<a href="' . $this->url($path) . '" aria-current="page">', $response->body(), $path . ' is marked current');
            }
        }
    }

    public function testTheEmailIsShownAsTextAndIsNotAFormField(): void
    {
        $body = $this->page($this->browser($this->ana), 'GET', '/account/profile')->body();

        $this->assertStringContainsString('ana@pages.test', $body);
        $this->assertStringNotContainsString('name="email"', $body);
        $this->assertStringContainsString('cannot be changed here', $this->text($body));
    }

    public function testTheHeaderShowsAccountOnlyWhenSignedInKeepsMyOrdersAndMarksTheCurrentPage(): void
    {
        $guest = $this->page($this->browser(), 'GET', '/products')->body();
        $this->assertStringNotContainsString('>Account</a>', $guest);
        $this->assertStringNotContainsString('>My orders</a>', $guest);

        $customer = $this->browser($this->ana);
        $products = $this->page($customer, 'GET', '/products')->body();
        $this->assertStringContainsString('href="' . $this->url('/account') . '">Account</a>', $products);
        $this->assertStringContainsString('href="' . $this->url('/orders') . '">My orders</a>', $products);
        $this->assertStringNotContainsString('aria-current="page">Account', $products);

        $profile = $this->page($customer, 'GET', '/account/profile')->body();
        $this->assertStringContainsString('href="' . $this->url('/account') . '" aria-current="page">Account</a>', $profile);

        $orders = $this->page($customer, 'GET', '/orders')->body();
        $this->assertStringContainsString('href="' . $this->url('/orders') . '" aria-current="page">My orders</a>', $orders);
        $this->assertStringContainsString('href="' . $this->url('/account') . '">Account</a>', $orders);
    }

    // ------------------------------------------------------------------ CSRF

    public function testEveryWriteWithoutAValidCsrfTokenIsRefusedAndChangesNothing(): void
    {
        $browser = $this->browser($this->ana);
        $first = $this->seedAddress($this->ana);
        $second = $this->seedAddress($this->ana, ['line1' => '5 Rizal Avenue']);
        $before = $this->rows($this->ana);
        $hash = (string) $this->customerRow($this->ana)['password_hash'];

        $writes = [
            '/account/profile' => ['full_name' => 'Changed Name', 'phone' => '09171234567'],
            '/account/addresses' => $this->address(['line1' => 'New Street']),
            '/account/addresses/' . $first => $this->address(['line1' => 'Edited Street']),
            '/account/addresses/' . $second . '/default' => [],
            '/account/addresses/' . $first . '/delete' => [],
            '/account/password' => ['current_password' => TestKit::PASSWORD, 'new_password' => 'another-long-passphrase', 'new_password_confirmation' => 'another-long-passphrase'],
        ];
        foreach ($writes as $path => $form) {
            foreach ([false, 'not-the-token', ''] as $csrf) {
                $this->assertSame(403, $this->page($browser, 'POST', $path, $form, csrf: $csrf)->status(), $path . ' with ' . var_export($csrf, true));
            }
        }

        $this->assertSame('Ana Reyes', $this->customerRow($this->ana)['full_name']);
        $this->assertSame($before, $this->rows($this->ana));
        $this->assertSame($hash, (string) $this->customerRow($this->ana)['password_hash']);
    }

    // ------------------------------------------------------------------ profile

    public function testASavedProfileChangesNameAndPhoneOnlyAndAPostedEmailChangesNothing(): void
    {
        $browser = $this->browser($this->ana);
        $before = $this->customerRow($this->ana);

        $response = $this->page($browser, 'POST', '/account/profile', ['full_name' => '  Ana  Marie Reyes ', 'phone' => '0917 123 4567', 'email' => 'attacker@pages.test', 'status' => 'disabled']);
        $this->assertSame($this->url('/account/profile'), $this->location($response));

        $row = $this->customerRow($this->ana);
        $this->assertSame('Ana Marie Reyes', $row['full_name']);
        $this->assertNotSame('', (string) $row['phone']);
        $this->assertSame($before['email'], $row['email'], 'the email is never changed here');
        $this->assertSame($before['password_hash'], $row['password_hash']);

        // After the redirect the page shows the new values and the confirmation, once.
        $page = $this->page($browser, 'GET', '/account/profile');
        $this->assertStringContainsString('value="Ana Marie Reyes"', $page->body());
        $this->assertStringContainsString('Your profile was saved.', $page->body());
        $this->assertStringNotContainsString('Your profile was saved.', $this->page($browser, 'GET', '/account/profile')->body(), 'a reload does not repeat it');
        $this->assertStringNotContainsString('attacker@pages.test', $page->body());
        $this->assertSame(1, $this->auditCount('customer.profile_updated'));
    }

    public function testAProfileErrorShowsTheSummaryFocusMarkAndKeepsWhatWasTyped(): void
    {
        $response = $this->page($this->browser($this->ana), 'POST', '/account/profile', ['full_name' => 'A', 'phone' => 'not a phone']);

        $this->assertSame(422, $response->status());
        $body = $response->body();
        $this->assertStringContainsString('id="form-errors"', $body);
        $this->assertStringContainsString('tabindex="-1" data-focus-on-load', $body);
        $this->assertStringContainsString('href="#field-full_name"', $body);
        $this->assertStringContainsString('href="#field-phone"', $body);
        $this->assertStringContainsString('aria-invalid="true"', $body);
        $this->assertStringContainsString('value="not a phone"', $body);
        $this->assertStringContainsString('<label for="field-full_name">', $body);
        $this->assertSame('Ana Reyes', $this->customerRow($this->ana)['full_name']);
        $this->assertSame('no-store', $response->header('Cache-Control'));
    }

    // ------------------------------------------------------------------ addresses

    public function testAddEditMakeDefaultAndDeleteThroughThePages(): void
    {
        $browser = $this->browser($this->ana);

        // Add two. The first becomes the default whatever the form says.
        $this->assertSame($this->url('/account/addresses'), $this->location($this->page($browser, 'POST', '/account/addresses', $this->address(['is_default' => '0']))));
        $this->assertStringContainsString('The address was added.', $this->page($browser, 'GET', '/account/addresses')->body());
        $this->page($browser, 'POST', '/account/addresses', $this->address(['label' => 'Office', 'line1' => '9 Lacson Street', 'is_default' => '1']));
        [$one, $two] = $this->rows($this->ana);
        $this->assertTrue($one['default']);
        $this->assertFalse($two['default'], 'a posted default flag is ignored');

        // Edit the second.
        $response = $this->page($browser, 'GET', '/account/addresses/' . $two['id'] . '/edit');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('value="9 Lacson Street"', $response->body());
        $this->assertSame($this->url('/account/addresses'), $this->location($this->page($browser, 'POST', '/account/addresses/' . $two['id'], $this->address(['label' => 'Work', 'line1' => '10 Lacson Street', 'barangay' => '', 'postal_code' => '']))));
        $this->assertSame('10 Lacson Street', $this->rows($this->ana)[1]['line1']);
        $this->assertStringContainsString('The address was saved.', $this->page($browser, 'GET', '/account/addresses')->body());

        // Make the second the default.
        $this->assertSame($this->url('/account/addresses'), $this->location($this->page($browser, 'POST', '/account/addresses/' . $two['id'] . '/default')));
        $rows = $this->rows($this->ana);
        $this->assertSame([false, true], array_column($rows, 'default'));
        $list = $this->page($browser, 'GET', '/account/addresses')->body();
        $this->assertSame(1, substr_count($list, '<span class="badge">Default</span>'));
        $this->assertStringContainsString('This is now your default address.', $list);

        // Delete the default: the other one is promoted.
        $this->assertSame($this->url('/account/addresses'), $this->location($this->page($browser, 'POST', '/account/addresses/' . $two['id'] . '/delete')));
        $rows = $this->rows($this->ana);
        $this->assertCount(1, $rows);
        $this->assertSame($one['id'], $rows[0]['id']);
        $this->assertTrue($rows[0]['default']);
        $this->assertStringContainsString('The address was deleted.', $this->page($browser, 'GET', '/account/addresses')->body());
    }

    public function testDeletingTheDefaultPromotesTheNewestRemainingAddress(): void
    {
        $browser = $this->browser($this->ana);
        $ids = [];
        foreach (['1 First', '2 Second', '3 Third'] as $line) {
            $ids[] = $this->seedAddress($this->ana, ['line1' => $line]);
            usleep(2000);
        }

        $this->page($browser, 'POST', '/account/addresses/' . $ids[0] . '/delete');

        $default = array_values(array_filter($this->rows($this->ana), static fn (array $row): bool => $row['default']));
        $this->assertCount(1, $default, 'exactly one default');
        $this->assertSame($ids[2], $default[0]['id'], 'the newest address is promoted');
    }

    public function testTheLimitOfTenIsShownAndEnforced(): void
    {
        $browser = $this->browser($this->ana);
        for ($i = 1; $i <= 10; $i++) {
            $this->seedAddress($this->ana, ['line1' => $i . ' Limit Street']);
        }

        $page = $this->page($browser, 'GET', '/account/addresses');
        $this->assertStringContainsString('10 of 10 addresses saved. You have reached the limit.', $this->text($page->body()));
        $this->assertStringNotContainsString('Add an address', $page->body(), 'no add form at the limit');

        // A form from an older page (or another tab) is still refused by the service.
        $response = $this->page($browser, 'POST', '/account/addresses', $this->address(['line1' => 'Eleventh Street']));
        $this->assertSame(409, $response->status());
        $this->assertStringContainsString('You can save up to 10 addresses', $this->formErrorLinks($response));
        $this->assertCount(10, $this->rows($this->ana));

        // Deleting one makes room again.
        $this->page($browser, 'POST', '/account/addresses/' . $this->rows($this->ana)[5]['id'] . '/delete');
        $this->assertStringContainsString('9 of 10 addresses saved.', $this->text($this->page($browser, 'GET', '/account/addresses')->body()));
        $this->assertSame(302, $this->page($browser, 'POST', '/account/addresses', $this->address(['line1' => 'Tenth Again']))->status());
    }

    public function testAnAddressErrorShowsInlineMessagesAndKeepsTheTypedValues(): void
    {
        $browser = $this->browser($this->ana);

        $add = $this->page($browser, 'POST', '/account/addresses', ['label' => 'Home', 'line1' => '', 'city' => 'Bacolod', 'province' => '', 'postal_code' => '12']);
        $this->assertSame(422, $add->status());
        foreach (['line1', 'province', 'postal_code'] as $field) {
            $this->assertStringContainsString('href="#field-' . $field . '"', $add->body());
        }
        $this->assertStringContainsString('value="Bacolod"', $add->body());
        $this->assertSame([], $this->rows($this->ana));

        $id = $this->seedAddress($this->ana);
        $edit = $this->page($browser, 'POST', '/account/addresses/' . $id, $this->address(['line1' => '', 'city' => 'Silay']));
        $this->assertSame(422, $edit->status());
        $this->assertStringContainsString('href="#field-line1"', $edit->body());
        $this->assertStringContainsString('value="Silay"', $edit->body());
        $this->assertSame('12 Mabini Street', $this->rows($this->ana)[0]['line1'], 'a refused edit changes nothing');
    }

    public function testAnotherCustomersUnknownAndMalformedAddressIdsAreTheSame404AndChangeNothing(): void
    {
        $ben = $this->browser($this->ben);
        $benId = $this->seedAddress($this->ben, ['line1' => '77 Ben Street']);
        $this->seedAddress($this->ben, ['line1' => '78 Ben Street']);
        $before = $this->rows($this->ben);
        $ana = $this->browser($this->ana);
        $anaOwn = $this->seedAddress($this->ana);

        $bodies = [];
        foreach ([$benId, $this->missingId(), 'not-a-uuid', '1; DROP TABLE customer_addresses'] as $id) {
            $get = $this->page($ana, 'GET', '/account/addresses/' . rawurlencode($id) . '/edit');
            $this->assertSame(404, $get->status(), 'edit ' . $id);
            $this->assertSame('no-store', $get->header('Cache-Control'));
            $bodies[] = $this->text($get->body());
            $this->assertStringNotContainsString('Ben Street', $get->body());

            foreach (['' => $this->address(['line1' => 'Taken Over']), '/default' => [], '/delete' => []] as $suffix => $form) {
                $post = $this->page($ana, 'POST', '/account/addresses/' . rawurlencode($id) . $suffix, $form);
                $this->assertSame(404, $post->status(), $suffix . ' ' . $id);
                $bodies[] = $this->text($post->body());
            }
        }

        $this->assertCount(1, array_unique($bodies), 'one and the same 404 page for every kind of bad id');
        $this->assertSame($before, $this->rows($this->ben), 'the other customer\'s data is untouched');
        $this->assertCount(1, $this->rows($this->ana));
        $this->assertSame($anaOwn, $this->rows($this->ana)[0]['id']);
        $this->assertSame(200, $this->page($ben, 'GET', '/account/addresses/' . $benId . '/edit')->status(), 'the owner still opens it');
    }

    // ------------------------------------------------------------------ password

    public function testAWrongCurrentPasswordShowsAnErrorAndCountsTowardsTheSignInLockout(): void
    {
        $browser = $this->browser($this->ana);
        $form = ['current_password' => 'not-my-password', 'new_password' => 'another-long-passphrase', 'new_password_confirmation' => 'another-long-passphrase'];
        $hash = (string) $this->customerRow($this->ana)['password_hash'];

        $first = $this->page($browser, 'POST', '/account/password', $form);
        $this->assertSame(422, $first->status());
        $this->assertStringContainsString(CustomerAccountService::WRONG_CURRENT_PASSWORD, $this->formErrorLinks($first));
        $this->assertStringContainsString('href="#field-current_password"', $first->body());
        $this->assertSame(1, $this->auditCount('customer.password_change_failed'));

        $max = max(1, $this->app->config()->int('LOGIN_MAX_ATTEMPTS', 5));
        $last = $first;
        for ($i = 1; $i <= $max && $last->status() !== 429; $i++) {
            $last = $this->page($browser, 'POST', '/account/password', $form);
        }
        $this->assertSame(429, $last->status(), 'the wrong attempts reached the sign-in lockout');
        $this->assertStringContainsString(AuthService::LOCKED_MESSAGE, $last->body());
        $this->assertNotNull($last->header('Retry-After'));

        // Locked out: even the right current password is refused, and sign-in is locked too (one shared lockout).
        $right = $this->page($browser, 'POST', '/account/password', ['current_password' => TestKit::PASSWORD] + $form);
        $this->assertSame(429, $right->status());
        $this->assertSame($hash, (string) $this->customerRow($this->ana)['password_hash']);
        $signIn = $this->page($this->browser(), 'POST', '/sign-in', ['email' => 'ana@pages.test', 'password' => TestKit::PASSWORD]);
        $this->assertSame(429, $signIn->status());
    }

    public function testTheRightCurrentPasswordChangesItKeepsTheCustomerSignedInAndIsAudited(): void
    {
        $browser = $this->browser($this->ana);
        $idBefore = $browser['session']->id();
        $newPassword = 'a-brand-new-passphrase-7';

        $response = $this->page($browser, 'POST', '/account/password', ['current_password' => TestKit::PASSWORD, 'new_password' => $newPassword, 'new_password_confirmation' => $newPassword]);

        $this->assertSame($this->url('/account/password'), $this->location($response));
        $this->assertNotSame($idBefore, $browser['session']->id(), 'a new session id after a credential change');
        $this->assertTrue(password_verify($newPassword, (string) $this->customerRow($this->ana)['password_hash']));
        $this->assertSame(1, $this->auditCount('customer.password_changed'));

        $detail = $this->core->query("SELECT detail::text FROM audit_log WHERE action = 'customer.password_changed'")->fetchColumn();
        $this->assertStringNotContainsString($newPassword, (string) $detail);
        $this->assertStringNotContainsString(TestKit::PASSWORD, (string) $detail);
        $this->assertStringNotContainsString('$2y$', (string) $detail);
        $this->assertStringNotContainsString('argon', strtolower((string) $detail));

        // Still signed in, with the confirmation shown once, and the new password works at sign-in.
        $page = $this->page($browser, 'GET', '/account/password');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Your password was changed.', $page->body());
        $this->assertStringNotContainsString($newPassword, $page->body());
        $this->assertSame(302, $this->page($this->browser(), 'POST', '/sign-in', ['email' => 'ana@pages.test', 'password' => $newPassword])->status());
        $this->assertSame(401, $this->page($this->browser(), 'POST', '/sign-in', ['email' => 'ana@pages.test', 'password' => TestKit::PASSWORD])->status());
    }

    public function testThePasswordFollowsTheSamePolicyAsTheApiAndNothingTypedIsEchoed(): void
    {
        $browser = $this->browser($this->ana);
        $secret = 'Typed-Secret-Marker-91';

        $short = $this->page($browser, 'POST', '/account/password', ['current_password' => TestKit::PASSWORD, 'new_password' => 'short', 'new_password_confirmation' => 'short']);
        $this->assertSame(422, $short->status());
        $this->assertStringContainsString('href="#field-new_password"', $short->body());

        $mismatch = $this->page($browser, 'POST', '/account/password', ['current_password' => $secret, 'new_password' => $secret . 'a', 'new_password_confirmation' => $secret . 'b']);
        $this->assertSame(422, $mismatch->status());
        $this->assertStringContainsString('href="#field-new_password_confirmation"', $mismatch->body());
        $this->assertStringNotContainsString($secret, $mismatch->body(), 'no password is printed back');
        $this->assertDoesNotMatchRegularExpression('/type="password"[^>]*value="[^"]/', $mismatch->body());

        $this->assertStringContainsString(sprintf('At least %d characters.', PasswordPolicy::MIN_BYTES), $this->page($browser, 'GET', '/account/password')->body());
        $this->assertStringContainsString('does not sign you out on your other devices', $this->text($this->page($browser, 'GET', '/account/password')->body()));
        $this->assertTrue(password_verify(TestKit::PASSWORD, (string) $this->customerRow($this->ana)['password_hash']));
    }

    // ------------------------------------------------------------------ escaping and caching

    public function testEveryPagePrintsMarkupAsText(): void
    {
        $name = 'Mallory ' . self::MARKUP;
        $account = $this->account('mallory@pages.test', $name);
        $browser = $this->browser($account);
        $id = $this->seedAddress($account, ['label' => 'Lab ' . self::MARKUP, 'line1' => '1 ' . self::MARKUP, 'barangay' => '<b>Brgy</b>', 'city' => 'City<i>x</i>', 'province' => 'Prov"><svg onload=1>']);

        foreach (['/account', '/account/profile', '/account/addresses', '/account/addresses/' . $id . '/edit', '/account/password', '/orders'] as $path) {
            $body = $this->page($browser, 'GET', $path)->body();
            $this->assertStringNotContainsString('<script>alert(1)', $body, $path);
            $this->assertStringNotContainsString('<svg onload', $body, $path);
            $this->assertStringNotContainsString('<b>Brgy</b>', $body, $path);
        }
        $this->assertStringContainsString('Mallory &lt;script&gt;alert(1)&lt;/script&gt;', $this->page($browser, 'GET', '/account')->body());
        $this->assertStringContainsString('value="Mallory &lt;script&gt;', $this->page($browser, 'GET', '/account/profile')->body());
        $this->assertStringContainsString('1 &lt;script&gt;alert(1)&lt;/script&gt;', $this->page($browser, 'GET', '/account/addresses')->body());
        $this->assertStringContainsString('value="1 &lt;script&gt;', $this->page($browser, 'GET', '/account/addresses/' . $id . '/edit')->body());

        // A refused form prints what was typed as text too.
        $refused = $this->page($browser, 'POST', '/account/profile', ['full_name' => '<script>alert(2)</script>', 'phone' => '<b>x</b>']);
        $this->assertSame(422, $refused->status());
        $this->assertStringNotContainsString('<script>alert(2)', $refused->body());
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $refused->body());
    }

    public function testEveryAccountResponseCarriesNoStore(): void
    {
        $browser = $this->browser($this->ana);
        $id = $this->seedAddress($this->ana);
        $responses = [
            $this->page($browser, 'GET', '/account'),
            $this->page($browser, 'GET', '/account/profile'),
            $this->page($browser, 'GET', '/account/addresses'),
            $this->page($browser, 'GET', '/account/addresses/' . $id . '/edit'),
            $this->page($browser, 'GET', '/account/addresses/' . $this->missingId() . '/edit'),
            $this->page($browser, 'GET', '/account/password'),
            $this->page($browser, 'POST', '/account/profile', ['full_name' => 'Ana Reyes', 'phone' => '']),
            $this->page($browser, 'POST', '/account/profile', ['full_name' => '', 'phone' => '']),
            $this->page($browser, 'POST', '/account/addresses/' . $id . '/default'),
            $this->page($browser, 'POST', '/account/password', ['current_password' => 'wrong-password', 'new_password' => 'x', 'new_password_confirmation' => 'x']),
        ];
        foreach ($responses as $index => $response) {
            $this->assertSame('no-store', $response->header('Cache-Control'), 'response ' . $index . ' (' . $response->status() . ')');
        }
    }

    // ------------------------------------------------------------------ account.menu on a real page

    public function testTheAccountMenuHookShowsAnExtensionItemDropsBrokenOnesAndSurvivesAThrowingCallback(): void
    {
        $organization = $this->kit->organizations->findBySlug($this->slug);
        $tenant = new Tenant($organization, new Database($this->kit->config), null, $this->org);

        // 1. An item after the built-in ones; unsafe and blank items are dropped; the label is escaped.
        $browser = $this->browser($this->ana);
        $browser['services']->forTenant($tenant)->hooks()->add(Hooks::ACCOUNT_MENU, static fn (array $items, array $context): array => [
            ...$items,
            ['label' => 'Rewards <b>club</b>', 'url' => $context['base_url'] . '/rewards'],
            ['label' => 'Away', 'url' => 'https://evil.test/x'],
            ['label' => 'Away too', 'url' => '//evil.test/x'],
            ['label' => 'Script', 'url' => 'javascript:alert(1)'],
            ['label' => '', 'url' => '/blank'],
            'junk',
        ]);
        $body = $this->page($browser, 'GET', '/account')->body();
        $this->assertStringContainsString('Rewards &lt;b&gt;club&lt;/b&gt;', $body);
        $this->assertStringContainsString('href="' . $this->url('/rewards') . '"', $body);
        $this->assertStringNotContainsString('evil.test', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringNotContainsString('Rewards <b>', $body);
        $this->assertLessThan(strpos($body, 'Rewards &lt;'), strpos($body, '>My orders</a>', strpos($body, 'aria-label="Account"')), 'extension items come after the built-in ones');

        // 2. A callback that throws: the page still opens with the built-in items.
        $other = $this->browser($this->ben);
        $other['services']->forTenant($tenant)->hooks()->add(Hooks::ACCOUNT_MENU, static function (array $items): array {
            throw new \RuntimeException('extension bug');
        });
        $response = $this->page($other, 'GET', '/account/profile');
        $this->assertSame(200, $response->status(), $this->debug($response));
        foreach (['Profile', 'Addresses', 'Password', 'My orders'] as $label) {
            $this->assertStringContainsString('>' . $label . '</a>', $response->body());
        }
        $this->assertStringNotContainsString('extension bug', $response->body());
    }
}

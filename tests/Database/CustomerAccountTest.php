<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\CustomerAccount;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Repository\CustomerAddressRepository;
use Gfh\Core\Service\AuthService;
use Gfh\Core\Service\CustomerAccountService;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Organization;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;

/**
 * Batch 6.3a: the customer account JSON API (/{org}/api/v1/account/...) and CustomerAccountService.
 *
 * Every request goes through the real App + Router + core/routes.php + middleware stack with in-memory sessions, so
 * a route without requireCustomer or CSRF, a controller missing from the route group, or a query without the customer
 * filter is caught here and not only in a unit test.
 */
final class CustomerAccountTest extends DatabaseTestCase
{
    private const NEW_PASSWORD = 'a-brand-new-passphrase-9';
    private const IP = '203.0.113.9';

    private App $app;
    private Organization $organization;
    private CustomerAccount $ana;
    private CustomerAccount $ben;
    private StaffUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 2));

        $this->organization = $this->kit->organization('acct-' . bin2hex(random_bytes(3)));
        $this->ana = $this->customer('Ana Reyes', 'ana@account.test', '0917 111 2222');
        $this->ben = $this->customer('Ben Santos', 'ben@account.test', '0918 333 4444');
        $this->admin = $this->kit->member($this->organization, 'admin@account.test', 'admin');
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function customer(string $name, string $email, string $phone): CustomerAccount
    {
        return $this->kit->auth->registerCustomer($name, $email, TestKit::PASSWORD, $phone, $this->kit->orgCustomers);
    }

    /**
     * One browser: a customer session (signed in as $customer, or signed out), a staff session (signed in as $staff,
     * or signed out), and the services that use them.
     *
     * @return array{services: Services, customer: Session, staff: Session}
     */
    private function browser(?CustomerAccount $customer, ?StaffUser $staff = null): array
    {
        $customerSession = Session::inMemory('customer', $this->app->config(), $customer === null ? [] : ['user_id' => $customer->id]);
        $staffSession = Session::inMemory('staff', $this->app->config(), $staff === null ? [] : ['user_id' => $staff->id]);

        return [
            'services' => new Services($this->app, false, $this->core, $this->org, testSessions: ['customer' => $customerSession, 'staff' => $staffSession]),
            'customer' => $customerSession,
            'staff' => $staffSession,
        ];
    }

    /**
     * @param array{services: Services, customer: Session, staff: Session} $browser
     * @param array<string, mixed>|null $payload sent as a JSON body
     * @param true|false|string $csrf true: the session's own token; false: none; a string: that token
     */
    private function call(array $browser, string $method, string $suffix, ?array $payload = null, bool|string $csrf = true): Response
    {
        $server = ['REMOTE_ADDR' => self::IP];
        if ($csrf === true) {
            $server['HTTP_X_CSRF_TOKEN'] = $browser['services']->csrf($browser['customer'])->token();
        } elseif (is_string($csrf)) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }

        $reader = null;
        if ($payload !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $reader = static fn (): string => $json;
        }

        $request = new Request($method, '/' . $this->organization->slug . '/api/v1/account' . $suffix, server: $server, bodyReader: $reader);

        return $this->app->handle($request, $browser['services']);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body(), true);
        $this->assertIsArray($decoded, 'The body must be the JSON envelope: ' . $response->body());

        return $decoded;
    }

    /** Asserts a success envelope with this status and returns its data. @return array<string, mixed> */
    private function data(Response $response, int $status = 200): array
    {
        $body = $this->decode($response);
        $this->assertSame($status, $response->status(), 'Unexpected status. Body: ' . $response->body());
        $this->assertTrue($body['ok'] ?? null, 'Expected ok: true. Body: ' . $response->body());
        $this->assertIsArray($body['data']);

        return $body['data'];
    }

    /** Asserts a failure envelope with this status and code and returns its error. @return array{code: string, message: string, fields: array<string, string>} */
    private function failure(Response $response, int $status, string $code): array
    {
        $body = $this->decode($response);
        $this->assertSame($status, $response->status(), 'Unexpected status. Body: ' . $response->body());
        $this->assertFalse($body['ok'] ?? null);
        $this->assertSame($code, $body['error']['code'] ?? null, 'Unexpected error code. Body: ' . $response->body());
        $this->assertIsArray($body['error']['fields'], 'fields is always an object');

        return $body['error'];
    }

    /** @param array<string, mixed> $override @return array<string, mixed> */
    private function address(array $override = []): array
    {
        return $override + [
            'label' => 'Home',
            'line1' => '12 Rizal Street',
            'barangay' => 'Barangay 1',
            'city' => 'Bacolod City',
            'province' => 'Negros Occidental',
            'postal_code' => '6100',
        ];
    }

    /** Saves an address straight through the service and returns its id. */
    private function save(CustomerAccount $customer, string $label): string
    {
        $browser = $this->browser($customer);

        return (string) $this->data($this->call($browser, 'POST', '/addresses', $this->address(['label' => $label])), 201)['address']['id'];
    }

    /** @return list<array<string, mixed>> the customer's address rows, oldest first */
    private function rows(CustomerAccount $customer): array
    {
        $statement = $this->core->prepare('SELECT id, label, line1, barangay, city, province, postal_code, is_default FROM customer_addresses WHERE customer_id = ? ORDER BY created_at, id');
        $statement->execute([$customer->id]);

        return array_values($statement->fetchAll());
    }

    /** @return array<string, mixed> */
    private function customerRow(CustomerAccount $customer): array
    {
        return $this->kit->customers->findById($customer->id) ?? throw new \RuntimeException('Customer row missing.');
    }

    /** Everything stored about one customer's account and addresses, to prove a refused request changed nothing. */
    private function state(CustomerAccount $customer): string
    {
        return json_encode([$this->customerRow($customer), $this->rows($customer)], JSON_THROW_ON_ERROR);
    }

    private function auditCount(): int
    {
        return (int) $this->core->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'customer.%'")->fetchColumn();
    }

    private function failedChecks(CustomerAccount $customer): int
    {
        $hash = hash_hmac('sha256', $customer->email, $this->app->config()->string('APP_KEY', 'gfh-login-identifier'));
        $statement = $this->core->prepare("SELECT COUNT(*) FROM login_attempts WHERE scope = 'customer' AND identifier_hash = ? AND succeeded = false");
        $statement->execute([$hash]);

        return (int) $statement->fetchColumn();
    }

    private function loginLimit(): int
    {
        return max(1, $this->app->config()->int('LOGIN_MAX_ATTEMPTS', 5));
    }

    private function assertExactlyOneDefault(CustomerAccount $customer, string $when): void
    {
        $rows = $this->rows($customer);
        if ($rows === []) {
            $this->assertSame(0, 0);

            return;
        }
        $defaults = array_filter($rows, static fn (array $row): bool => $row['is_default'] === true);
        $this->assertCount(1, $defaults, "Exactly one default address after: $when");
    }

    /** @return list<array{0: string, 1: string, 2: ?array<string, mixed>}> every account endpoint with a believable payload */
    private function everyRequest(string $addressId): array
    {
        $password = ['current_password' => TestKit::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD];

        return [
            ['GET', '/profile', null],
            ['PUT', '/profile', ['full_name' => 'Changed Name', 'phone' => '0999 000 1111']],
            ['POST', '/password', $password],
            ['GET', '/addresses', null],
            ['POST', '/addresses', $this->address()],
            ['PUT', '/addresses/' . $addressId, $this->address(['line1' => 'Changed Street'])],
            ['POST', '/addresses/' . $addressId . '/default', null],
            ['DELETE', '/addresses/' . $addressId, null],
        ];
    }

    private function isWrite(string $method): bool
    {
        return !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    // ------------------------------------------------------------------ the real router

    public function testEveryAccountEndpointIsReachableThroughTheRealRouterAndNeverAnswers500(): void
    {
        // A controller missing from the route group's use (...) list is an undefined variable at request time: a 500.
        $browser = $this->browser($this->ana);

        $profile = $this->data($this->call($browser, 'GET', '/profile'));
        $this->assertSame('ana@account.test', $profile['customer']['email']);

        $this->data($this->call($browser, 'PUT', '/profile', ['full_name' => 'Ana Reyes', 'phone' => '0917 111 2222']));
        $this->data($this->call($browser, 'GET', '/addresses'));
        $created = $this->data($this->call($browser, 'POST', '/addresses', $this->address()), 201);
        $id = (string) $created['address']['id'];
        $this->data($this->call($browser, 'PUT', '/addresses/' . $id, $this->address(['line1' => '99 New Street'])));
        $this->data($this->call($browser, 'POST', '/addresses/' . $id . '/default'));
        $this->data($this->call($browser, 'POST', '/password', ['current_password' => TestKit::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD]));
        $this->data($this->call($browser, 'DELETE', '/addresses/' . $id));
    }

    public function testUnsupportedMethodsAnswer405AndNeverReachAService(): void
    {
        $browser = $this->browser($this->ana);
        $before = $this->state($this->ana);

        foreach ([['DELETE', '/profile'], ['PATCH', '/profile'], ['PUT', '/password'], ['GET', '/password'], ['PATCH', '/addresses'], ['PUT', '/addresses']] as [$method, $suffix]) {
            $this->failure($this->call($browser, $method, $suffix), 405, 'method_not_allowed');
        }

        $this->assertSame($before, $this->state($this->ana));
    }

    // ------------------------------------------------------------------ signed out, staff, CSRF

    public function testSignedOutRequestsGet401OnEveryEndpointAndChangeNothing(): void
    {
        $addressId = $this->save($this->ben, 'Ben home');
        $before = [$this->state($this->ana), $this->state($this->ben)];
        $audit = $this->auditCount();

        $browser = $this->browser(null);
        foreach ($this->everyRequest($addressId) as [$method, $suffix, $payload]) {
            // A signed-out browser still holds an anonymous session with its own token, as one that called .../csrf does.
            $error = $this->failure($this->call($browser, $method, $suffix, $payload), 401, 'unauthorized');
            $this->assertSame([], $error['fields'], "$method $suffix");
        }

        $this->assertSame($before, [$this->state($this->ana), $this->state($this->ben)]);
        $this->assertSame($audit, $this->auditCount(), 'a refused request writes no audit entry');
    }

    public function testASignedOutWriteWithoutATokenIsRefusedByCsrfFirstAsOnEveryCustomerRoute(): void
    {
        // The middleware order is the project's: tenant, session, CSRF, then requireCustomer (docs/conventions.md 11b).
        $browser = $this->browser(null);

        foreach ($this->everyRequest((string) $this->ben->id) as [$method, $suffix, $payload]) {
            if ($this->isWrite($method)) {
                $this->failure($this->call($browser, $method, $suffix, $payload, false), 403, 'csrf_mismatch');
            } else {
                $this->failure($this->call($browser, $method, $suffix, $payload, false), 401, 'unauthorized');
            }
        }
    }

    public function testAStaffSessionIsNotACustomerSession(): void
    {
        $addressId = $this->save($this->ben, 'Ben home');
        $before = [$this->state($this->ana), $this->state($this->ben)];

        // A signed-in Admin of this very organization, but no customer signed in.
        $browser = $this->browser(null, $this->admin);
        foreach ($this->everyRequest($addressId) as [$method, $suffix, $payload]) {
            $this->failure($this->call($browser, $method, $suffix, $payload), 401, 'unauthorized');
        }

        // A staff CSRF token does not open the customer session either.
        $staffToken = $browser['services']->csrf($browser['staff'])->token();
        $this->failure($this->call($browser, 'PUT', '/profile', ['full_name' => 'Staff Wrote This', 'phone' => ''], $staffToken), 403, 'csrf_mismatch');

        $this->assertSame($before, [$this->state($this->ana), $this->state($this->ben)]);
    }

    public function testEveryWriteRefusesAMissingOrWrongCsrfTokenAndChangesNothing(): void
    {
        $addressId = $this->save($this->ana, 'Ana home');
        $browser = $this->browser($this->ana);
        $otherToken = (new Services($this->app, false, $this->core, $this->org, testSessions: ['customer' => $session = Session::inMemory('customer', $this->app->config())]))->csrf($session)->token();

        $before = $this->state($this->ana);
        $audit = $this->auditCount();
        $sessionId = $browser['customer']->id();
        $writes = 0;

        foreach ($this->everyRequest($addressId) as [$method, $suffix, $payload]) {
            if (!$this->isWrite($method)) {
                continue;
            }
            $writes++;
            foreach (['no token' => false, 'wrong token' => 'not-the-token', 'a token from another session' => $otherToken, 'an empty token' => ''] as $label => $token) {
                $this->failure($this->call($browser, $method, $suffix, $payload, $token), 403, 'csrf_mismatch');
                $this->assertSame($before, $this->state($this->ana), "$method $suffix with $label changed something");
            }
        }

        $this->assertSame(6, $writes, 'all six write endpoints were tried');
        $this->assertSame($audit, $this->auditCount());
        $this->assertSame($sessionId, $browser['customer']->id(), 'a refused password change must not touch the session');
        $this->assertTrue(password_verify(TestKit::PASSWORD, (string) $this->customerRow($this->ana)['password_hash']), 'the password is unchanged');
    }

    public function testTheCsrfTokenIsAlsoAcceptedAsAFormField(): void
    {
        $browser = $this->browser($this->ana);
        $token = $browser['services']->csrf($browser['customer'])->token();

        $request = new Request('POST', '/' . $this->organization->slug . '/api/v1/account/addresses', post: $this->address() + ['_csrf' => $token], server: ['REMOTE_ADDR' => self::IP]);
        $this->data($this->app->handle($request, $browser['services']), 201);
    }

    // ------------------------------------------------------------------ profile

    public function testTheProfileShowsOnlyTheSignedInCustomerWhateverIdIsSent(): void
    {
        $browser = $this->browser($this->ana);

        $data = $this->data($this->call($browser, 'GET', '/profile'));
        $this->assertSame(['id', 'full_name', 'email', 'phone'], array_keys($data['customer']));
        $this->assertSame($this->ana->id, $data['customer']['id']);
        $this->assertSame('Ana Reyes', $data['customer']['full_name']);
        $this->assertSame('0917 111 2222', $data['customer']['phone']);

        // Nothing in the URL, query or body chooses whose profile it is.
        $request = new Request('GET', '/' . $this->organization->slug . '/api/v1/account/profile', query: ['customer_id' => $this->ben->id, 'id' => $this->ben->id, 'email' => 'ben@account.test'], server: ['REMOTE_ADDR' => self::IP]);
        $response = $this->app->handle($request, $browser['services']);
        $this->assertSame($this->ana->id, $this->data($response)['customer']['id']);
        $this->assertStringNotContainsString('ben@account.test', $response->body());
        $this->assertStringNotContainsString('Ben Santos', $response->body());
        $this->assertStringNotContainsString('password', $response->body(), 'no password or hash is ever returned');
    }

    public function testCustomerAIsNeverShownOrChangedByCustomerBsRequestsEvenWithAIdInTheBody(): void
    {
        $beforeAna = $this->state($this->ana);

        $browser = $this->browser($this->ben);
        $data = $this->data($this->call($browser, 'PUT', '/profile', [
            'full_name' => 'Ben Santos Jr',
            'phone' => '0918 333 4444',
            'id' => $this->ana->id,
            'customer_id' => $this->ana->id,
            'email' => 'ana@account.test',
        ]));

        $this->assertSame($this->ben->id, $data['customer']['id']);
        $this->assertSame('ben@account.test', $data['customer']['email']);
        $this->assertSame($beforeAna, $this->state($this->ana), 'Ana was not touched');
        $this->assertSame('Ben Santos Jr', $this->customerRow($this->ben)['full_name']);
    }

    public function testProfileUpdateChangesNameAndPhoneAndNeverEmailStatusOrPassword(): void
    {
        $before = $this->customerRow($this->ana);
        $browser = $this->browser($this->ana);

        $data = $this->data($this->call($browser, 'PUT', '/profile', [
            'full_name' => '  Ana   Maria  Reyes ',
            'phone' => '+63 917 555 0000',
            // None of these may take effect.
            'email' => 'attacker@evil.test',
            'status' => 'inactive',
            'password' => 'should-not-be-set-1',
            'password_hash' => 'x',
            'id' => $this->ben->id,
        ]));

        $this->assertSame('Ana Maria Reyes', $data['customer']['full_name'], 'spaces are tidied like at registration');
        $this->assertSame('+63 917 555 0000', $data['customer']['phone']);
        $this->assertSame('ana@account.test', $data['customer']['email']);

        $after = $this->customerRow($this->ana);
        $this->assertSame($before['email'], $after['email']);
        $this->assertSame('active', $after['status']);
        $this->assertSame($before['password_hash'], $after['password_hash'], 'a profile update never touches the password');
        $this->assertSame($this->ana->id, $after['id']);

        // The old password still signs in, and the email never moved.
        $this->assertSame($this->ana->id, $browser['services']->auth()->authenticateCustomer('ana@account.test', TestKit::PASSWORD, self::IP)->id);
        $this->assertNull($this->kit->customers->findByEmail('attacker@evil.test'));
    }

    public function testProfileUpdateValidatesEveryFieldWithTheRegistrationMessages(): void
    {
        $browser = $this->browser($this->ana);
        $before = $this->state($this->ana);
        $auditBefore = $this->auditCount();

        $nameMessage = 'Enter your full name (2 to 120 characters).';
        $phoneMessage = 'Enter a valid phone number, or leave it empty.';

        $cases = [
            'missing name' => [['phone' => '0917 111 2222'], ['full_name' => $nameMessage]],
            'one character' => [['full_name' => 'A', 'phone' => ''], ['full_name' => $nameMessage]],
            'too long' => [['full_name' => str_repeat('a', 121), 'phone' => ''], ['full_name' => $nameMessage]],
            'only spaces' => [['full_name' => '     ', 'phone' => ''], ['full_name' => $nameMessage]],
            'not text' => [['full_name' => ['Ana'], 'phone' => ''], ['full_name' => $nameMessage]],
            'a control character' => [['full_name' => "Ana\x07Reyes", 'phone' => ''], ['full_name' => $nameMessage]],
            'letters in the phone' => [['full_name' => 'Ana Reyes', 'phone' => 'call-me-maybe'], ['phone' => $phoneMessage]],
            'a short phone' => [['full_name' => 'Ana Reyes', 'phone' => '12345'], ['phone' => $phoneMessage]],
            'a long phone' => [['full_name' => 'Ana Reyes', 'phone' => str_repeat('1', 21)], ['phone' => $phoneMessage]],
            'a phone that is not text' => [['full_name' => 'Ana Reyes', 'phone' => ['0917']], ['phone' => $phoneMessage]],
            'both wrong' => [['full_name' => 'A', 'phone' => 'abc'], ['full_name' => $nameMessage, 'phone' => $phoneMessage]],
            'nothing sent' => [[], ['full_name' => $nameMessage]],
        ];

        foreach ($cases as $label => [$payload, $expected]) {
            $error = $this->failure($this->call($browser, 'PUT', '/profile', $payload), 422, 'validation_failed');
            $this->assertSame($expected, $error['fields'], $label);
            $this->assertSame($before, $this->state($this->ana), "$label changed something");
        }

        $this->assertSame($auditBefore, $this->auditCount(), 'a refused update writes no audit entry');
    }

    public function testProfileBoundariesAndClearingThePhone(): void
    {
        $browser = $this->browser($this->ana);

        $this->assertSame(str_repeat('a', 120), $this->data($this->call($browser, 'PUT', '/profile', ['full_name' => str_repeat('a', 120), 'phone' => str_repeat('1', 20)]))['customer']['full_name']);
        $this->assertSame('Al', $this->data($this->call($browser, 'PUT', '/profile', ['full_name' => 'Al', 'phone' => '1234567']))['customer']['full_name']);

        // PUT replaces the two fields, so a phone that is left out or empty is cleared.
        $this->assertNull($this->data($this->call($browser, 'PUT', '/profile', ['full_name' => 'Ana Reyes']))['customer']['phone']);
        $this->assertNull($this->customerRow($this->ana)['phone']);
        $this->assertNull($this->data($this->call($browser, 'PUT', '/profile', ['full_name' => 'Ana Reyes', 'phone' => '']))['customer']['phone']);
    }

    public function testProfileUpdateAuditNamesTheFieldsAndNeverTheValues(): void
    {
        $browser = $this->browser($this->ana);
        $this->data($this->call($browser, 'PUT', '/profile', ['full_name' => 'Ana Secret Surname', 'phone' => '0917 111 2222']));
        $this->assertSame(1, $this->countAudit('customer.profile_updated'));

        // Saving the same values again changes nothing, so it writes nothing.
        $this->data($this->call($browser, 'PUT', '/profile', ['full_name' => 'Ana Secret Surname', 'phone' => '0917 111 2222']));
        $this->assertSame(1, $this->countAudit('customer.profile_updated'));

        $statement = $this->core->prepare("SELECT entity, entity_id, detail::text AS detail FROM audit_log WHERE action = 'customer.profile_updated'");
        $statement->execute();
        $row = $statement->fetch();
        $this->assertSame('customer', $row['entity']);
        $this->assertSame($this->ana->id, $row['entity_id']);
        $this->assertSame(['fields' => ['full_name']], json_decode((string) $row['detail'], true));
        $this->assertStringNotContainsString('Secret', (string) $row['detail']);
    }

    public function testMalformedJsonIsABadRequestAndAFormBodyWorksToo(): void
    {
        $browser = $this->browser($this->ana);
        $token = $browser['services']->csrf($browser['customer'])->token();
        $server = ['REMOTE_ADDR' => self::IP, 'HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'];

        $bad = new Request('PUT', '/' . $this->organization->slug . '/api/v1/account/profile', server: $server, bodyReader: static fn (): string => '{not json');
        $this->failure($this->app->handle($bad, $browser['services']), 400, 'bad_request');

        $form = new Request('PUT', '/' . $this->organization->slug . '/api/v1/account/profile', post: ['full_name' => 'Ana Form', 'phone' => ''], server: ['REMOTE_ADDR' => self::IP, 'HTTP_X_CSRF_TOKEN' => $token]);
        $this->assertSame('Ana Form', $this->data($this->app->handle($form, $browser['services']))['customer']['full_name']);
    }

    // ------------------------------------------------------------------ addresses: validation

    public function testAddressValidationGivesEachFieldItsOwnMessage(): void
    {
        $browser = $this->browser($this->ana);
        $messages = [
            'label' => 'Use at most 40 characters for the label.',
            'line1' => 'Enter the street address (up to 200 characters).',
            'barangay' => 'Use at most 100 characters for the barangay.',
            'city' => 'Enter the city or municipality (up to 100 characters).',
            'province' => 'Enter the province (up to 100 characters).',
            'postal_code' => 'Enter a 4-digit postal code, or leave it empty.',
        ];

        $cases = [
            'label too long' => [['label' => str_repeat('l', 41)], ['label']],
            'line1 missing' => [['line1' => null], ['line1']],
            'line1 empty' => [['line1' => '   '], ['line1']],
            'line1 too long' => [['line1' => str_repeat('x', 201)], ['line1']],
            'barangay too long' => [['barangay' => str_repeat('b', 101)], ['barangay']],
            'city missing' => [['city' => null], ['city']],
            'city too long' => [['city' => str_repeat('c', 101)], ['city']],
            'province missing' => [['province' => ''], ['province']],
            'province too long' => [['province' => str_repeat('p', 101)], ['province']],
            'postal code of three digits' => [['postal_code' => '123'], ['postal_code']],
            'postal code of five digits' => [['postal_code' => '12345'], ['postal_code']],
            'postal code with letters' => [['postal_code' => '61A0'], ['postal_code']],
            'postal code with a space inside' => [['postal_code' => '61 00'], ['postal_code']],
            'postal code as a number' => [['postal_code' => 6100], ['postal_code']],
            'postal code as a list' => [['postal_code' => ['6100']], ['postal_code']],
            'a field that is not text' => [['city' => ['Bacolod']], ['city']],
            'a control character' => [['line1' => "12 Rizal\x00Street"], ['line1']],
            'several at once' => [['label' => str_repeat('l', 41), 'city' => '', 'postal_code' => 'abc'], ['label', 'city', 'postal_code']],
        ];

        $before = $this->state($this->ana);
        foreach ($cases as $label => [$override, $fields]) {
            $expected = [];
            foreach ($fields as $field) {
                $expected[$field] = $messages[$field];
            }

            $error = $this->failure($this->call($browser, 'POST', '/addresses', $this->address($override)), 422, 'validation_failed');
            $this->assertSame($expected, $error['fields'], $label);
        }
        $this->assertSame($before, $this->state($this->ana), 'a refused address is never saved');

        // An empty body names the three required fields.
        $this->assertSame(['line1', 'city', 'province'], array_keys($this->failure($this->call($browser, 'POST', '/addresses', []), 422, 'validation_failed')['fields']));
    }

    public function testAddressBoundariesAreAcceptedAndTextIsTidied(): void
    {
        $browser = $this->browser($this->ana);

        $address = $this->data($this->call($browser, 'POST', '/addresses', [
            'label' => str_repeat('l', 40),
            'line1' => str_repeat('x', 200),
            'barangay' => str_repeat('b', 100),
            'city' => str_repeat('c', 100),
            'province' => str_repeat('p', 100),
            'postal_code' => '0000',
        ]), 201)['address'];
        $this->assertSame(40, strlen($address['label']));
        $this->assertSame(200, strlen($address['line1']));
        $this->assertSame('0000', $address['postal_code']);

        $tidy = $this->data($this->call($browser, 'POST', '/addresses', ['label' => '  ', 'line1' => "  12   Rizal \t Street ", 'barangay' => '', 'city' => ' Bacolod  City ', 'province' => 'Negros Occidental', 'postal_code' => ' 6100 ']), 201)['address'];
        $this->assertNull($tidy['label'], 'an empty optional field is stored as nothing');
        $this->assertNull($tidy['barangay']);
        $this->assertSame('12 Rizal Street', $tidy['line1']);
        $this->assertSame('Bacolod City', $tidy['city']);
        $this->assertSame('6100', $tidy['postal_code']);

        $minimal = $this->data($this->call($browser, 'POST', '/addresses', ['line1' => '1 Road', 'city' => 'Silay', 'province' => 'Negros Occidental']), 201)['address'];
        $this->assertNull($minimal['postal_code']);
        $this->assertSame(['id', 'label', 'line1', 'barangay', 'city', 'province', 'postal_code', 'is_default', 'created_at', 'updated_at'], array_keys($minimal));
        $this->assertSame(1, preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $minimal['created_at']), 'timestamps are ISO 8601');
    }

    // ------------------------------------------------------------------ addresses: default, order, limit

    public function testTheFirstAddressBecomesTheDefaultAndNoInputCanChangeThat(): void
    {
        $browser = $this->browser($this->ana);

        $first = $this->data($this->call($browser, 'POST', '/addresses', $this->address(['label' => 'First'])), 201)['address'];
        $this->assertTrue($first['is_default']);

        // Asking to be the default on create is ignored.
        $second = $this->data($this->call($browser, 'POST', '/addresses', $this->address(['label' => 'Second', 'is_default' => true])), 201)['address'];
        $this->assertFalse($second['is_default']);
        $this->assertExactlyOneDefault($this->ana, 'the second create');

        $defaults = array_values(array_filter($this->rows($this->ana), static fn (array $row): bool => $row['is_default'] === true));
        $this->assertSame($first['id'], $defaults[0]['id']);

        // Ben's first address is his own default, whatever Ana has.
        $this->assertTrue($this->data($this->call($this->browser($this->ben), 'POST', '/addresses', $this->address()), 201)['address']['is_default']);
    }

    public function testExactlyOneDefaultAfterEveryOperation(): void
    {
        $browser = $this->browser($this->ana);
        $a = $this->save($this->ana, 'A');
        $this->assertExactlyOneDefault($this->ana, 'create A');
        $b = $this->save($this->ana, 'B');
        $this->assertExactlyOneDefault($this->ana, 'create B');
        $c = $this->save($this->ana, 'C');
        $this->assertExactlyOneDefault($this->ana, 'create C');

        $this->assertTrue($this->data($this->call($browser, 'POST', '/addresses/' . $c . '/default'))['address']['is_default']);
        $this->assertExactlyOneDefault($this->ana, 'set C as the default');
        $this->assertSame([$c], $this->defaultIds($this->ana), 'the old default was cleared');

        $this->data($this->call($browser, 'POST', '/addresses/' . $c . '/default'));
        $this->assertSame([$c], $this->defaultIds($this->ana), 'setting the current default again changes nothing');

        $this->data($this->call($browser, 'POST', '/addresses/' . $a . '/default'));
        $this->assertSame([$a], $this->defaultIds($this->ana), 'set A as the default');

        // Editing never moves the default, even when the body asks for it.
        $this->data($this->call($browser, 'PUT', '/addresses/' . $b, $this->address(['label' => 'B edited', 'is_default' => true])));
        $this->assertSame([$a], $this->defaultIds($this->ana), 'edit B');

        $this->data($this->call($browser, 'DELETE', '/addresses/' . $b));
        $this->assertSame([$a], $this->defaultIds($this->ana), 'deleting a non-default address keeps the default');

        $this->data($this->call($browser, 'DELETE', '/addresses/' . $a));
        $this->assertSame([$c], $this->defaultIds($this->ana), 'deleting the default promotes the remaining address');

        $this->data($this->call($browser, 'DELETE', '/addresses/' . $c));
        $this->assertSame([], $this->rows($this->ana));

        // With none left, the next address is the first again, so it is the default.
        $fresh = $this->data($this->call($browser, 'POST', '/addresses', $this->address()), 201)['address'];
        $this->assertTrue($fresh['is_default']);
        $this->assertExactlyOneDefault($this->ana, 'create after deleting everything');
    }

    /** @return list<string> */
    private function defaultIds(CustomerAccount $customer): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['id'],
            array_filter($this->rows($customer), static fn (array $row): bool => $row['is_default'] === true),
        ));
    }

    public function testDeletingTheDefaultPromotesTheNewestRemainingAddressNotTheOldest(): void
    {
        $browser = $this->browser($this->ana);
        $oldest = $this->save($this->ana, 'Oldest');
        $middle = $this->save($this->ana, 'Middle');
        $newest = $this->save($this->ana, 'Newest');
        $this->assertSame([$oldest], $this->defaultIds($this->ana));

        $this->data($this->call($browser, 'DELETE', '/addresses/' . $oldest));
        $this->assertSame([$newest], $this->defaultIds($this->ana), 'the most recently created address is promoted');

        $this->data($this->call($browser, 'DELETE', '/addresses/' . $newest));
        $this->assertSame([$middle], $this->defaultIds($this->ana));
    }

    public function testTheListShowsTheDefaultFirstThenTheNewest(): void
    {
        $browser = $this->browser($this->ana);
        $a = $this->save($this->ana, 'A');
        $b = $this->save($this->ana, 'B');
        $c = $this->save($this->ana, 'C');
        $d = $this->save($this->ana, 'D');

        $ids = static fn (array $data): array => array_column($data['items'], 'id');

        $data = $this->data($this->call($browser, 'GET', '/addresses'));
        $this->assertSame([$a, $d, $c, $b], $ids($data), 'the first address is the default, then newest first');
        $this->assertSame(CustomerAccountService::MAX_ADDRESSES, $data['max_addresses']);

        $this->data($this->call($browser, 'POST', '/addresses/' . $c . '/default'));
        $this->assertSame([$c, $d, $b, $a], $ids($this->data($this->call($browser, 'GET', '/addresses'))));

        $this->assertSame([], $this->data($this->call($this->browser($this->ben), 'GET', '/addresses'))['items'], 'another customer sees none of them');
    }

    public function testNoMoreThanTenAddressesPerCustomer(): void
    {
        $browser = $this->browser($this->ana);
        for ($i = 1; $i <= CustomerAccountService::MAX_ADDRESSES; $i++) {
            $this->save($this->ana, 'Address ' . $i);
        }
        $this->assertCount(10, $this->rows($this->ana));

        $before = $this->state($this->ana);
        $audit = $this->auditCount();
        $error = $this->failure($this->call($browser, 'POST', '/addresses', $this->address(['label' => 'Eleven'])), 409, 'conflict');
        $this->assertStringContainsString('10 addresses', $error['message']);
        $this->assertSame($before, $this->state($this->ana), 'the eleventh is not saved');
        $this->assertSame($audit, $this->auditCount());

        // The limit is per customer, and deleting one makes room.
        $this->data($this->call($this->browser($this->ben), 'POST', '/addresses', $this->address()), 201);
        $this->data($this->call($browser, 'DELETE', '/addresses/' . $this->rows($this->ana)[3]['id']));
        $this->data($this->call($browser, 'POST', '/addresses', $this->address(['label' => 'Eleven'])), 201);
        $this->assertCount(10, $this->rows($this->ana));
        $this->assertExactlyOneDefault($this->ana, 'after the limit was reached and a slot reused');
    }

    public function testUpdatingAnAddressReplacesTheFieldsAndClearsLeftOutOptionalOnes(): void
    {
        $browser = $this->browser($this->ana);
        $id = $this->save($this->ana, 'Home');

        $updated = $this->data($this->call($browser, 'PUT', '/addresses/' . $id, ['line1' => '5 Lacson Street', 'city' => 'Talisay', 'province' => 'Negros Occidental']))['address'];
        $this->assertSame($id, $updated['id']);
        $this->assertSame('5 Lacson Street', $updated['line1']);
        $this->assertSame('Talisay', $updated['city']);
        $this->assertNull($updated['label']);
        $this->assertNull($updated['barangay']);
        $this->assertNull($updated['postal_code']);
        $this->assertTrue($updated['is_default'], 'editing does not move the default');

        $before = $this->state($this->ana);
        $error = $this->failure($this->call($browser, 'PUT', '/addresses/' . $id, $this->address(['postal_code' => '12'])), 422, 'validation_failed');
        $this->assertSame(['postal_code'], array_keys($error['fields']));
        $this->assertSame($before, $this->state($this->ana), 'a refused edit changes nothing');
    }

    // ------------------------------------------------------------------ addresses: other customers

    public function testAnotherCustomersAddressIsAPlain404ForUpdateDeleteAndSetDefault(): void
    {
        $theirs = $this->save($this->ben, 'Ben home');
        $theirSecond = $this->save($this->ben, 'Ben work');
        $unknown = '3f0c8a52-6b7e-4a55-9d0e-1c2b3a4d5e6f';

        $benBefore = $this->state($this->ben);
        $anaBefore = $this->state($this->ana);
        $audit = $this->auditCount();
        $browser = $this->browser($this->ana);

        $valid = $this->address(['line1' => 'Taken over']);
        $invalid = ['line1' => '', 'city' => '', 'province' => '', 'postal_code' => 'bad'];

        foreach ([$theirs, $theirSecond] as $id) {
            $reference = $this->failure($this->call($browser, 'PUT', '/addresses/' . $unknown, $valid), 404, 'not_found');

            $attempts = [
                'update' => ['PUT', '/addresses/' . $id, $valid],
                'update with a body that would fail validation' => ['PUT', '/addresses/' . $id, $invalid],
                'set the default' => ['POST', '/addresses/' . $id . '/default', null],
                'delete' => ['DELETE', '/addresses/' . $id, null],
            ];
            foreach ($attempts as $label => [$method, $suffix, $payload]) {
                $error = $this->failure($this->call($browser, $method, $suffix, $payload), 404, 'not_found');
                // The same answer an unknown id gets, so nothing shows that the address exists.
                $this->assertSame($reference['message'], $error['message'], "$label: the message differs from an unknown id's");
                $this->assertSame([], $error['fields'], $label);
            }
        }

        $this->assertSame($benBefore, $this->state($this->ben), "Ben's address and profile are untouched");
        $this->assertSame($anaBefore, $this->state($this->ana));
        $this->assertSame($audit, $this->auditCount(), 'a refused request writes no audit entry');

        // The list never shows another customer's address, and nothing in the request can ask for it.
        $request = new Request('GET', '/' . $this->organization->slug . '/api/v1/account/addresses', query: ['customer_id' => $this->ben->id], server: ['REMOTE_ADDR' => self::IP]);
        $response = $this->app->handle($request, $browser['services']);
        $this->assertSame([], $this->data($response)['items']);
        $this->assertStringNotContainsString('Ben', $response->body());
    }

    public function testTheRepositoryItselfNeverTouchesAnotherCustomersAddress(): void
    {
        // The service checks ownership first, so this proves the second guard: every address query filters by the
        // customer id on its own, and an address id alone never selects, changes or deletes a row.
        $repository = new CustomerAddressRepository($this->core);
        $theirs = $this->save($this->ben, 'Ben home');
        $this->save($this->ben, 'Ben work');
        $before = $this->state($this->ben);
        $fields = ['label' => 'Hijacked', 'line1' => 'Hijacked', 'barangay' => null, 'city' => 'Hijacked', 'province' => 'Hijacked', 'postal_code' => '9999'];

        $this->assertNull($repository->findForCustomer($this->ana->id, $theirs));
        $this->assertFalse($repository->update($this->ana->id, $theirs, $fields), 'update must match no row');
        $this->assertFalse($repository->markDefault($this->ana->id, $theirs), 'markDefault must match no row');
        $this->assertFalse($repository->delete($this->ana->id, $theirs), 'delete must match no row');
        $this->assertSame([], $repository->listForCustomer($this->ana->id));
        $this->assertNull($repository->newestId($this->ana->id));
        $this->assertSame(0, $repository->countForCustomer($this->ana->id));

        // Clearing the default is per customer too: Ana's call must leave Ben's default alone.
        $repository->clearDefault($this->ana->id);

        $this->assertSame($before, $this->state($this->ben), "Ben's addresses are untouched");
    }

    public function testAMalformedAddressIdIsTheSame404(): void
    {
        $browser = $this->browser($this->ana);
        $unknown = $this->failure($this->call($browser, 'DELETE', '/addresses/3f0c8a52-6b7e-4a55-9d0e-1c2b3a4d5e6f'), 404, 'not_found');

        foreach (['not-a-uuid', '1', "1'; DROP TABLE customer_addresses; --", str_repeat('a', 80)] as $id) {
            foreach ([['PUT', $this->address()], ['POST', null], ['DELETE', null]] as [$method, $payload]) {
                $suffix = $method === 'POST' ? '/addresses/' . rawurlencode($id) . '/default' : '/addresses/' . rawurlencode($id);
                $error = $this->failure($this->call($browser, $method, $suffix, $payload), 404, 'not_found');
                $this->assertSame($unknown['message'], $error['message'], "$method $id");
            }
        }

        $this->assertSame(1, (int) $this->core->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_name = \'customer_addresses\'')->fetchColumn(), 'the table is still there');
    }

    public function testEveryAddressChangeWritesAnAuditEntryWithNoAddressTextInIt(): void
    {
        $browser = $this->browser($this->ana);
        $a = $this->save($this->ana, 'Audit home');
        $b = $this->save($this->ana, 'Audit work');
        $this->data($this->call($browser, 'PUT', '/addresses/' . $b, $this->address(['line1' => '77 Secret Lane', 'label' => 'Audit work'])));
        $this->data($this->call($browser, 'POST', '/addresses/' . $b . '/default'));
        $this->data($this->call($browser, 'DELETE', '/addresses/' . $b));

        $this->assertSame(2, $this->countAudit('customer.address_created'));
        $this->assertSame(1, $this->countAudit('customer.address_updated'));
        $this->assertSame(1, $this->countAudit('customer.address_default_changed'));
        $this->assertSame(1, $this->countAudit('customer.address_deleted'));

        $statement = $this->core->prepare("SELECT detail::text FROM audit_log WHERE action LIKE 'customer.address_%'");
        $statement->execute();
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $detail) {
            $this->assertStringNotContainsString('Secret', (string) $detail);
            $this->assertStringNotContainsString('Rizal', (string) $detail);
            $this->assertStringContainsString($this->ana->id, (string) $detail, 'the entry says whose account it was');
        }

        $deleted = $this->core->query("SELECT detail FROM audit_log WHERE action = 'customer.address_deleted'")->fetchColumn();
        $this->assertSame($a, json_decode((string) $deleted, true)['promoted_address_id'], 'the entry records the promotion');
    }

    // ------------------------------------------------------------------ password

    /** @return array<string, string> */
    private function passwordForm(string $current = TestKit::PASSWORD, string $new = self::NEW_PASSWORD, ?string $confirmation = null): array
    {
        return ['current_password' => $current, 'new_password' => $new, 'new_password_confirmation' => $confirmation ?? $new];
    }

    public function testAWrongCurrentPasswordIsA422OnThatFieldIsRecordedAndChangesNothing(): void
    {
        $browser = $this->browser($this->ana);
        $before = $this->state($this->ana);
        $sessionId = $browser['customer']->id();

        $error = $this->failure($this->call($browser, 'POST', '/password', $this->passwordForm('not-my-password-1')), 422, 'validation_failed');
        $this->assertSame(['current_password' => CustomerAccountService::WRONG_CURRENT_PASSWORD], $error['fields']);

        $this->assertSame($before, $this->state($this->ana), 'the password is unchanged');
        $this->assertSame(1, $this->failedChecks($this->ana), 'the wrong password is recorded under the customer scope');
        $this->assertSame($sessionId, $browser['customer']->id(), 'the session id is unchanged after a refused change');
        $this->assertSame(0, $this->countAudit('customer.password_changed'));
        $this->assertSame(1, $this->countAudit('customer.password_change_failed'));

        // The failed attempt leaves the attempted password nowhere in the audit log.
        $detail = (string) $this->core->query("SELECT detail::text FROM audit_log WHERE action = 'customer.password_change_failed'")->fetchColumn();
        $this->assertStringNotContainsString('not-my-password-1', $detail);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $detail);
    }

    public function testWrongCurrentPasswordsLockTheCustomerOutAtTheSameLimitAsSignIn(): void
    {
        $limit = $this->loginLimit();
        $browser = $this->browser($this->ana);

        for ($i = 1; $i <= $limit; $i++) {
            $this->failure($this->call($browser, 'POST', '/password', $this->passwordForm('guess-number-' . $i)), 422, 'validation_failed');
            $this->assertSame($i, $this->failedChecks($this->ana));
        }

        // Locked: even the CORRECT current password is refused, as at sign-in, with the same status, message and header.
        $locked = $this->call($browser, 'POST', '/password', $this->passwordForm());
        $error = $this->failure($locked, 429, 'too_many_requests');
        $this->assertSame(AuthService::LOCKED_MESSAGE, $error['message']);
        $this->assertNotNull($locked->header('Retry-After'));
        $this->assertTrue(password_verify(TestKit::PASSWORD, (string) $this->customerRow($this->ana)['password_hash']), 'a locked-out customer cannot change the password');
        $this->assertSame(0, $this->countAudit('customer.password_changed'));

        // It is one shared lockout: sign-in is refused now too, with the same message.
        $signIn = $this->assertHttpStatus(429, fn () => $browser['services']->auth()->authenticateCustomer('ana@account.test', TestKit::PASSWORD, self::IP));
        $this->assertSame(AuthService::LOCKED_MESSAGE, $signIn->getMessage());

        // Another customer is not affected.
        $this->data($this->call($this->browser($this->ben), 'POST', '/password', $this->passwordForm()));
    }

    public function testFailedSignInsCountTowardsThePasswordChangeLockoutToo(): void
    {
        $browser = $this->browser($this->ana);
        for ($i = 0; $i < $this->loginLimit(); $i++) {
            $this->assertHttpStatus(401, fn () => $browser['services']->auth()->authenticateCustomer('ana@account.test', 'wrong-at-sign-in-' . $i, self::IP));
        }

        $this->failure($this->call($browser, 'POST', '/password', $this->passwordForm()), 429, 'too_many_requests');
        $this->assertTrue(password_verify(TestKit::PASSWORD, (string) $this->customerRow($this->ana)['password_hash']));
    }

    public function testACorrectPasswordClearsEarlierFailuresLikeSignInDoes(): void
    {
        $limit = $this->loginLimit();
        $browser = $this->browser($this->ana);

        for ($i = 1; $i < $limit; $i++) {
            $this->failure($this->call($browser, 'POST', '/password', $this->passwordForm('wrong-before-' . $i)), 422, 'validation_failed');
        }
        $this->data($this->call($browser, 'POST', '/password', $this->passwordForm(TestKit::PASSWORD, self::NEW_PASSWORD)));

        // After the success the count starts again: limit - 1 more failures still do not lock.
        for ($i = 1; $i < $limit; $i++) {
            $this->failure($this->call($browser, 'POST', '/password', $this->passwordForm('wrong-after-' . $i, 'another-new-passphrase-3')), 422, 'validation_failed');
        }
        $this->data($this->call($browser, 'POST', '/password', $this->passwordForm(self::NEW_PASSWORD, 'another-new-passphrase-3')));
    }

    public function testABlankCurrentPasswordIsAFormErrorNotAGuess(): void
    {
        $browser = $this->browser($this->ana);

        $error = $this->failure($this->call($browser, 'POST', '/password', $this->passwordForm('')), 422, 'validation_failed');
        $this->assertSame(['current_password' => 'Enter your current password.'], $error['fields']);
        $this->assertSame(0, $this->failedChecks($this->ana), 'nothing was guessed, so nothing is counted');

        $missing = $this->failure($this->call($browser, 'POST', '/password', ['new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD]), 422, 'validation_failed');
        $this->assertArrayHasKey('current_password', $missing['fields']);

        $notText = $this->failure($this->call($browser, 'POST', '/password', ['current_password' => ['x'], 'new_password' => self::NEW_PASSWORD, 'new_password_confirmation' => self::NEW_PASSWORD]), 422, 'validation_failed');
        $this->assertArrayHasKey('current_password', $notText['fields']);
    }

    public function testWeakEmailAndMismatchedNewPasswordsAreRefusedAndNothingChanges(): void
    {
        $browser = $this->browser($this->ana);
        $before = $this->state($this->ana);
        $sessionId = $browser['customer']->id();

        $cases = [
            'too short' => [$this->passwordForm(TestKit::PASSWORD, 'short1'), ['new_password' => 'Use at least 10 characters.']],
            'too long' => [$this->passwordForm(TestKit::PASSWORD, str_repeat('a', 73)), ['new_password' => 'Use at most 72 characters.']],
            'only spaces' => [$this->passwordForm(TestKit::PASSWORD, str_repeat(' ', 12)), ['new_password' => 'The password cannot be only spaces.']],
            'the email address' => [$this->passwordForm(TestKit::PASSWORD, 'ana@account.test'), ['new_password' => 'The password cannot be your email address.']],
            'the email in capitals' => [$this->passwordForm(TestKit::PASSWORD, 'ANA@ACCOUNT.TEST'), ['new_password' => 'The password cannot be your email address.']],
            'a different confirmation' => [$this->passwordForm(TestKit::PASSWORD, self::NEW_PASSWORD, 'a-brand-new-passphrase-8'), ['new_password_confirmation' => 'The confirmation does not match the new password.']],
            'a missing confirmation' => [['current_password' => TestKit::PASSWORD, 'new_password' => self::NEW_PASSWORD], ['new_password_confirmation' => 'The confirmation does not match the new password.']],
            'weak and mismatched' => [$this->passwordForm(TestKit::PASSWORD, 'short1', 'other'), ['new_password' => 'Use at least 10 characters.', 'new_password_confirmation' => 'The confirmation does not match the new password.']],
        ];

        foreach ($cases as $label => [$payload, $expected]) {
            $error = $this->failure($this->call($browser, 'POST', '/password', $payload), 422, 'validation_failed');
            $this->assertSame($expected, $error['fields'], $label);
            $this->assertSame($before, $this->state($this->ana), "$label changed something");
        }

        $this->assertSame(0, $this->failedChecks($this->ana), 'a good current password with a bad new one is not a failed guess');
        $this->assertSame($sessionId, $browser['customer']->id());
        $this->assertSame(0, $this->countAudit('customer.password_changed'));
    }

    public function testChangingThePasswordStoresARealHashEndsTheOldPasswordAndRegeneratesTheSession(): void
    {
        $browser = $this->browser($this->ana);
        $oldHash = (string) $this->customerRow($this->ana)['password_hash'];
        $sessionBefore = $browser['customer']->id();

        $data = $this->data($this->call($browser, 'POST', '/password', $this->passwordForm()));
        $this->assertSame(['password_changed' => true], $data);

        // A real hash: not the plain text, not the old hash, and it verifies.
        $hash = (string) $this->customerRow($this->ana)['password_hash'];
        $this->assertNotSame(self::NEW_PASSWORD, $hash);
        $this->assertNotSame($oldHash, $hash);
        $this->assertGreaterThanOrEqual(50, strlen($hash));
        $this->assertStringStartsWith('$', $hash);
        $this->assertNotNull(password_get_info($hash)['algo'], 'a password_hash() value');
        $this->assertTrue(password_verify(self::NEW_PASSWORD, $hash));
        $this->assertFalse(password_verify(TestKit::PASSWORD, $hash));

        // The old password stops working at sign-in and the new one works.
        $services = $this->browser(null)['services'];
        $this->assertHttpStatus(401, fn () => $services->auth()->authenticateCustomer('ana@account.test', TestKit::PASSWORD, self::IP));
        $this->assertSame($this->ana->id, $services->auth()->authenticateCustomer('ana@account.test', self::NEW_PASSWORD, self::IP)->id);

        // The session id changed, and the customer is still signed in with the same token.
        $this->assertNotSame($sessionBefore, $browser['customer']->id(), 'the session id is regenerated');
        $this->assertSame($this->ana->id, $this->data($this->call($browser, 'GET', '/profile'))['customer']['id']);
        $this->data($this->call($browser, 'PUT', '/profile', ['full_name' => 'Ana Reyes', 'phone' => '']));

        // Only the password changed.
        $this->assertSame('ana@account.test', $this->customerRow($this->ana)['email']);
        $this->assertSame('active', $this->customerRow($this->ana)['status']);
    }

    public function testThePasswordChangeAuditEntryExistsAndHoldsNoPasswordHashOrToken(): void
    {
        $browser = $this->browser($this->ana);
        $this->data($this->call($browser, 'POST', '/password', $this->passwordForm()));

        $this->assertSame(1, $this->countAudit('customer.password_changed'));
        $statement = $this->core->prepare("SELECT entity, entity_id, actor_user_id, detail::text AS detail FROM audit_log WHERE action = 'customer.password_changed'");
        $statement->execute();
        $row = $statement->fetch();
        $this->assertSame('customer', $row['entity']);
        $this->assertSame($this->ana->id, $row['entity_id']);
        $this->assertNull($row['actor_user_id'], 'the customer is not a staff user');

        // Search every audit row about this customer, whatever its action, for any secret.
        $hash = (string) $this->customerRow($this->ana)['password_hash'];
        $all = $this->core->prepare('SELECT action, entity_id, detail::text AS detail FROM audit_log WHERE entity_id = ?');
        $all->execute([$this->ana->id]);
        $text = json_encode($all->fetchAll(), JSON_THROW_ON_ERROR);
        foreach ([self::NEW_PASSWORD, TestKit::PASSWORD, $hash, substr($hash, 0, 20), $browser['services']->csrf($browser['customer'])->token()] as $secret) {
            $this->assertStringNotContainsString($secret, $text, 'no secret may appear in the audit log');
        }
        $this->assertStringNotContainsString('password_hash', $text);
        $this->assertStringNotContainsString('token', strtolower((string) $row['detail']));
    }

    public function testOtherDevicesStaySignedInBecauseSessionsArePhpSessions(): void
    {
        $phone = $this->browser($this->ana);
        $laptop = $this->browser($this->ana);
        $laptopSession = $laptop['customer']->id();

        $this->data($this->call($phone, 'POST', '/password', $this->passwordForm()));

        // Documented in docs/api.md: nothing signs the other device out.
        $this->assertSame($laptopSession, $laptop['customer']->id());
        $this->assertSame($this->ana->id, $this->data($this->call($laptop, 'GET', '/profile'))['customer']['id']);
    }
}

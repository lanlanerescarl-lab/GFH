<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Batch SA.1b end-to-end tests through the real App + Router + core/routes.php + middleware stack
 * and real PostgreSQL core database. Never opens an organization database.
 */
final class SuperAdminBatch1bTest extends DatabaseTestCase
{
    private const IP = '198.51.100.77';

    private App $app;
    private bool $booted = false;
    private int $orgDbFactoryCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 2));
        $this->booted = true;
        $this->orgDbFactoryCalls = 0;
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

        $factory = function (): \PDO {
            $this->orgDbFactoryCalls++;
            throw new \RuntimeException('Super Admin SA.1b must never open an organization database.');
        };

        $services = new Services(
            $this->app,
            false,
            $this->core,
            null,
            testSessions: ['staff' => $staffSession, 'customer' => $customerSession],
            orgConnectionFactory: $factory,
        );

        return ['services' => $services, 'staff' => $staffSession, 'customer' => $customerSession];
    }

    /** @param array<string, mixed> $query */
    private function get(array $browser, string $path, array $query = []): Response
    {
        return $this->app->handle(
            new Request('GET', $path, query: $query, server: ['REMOTE_ADDR' => self::IP]),
            $browser['services'],
        );
    }

    /**
     * Inserts an organization with explicit status, versions, features, branding, and encrypted connection payload.
     *
     * @param list<string>         $features
     * @param array<string, mixed> $branding
     */
    private function seedOrganization(
        string $slug,
        string $name,
        string $shortName,
        string $status = 'active',
        int $migrationVersion = 7,
        ?string $templateVersion = 'v1.0.0',
        array $features = [],
        array $branding = [],
        string $connectionCiphertext = 'postgresql://gfh_secret_user:top_secret_pw@db.internal:5432/org_db',
    ): string {
        $org = $this->kit->organizations->create($slug, $name, $shortName, $connectionCiphertext, 'public', $branding, $features);

        $update = $this->core->prepare(
            'UPDATE organizations SET status = :status, migration_version = :migration_version, template_version = :template_version WHERE id = :id',
        );
        $update->execute([
            'status' => $status,
            'migration_version' => $migrationVersion,
            'template_version' => $templateVersion,
            'id' => $org->id,
        ]);

        return $org->id;
    }

    /**
     * Inserts a core audit_log row with an explicit UTC occurred_at timestamp.
     *
     * @param array<string, mixed> $detail
     */
    private function seedAuditEvent(
        string $occurredAtUtc,
        string $action,
        ?string $actorUserId = null,
        ?string $organizationId = null,
        ?string $entity = 'user',
        ?string $entityId = 'ent-001',
        array $detail = [],
    ): string {
        $stmt = $this->core->prepare(
            'INSERT INTO audit_log (occurred_at, organization_id, actor_user_id, action, entity, entity_id, detail) '
            . 'VALUES (CAST(:occurred_at AS timestamptz), :org_id, :actor_id, :action, :entity, :entity_id, CAST(:detail AS jsonb)) '
            . 'RETURNING id',
        );
        $stmt->execute([
            'occurred_at' => $occurredAtUtc,
            'org_id' => $organizationId,
            'actor_id' => $actorUserId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'detail' => json_encode((object) $detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (string) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------ 1. Role matrix across all SA.1b pages

    /** @return array<string, array{string}> */
    public static function sa1bPaths(): array
    {
        return [
            'overview' => ['/superadmin'],
            'organizations list' => ['/superadmin/organizations'],
            'organization detail' => ['/superadmin/organizations/alpha-org'],
            'audit log' => ['/superadmin/audit'],
        ];
    }

    #[DataProvider('sa1bPaths')]
    public function testRoleMatrixHoldsOnOverviewOrganizationsDetailAndAuditLog(string $path): void
    {
        $this->seedOrganization('alpha-org', 'Alpha Foods', 'ALP');
        $sa = $this->kit->staff('sa-matrix@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Super One');
        $gv = $this->kit->staff('gv-matrix@example.test', TestKit::PASSWORD, 'group_viewer', 'active', 'Viewer One');

        // Super Admin and Group Viewer both allowed.
        $saResp = $this->get($this->browser($sa->id), $path);
        $this->assertSame(200, $saResp->status(), $path);
        $this->assertSame('no-store', $saResp->header('Cache-Control'));

        $gvResp = $this->get($this->browser($gv->id), $path);
        $this->assertSame(200, $gvResp->status(), $path);
        $this->assertSame('no-store', $gvResp->header('Cache-Control'));
        $this->assertStringContainsString('View only', $gvResp->body());

        // Organization roles (admin, manager, staff, cashier) all get 403.
        $otherOrg = $this->kit->organization('role-check-' . bin2hex(random_bytes(3)));
        foreach (['admin', 'manager', 'staff', 'cashier'] as $role) {
            $member = $this->kit->member($otherOrg, $role . '-m@example.test', $role);
            $refused = $this->get($this->browser($member->id), $path);
            $this->assertSame(403, $refused->status(), "$role on $path must get 403");
        }

        // Guest redirected to sign-in.
        $guest = $this->get($this->browser(), $path);
        $this->assertSame(302, $guest->status(), $path);
        $this->assertSame('/superadmin/sign-in?next=' . rawurlencode($path), $guest->header('Location'));

        // Customer redirected to sign-in.
        $customer = $this->kit->auth->registerCustomer('Cust', 'cust-m@example.test', TestKit::PASSWORD);
        $custResp = $this->get($this->browser(null, $customer->id), $path);
        $this->assertSame(302, $custResp->status(), $path);

        $this->assertSame(0, $this->orgDbFactoryCalls);
    }

    // ------------------------------------------------------------------ 2. Overview with real core data

    public function testOverviewShowsStatusCountsOrganizationsListAndTenNewestAuditEvents(): void
    {
        $sa = $this->kit->staff('sa-ov@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Maria Santos');
        $orgA = $this->seedOrganization('north-hub', 'North Hub', 'NH', 'active', 7, 'v1.2.0', ['batches']);
        $this->seedOrganization('south-hub', 'South Hub', 'SH', 'suspended', 6, 'v1.1.0');
        $this->seedOrganization('old-hub', 'Old Hub', 'OH', 'archived', 5, null);

        // Insert 12 audit events so Overview must show only the 10 newest.
        for ($i = 1; $i <= 12; $i++) {
            $ts = sprintf('2026-11-26T%02d:00:00+00:00', $i);
            $this->seedAuditEvent(
                $ts,
                'event.step_' . $i,
                $i % 2 === 0 ? $sa->id : null,
                $i % 3 === 0 ? $orgA : null,
                'record',
                'REC-' . $i,
                ['summary' => 'Activity marker #' . $i],
            );
        }

        $browser = $this->browser($sa->id);
        $overview = $browser['services']->superAdminOverview()->overview($sa);
        $this->assertSame(['total' => 3, 'active' => 1, 'suspended' => 1, 'archived' => 1], $overview['counts']);
        $this->assertCount(3, $overview['organizations']);
        $this->assertCount(10, $overview['recent_activity']);
        $this->assertSame('event.step_12', $overview['recent_activity'][0]['action']);
        $this->assertSame('event.step_3', $overview['recent_activity'][9]['action']);

        $response = $this->get($browser, '/superadmin');
        $this->assertSame(200, $response->status());
        $body = $response->body();

        $this->assertStringContainsString('Consolidated performance and operational health across 3 GFH organizations.', $body);
        $this->assertStringContainsString('North Hub', $body);
        $this->assertStringContainsString('href="/superadmin/organizations/north-hub"', $body);
        $this->assertStringContainsString('v7', $body);
        $this->assertStringContainsString('v1.2.0', $body);
        $this->assertStringContainsString('South Hub', $body);
        $this->assertStringContainsString('Old Hub', $body);
        $this->assertStringContainsString('Recent system activity', $body);
        $this->assertStringContainsString('href="/superadmin/audit">View audit log</a>', $body);
        $this->assertStringContainsString('Activity marker #12', $body);
        $this->assertStringContainsString('Activity marker #3', $body);
        $this->assertStringNotContainsString('Activity marker #2', $body);
        $this->assertStringNotContainsString('Activity marker #1<', $body);
        $this->assertSame(0, $this->orgDbFactoryCalls);
    }

    // ------------------------------------------------------------------ 3. Organizations list & detail

    public function testOrganizationsListFiltersBySearchAndStatusAndDetailShowsFeaturesBrandingAnd404(): void
    {
        $sa = $this->kit->staff('sa-orgs@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Ramon Cruz');

        $this->seedOrganization(
            'fresh-market',
            'Fresh Market Co.',
            'FMC',
            'active',
            7,
            'v1.0.0',
            ['batches', 'care_notes'],
            [
                'primary' => '#2171b5',
                'primary_strong' => '#08519c',
                'font_body' => 'Inter',
                'radius_card' => 12,
                // Should be dropped by BrandingCss::validated():
                'primary_bad_key' => '#000000',
                'background' => '#111111', // fails contrast with default ink #1a2a3a
            ],
        );
        $this->seedOrganization('paused-depot', 'Paused Depot', 'PD', 'suspended', 4, 'v0.9.0', ['made_to_order']);
        $this->seedOrganization('retired-branch', 'Retired Store', 'RS', 'archived', 2, null);

        $browser = $this->browser($sa->id);

        // Full list.
        $allResp = $this->get($browser, '/superadmin/organizations');
        $this->assertSame(200, $allResp->status());
        $allBody = $allResp->body();
        $this->assertStringContainsString('Fresh Market Co.', $allBody);
        $this->assertStringContainsString('Paused Depot', $allBody);
        $this->assertStringContainsString('Retired Store', $allBody);
        $this->assertStringContainsString('batches', $allBody);
        $this->assertStringContainsString('care_notes', $allBody);
        $this->assertStringContainsString('Not connected', $allBody);
        $this->assertStringNotContainsString('New organization', $allBody);

        // Status filter.
        $suspendedResp = $this->get($browser, '/superadmin/organizations', ['status' => 'suspended']);
        $this->assertSame(200, $suspendedResp->status());
        $this->assertStringContainsString('Paused Depot', $suspendedResp->body());
        $this->assertStringNotContainsString('Fresh Market Co.', $suspendedResp->body());

        // Search by slug.
        $searchResp = $this->get($browser, '/superadmin/organizations', ['q' => 'fresh-market']);
        $this->assertSame(200, $searchResp->status());
        $this->assertStringContainsString('Fresh Market Co.', $searchResp->body());
        $this->assertStringNotContainsString('Paused Depot', $searchResp->body());

        // Filtered empty state ("No organizations match").
        $noneResp = $this->get($browser, '/superadmin/organizations', ['q' => 'no-such-org-xyz']);
        $this->assertSame(200, $noneResp->status());
        $this->assertStringContainsString('No organizations match', $noneResp->body());
        $this->assertStringContainsString('Reset', $noneResp->body());

        // Detail page.
        $detailResp = $this->get($browser, '/superadmin/organizations/fresh-market');
        $this->assertSame(200, $detailResp->status());
        $detailBody = $detailResp->body();
        $this->assertStringContainsString('General information', $detailBody);
        $this->assertStringContainsString('Fresh Market Co.', $detailBody);
        $this->assertStringContainsString('FMC', $detailBody);
        $this->assertStringContainsString('fresh-market', $detailBody);
        $this->assertStringContainsString('v7', $detailBody);
        $this->assertStringContainsString('v1.0.0', $detailBody);
        $this->assertStringContainsString('Asia/Manila', $detailBody);
        $this->assertStringContainsString('batches', $detailBody);
        $this->assertStringContainsString('care_notes', $detailBody);
        $this->assertStringContainsString('#2171b5', $detailBody);
        $this->assertStringContainsString('#08519c', $detailBody);
        $this->assertStringContainsString('Inter', $detailBody);
        $this->assertStringContainsString('12px', $detailBody);
        // Ignored / low-contrast branding keys must NOT appear.
        $this->assertStringNotContainsString('primary_bad_key', $detailBody);
        $this->assertStringNotContainsString('#111111', $detailBody);

        // Sidebar marks Organizations as current on detail page too.
        $this->assertStringContainsString('href="/superadmin/organizations" aria-current="page"', $detailBody);

        // Unknown and malformed slugs give standard 404.
        $this->assertSame(404, $this->get($browser, '/superadmin/organizations/does-not-exist')->status());
        $this->assertSame(404, $this->get($browser, '/superadmin/organizations/INVALID_SLUG!')->status());
        $this->assertSame(0, $this->orgDbFactoryCalls);
    }

    // ------------------------------------------------------------------ 4. Audit log: search, filters, sort, pagination, Manila timezone

    public function testAuditLogSupportsSearchFiltersManilaDateRangeSortAnd25PerPagePagination(): void
    {
        $sa = $this->kit->staff('sa-audit@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Elena Lim');
        $otherActor = $this->kit->staff('other-actor@example.test', TestKit::PASSWORD, 'group_viewer', 'active', 'Dante Vega');
        $orgId = $this->seedOrganization('audit-org', 'Audit Org', 'AUD');

        // Insert 30 events to verify 25/page pagination (page 1 = 25, page 2 = 5, total = 30).
        for ($i = 1; $i <= 28; $i++) {
            $ts = sprintf('2026-11-20T00:%02d:00+00:00', $i);
            $this->seedAuditEvent(
                $ts,
                'bulk.event',
                $sa->id,
                $orgId,
                'membership',
                sprintf('MEM-%03d', $i),
                ['summary' => sprintf('Bulk item %02d', $i)],
            );
        }

        // Event #29: UTC 2026-11-26 16:30:00 -> 27 Nov 2026, 00:30 Asia/Manila (next day!), system actor, core group.
        $midnightEventId = $this->seedAuditEvent(
            '2026-11-26T16:30:00+00:00',
            'system.midnight_check',
            null,
            null,
            'registry',
            'REG-MIDNIGHT',
            ['summary' => 'Midnight boundary check', 'note' => 'Crossed into Nov 27 in Manila'],
        );

        // Event #30: newest event by Dante Vega.
        $this->seedAuditEvent(
            '2026-11-27T02:00:00+00:00',
            'user.created',
            $otherActor->id,
            $orgId,
            'user',
            'USR-999',
            ['summary' => 'Created account USR-999'],
        );

        $browser = $this->browser($sa->id);
        $svc = $browser['services']->superAdminAuditQueries();

        // Default sort (newest first) & pagination page 1.
        $page1 = $svc->list($sa, []);
        $this->assertSame(30, $page1['pagination']['total']);
        $this->assertSame(25, count($page1['items']));
        $this->assertSame(2, $page1['pagination']['pages']);
        $this->assertSame(1, $page1['pagination']['from']);
        $this->assertSame(25, $page1['pagination']['to']);
        $this->assertSame('user.created', $page1['items'][0]['action']);
        $this->assertSame('system.midnight_check', $page1['items'][1]['action']);

        // Page 2 has remaining 5 events.
        $page2 = $svc->list($sa, ['page' => '2']);
        $this->assertSame(5, count($page2['items']));
        $this->assertSame(26, $page2['pagination']['from']);
        $this->assertSame(30, $page2['pagination']['to']);

        // Ascending sort (oldest first).
        $asc = $svc->list($sa, ['sort' => 'time']);
        $this->assertSame('MEM-001', $asc['items'][0]['entity_id']);

        // HTML check: pagination summary, labelled buttons, aria-sort, and Manila time for 16:30 UTC event.
        $htmlResp = $this->get($browser, '/superadmin/audit');
        $this->assertSame(200, $htmlResp->status());
        $html = $htmlResp->body();
        $this->assertStringContainsString('Showing 1 to 25 of 30', $html);
        $this->assertStringContainsString('aria-label="Page 1"', $html);
        $this->assertStringContainsString('aria-label="Page 2"', $html);
        $this->assertStringContainsString('aria-sort="descending"', $html);
        $this->assertStringContainsString('27 Nov 2026, 00:30 Asia/Manila', $html);
        $this->assertStringContainsString('26 Nov 2026, 16:30 UTC', $html);
        $this->assertStringContainsString('System', $html);
        $this->assertStringContainsString('Core group', $html);
        $this->assertStringContainsString('src="/assets/superadmin/js/audit-drawer.js"', $html);

        // No-JS ?inspect= fallback opens the drawer server-side.
        $inspectResp = $this->get($browser, '/superadmin/audit', ['inspect' => $midnightEventId]);
        $this->assertSame(200, $inspectResp->status());
        $this->assertStringContainsString('id="sa-audit-drawer" data-sa-audit-drawer>', $inspectResp->body());
        $this->assertStringContainsString('Crossed into Nov 27 in Manila', $inspectResp->body());

        // Filter: Manila date range — 16:30 UTC Nov 26 belongs to Nov 27 Asia/Manila, NOT Nov 26.
        $nov27 = $svc->list($sa, ['from' => '2026-11-27', 'to' => '2026-11-27']);
        $this->assertSame(2, $nov27['pagination']['total']);
        $this->assertContains('system.midnight_check', array_column($nov27['items'], 'action'));

        $nov26 = $svc->list($sa, ['from' => '2026-11-26', 'to' => '2026-11-26']);
        $this->assertSame(0, $nov26['pagination']['total']);

        // Filter: organization = core, actor = system, action, entity, search by actor/entity_id/action.
        $coreOnly = $svc->list($sa, ['organization' => 'core']);
        $this->assertSame(1, $coreOnly['pagination']['total']);
        $this->assertSame('REG-MIDNIGHT', $coreOnly['items'][0]['entity_id']);

        $systemOnly = $svc->list($sa, ['actor' => 'system']);
        $this->assertSame(1, $systemOnly['pagination']['total']);

        $byActor = $svc->list($sa, ['actor' => $otherActor->id]);
        $this->assertSame(1, $byActor['pagination']['total']);
        $this->assertSame('Dante Vega', $byActor['items'][0]['actor_name']);

        $byAction = $svc->list($sa, ['action' => 'user.created']);
        $this->assertSame(1, $byAction['pagination']['total']);

        $byEntity = $svc->list($sa, ['entity' => 'registry']);
        $this->assertSame(1, $byEntity['pagination']['total']);

        $bySearchActor = $svc->list($sa, ['q' => 'Dante']);
        $this->assertSame(1, $bySearchActor['pagination']['total']);

        $bySearchEntityId = $svc->list($sa, ['q' => 'MEM-014']);
        $this->assertSame(1, $bySearchEntityId['pagination']['total']);

        $this->assertSame(0, $this->orgDbFactoryCalls);
    }

    // ------------------------------------------------------------------ 5. Output safety, redaction, SQL-safe search

    public function testOutputEscapingRedactionTruncationNoSecretsAndSqlSafeSearch(): void
    {
        $hostileActorName = 'Actor <script>alert("actor")</script>';
        $sa = $this->kit->staff('sa-safe@example.test', TestKit::PASSWORD, 'super_admin', 'active', $hostileActorName);
        $saHash = (string) $this->kit->userRow($sa)['password_hash'];

        $orgId = $this->seedOrganization(
            'xss-org',
            'Org <script>alert("org")</script>',
            '<b>XSS</b>',
            'active',
            7,
            'v1.0.0',
            ['care_notes'],
            ['primary' => '#2171b5'],
            'postgresql://leak_user:leak_pass@10.0.0.5:5432/leak_db',
        );

        $longNote = str_repeat('X', 320);
        $this->seedAuditEvent(
            '2026-11-26T16:30:00+00:00',
            'security.test',
            $sa->id,
            $orgId,
            'user',
            'ID-<script>alert("id")</script>',
            [
                'summary' => 'Checked <script>alert("sum")</script>',
                'note' => '<script>alert("detail")</script>',
                'long_text' => $longNote,
                'password' => 'PLAIN-PASSWORD-SECRET-1',
                'token' => 'RESET-TOKEN-SECRET-2',
                'password_hash' => '$2y$10$FAKEHASHSECRET3',
                'secret' => 'API-SECRET-4',
                'connection_string' => 'postgresql://audit_user:audit_pass@db.internal:5432/core',
                'nested' => [
                    'client_secret' => 'NESTED-SECRET-5',
                    'safe' => 'visible-safe-value',
                ],
            ],
        );

        $browser = $this->browser($sa->id);

        foreach (['/superadmin', '/superadmin/organizations', '/superadmin/organizations/xss-org', '/superadmin/audit'] as $path) {
            $resp = $this->get($browser, $path);
            $this->assertSame(200, $resp->status(), $path);
            $body = $resp->body();

            // No unescaped <script> tags anywhere.
            $this->assertStringNotContainsString('<script>alert(', $body, $path);

            // No secrets, hashes, tokens, or postgresql:// URIs anywhere.
            foreach ([
                $saHash,
                'connection_encrypted',
                'postgresql://',
                'leak_pass',
                'PLAIN-PASSWORD-SECRET-1',
                'RESET-TOKEN-SECRET-2',
                '$2y$10$FAKEHASHSECRET3',
                'API-SECRET-4',
                'NESTED-SECRET-5',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "$path leaked $forbidden");
            }
        }

        // Verify Audit Log escaped the safe fields, kept nested.safe, omitted redacted keys, and truncated long_text.
        $auditBody = $this->get($browser, '/superadmin/audit')->body();
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;detail&quot;)&lt;/script&gt;', $auditBody);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;actor&quot;)&lt;/script&gt;', $auditBody);
        $this->assertStringContainsString('visible-safe-value', $auditBody);
        $this->assertStringContainsString(str_repeat('X', 200) . '…', $auditBody);
        $this->assertStringNotContainsString($longNote, $auditBody);
        $this->assertStringNotContainsString('>password_hash<', $auditBody);
        $this->assertStringNotContainsString('>connection_string<', $auditBody);

        // Search with SQL metacharacters and literal % / _ is harmless and matches literally.
        $sqlInjection = "' OR '1'='1' -- ; DROP TABLE organizations; % _ \\";
        $orgSearch = $this->get($browser, '/superadmin/organizations', ['q' => $sqlInjection]);
        $this->assertSame(200, $orgSearch->status());
        $this->assertStringContainsString('No organizations match', $orgSearch->body());

        $auditSearch = $this->get($browser, '/superadmin/audit', ['q' => $sqlInjection]);
        $this->assertSame(200, $auditSearch->status());
        $this->assertStringContainsString('No audit events match', $auditSearch->body());

        // Literal percent sign must not act as a wildcard matching everything.
        $percentSearch = $this->get($browser, '/superadmin/organizations', ['q' => '%']);
        $this->assertSame(200, $percentSearch->status());
        $this->assertStringContainsString('No organizations match', $percentSearch->body());

        $auditPercent = $this->get($browser, '/superadmin/audit', ['q' => '%']);
        $this->assertSame(200, $auditPercent->status());
        $this->assertStringContainsString('No audit events match', $auditPercent->body());

        $this->assertSame(0, $this->orgDbFactoryCalls);
    }

    // ------------------------------------------------------------------ 6. Empty states & no org DB instantiated

    public function testEmptyStatesRenderCleanlyWithZeroOrganizationsAndZeroAuditEvents(): void
    {
        $sa = $this->kit->staff('sa-empty@example.test', TestKit::PASSWORD, 'super_admin', 'active', 'Empty Tester');
        $browser = $this->browser($sa->id);

        // Confirm test DB starts with 0 organizations and 0 audit events in this rolled-back transaction.
        $this->assertSame(0, (int) $this->core->query('SELECT COUNT(*) FROM organizations')->fetchColumn());
        $this->assertSame(0, (int) $this->core->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());

        $overview = $this->get($browser, '/superadmin');
        $this->assertSame(200, $overview->status());
        $this->assertStringContainsString('No organizations registered yet', $overview->body());
        $this->assertStringContainsString('No system activity recorded yet', $overview->body());

        $orgs = $this->get($browser, '/superadmin/organizations');
        $this->assertSame(200, $orgs->status());
        $this->assertStringContainsString('No organizations registered yet', $orgs->body());

        $audit = $this->get($browser, '/superadmin/audit');
        $this->assertSame(200, $audit->status());
        $this->assertStringContainsString('No audit events match', $audit->body());
        $this->assertStringContainsString('Showing 0 to 0 of 0', $audit->body());

        // Verify Services never instantiated tenants or database, and org factory was never called.
        $instances = (new \ReflectionProperty(Services::class, 'instances'))->getValue($browser['services']);
        $this->assertArrayNotHasKey('tenants', $instances);
        $this->assertArrayNotHasKey('database', $instances);
        $this->assertSame(0, $this->orgDbFactoryCalls);
    }
}

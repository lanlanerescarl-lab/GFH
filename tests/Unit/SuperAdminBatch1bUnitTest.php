<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\MembershipRepository;
use Gfh\Core\Repository\PermissionRepository;
use Gfh\Core\Repository\SuperAdminAuditRepository;
use Gfh\Core\Repository\SuperAdminOrganizationRepository;
use Gfh\Core\Service\PermissionService;
use Gfh\Core\Service\SuperAdminAuditQueryService;
use Gfh\Core\Service\SuperAdminOrganizationQueryService;
use Gfh\Core\Service\SuperAdminOverviewService;
use Gfh\Core\View\BrandingCss;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Batch SA.1b unit tests (no database, no SQLite — stubs only):
 * BrandingCss::validated(), audit detail redaction & truncation, Asia/Manila + UTC timestamp formatting,
 * and service-level permission enforcement when controllers are bypassed.
 */
final class SuperAdminBatch1bUnitTest extends TestCase
{
    private function pdoStub(): \PDO
    {
        return (new \ReflectionClass(\PDO::class))->newInstanceWithoutConstructor();
    }

    private function permissionServiceStub(): PermissionService
    {
        $pdo = $this->pdoStub();

        return new PermissionService(new MembershipRepository($pdo), new PermissionRepository($pdo));
    }

    public function testBrandingCssValidatedKeepsOnlySafeContrastCheckedKeys(): void
    {
        $validated = BrandingCss::validated([
            'primary' => '#2171B5',
            'primary_strong' => '#08519c',
            'background' => '#ffffff',
            'ink' => '#1a2a3a',
            'font_body' => 'Inter',
            'font_heading' => 'Source Serif 4',
            'radius_card' => 12,
            'radius_control' => '8px',
            // Invalid / ignored keys:
            'evil' => 'url(https://evil.example/x)',
            'connection_encrypted' => 'secret',
        ]);

        $this->assertSame([
            'primary' => '#2171b5',
            'primary_strong' => '#08519c',
            'background' => '#ffffff',
            'ink' => '#1a2a3a',
            'font_body' => 'Inter',
            'font_heading' => 'Source Serif 4',
            'radius_card' => '12px',
            'radius_control' => '8px',
        ], $validated);

        // Low-contrast primary color and malformed radius/font are dropped.
        $filtered = BrandingCss::validated([
            'primary' => '#ffff00',
            'font_body' => 'Inter; } body { display:none',
            'radius_card' => 999,
        ]);
        $this->assertSame([], $filtered);
    }

    /** @return array<string, array{string}> */
    public static function redactedKeys(): array
    {
        return [
            'password' => ['password'],
            'token' => ['token'],
            'password_hash' => ['password_hash'],
            'secret' => ['secret'],
            'connection_string' => ['connection_string'],
            'mixed case password' => ['UserPassword'],
            'api token' => ['api_TOKEN_value'],
            'hash suffix' => ['reset_hash'],
            'client secret' => ['clientSecret'],
            'db connection' => ['db_Connection_url'],
        ];
    }

    #[DataProvider('redactedKeys')]
    public function testAuditDetailRedactionOmitsSensitiveKeysCaseInsensitively(string $sensitiveKey): void
    {
        $pairs = SuperAdminAuditQueryService::redactAndFlattenDetail([
            'summary' => 'Safe summary',
            $sensitiveKey => 'SENSITIVE-VALUE-DO-NOT-SHOW',
            'nested' => [
                $sensitiveKey => 'NESTED-SENSITIVE-VALUE',
                'safe_child' => 'ok',
            ],
            'list_with_object' => [
                ['label' => 'keep', $sensitiveKey => 'LIST-SENSITIVE'],
            ],
        ]);

        $keys = array_column($pairs, 'key');
        $values = array_column($pairs, 'value');

        $this->assertContains('summary', $keys);
        $this->assertContains('nested.safe_child', $keys);
        foreach ($keys as $key) {
            $this->assertFalse(SuperAdminAuditQueryService::isRedactedKey($key), 'Redacted key leaked: ' . $key);
        }
        $joined = implode("\n", $values);
        $this->assertStringNotContainsString('SENSITIVE-VALUE-DO-NOT-SHOW', $joined);
        $this->assertStringNotContainsString('NESTED-SENSITIVE-VALUE', $joined);
        $this->assertStringNotContainsString('LIST-SENSITIVE', $joined);
    }

    public function testAuditDetailTruncatesLongValues(): void
    {
        $long = str_repeat('A', 350);
        $pairs = SuperAdminAuditQueryService::redactAndFlattenDetail(['note' => $long]);

        $this->assertCount(1, $pairs);
        $this->assertSame('note', $pairs[0]['key']);
        $this->assertSame(SuperAdminAuditQueryService::MAX_VALUE_LENGTH + 1, mb_strlen($pairs[0]['value'], 'UTF-8'));
        $this->assertStringEndsWith('…', $pairs[0]['value']);
        $this->assertStringNotContainsString($long, $pairs[0]['value']);
    }

    public function testUtcTimestampAt1630FormatsAs0030NextDayInAsiaManilaWithZoneLabel(): void
    {
        [$manila, $utc] = SuperAdminAuditQueryService::formatTimestamps('2026-11-26T16:30:00+00:00');

        $this->assertSame('27 Nov 2026, 00:30 Asia/Manila', $manila);
        $this->assertSame('26 Nov 2026, 16:30 UTC', $utc);
    }

    public function testAllThreeServicesRefuseNonGroupOrInactiveActorsEvenWhenControllersAreBypassed(): void
    {
        $pdo = $this->pdoStub();
        $permissions = $this->permissionServiceStub();

        $orgRepo = new SuperAdminOrganizationRepository($pdo);
        $auditRepo = new SuperAdminAuditRepository($pdo);

        $orgService = new SuperAdminOrganizationQueryService($orgRepo, $permissions);
        $overviewService = new SuperAdminOverviewService($orgRepo, $auditRepo, $permissions);
        $auditService = new SuperAdminAuditQueryService($auditRepo, $permissions);

        $blockedActors = [
            new StaffUser('00000000-0000-4000-8000-000000000010', 'Org Admin', 'org@example.test', null, 'active'),
            new StaffUser('00000000-0000-4000-8000-000000000011', 'Inactive SA', 'off@example.test', 'super_admin', 'inactive'),
            new StaffUser('00000000-0000-4000-8000-000000000012', 'Pending GV', 'pend@example.test', 'group_viewer', 'pending'),
        ];

        foreach ($blockedActors as $actor) {
            foreach ([
                fn () => $overviewService->overview($actor),
                fn () => $orgService->list($actor),
                fn () => $orgService->detail($actor, 'demo'),
                fn () => $auditService->list($actor, []),
            ] as $call) {
                try {
                    $call();
                    $this->fail('Expected 403 for ' . $actor->email);
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->status());
                }
            }
        }
    }
}

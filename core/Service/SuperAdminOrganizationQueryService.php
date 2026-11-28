<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Config\Config;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\SuperAdminOrganizationRepository;
use Gfh\Core\View\BrandingCss;

/**
 * Read-only Super Admin queries for the organization registry (SA.1b).
 * Enforces PermissionService::can($actor, null, 'organizations', 'view') at the service level,
 * never opens an organization database, and never reads or exposes connection_encrypted.
 */
final class SuperAdminOrganizationQueryService
{
    public const VALID_STATUSES = ['active', 'suspended', 'archived'];

    private const BRANDING_LABELS = [
        'primary' => 'Primary color',
        'primary_strong' => 'Strong primary color',
        'background' => 'Page background',
        'ink' => 'Body text color',
        'font_body' => 'Body font',
        'font_heading' => 'Heading font',
        'radius_card' => 'Card corner radius',
        'radius_control' => 'Control corner radius',
    ];

    private const BRANDING_COLORS = ['primary', 'primary_strong', 'background', 'ink'];

    public function __construct(
        private readonly SuperAdminOrganizationRepository $organizations,
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * Lists organizations with search and status filtering for /superadmin/organizations.
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     counts: array{total: int, active: int, suspended: int, archived: int},
     *     has_any: bool,
     *     filters: array{q: string, status: string},
     * }
     * @throws HttpException 403 when the actor lacks organizations:view
     */
    public function list(StaffUser $actor, ?string $search = null, ?string $status = null): array
    {
        $this->permissions->assert($actor, null, 'organizations', 'view');

        $cleanSearch = self::cleanSearch($search);
        $cleanStatus = self::cleanStatus($status);

        $counts = $this->organizations->countByStatus();
        $rows = $this->organizations->list($cleanSearch, $cleanStatus);

        return [
            'items' => array_map(static fn (array $row): array => self::presentListRow($row), $rows),
            'counts' => $counts,
            'has_any' => $counts['total'] > 0,
            'filters' => [
                'q' => $cleanSearch ?? '',
                'status' => $cleanStatus ?? '',
            ],
        ];
    }

    /**
     * Loads one organization's general registry detail with enabled feature keys and validated branding.
     *
     * @return array<string, mixed>
     * @throws HttpException 403 when unauthorized, 404 when the slug does not exist
     */
    public function detail(StaffUser $actor, string $slug): array
    {
        $this->permissions->assert($actor, null, 'organizations', 'view');

        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug) !== 1) {
            throw HttpException::notFound('Organization not found.');
        }

        $row = $this->organizations->findBySlug($slug);
        if ($row === null) {
            throw HttpException::notFound('Organization not found.');
        }

        return self::presentDetail($row);
    }

    /**
     * Shared presentation helper so Overview and Organizations list format rows consistently.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentListRow(array $row): array
    {
        $status = (string) ($row['status'] ?? 'active');
        $migrationVersion = (int) ($row['migration_version'] ?? 0);
        $templateVersion = isset($row['template_version']) && trim((string) $row['template_version']) !== ''
            ? trim((string) $row['template_version'])
            : null;

        /** @var list<string> $features */
        $features = is_array($row['features'] ?? null) ? array_values(array_map('strval', $row['features'])) : [];

        return [
            'id' => (string) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'short_name' => (string) $row['short_name'],
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'status_tone' => self::statusTone($status),
            'migration_version' => $migrationVersion,
            'migration_label' => 'v' . $migrationVersion,
            'template_version' => $templateVersion,
            'features' => $features,
            'freshness_label' => 'Not connected',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentDetail(array $row): array
    {
        $base = self::presentListRow($row);
        $rawBranding = self::decodeJsonObject($row['branding'] ?? null);
        $validatedBranding = BrandingCss::validated($rawBranding);

        $brandingItems = [];
        foreach ($validatedBranding as $key => $value) {
            $brandingItems[] = [
                'key' => $key,
                'label' => self::BRANDING_LABELS[$key] ?? $key,
                'kind' => in_array($key, self::BRANDING_COLORS, true)
                    ? 'color'
                    : (str_starts_with($key, 'font_') ? 'font' : 'radius'),
                'value' => $value,
            ];
        }

        return $base + [
            'created_at_manila' => self::formatManila($row['created_at'] ?? null),
            'updated_at_manila' => self::formatManila($row['updated_at'] ?? null),
            'branding' => $brandingItems,
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'suspended' => 'Suspended',
            'archived' => 'Archived',
            default => ucfirst($status),
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'active' => 'ok',
            'suspended' => 'warn',
            'archived' => 'neutral',
            default => 'neutral',
        };
    }

    private static function formatManila(mixed $timestamp): string
    {
        if (!is_string($timestamp) || trim($timestamp) === '') {
            return '—';
        }

        try {
            $dt = (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone(Config::TIMEZONE));

            return $dt->format('d M Y, H:i') . ' ' . Config::TIMEZONE;
        } catch (\Throwable) {
            return '—';
        }
    }

    /** @return array<string, mixed> */
    private static function decodeJsonObject(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function cleanSearch(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }
        $trimmed = trim($search);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 100, 'UTF-8');
    }

    private static function cleanStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }
        $normalized = strtolower(trim($status));

        return in_array($normalized, self::VALID_STATUSES, true) ? $normalized : null;
    }
}

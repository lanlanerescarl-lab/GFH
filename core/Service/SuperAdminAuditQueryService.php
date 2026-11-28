<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\SuperAdminAuditRepository;

/**
 * Read-only Super Admin audit log query service (SA.1b).
 *
 * Enforces PermissionService::can($actor, null, 'audit_log', 'view'), filters/paginates at 25 rows per page,
 * formats timestamps in Asia/Manila (with UTC alongside for the Inspect drawer), and redacts sensitive detail keys
 * matching password, token, hash, secret, or connection (case-insensitive) while truncating long values.
 */
final class SuperAdminAuditQueryService
{
    public const PER_PAGE = 25;
    public const MAX_VALUE_LENGTH = 200;
    public const MAX_SUMMARY_LENGTH = 160;
    public const REDACTED_KEY_PATTERN = '/password|token|hash|secret|connection/i';

    public function __construct(
        private readonly SuperAdminAuditRepository $audit,
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * Queries the core audit log for /superadmin/audit.
     *
     * @param array<string, mixed> $query raw query parameters (q, from, to, organization, actor, action, entity, sort, page, inspect)
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, pages: int, from: int, to: int},
     *     sort: string,
     *     filters: array{q: string, from: string, to: string, organization: string, actor: string, action: string, entity: string},
     *     options: array{
     *         organizations: list<array{id: string, name: string}>,
     *         actors: list<array{id: string, name: string}>,
     *         actions: list<string>,
     *         entities: list<string>,
     *     },
     *     errors: array<string, string>,
     *     inspected: array<string, mixed>|null,
     *     has_active_filters: bool,
     * }
     * @throws HttpException 403 when the actor lacks audit_log:view
     */
    public function list(StaffUser $actor, array $query = []): array
    {
        $this->permissions->assert($actor, null, 'audit_log', 'view');

        $errors = [];
        $q = self::cleanText($query['q'] ?? null, 100);
        $action = self::cleanText($query['action'] ?? null, 120);
        $entity = self::cleanText($query['entity'] ?? null, 120);

        $orgRaw = self::cleanText($query['organization'] ?? null, 64);
        $organization = '';
        if ($orgRaw === 'core' || self::isUuid($orgRaw)) {
            $organization = $orgRaw;
        }

        $actorRaw = self::cleanText($query['actor'] ?? null, 64);
        $actorFilter = '';
        if ($actorRaw === 'system' || self::isUuid($actorRaw)) {
            $actorFilter = $actorRaw;
        }

        $fromRaw = self::cleanText($query['from'] ?? null, 20);
        $toRaw = self::cleanText($query['to'] ?? null, 20);

        $fromDate = self::parseManilaDate($fromRaw);
        if ($fromRaw !== '' && $fromDate === null) {
            $errors['from'] = 'Use a valid date (YYYY-MM-DD).';
        }

        $toDate = self::parseManilaDate($toRaw);
        if ($toRaw !== '' && $toDate === null) {
            $errors['to'] = 'Use a valid date (YYYY-MM-DD).';
        }

        if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
            $errors['to'] = 'End date must be on or after the start date.';
            $toDate = null;
        }

        $utc = new \DateTimeZone('UTC');
        $fromUtc = $fromDate?->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $toUtc = $toDate?->modify('+1 day')->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');

        $sortRaw = is_string($query['sort'] ?? null) ? trim($query['sort']) : '';
        $sort = $sortRaw === 'time' ? 'time' : '-time';
        $direction = $sort === 'time' ? 'asc' : 'desc';

        $page = self::parsePage($query['page'] ?? 1);

        $repoFilters = [
            'q' => $q !== '' ? $q : null,
            'organization' => $organization !== '' ? $organization : null,
            'actor' => $actorFilter !== '' ? $actorFilter : null,
            'action' => $action !== '' ? $action : null,
            'entity' => $entity !== '' ? $entity : null,
            'from_utc' => $fromUtc,
            'to_utc' => $toUtc,
        ];

        $result = $this->audit->query($repoFilters, $direction, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $total = $result['total'];
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        if ($page > $pages && $total > 0) {
            $page = $pages;
            $result = $this->audit->query($repoFilters, $direction, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        }

        $items = array_map(static fn (array $row): array => self::presentEvent($row), $result['rows']);

        $fromRow = $total === 0 ? 0 : (($page - 1) * self::PER_PAGE) + 1;
        $toRow = $total === 0 ? 0 : min($total, $fromRow + count($items) - 1);

        // Optional no-JS inspect drawer fallback via ?inspect={uuid}.
        $inspected = null;
        $inspectId = self::cleanText($query['inspect'] ?? null, 64);
        if (self::isUuid($inspectId)) {
            foreach ($items as $item) {
                if ($item['id'] === $inspectId) {
                    $inspected = $item;
                    break;
                }
            }
            if ($inspected === null) {
                $found = $this->audit->findById($inspectId);
                if ($found !== null) {
                    $inspected = self::presentEvent($found);
                }
            }
        }

        $filtersOut = [
            'q' => $q,
            'from' => $fromRaw,
            'to' => $toRaw,
            'organization' => $organization,
            'actor' => $actorFilter,
            'action' => $action,
            'entity' => $entity,
        ];

        $hasActiveFilters = $q !== ''
            || $fromRaw !== ''
            || $toRaw !== ''
            || $organization !== ''
            || $actorFilter !== ''
            || $action !== ''
            || $entity !== '';

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'pages' => $pages,
                'from' => $fromRow,
                'to' => $toRow,
            ],
            'sort' => $sort,
            'filters' => $filtersOut,
            'options' => $this->audit->filterOptions(),
            'errors' => $errors,
            'inspected' => $inspected,
            'has_active_filters' => $hasActiveFilters,
        ];
    }

    /**
     * Formats one core audit_log row with Manila/UTC timestamps, safe actor/organization names,
     * redacted detail key/value pairs, and a safe summary.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function presentEvent(array $row): array
    {
        $detail = self::decodeDetail($row['detail'] ?? null);
        $detailPairs = self::redactAndFlattenDetail($detail);

        $actorName = isset($row['actor_name']) && trim((string) $row['actor_name']) !== ''
            ? (string) $row['actor_name']
            : 'System';

        $organizationName = isset($row['organization_name']) && trim((string) $row['organization_name']) !== ''
            ? (string) $row['organization_name']
            : 'Core group';

        $action = (string) ($row['action'] ?? '');
        $entity = isset($row['entity']) && trim((string) $row['entity']) !== '' ? (string) $row['entity'] : '—';
        $entityId = isset($row['entity_id']) && trim((string) $row['entity_id']) !== '' ? (string) $row['entity_id'] : '—';

        [$manilaTime, $utcTime] = self::formatTimestamps($row['occurred_at'] ?? null);

        return [
            'id' => (string) ($row['id'] ?? ''),
            'occurred_at_manila' => $manilaTime,
            'occurred_at_utc' => $utcTime,
            'actor_name' => $actorName,
            'organization_name' => $organizationName,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'summary' => self::buildSummary($action, $entity, $entityId, $detailPairs),
            'detail_pairs' => $detailPairs,
        ];
    }

    /**
     * Removes any key matching password, token, hash, secret, or connection (case-insensitive)
     * recursively and truncates long values.
     *
     * @param array<mixed> $detail
     * @return list<array{key: string, value: string}>
     */
    public static function redactAndFlattenDetail(array $detail, string $prefix = ''): array
    {
        $pairs = [];

        foreach ($detail as $rawKey => $value) {
            $key = (string) $rawKey;
            if (self::isRedactedKey($key)) {
                continue;
            }

            $fullKey = $prefix === '' ? $key : $prefix . '.' . $key;
            if (self::isRedactedKey($fullKey)) {
                continue;
            }

            if (is_array($value)) {
                if ($value === []) {
                    $pairs[] = ['key' => $fullKey, 'value' => '—'];
                    continue;
                }

                if (!array_is_list($value)) {
                    foreach (self::redactAndFlattenDetail($value, $fullKey) as $nested) {
                        $pairs[] = $nested;
                    }
                    continue;
                }

                $sanitizedList = self::stripRedactedFromNested($value);
                $formatted = self::formatListValue($sanitizedList);
                $pairs[] = ['key' => $fullKey, 'value' => self::truncate($formatted, self::MAX_VALUE_LENGTH)];
                continue;
            }

            $pairs[] = [
                'key' => $fullKey,
                'value' => self::truncate(self::scalarToString($value), self::MAX_VALUE_LENGTH),
            ];
        }

        return $pairs;
    }

    public static function isRedactedKey(string $key): bool
    {
        return preg_match(self::REDACTED_KEY_PATTERN, $key) === 1;
    }

    /** @return array{0: string, 1: string} [manila, utc] */
    public static function formatTimestamps(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return ['—', '—'];
        }

        try {
            $dt = new \DateTimeImmutable($raw);
            $manila = $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format('d M Y, H:i') . ' Asia/Manila';
            $utc = $dt->setTimezone(new \DateTimeZone('UTC'))->format('d M Y, H:i') . ' UTC';

            return [$manila, $utc];
        } catch (\Throwable) {
            return ['—', '—'];
        }
    }

    /**
     * @param list<array{key: string, value: string}> $detailPairs
     */
    private static function buildSummary(string $action, string $entity, string $entityId, array $detailPairs): string
    {
        foreach ($detailPairs as $pair) {
            if ($pair['key'] === 'summary' && $pair['value'] !== '' && $pair['value'] !== '—') {
                return self::truncate($pair['value'], self::MAX_SUMMARY_LENGTH);
            }
        }

        $parts = [];
        if ($action !== '') {
            $parts[] = $action;
        }
        if ($entity !== '—') {
            $parts[] = $entityId !== '—' ? $entity . ' ' . $entityId : $entity;
        }

        return self::truncate(implode(' · ', $parts) ?: 'Audit event', self::MAX_SUMMARY_LENGTH);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function stripRedactedFromNested(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isRedactedKey($key)) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::stripRedactedFromNested($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return array_is_list($data) ? array_values($clean) : $clean;
    }

    /** @param list<mixed> $list */
    private static function formatListValue(array $list): string
    {
        $allScalar = true;
        foreach ($list as $item) {
            if (is_array($item)) {
                $allScalar = false;
                break;
            }
        }

        if ($allScalar) {
            return implode(', ', array_map(static fn (mixed $item): string => self::scalarToString($item), $list));
        }

        $encoded = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    private static function scalarToString(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        return '';
    }

    public static function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value, 'UTF-8') <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength, 'UTF-8') . '…';
    }

    /** @return array<mixed> */
    private static function decodeDetail(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function cleanText(mixed $value, int $maxLength): string
    {
        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim($value), 0, $maxLength, 'UTF-8');
    }

    private static function parseManilaDate(string $value): ?\DateTimeImmutable
    {
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $zone = new \DateTimeZone('Asia/Manila');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }

    private static function parsePage(mixed $value): int
    {
        if (is_int($value) && $value >= 1) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,6}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return 1;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}

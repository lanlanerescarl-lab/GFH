<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Http\HttpException;
use Gfh\Core\Repository\SettingsRepository;
use Gfh\Core\Support\SettingLabels;

/**
 * Organization settings. Core keys have defaults and ranges; extensions add their own keys with defaults
 * through define() (Stage 4 wires that to the extension manifest). Unknown keys are refused.
 */
final class SettingsService
{
    /** Core keys: default value and the allowed whole-number range. */
    private const CORE = [
        'deposit_percent' => ['default' => 0, 'min' => 0, 'max' => 100],
        'reservation_hours' => ['default' => 48, 'min' => 1, 'max' => 720],
        'near_expiry_days' => ['default' => 7, 'min' => 0, 'max' => 365],
    ];

    /** @var array<string, array{default: mixed, validator: ?callable, meta: array<string, mixed>}> */
    private array $extra = [];
    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    public function __construct(private readonly SettingsRepository $repository, private readonly AuditService $audit, private readonly \PDO $pdo)
    {
    }

    /**
     * Declares an extension's setting with its default. The validator returns an error message, or null when the value
     * is fine. $meta (label, type, min, max, extension, extension_key) is only what describe() shows; it changes no rule.
     *
     * @param array<string, mixed> $meta
     */
    public function define(string $key, mixed $default, ?callable $validator = null, array $meta = []): void
    {
        if (isset(self::CORE[$key]) || preg_match('/^[a-z][a-z0-9_.]{2,60}$/', $key) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" cannot be used as an extension setting key.', $key));
        }

        $this->extra[$key] = ['default' => $default, 'validator' => $validator, 'meta' => $meta];
    }

    public function get(string $key): mixed
    {
        $all = $this->all();
        if (!array_key_exists($key, $all)) {
            throw new \InvalidArgumentException(sprintf('Unknown setting "%s".', $key));
        }

        return $all[$key];
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /** @return array<string, mixed> every known setting with its stored value or default */
    public function all(): array
    {
        $this->stored ??= $this->repository->all();

        $result = [];
        foreach (self::CORE as $key => $definition) {
            $result[$key] = $this->stored[$key] ?? $definition['default'];
        }
        foreach ($this->extra as $key => $definition) {
            $result[$key] = $this->stored[$key] ?? $definition['default'];
        }

        return $result;
    }

    /**
     * Every known setting described for the Settings page: key, label, type (int, bool or string), current value,
     * default, the whole-number range (null when it has none) and where it comes from (null for core, otherwise the
     * extension's name). Core settings first, then extension settings in the order they were declared.
     *
     * @return list<array{key: string, label: string, help: string, type: string, value: mixed, default: mixed, min: ?int, max: ?int, source: ?string}>
     */
    public function describe(): array
    {
        $values = $this->all();
        $rows = [];
        foreach (self::CORE as $key => $definition) {
            $rows[] = [
                'key' => $key,
                'label' => SettingLabels::label($key),
                'help' => SettingLabels::help($key),
                'type' => 'int',
                'value' => $values[$key],
                'default' => $definition['default'],
                'min' => $definition['min'],
                'max' => $definition['max'],
                'source' => null,
            ];
        }
        foreach ($this->extra as $key => $definition) {
            $meta = $definition['meta'];
            $default = $definition['default'];
            $rows[] = [
                'key' => $key,
                'label' => (string) ($meta['label'] ?? $key),
                'help' => '',
                'type' => (string) ($meta['type'] ?? (is_int($default) ? 'int' : (is_bool($default) ? 'bool' : 'string'))),
                'value' => $values[$key],
                'default' => $default,
                'min' => isset($meta['min']) ? (int) $meta['min'] : null,
                'max' => isset($meta['max']) ? (int) $meta['max'] : null,
                'source' => (string) ($meta['extension'] ?? $meta['extension_key'] ?? 'an extension'),
            ];
        }

        return $rows;
    }

    /** @throws HttpException 422 for an unknown key or an out-of-range value */
    public function set(string $key, mixed $value, ?string $actorUserId): void
    {
        if (isset(self::CORE[$key])) {
            $rule = self::CORE[$key];
            $number = is_int($value) ? $value : (is_string($value) && preg_match('/^\d{1,6}$/', $value) === 1 ? (int) $value : null);
            if ($number === null || $number < $rule['min'] || $number > $rule['max']) {
                throw HttpException::unprocessable([$key => sprintf('Enter a whole number from %d to %d.', $rule['min'], $rule['max'])]);
            }
            $value = $number;
        } elseif (isset($this->extra[$key])) {
            $validator = $this->extra[$key]['validator'];
            $message = $validator === null ? null : $validator($value);
            if ($message !== null) {
                throw HttpException::unprocessable([$key => (string) $message]);
            }
            // A whole-number setting stays a whole number: the validator accepts digits sent as text, but they are stored as a number.
            if (is_int($this->extra[$key]['default']) && is_string($value)) {
                $value = (int) $value;
            }
        } else {
            throw HttpException::unprocessable(['key' => sprintf('"%s" is not a setting.', $key)]);
        }

        $this->repository->set($key, $value, $actorUserId);
        $this->stored = null;
        $this->audit->organization($this->pdo, 'settings.changed', $actorUserId, 'setting', $key, ['value' => $value]);
    }
}

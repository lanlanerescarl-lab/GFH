<?php

declare(strict_types=1);

namespace Gfh\Core\Service;

use Gfh\Core\Hooks\Hooks;

/**
 * Extension checkout fields (Batch 8.3, hook checkout.fields). Two jobs, both pure rules with no database:
 *
 *  1. definitions(): reads the hook and keeps only well-formed field definitions. A bad one is ignored and logged
 *     (Hooks::problem), never an error for the customer.
 *  2. validate(): checks the values a customer posted against those definitions, on the server, whatever the browser did.
 *
 * The shape of a definition is documented in docs/extensions.md ("Checkout fields").
 */
final class CheckoutFieldService
{
    public const TYPES = ['text', 'textarea', 'select', 'checkbox'];
    public const MAX_FIELDS = 10;
    public const MAX_OPTIONS = 50;
    public const LABEL_LIMIT = 80;
    public const HELP_LIMIT = 200;
    public const OPTION_VALUE_LIMIT = 100;
    public const MAX_LENGTH_CEILING = 500;
    public const DEFAULT_MAX_LENGTH = ['text' => 150, 'textarea' => 500];
    public const ID_PATTERN = '/^[a-z0-9_]{1,40}$/D';

    public function __construct(private readonly Hooks $hooks)
    {
    }

    /**
     * The usable field definitions, in the order the extensions gave them.
     *
     * @return list<array{id: string, label: string, type: string, required: bool, options: list<array{value: string, label: string}>, help: string, max_length: ?int}>
     */
    public function definitions(): array
    {
        $raw = $this->hooks->filter(Hooks::CHECKOUT_FIELDS, [], []);

        return self::normalize(is_array($raw) ? $raw : [], function (string $reason, string $key): void {
            $this->hooks->problem(Hooks::CHECKOUT_FIELDS, $reason, $key);
        });
    }

    /**
     * Keeps the well-formed definitions. $problem receives a short reason and a key for each one that is dropped.
     *
     * @param array<mixed>                      $raw
     * @param (callable(string, string): void)|null $problem
     * @return list<array{id: string, label: string, type: string, required: bool, options: list<array{value: string, label: string}>, help: string, max_length: ?int}>
     */
    public static function normalize(array $raw, ?callable $problem = null): array
    {
        $report = static function (string $reason, string $key) use ($problem): void {
            if ($problem !== null) {
                $problem($reason, $key);
            }
        };

        $fields = [];
        $seen = [];
        foreach ($raw as $position => $item) {
            $where = 'definition ' . (is_int($position) ? $position : 'x');
            if (!is_array($item)) {
                $report($where . ' ignored: it is not an object', $where);
                continue;
            }
            if (count($fields) >= self::MAX_FIELDS) {
                $report(sprintf('%s ignored: at most %d fields are shown', $where, self::MAX_FIELDS), 'too-many');
                continue;
            }

            $id = $item['id'] ?? null;
            if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
                $report($where . ' ignored: id must be 1 to 40 lower-case letters, digits or underscores', $where);
                continue;
            }
            $where = 'field "' . $id . '"';
            if (isset($seen[$id])) {
                $report($where . ' ignored: the id is already used by an earlier field', 'dup:' . $id);
                continue;
            }

            $label = self::plain($item['label'] ?? null, self::LABEL_LIMIT, false);
            if ($label === null || $label === '') {
                $report($where . ' ignored: label must be plain text of 1 to ' . self::LABEL_LIMIT . ' characters', $id);
                continue;
            }
            $type = $item['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                $report($where . ' ignored: type must be text, textarea, select or checkbox', $id);
                continue;
            }
            if (isset($item['required']) && !is_bool($item['required'])) {
                $report($where . ' ignored: required must be true or false', $id);
                continue;
            }
            $help = '';
            if (isset($item['help']) && $item['help'] !== '') {
                $help = self::plain($item['help'], self::HELP_LIMIT, false);
                if ($help === null) {
                    $report($where . ' ignored: help must be plain text of up to ' . self::HELP_LIMIT . ' characters', $id);
                    continue;
                }
            }

            $options = [];
            if ($type === 'select') {
                $options = self::options($item['options'] ?? null);
                if ($options === null) {
                    $report($where . ' ignored: a select needs 1 to ' . self::MAX_OPTIONS . ' options, each {value, label} with a unique plain-text value', $id);
                    continue;
                }
            }

            $maxLength = null;
            if ($type === 'text' || $type === 'textarea') {
                $maxLength = self::DEFAULT_MAX_LENGTH[$type];
                if (isset($item['max_length'])) {
                    if (!is_int($item['max_length']) || $item['max_length'] < 1 || $item['max_length'] > self::MAX_LENGTH_CEILING) {
                        $report($where . ' ignored: max_length must be a whole number from 1 to ' . self::MAX_LENGTH_CEILING, $id);
                        continue;
                    }
                    $maxLength = $item['max_length'];
                }
            }

            $seen[$id] = true;
            $fields[] = [
                'id' => $id,
                'label' => $label,
                'type' => $type,
                'required' => ($item['required'] ?? false) === true,
                'options' => $options,
                'help' => $help,
                'max_length' => $maxLength,
            ];
        }

        return $fields;
    }

    /**
     * Checks the posted `checkout` object against the definitions.
     *
     * Every declared id is in `values` (blank text, an empty choice or false when nothing valid was posted), so a hook
     * never sees a value that failed a rule. `errors` holds one message per problem, keyed `checkout.{id}` (or `checkout`
     * for a key that is not a valid id, without echoing it).
     *
     * @param list<array{id: string, label: string, type: string, required: bool, options: list<array{value: string, label: string}>, help: string, max_length: ?int}> $definitions
     * @param array<mixed> $posted
     * @param bool $checkRequired false skips the "required" rule (an order a member of staff places on a customer's behalf)
     * @return array{values: array<string, string|bool>, errors: array<string, string>}
     */
    public static function validate(array $definitions, array $posted, bool $checkRequired = true): array
    {
        $values = [];
        $errors = [];
        $known = [];
        foreach ($definitions as $definition) {
            $known[$definition['id']] = true;
        }

        foreach ($posted as $key => $_) {
            if (!is_string($key) || !isset($known[$key])) {
                $name = is_string($key) && preg_match(self::ID_PATTERN, $key) === 1 ? 'checkout.' . $key : 'checkout';
                $errors[$name] = 'This field is not part of the checkout.';
            }
        }

        foreach ($definitions as $definition) {
            $id = $definition['id'];
            $label = $definition['label'];
            $present = array_key_exists($id, $posted);
            $value = $present ? $posted[$id] : null;

            if ($definition['type'] === 'checkbox') {
                $ticked = self::ticked($value);
                if ($ticked === null) {
                    $errors['checkout.' . $id] = $label . ' must be ticked or left empty.';
                    $ticked = false;
                } elseif ($definition['required'] && $checkRequired && !$ticked) {
                    $errors['checkout.' . $id] = $label . ' must be ticked.';
                }
                $values[$id] = $ticked;
                continue;
            }

            $values[$id] = '';
            if ($value === null) {
                $text = '';
            } elseif (is_string($value)) {
                $text = $definition['type'] === 'textarea' ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : trim($value);
            } else {
                $errors['checkout.' . $id] = $label . ' must be plain text.';
                continue;
            }

            if ($text === '') {
                if ($definition['required'] && $checkRequired) {
                    $errors['checkout.' . $id] = $label . ' is required.';
                }
                continue;
            }
            if (!self::isPlainText($text, $definition['type'] === 'textarea')) {
                $errors['checkout.' . $id] = $label . ' must be plain text: no tags and no control characters.';
                continue;
            }
            if ($definition['type'] === 'select') {
                $allowed = array_column($definition['options'], 'value');
                if (!in_array($text, $allowed, true)) {
                    $errors['checkout.' . $id] = 'Choose one of the listed options for ' . $label . '.';
                    continue;
                }
            } elseif (mb_strlen($text) > (int) $definition['max_length']) {
                $errors['checkout.' . $id] = sprintf('%s must be at most %d characters.', $label, (int) $definition['max_length']);
                continue;
            }

            $values[$id] = $text;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** True, false, or null when the value is not a checkbox value. */
    private static function ticked(mixed $value): ?bool
    {
        if ($value === null || $value === false || $value === '' || $value === '0' || $value === 'false') {
            return false;
        }
        if ($value === true || $value === '1' || $value === 'true' || $value === 'on') {
            return true;
        }

        return null;
    }

    /** Valid UTF-8, no control characters (a textarea may hold line breaks and tabs), and nothing that starts a tag. */
    public static function isPlainText(string $text, bool $multiline = false): bool
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return false;
        }
        $control = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        if (preg_match($control, $text) === 1) {
            return false;
        }

        return preg_match('/<[a-z\/!?]/i', $text) !== 1;
    }

    /** Plain text of 1 to $limit characters (trimmed), or null. */
    private static function plain(mixed $value, int $limit, bool $multiline): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return mb_strlen($value) <= $limit && self::isPlainText($value, $multiline) ? $value : null;
    }

    /** @return list<array{value: string, label: string}>|null null when the options are not usable */
    private static function options(mixed $raw): ?array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > self::MAX_OPTIONS) {
            return null;
        }
        $options = [];
        $seen = [];
        foreach ($raw as $option) {
            if (!is_array($option)) {
                return null;
            }
            $value = self::plain($option['value'] ?? null, self::OPTION_VALUE_LIMIT, false);
            $label = self::plain($option['label'] ?? null, self::LABEL_LIMIT, false);
            if ($value === null || $value === '' || $label === null || $label === '' || isset($seen[$value])) {
                return null;
            }
            $seen[$value] = true;
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}

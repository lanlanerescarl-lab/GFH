<?php

declare(strict_types=1);

namespace Gfh\Core\Extension;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\View\AssetUrls;

/**
 * The validated content of an extension's extension.json (the format is described in docs/extensions.md).
 * Parsing is strict on purpose: an unknown field, a wrong type or an unknown hook name stops that one
 * extension with a clear message, instead of being ignored and misbehaving later.
 */
final class ExtensionManifest
{
    public const FILE = 'extension.json';
    public const KEY_PATTERN = '/^[a-z][a-z0-9-]{1,40}$/D';

    private const FIELDS = ['key', 'name', 'version', 'description', 'entry', 'hooks', 'settings', 'namespace'];
    private const SETTING_FIELDS = ['default', 'type', 'min', 'max', 'label'];
    private const SETTING_TYPES = ['int', 'string', 'bool'];

    /**
     * @param list<string>                                                                                        $hooks
     * @param array<string, array{default: int|string|bool, type: string, min: ?int, max: ?int, label: string}> $settings
     * @param array<string, string>                                                                               $namespaces prefix => folder, parsed now, registered in Stage 8
     */
    private function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $directory,
        public readonly string $entry,
        public readonly array $hooks,
        public readonly array $settings,
        public readonly array $namespaces,
    ) {
    }

    /** @throws ExtensionException */
    public static function fromDirectory(string $directory): self
    {
        $file = $directory . '/' . self::FILE;
        if (!is_file($file)) {
            throw new ExtensionException(sprintf('%s is missing.', self::FILE));
        }

        $json = file_get_contents($file);
        if ($json === false) {
            throw new ExtensionException(sprintf('%s cannot be read.', self::FILE));
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ExtensionException(sprintf('%s is not valid JSON (%s).', self::FILE, $exception->getMessage()));
        }

        return self::fromArray($data, $directory);
    }

    /** @throws ExtensionException */
    public static function fromArray(mixed $data, string $directory): self
    {
        if (!is_array($data) || array_is_list($data)) {
            throw new ExtensionException('The manifest must be a JSON object.');
        }

        $unknown = array_diff(array_map('strval', array_keys($data)), self::FIELDS);
        if ($unknown !== []) {
            throw new ExtensionException(sprintf('Unknown field(s) in the manifest: %s. Allowed: %s.', implode(', ', $unknown), implode(', ', self::FIELDS)));
        }

        $key = self::text($data, 'key', true);
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new ExtensionException('"key" must be 2 to 41 characters: lower-case letters, digits and hyphens, starting with a letter.');
        }
        if (basename($directory) !== $key) {
            throw new ExtensionException(sprintf('"key" (%s) must match the folder name (%s).', $key, basename($directory)));
        }

        $name = self::text($data, 'name', true);
        if (mb_strlen($name) > 80) {
            throw new ExtensionException('"name" can be at most 80 characters.');
        }

        $version = self::text($data, 'version', true);
        if (preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/D', $version) !== 1) {
            throw new ExtensionException('"version" must look like 1.0.0.');
        }

        $description = self::text($data, 'description', false);
        if (mb_strlen($description) > 300) {
            throw new ExtensionException('"description" can be at most 300 characters.');
        }

        // Do not trim: a file name with surrounding whitespace or a trailing newline must be refused.
        $entry = self::text($data, 'entry', true, false);
        if (preg_match('/^[A-Za-z0-9_-]+\.php$/D', $entry) !== 1) {
            throw new ExtensionException('"entry" must be a plain PHP file name in the extension folder, for example register.php.');
        }
        if (!is_file($directory . '/' . $entry)) {
            throw new ExtensionException(sprintf('The entry file "%s" does not exist.', $entry));
        }

        return new self(
            $key,
            $name,
            $version,
            $description,
            $directory,
            $entry,
            self::hooks($data['hooks'] ?? []),
            self::settings($key, $data['settings'] ?? []),
            self::namespaces($data['namespace'] ?? []),
        );
    }

    /** The error message for a setting value, or null when it is fine. Used as the SettingsService validator. */
    public function settingError(string $settingKey, mixed $value): ?string
    {
        $setting = $this->settings[$settingKey] ?? null;
        if ($setting === null) {
            return 'Not a setting of this extension.';
        }

        if ($setting['type'] === 'bool') {
            return is_bool($value) ? null : 'Choose yes or no.';
        }

        if ($setting['type'] === 'string') {
            return is_string($value) && mb_strlen($value) <= 500 ? null : 'Enter text of at most 500 characters.';
        }

        $number = is_int($value) ? $value : (is_string($value) && preg_match('/^\d{1,9}$/D', $value) === 1 ? (int) $value : null);
        $min = $setting['min'] ?? PHP_INT_MIN;
        $max = $setting['max'] ?? PHP_INT_MAX;
        if ($number === null || $number < $min || $number > $max) {
            return $setting['min'] !== null && $setting['max'] !== null
                ? sprintf('Enter a whole number from %d to %d.', $setting['min'], $setting['max'])
                : 'Enter a whole number.';
        }

        return null;
    }

    /** @param array<mixed> $data */
    private static function text(array $data, string $field, bool $required, bool $trim = true): string
    {
        if (!array_key_exists($field, $data)) {
            if ($required) {
                throw new ExtensionException(sprintf('"%s" is required.', $field));
            }

            return '';
        }

        if (!is_string($data[$field]) || trim($data[$field]) === '') {
            throw new ExtensionException(sprintf('"%s" must be non-empty text.', $field));
        }

        return $trim ? trim($data[$field]) : $data[$field];
    }

    /** @return list<string> */
    private static function hooks(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ExtensionException('"hooks" must be a list of hook names.');
        }

        $hooks = [];
        foreach ($value as $name) {
            if (!is_string($name) || !in_array($name, Hooks::NAMES, true)) {
                throw new ExtensionException(sprintf('Unknown hook %s. Known hooks: %s.', json_encode($name, JSON_UNESCAPED_SLASHES), implode(', ', Hooks::NAMES)));
            }
            $hooks[] = $name;
        }

        return array_values(array_unique($hooks));
    }

    /** @return array<string, array{default: int|string|bool, type: string, min: ?int, max: ?int, label: string}> */
    private static function settings(string $extensionKey, mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ExtensionException('"settings" must be an object with one entry per setting.');
        }

        // Setting keys cannot clash with core settings or other extensions: they start with the extension key.
        $prefix = str_replace('-', '_', $extensionKey) . '.';
        $settings = [];

        foreach ($value as $settingKey => $spec) {
            $settingKey = (string) $settingKey;
            if (preg_match('/^[a-z][a-z0-9_.]{2,60}$/D', $settingKey) !== 1 || !str_starts_with($settingKey, $prefix) || strlen($settingKey) === strlen($prefix)) {
                throw new ExtensionException(sprintf('Setting key "%s" is not allowed: it must start with "%s" and use only lower-case letters, digits, underscores and dots.', $settingKey, $prefix));
            }
            if (!is_array($spec) || array_is_list($spec)) {
                throw new ExtensionException(sprintf('Setting "%s" must be an object with at least a "default".', $settingKey));
            }
            $unknown = array_diff(array_map('strval', array_keys($spec)), self::SETTING_FIELDS);
            if ($unknown !== []) {
                throw new ExtensionException(sprintf('Setting "%s" has unknown field(s): %s. Allowed: %s.', $settingKey, implode(', ', $unknown), implode(', ', self::SETTING_FIELDS)));
            }
            if (!array_key_exists('default', $spec) || !(is_int($spec['default']) || is_string($spec['default']) || is_bool($spec['default']))) {
                throw new ExtensionException(sprintf('Setting "%s" needs a "default" that is a whole number, text or true/false.', $settingKey));
            }

            $default = $spec['default'];
            $actual = is_int($default) ? 'int' : (is_bool($default) ? 'bool' : 'string');
            $type = $spec['type'] ?? $actual;
            if (!in_array($type, self::SETTING_TYPES, true) || $type !== $actual) {
                throw new ExtensionException(sprintf('Setting "%s": "type" must be int, string or bool and match the default.', $settingKey));
            }

            $min = $spec['min'] ?? null;
            $max = $spec['max'] ?? null;
            if (($min !== null || $max !== null) && $type !== 'int') {
                throw new ExtensionException(sprintf('Setting "%s": "min" and "max" only apply to whole numbers.', $settingKey));
            }
            foreach (['min' => $min, 'max' => $max] as $bound => $number) {
                if ($number !== null && !is_int($number)) {
                    throw new ExtensionException(sprintf('Setting "%s": "%s" must be a whole number.', $settingKey, $bound));
                }
            }
            if ($min !== null && $max !== null && $min > $max) {
                throw new ExtensionException(sprintf('Setting "%s": "min" is larger than "max".', $settingKey));
            }
            if ($type === 'int' && (($min !== null && $default < $min) || ($max !== null && $default > $max))) {
                throw new ExtensionException(sprintf('Setting "%s": the default is outside min and max.', $settingKey));
            }

            $label = $spec['label'] ?? $settingKey;
            if (!is_string($label) || trim($label) === '' || mb_strlen($label) > 120) {
                throw new ExtensionException(sprintf('Setting "%s": "label" must be text of at most 120 characters.', $settingKey));
            }

            $settings[$settingKey] = ['default' => $default, 'type' => $type, 'min' => $min, 'max' => $max, 'label' => trim($label)];
        }

        return $settings;
    }

    /** @return array<string, string> */
    private static function namespaces(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ExtensionException('"namespace" must be an object that maps a PHP namespace to a folder.');
        }

        $result = [];
        foreach ($value as $prefix => $folder) {
            $prefix = (string) $prefix;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\$/D', $prefix) !== 1) {
                throw new ExtensionException(sprintf('Namespace "%s" must end with a backslash, for example Acme\\\\Expiry\\\\.', $prefix));
            }
            if (!is_string($folder) || preg_match('#^' . AssetUrls::SEGMENT . '(/' . AssetUrls::SEGMENT . ')*/?$#D', $folder) !== 1) {
                throw new ExtensionException(sprintf('Namespace "%s" must map to a plain folder inside the extension, for example src/.', $prefix));
            }
            $result[$prefix] = rtrim($folder, '/') . '/';
        }

        return $result;
    }
}

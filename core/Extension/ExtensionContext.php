<?php

declare(strict_types=1);

namespace Gfh\Core\Extension;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\SettingsService;
use Gfh\Core\View\AssetUrls;

/**
 * What an extension's entry file receives: the only way for it to register behaviour.
 * Registrations are collected here and applied to the hook registry only after the entry file finished
 * without an error, so an extension that fails halfway leaves nothing behind.
 */
final class ExtensionContext
{
    /** @var list<array{name: string, callback: callable, priority: int}> */
    private array $registrations = [];
    private bool $active = false;

    public function __construct(private readonly ExtensionManifest $manifest, private readonly SettingsService $settings)
    {
    }

    public function key(): string
    {
        return $this->manifest->key;
    }

    /** The path of a file inside the extension's own folder, for example path('src/Expiry.php'). */
    public function path(string $file = ''): string
    {
        if ($file === '') {
            return $this->manifest->directory;
        }
        if (preg_match('#^' . AssetUrls::SEGMENT . '(/' . AssetUrls::SEGMENT . ')*$#D', $file) !== 1) {
            throw new ExtensionException(sprintf('"%s" is not a valid file path inside the extension.', $file));
        }

        return $this->manifest->directory . '/' . $file;
    }

    /**
     * Registers a callback for a hook the manifest declares. The callback takes ($value, array $context)
     * and returns the new value (see Hooks). Lower priority numbers run first.
     */
    public function hook(string $name, callable $callback, int $priority = 10): void
    {
        if (!in_array($name, Hooks::NAMES, true)) {
            throw new ExtensionException(sprintf('Unknown hook "%s". Known hooks: %s.', $name, implode(', ', Hooks::NAMES)));
        }
        if (!in_array($name, $this->manifest->hooks, true)) {
            throw new ExtensionException(sprintf('The hook "%s" is not listed under "hooks" in %s.', $name, ExtensionManifest::FILE));
        }
        if ($priority < 0 || $priority > 100) {
            throw new ExtensionException('A hook priority must be from 0 to 100.');
        }

        $this->registrations[] = ['name' => $name, 'callback' => $callback, 'priority' => $priority];
    }

    /**
     * The current value of one of this extension's own settings. Call it inside a hook callback, not while the
     * entry file is registering: settings are connected only after the entry file has finished.
     */
    public function setting(string $key): mixed
    {
        if (!$this->active) {
            throw new ExtensionException('Read settings inside a hook callback, not while the entry file is registering.');
        }
        if (!isset($this->manifest->settings[$key])) {
            throw new ExtensionException(sprintf('"%s" is not a setting declared by this extension.', $key));
        }

        return $this->settings->get($key);
    }

    /** @return list<array{name: string, callback: callable, priority: int}> */
    public function registrations(): array
    {
        return $this->registrations;
    }

    /** Called by the loader once the extension is accepted. */
    public function activate(): void
    {
        $this->active = true;
    }
}

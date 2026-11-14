<?php

declare(strict_types=1);

namespace Gfh\Core\Extension;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\SettingsService;
use Gfh\Core\View\TemplateLocator;

/**
 * Finds, checks and loads the extensions of one organization.
 *
 *   extensions/{key}/                shared; loaded for an organization that has the matching feature switched on
 *   orgs/{slug}/extensions/{key}/    local to one organization; always loaded for it, and wins over a shared one with the same key
 *
 * Each extension is loaded on its own: a broken manifest, an unknown hook name or an entry file that throws
 * produces one entry in the report (and a log line) and that extension is skipped. The rest of the site keeps working.
 *
 * Stage 8 will add to this class: registering an extension's PHP namespace with Autoloader::addNamespace()
 * (the manifest already parses "namespace"), running extension migrations, and reading enable/disable
 * state from the database instead of a plain feature list. The marked places below are where that goes.
 */
final class ExtensionLoader
{
    /** @param (\Closure(string, array<string, mixed>): void)|null $log called once for every extension that fails */
    public function __construct(private readonly string $rootPath, private readonly ?\Closure $log = null)
    {
    }

    /**
     * @param list<string> $enabledFeatures feature keys switched on for the organization; "cold_chain" enables the shared extension "cold-chain"
     * @return array{loaded: list<string>, failed: array<string, string>} extension keys that loaded, and for each failure a short message
     */
    public function load(string $organizationSlug, Hooks $hooks, SettingsService $settings, array $enabledFeatures): array
    {
        TemplateLocator::assertSlug($organizationSlug);

        $enabled = array_map(static fn (string $feature): string => str_replace('_', '-', $feature), $enabledFeatures);
        $loaded = [];
        $failed = [];

        foreach ($this->candidates($organizationSlug, $enabled) as $key => $directory) {
            try {
                $manifest = ExtensionManifest::fromDirectory($directory);
                $this->activate($manifest, $hooks, $settings);
                $loaded[] = $manifest->key;
            } catch (\Throwable $exception) {
                // Throwable on purpose: a parse error or type error in extension code must not take the site down.
                $failed[$key] = $this->describe($exception);
                if ($this->log !== null) {
                    ($this->log)(sprintf('Extension "%s" was skipped: %s', $key, $failed[$key]), ['extension' => $key, 'organization' => $organizationSlug]);
                }
            }
        }

        return ['loaded' => $loaded, 'failed' => $failed];
    }

    /**
     * Extension folders to try, in a fixed order: shared ones (A to Z), then the organization's own.
     * A shared extension that is not switched on is not even opened, so a broken one cannot affect organizations that do not use it.
     *
     * @param list<string> $enabled
     * @return array<string, string> key => folder
     */
    private function candidates(string $slug, array $enabled): array
    {
        $found = [];
        foreach ($this->folders($this->rootPath . '/extensions') as $key => $directory) {
            if (in_array($key, $enabled, true)) {
                $found[$key] = $directory;
            }
        }
        // Stage 8: replace the $enabled list above with the stored enable/disable state.

        foreach ($this->folders($this->rootPath . '/orgs/' . $slug . '/extensions') as $key => $directory) {
            $found[$key] = $directory;
        }

        return $found;
    }

    /** @return array<string, string> folder name => path, for sub-folders with a usable name */
    private function folders(string $parent): array
    {
        if (!is_dir($parent)) {
            return [];
        }

        $names = scandir($parent) ?: [];
        sort($names);

        $folders = [];
        foreach ($names as $name) {
            if ($name[0] === '.' || !is_dir($parent . '/' . $name) || is_link($parent . '/' . $name)) {
                continue;
            }
            $folders[$name] = $parent . '/' . $name;
        }

        return $folders;
    }

    /** @throws ExtensionException|\Throwable */
    private function activate(ExtensionManifest $manifest, Hooks $hooks, SettingsService $settings): void
    {
        // Stage 8: register $manifest->namespaces with Autoloader::addNamespace() here, before the entry file runs.
        $context = new ExtensionContext($manifest, $settings);
        $register = $this->entry($manifest);
        $register($context);

        // Only now, with the entry file finished without an error, do the shared registries change.
        foreach ($manifest->settings as $settingKey => $setting) {
            $settings->define(
                $settingKey,
                $setting['default'],
                static fn (mixed $value): ?string => $manifest->settingError($settingKey, $value),
            );
        }
        foreach ($context->registrations() as $registration) {
            $hooks->add($registration['name'], $registration['callback'], $registration['priority']);
        }
        $context->activate();
    }

    /** Loads the entry file, which must return a function that takes the ExtensionContext. */
    private function entry(ExtensionManifest $manifest): callable
    {
        $file = $manifest->directory . '/' . $manifest->entry;
        $real = realpath($file);
        $folder = realpath($manifest->directory);
        if ($real === false || $folder === false || !str_starts_with(str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', $folder), '/') . '/')) {
            throw new ExtensionException('The entry file must be inside the extension folder.');
        }

        // The entry file should only return its function; anything it prints is discarded.
        ob_start();
        try {
            $register = (static fn (string $__file): mixed => require $__file)($file);
        } finally {
            ob_end_clean();
        }

        if (!$register instanceof \Closure) {
            throw new ExtensionException(sprintf('%s must return a function: return function (ExtensionContext $x): void { ... };', $manifest->entry));
        }

        return $register;
    }

    private function describe(\Throwable $exception): string
    {
        $message = $exception instanceof ExtensionException
            ? $exception->getMessage()
            : sprintf('%s: %s', $exception::class, $exception->getMessage());

        return mb_substr(str_replace(["\r", "\n"], ' ', $message), 0, 300);
    }
}

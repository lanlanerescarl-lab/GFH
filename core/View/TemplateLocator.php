<?php

declare(strict_types=1);

namespace Gfh\Core\View;

use Gfh\Core\Service\OrganizationRegistry;

/**
 * Finds the file behind a template name for one app (admin, shop or pos) of one organization.
 * The lookup order is fixed: orgs/{slug}/{app}/{name}.php first, then templates/{app}/{name}.php.
 * An organization therefore overrides a single file without copying the rest.
 *
 * Every part that reaches the file system is validated first: the slug must be a valid organization slug,
 * the app one of three fixed names, and the template name plain folder and file names with no dots, so
 * nothing can climb out of the template folders. The resolved path is also checked against the real path
 * of its folder, which stops a symbolic link from leading elsewhere.
 */
final class TemplateLocator
{
    public const APPS = ['admin', 'shop', 'pos'];

    public function __construct(private readonly string $rootPath)
    {
    }

    /** @throws \InvalidArgumentException for a bad slug, app or name, or when neither folder has the file */
    public function resolve(string $organizationSlug, string $app, string $name): string
    {
        self::assertSlug($organizationSlug);
        if (!in_array($app, self::APPS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown app "%s". Use admin, shop or pos.', $app));
        }
        // Folder and file names made of letters, digits, underscores and hyphens; no dots, no empty parts.
        // The D flag makes $ match only at the very end, so a trailing line break is refused too.
        if (preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#D', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid template name.', $this->printable($name)));
        }

        $candidates = [
            $this->rootPath . '/orgs/' . $organizationSlug . '/' . $app,
            $this->rootPath . '/templates/' . $app,
        ];

        foreach ($candidates as $folder) {
            $found = $this->inside($folder, $name);
            if ($found !== null) {
                return $found;
            }
        }

        throw new \InvalidArgumentException(sprintf('Template not found: %s/%s', $app, $name));
    }

    /** True when the slug has the shape of an organization slug and is not a reserved word. */
    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z][a-z0-9-]{1,30}[a-z0-9]$/D', $slug) === 1
            && !in_array($slug, OrganizationRegistry::RESERVED_SLUGS, true);
    }

    /** @throws \InvalidArgumentException */
    public static function assertSlug(string $slug): void
    {
        if (!self::isValidSlug($slug)) {
            throw new \InvalidArgumentException('That is not a valid organization slug.');
        }
    }

    /** The file inside $folder for $name, or null when it is missing or resolves outside the folder. */
    private function inside(string $folder, string $name): ?string
    {
        $file = $folder . '/' . $name . '.php';
        if (!is_file($file)) {
            return null;
        }

        $realFolder = realpath($folder);
        $realFile = realpath($file);
        if ($realFolder === false || $realFile === false) {
            return null;
        }

        $realFolder = rtrim(str_replace('\\', '/', $realFolder), '/') . '/';
        if (!str_starts_with(str_replace('\\', '/', $realFile), $realFolder)) {
            return null;
        }

        return $file;
    }

    /** Keeps odd bytes out of exception messages, which end up in the log. */
    private function printable(string $value): string
    {
        return preg_replace('/[^\x20-\x7E]/', '?', mb_substr($value, 0, 60)) ?? '';
    }
}

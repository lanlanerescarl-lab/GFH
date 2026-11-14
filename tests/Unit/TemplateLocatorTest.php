<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\View\TemplateLocator;
use Gfh\Tests\Support\TemplateTree;
use PHPUnit\Framework\TestCase;

final class TemplateLocatorTest extends TestCase
{
    private TemplateTree $tree;
    private TemplateLocator $locator;

    protected function setUp(): void
    {
        $this->tree = new TemplateTree();
        $this->tree->put('templates/admin/home.php', 'shared home');
        $this->tree->put('templates/admin/partials/nav.php', 'shared nav');
        $this->tree->put('orgs/acme/admin/home.php', 'acme home');
        $this->tree->put('orgs/beta/admin/partials/nav.php', 'beta nav');
        $this->tree->put('orgs/beta/shop/only-beta.php', 'beta only');
        $this->locator = new TemplateLocator($this->tree->root);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private function contents(string $slug, string $app, string $name): string
    {
        return (string) file_get_contents($this->locator->resolve($slug, $app, $name));
    }

    public function testTheOrganizationFileWins(): void
    {
        $this->assertSame('acme home', $this->contents('acme', 'admin', 'home'));
    }

    public function testAMissingOrganizationFileFallsBackToTheSharedTemplate(): void
    {
        $this->assertSame('shared home', $this->contents('beta', 'admin', 'home'));
        $this->assertSame('shared nav', $this->contents('acme', 'admin', 'partials/nav'));
    }

    public function testAnOrganizationCanOverrideOneFileWithoutCopyingTheRest(): void
    {
        $this->assertSame('beta nav', $this->contents('beta', 'admin', 'partials/nav'));
        $this->assertSame('shared home', $this->contents('beta', 'admin', 'home'));
    }

    public function testAnotherOrganizationsFilesAreNeverUsed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->locator->resolve('acme', 'shop', 'only-beta');
    }

    public function testAMissingTemplateIsReported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Template not found');
        $this->locator->resolve('acme', 'admin', 'nothing-here');
    }

    public function testNamesThatCouldClimbOutOfTheFolderAreRefused(): void
    {
        $this->tree->put('secret.php', 'top secret');
        $this->tree->put('orgs/acme/secret.php', 'org secret');

        $bad = ['../secret', '../../secret', 'partials/../../secret', '/secret', '\\secret', '..\\secret', 'home.php', 'home.', '.hidden',
            'partials//nav', 'partials/', '', 'home' . "\n", "home\0", 'ho me', 'home?x=1', 'C:/secret'];

        foreach ($bad as $name) {
            $this->assertRefused(fn () => $this->locator->resolve('acme', 'admin', $name), 'name ' . json_encode($name));
        }
    }

    public function testBadOrganizationSlugsAreRefusedBeforeTheyTouchAPath(): void
    {
        $bad = ['', '..', '.', 'a/b', '../acme', 'Acme', 'acme ', "acme\n", "ac\0me", '_skeleton', 'a', '-acme', 'acme-', 'api', 'assets', 'superadmin', str_repeat('a', 40)];

        foreach ($bad as $slug) {
            $this->assertRefused(fn () => $this->locator->resolve($slug, 'admin', 'home'), 'slug ' . json_encode($slug));
        }
    }

    public function testOnlyTheThreeAppsAreAccepted(): void
    {
        foreach (['api', 'ADMIN', '../admin', 'admin/', '', 'superadmin'] as $app) {
            $this->assertRefused(fn () => $this->locator->resolve('acme', $app, 'home'), 'app ' . json_encode($app));
        }

        $this->assertSame('shared home', $this->contents('zeta-shop', 'admin', 'home'));
    }

    public function testSlugRule(): void
    {
        $this->assertTrue(TemplateLocator::isValidSlug('demo'));
        $this->assertTrue(TemplateLocator::isValidSlug('home-appliances'));
        $this->assertFalse(TemplateLocator::isValidSlug('Demo'));
        $this->assertFalse(TemplateLocator::isValidSlug('media'));
    }

    /** @param callable(): mixed $action */
    private function assertRefused(callable $action, string $label): void
    {
        try {
            $action();
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected a refusal for ' . $label);
    }
}

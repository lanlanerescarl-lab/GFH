<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Extension\ExtensionLoader;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\SettingsService;
use Gfh\Tests\Support\TemplateTree;
use PHPUnit\Framework\TestCase;

/** Batch 8.2: a care-notes entry file that throws leaves no hook behind and does not stop other extensions. */
final class CareNotesBrokenExtensionTest extends TestCase
{
    private TemplateTree $tree;

    protected function setUp(): void
    {
        $this->tree = new TemplateTree();
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testACareNotesEntryFileThatThrowsIsReportedAndLeavesNothingRegistered(): void
    {
        // The real manifest, with an entry file that fails after it has already queued hooks.
        $this->tree->put('extensions/care-notes/extension.json', (string) file_get_contents(dirname(__DIR__, 2) . '/extensions/care-notes/extension.json'));
        $this->tree->put('extensions/care-notes/register.php', '<?php return function ($x) { $x->hook("navigation.admin", fn (array $i) => [...$i, "care"]); throw new \RuntimeException("care notes exploded"); };');
        $this->tree->put('extensions/steady/extension.json', '{"key":"steady","name":"Steady","version":"1.0.0","entry":"register.php","hooks":["navigation.admin"]}');
        $this->tree->put('extensions/steady/register.php', '<?php return function ($x) { $x->hook("navigation.admin", fn (array $i) => [...$i, "steady"]); };');

        $logged = [];
        $settings = (new \ReflectionClass(SettingsService::class))->newInstanceWithoutConstructor();
        $hooks = new Hooks();
        $report = (new ExtensionLoader($this->tree->root, function (string $message, array $context) use (&$logged): void {
            $logged[] = $message;
        }))->load('acme', $hooks, $settings, ['care_notes', 'steady']);

        $this->assertSame(['steady'], $report['loaded']);
        $this->assertSame(['care-notes'], array_keys($report['failed']));
        $this->assertStringContainsString('care notes exploded', $report['failed']['care-notes']);
        $this->assertCount(1, $logged, 'logged once');
        $this->assertSame(['base', 'steady'], $hooks->filter(Hooks::NAVIGATION_ADMIN, ['base']), 'nothing the failed extension queued is left');
        foreach ([Hooks::PRODUCT_FORM_TABS, Hooks::PRODUCT_FORM_SAVE, Hooks::PRODUCT_DETAIL_SECTIONS] as $name) {
            $this->assertFalse($hooks->has($name));
        }
    }
}

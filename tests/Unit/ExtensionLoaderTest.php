<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Extension\ExtensionLoader;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Service\SettingsService;
use Gfh\Tests\Support\TemplateTree;
use PHPUnit\Framework\TestCase;

final class ExtensionLoaderTest extends TestCase
{
    private TemplateTree $tree;
    private Hooks $hooks;
    private SettingsService $settings;
    /** @var list<array{string, array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->tree = new TemplateTree();
        $this->hooks = new Hooks();
        // The settings tests only use define() and the validation in set(), which never touch the database.
        $this->settings = (new \ReflectionClass(SettingsService::class))->newInstanceWithoutConstructor();
        $this->logged = [];
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    /** @param array<string, mixed> $manifest */
    private function extension(string $parent, string $key, array $manifest = [], ?string $entry = null): void
    {
        $manifest += [
            'key' => $key,
            'name' => ucfirst($key),
            'version' => '1.0.0',
            'entry' => 'register.php',
            'hooks' => ['navigation.admin'],
        ];
        $entry ??= '<?php return function ($x) { $x->hook("navigation.admin", function (array $items) { $items[] = "' . $key . '"; return $items; }); };';

        $this->tree->put($parent . '/' . $key . '/extension.json', (string) json_encode($manifest, JSON_UNESCAPED_SLASHES));
        $this->tree->put($parent . '/' . $key . '/register.php', $entry);
    }

    /**
     * @param list<string> $features
     * @return array{loaded: list<string>, failed: array<string, string>}
     */
    private function load(string $slug = 'acme', array $features = []): array
    {
        $loader = new ExtensionLoader($this->tree->root, function (string $message, array $context): void {
            $this->logged[] = [$message, $context];
        });

        return $loader->load($slug, $this->hooks, $this->settings, $features);
    }

    /** @return list<string> */
    private function menu(): array
    {
        /** @var list<string> */
        return $this->hooks->filter(Hooks::NAVIGATION_ADMIN, ['base']);
    }

    public function testHooksWithNothingRegisteredPassTheValueThroughUnchanged(): void
    {
        $report = $this->load();

        $this->assertSame(['loaded' => [], 'failed' => []], $report);
        foreach (Hooks::NAMES as $name) {
            $this->assertFalse($this->hooks->has($name));
            $this->assertSame(['a', 'b'], $this->hooks->filter($name, ['a', 'b']));
        }
    }

    public function testASharedExtensionLoadsOnlyForAnOrganizationThatHasItsFeatureOn(): void
    {
        $this->extension('extensions', 'cold-chain');

        $off = $this->load('acme', []);
        $this->assertSame([], $off['loaded']);
        $this->assertSame(['base'], $this->menu());

        $on = $this->load('acme', ['cold_chain']);
        $this->assertSame(['cold-chain'], $on['loaded']);
        $this->assertSame(['base', 'cold-chain'], $this->menu());
    }

    public function testAnExtensionOnlyChangesBehaviourThroughItsHooks(): void
    {
        $this->extension('orgs/acme/extensions', 'first-one');
        $this->extension('orgs/acme/extensions', 'second-one');

        $this->assertSame(['first-one', 'second-one'], $this->load()['loaded']);
        $this->assertSame(['base', 'first-one', 'second-one'], $this->menu());
        $this->assertFalse($this->hooks->has(Hooks::NAVIGATION_SHOP));
    }

    public function testOrganizationLocalExtensionsAlwaysLoadAndOnlyForThatOrganization(): void
    {
        $this->extension('orgs/acme/extensions', 'acme-only');

        $this->assertSame(['acme-only'], $this->load('acme')['loaded']);

        $this->hooks = new Hooks();
        $this->assertSame([], $this->load('beta-shop')['loaded']);
        $this->assertSame(['base'], $this->menu());
    }

    public function testALocalExtensionReplacesASharedOneWithTheSameKey(): void
    {
        $this->extension('extensions', 'cold-chain', [], '<?php return function ($x) { $x->hook("navigation.admin", fn (array $i) => [...$i, "shared"]); };');
        $this->extension('orgs/acme/extensions', 'cold-chain', [], '<?php return function ($x) { $x->hook("navigation.admin", fn (array $i) => [...$i, "local"]); };');

        $this->assertSame(['cold-chain'], $this->load('acme', ['cold_chain'])['loaded']);
        $this->assertSame(['base', 'local'], $this->menu());
    }

    public function testPriorityDecidesTheOrderAcrossExtensions(): void
    {
        // "a-late" loads first by name, but its higher priority number makes it run after "b-early".
        $this->extension('orgs/acme/extensions', 'a-late', [], '<?php return function ($x) { $x->hook("navigation.admin", fn (array $i) => [...$i, "late"], 50); };');
        $this->extension('orgs/acme/extensions', 'b-early', [], '<?php return function ($x) { $x->hook("navigation.admin", fn (array $i) => [...$i, "early"], 5); };');

        $this->assertSame(['a-late', 'b-early'], $this->load()['loaded']);

        $this->assertSame(['base', 'early', 'late'], $this->menu());
    }

    public function testABrokenExtensionIsSkippedLoggedAndTheOthersStillLoad(): void
    {
        $this->tree->put('orgs/acme/extensions/aaa-broken/extension.json', '{ not json');
        $this->extension('orgs/acme/extensions', 'bbb-good');

        $report = $this->load();

        $this->assertSame(['bbb-good'], $report['loaded']);
        $this->assertSame(['aaa-broken'], array_keys($report['failed']));
        $this->assertStringContainsString('not valid JSON', $report['failed']['aaa-broken']);
        $this->assertSame(['base', 'bbb-good'], $this->menu());

        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('aaa-broken', $this->logged[0][0]);
        $this->assertSame('acme', $this->logged[0][1]['organization']);
    }

    public function testAFolderWithoutAManifestIsReportedNotIgnored(): void
    {
        $this->tree->put('orgs/acme/extensions/no-manifest/readme.txt', 'nothing here');

        $report = $this->load();

        $this->assertStringContainsString('extension.json is missing', $report['failed']['no-manifest']);
    }

    public function testAnUnknownHookInTheManifestFailsClearly(): void
    {
        $this->extension('orgs/acme/extensions', 'typo-hook', ['hooks' => ['navigation.adnim']]);

        $report = $this->load();

        $this->assertSame([], $report['loaded']);
        $this->assertStringContainsString('Unknown hook "navigation.adnim"', $report['failed']['typo-hook']);
        $this->assertFalse($this->hooks->has(Hooks::NAVIGATION_ADMIN));
    }

    public function testRegisteringAHookTheManifestDoesNotDeclareFails(): void
    {
        $this->extension('orgs/acme/extensions', 'sneaky', ['hooks' => ['navigation.shop']]);

        $report = $this->load();

        $this->assertStringContainsString('is not listed under "hooks"', $report['failed']['sneaky']);
        $this->assertFalse($this->hooks->has(Hooks::NAVIGATION_ADMIN));
    }

    public function testRegisteringAnUnknownHookNameInTheEntryFileFails(): void
    {
        $this->extension('orgs/acme/extensions', 'bad-name', [], '<?php return function ($x) { $x->hook("nope.nope", fn ($v) => $v); };');

        $this->assertStringContainsString('Unknown hook "nope.nope"', $this->load()['failed']['bad-name']);
    }

    public function testAnEntryFileThatFailsHalfwayLeavesNothingBehind(): void
    {
        $this->extension('orgs/acme/extensions', 'half-done', ['settings' => ['half_done.limit' => ['default' => 3]]], '<?php return function ($x) {
            $x->hook("navigation.admin", fn (array $i) => [...$i, "half"]);
            throw new \RuntimeException("boom");
        };');

        $report = $this->load();

        $this->assertSame('RuntimeException: boom', $report['failed']['half-done']);
        $this->assertFalse($this->hooks->has(Hooks::NAVIGATION_ADMIN));
        $this->assertSame('key', $this->settingFieldFor('half_done.limit'), 'the settings of a failed extension are not defined');
    }

    public function testAnEntryFileMustReturnAFunction(): void
    {
        $this->extension('orgs/acme/extensions', 'no-function', [], '<?php return 5;');

        $this->assertStringContainsString('must return a function', $this->load()['failed']['no-function']);
    }

    public function testASyntaxErrorInTheEntryFileDoesNotTakeTheLoaderDown(): void
    {
        $this->extension('orgs/acme/extensions', 'syntax-bug', [], '<?php return function ($x) { ');
        $this->extension('orgs/acme/extensions', 'zzz-fine');

        $report = $this->load();

        $this->assertStringContainsString('ParseError', $report['failed']['syntax-bug']);
        $this->assertSame(['zzz-fine'], $report['loaded']);
    }

    public function testOutputFromTheEntryFileIsDiscarded(): void
    {
        $this->extension('orgs/acme/extensions', 'chatty', [], '<?php echo "hello"; return function ($x) {};');

        $this->expectOutputString('');
        $this->assertSame(['chatty'], $this->load()['loaded']);
    }

    public function testADisabledSharedExtensionIsNotEvenOpened(): void
    {
        $this->tree->put('extensions/cold-chain/extension.json', '{ not json');

        $this->assertSame(['loaded' => [], 'failed' => []], $this->load('acme', []));
        $this->assertSame([], $this->logged);

        $this->assertArrayHasKey('cold-chain', $this->load('acme', ['cold_chain'])['failed']);
    }

    public function testExtensionSettingsAreConnectedWithTheirOwnValidation(): void
    {
        $this->extension('orgs/acme/extensions', 'cold-chain', ['settings' => ['cold_chain.max_temp' => ['default' => 8, 'min' => 0, 'max' => 30]]]);

        $this->assertSame(['cold-chain'], $this->load()['loaded']);

        $this->assertSame('cold_chain.max_temp', $this->settingFieldFor('cold_chain.max_temp', 99), 'a bad value is a field error on the setting itself');
        $this->assertSame('key', $this->settingFieldFor('cold_chain.unknown'), 'a key nobody defined is still refused');
    }

    public function testSettingsCannotBeReadWhileTheEntryFileIsRegistering(): void
    {
        $this->extension('orgs/acme/extensions', 'eager', ['settings' => ['eager.limit' => ['default' => 1]]], '<?php return function ($x) { $x->setting("eager.limit"); };');

        $this->assertStringContainsString('inside a hook callback', $this->load()['failed']['eager']);
    }

    public function testAnExtensionCannotReachOutsideItsOwnFolder(): void
    {
        $this->extension('orgs/acme/extensions', 'climber', [], '<?php return function ($x) { $x->path("../secret.php"); };');

        $this->assertStringContainsString('not a valid file path', $this->load()['failed']['climber']);
    }

    public function testBadOrganizationSlugsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->load('../acme');
    }

    /** Tries to set a value and returns which field the refusal names (an unprocessable response), or "none". */
    private function settingFieldFor(string $key, mixed $value = 1): string
    {
        try {
            $this->settings->set($key, $value, null);
        } catch (HttpException $exception) {
            return (string) array_key_first($exception->fields());
        }

        return 'none';
    }
}

<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Extension\ExtensionException;
use Gfh\Core\Extension\ExtensionManifest;
use Gfh\Tests\Support\TemplateTree;
use PHPUnit\Framework\TestCase;

final class ExtensionManifestTest extends TestCase
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

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return [
            'key' => 'cold-chain',
            'name' => 'Cold chain',
            'version' => '1.2.0',
            'description' => 'Temperature checks for chilled goods.',
            'entry' => 'register.php',
            'hooks' => ['stock.pick_strategy', 'navigation.admin'],
            'settings' => ['cold_chain.max_temp' => ['default' => 8, 'min' => 0, 'max' => 30, 'label' => 'Highest allowed temperature']],
            'namespace' => ['Acme\\ColdChain\\' => 'src/'],
        ];
    }

    /** @param array<string, mixed>|string $manifest */
    private function parse(array|string $manifest, string $folder = 'cold-chain', bool $withEntry = true): ExtensionManifest
    {
        $json = is_string($manifest) ? $manifest : json_encode($manifest, JSON_UNESCAPED_SLASHES);
        $this->tree->put('extensions/' . $folder . '/extension.json', (string) $json);
        if ($withEntry) {
            $this->tree->put('extensions/' . $folder . '/register.php', '<?php return function ($x) {};');
        }

        return ExtensionManifest::fromDirectory($this->tree->root . '/extensions/' . $folder);
    }

    /** @param array<string, mixed>|string $manifest */
    private function assertBroken(array|string $manifest, string $message, string $folder = 'cold-chain', bool $withEntry = true): void
    {
        try {
            $this->parse($manifest, $folder, $withEntry);
        } catch (ExtensionException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());

            return;
        }

        $this->fail('Expected the manifest to be refused with: ' . $message);
    }

    public function testAValidManifestIsParsed(): void
    {
        $manifest = $this->parse($this->valid());

        $this->assertSame('cold-chain', $manifest->key);
        $this->assertSame('Cold chain', $manifest->name);
        $this->assertSame('1.2.0', $manifest->version);
        $this->assertSame('register.php', $manifest->entry);
        $this->assertSame(['stock.pick_strategy', 'navigation.admin'], $manifest->hooks);
        $this->assertSame(8, $manifest->settings['cold_chain.max_temp']['default']);
        $this->assertSame('int', $manifest->settings['cold_chain.max_temp']['type']);
        $this->assertSame(['Acme\\ColdChain\\' => 'src/'], $manifest->namespaces);
    }

    public function testTheSmallestManifestNeedsOnlyKeyNameVersionAndEntry(): void
    {
        $manifest = $this->parse(['key' => 'cold-chain', 'name' => 'Cold chain', 'version' => '0.1.0', 'entry' => 'register.php']);

        $this->assertSame([], $manifest->hooks);
        $this->assertSame([], $manifest->settings);
        $this->assertSame([], $manifest->namespaces);
        $this->assertSame('', $manifest->description);
    }

    public function testManifestsThatAreNotAJsonObjectAreRefused(): void
    {
        $this->assertBroken('{nope', 'not valid JSON');
        $this->assertBroken('', 'not valid JSON');
        $this->assertBroken('[]', 'must be a JSON object');
        $this->assertBroken('"text"', 'must be a JSON object');
    }

    public function testAMissingManifestFileIsReported(): void
    {
        $this->tree->put('extensions/cold-chain/register.php', '<?php return function ($x) {};');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('extension.json is missing');
        ExtensionManifest::fromDirectory($this->tree->root . '/extensions/cold-chain');
    }

    public function testRequiredFieldsMustBePresent(): void
    {
        foreach (['key', 'name', 'version', 'entry'] as $field) {
            $manifest = $this->valid();
            unset($manifest[$field]);
            $this->assertBroken($manifest, '"' . $field . '" is required');
        }
    }

    public function testAnUnknownFieldIsRefusedSoTyposAreCaught(): void
    {
        $manifest = $this->valid();
        $manifest['hook'] = ['navigation.admin'];

        $this->assertBroken($manifest, 'Unknown field(s) in the manifest: hook');
    }

    public function testTheKeyMustBeWellFormedAndMatchTheFolder(): void
    {
        $this->assertBroken(['key' => 'Cold Chain'] + $this->valid(), '"key" must be');
        $this->assertBroken(['key' => 'x'] + $this->valid(), '"key" must be');
        $this->assertBroken(['key' => 'other-thing'] + $this->valid(), 'must match the folder name');
    }

    public function testTheVersionMustLookLikeASemanticVersion(): void
    {
        foreach (['1.0', 'v1.0.0', '1.0.0-beta', 'latest'] as $version) {
            $this->assertBroken(['version' => $version] + $this->valid(), '"version" must look like 1.0.0');
        }
    }

    public function testTheEntryFileMustBeAPlainPhpFileThatExists(): void
    {
        foreach (['../register.php', 'sub/register.php', 'register.txt', '/etc/passwd', 'register', '.php', "register.php\n", ' register.php'] as $entry) {
            $this->assertBroken(['entry' => $entry] + $this->valid(), '"entry" must be a plain PHP file name');
        }

        $this->assertBroken(['entry' => 'missing.php'] + $this->valid(), 'The entry file "missing.php" does not exist');
    }

    public function testOnlyKnownHookNamesAreAccepted(): void
    {
        $this->assertBroken(['hooks' => ['stock.pick_strategy', 'navigation.adnim']] + $this->valid(), 'Unknown hook "navigation.adnim"');
        $this->assertBroken(['hooks' => [5]] + $this->valid(), 'Unknown hook 5');
        $this->assertBroken(['hooks' => 'stock.pick_strategy'] + $this->valid(), '"hooks" must be a list');
        $this->assertBroken(['hooks' => ['a' => 'stock.pick_strategy']] + $this->valid(), '"hooks" must be a list');
    }

    public function testSettingKeysMustStartWithTheExtensionKey(): void
    {
        foreach (['max_temp', 'deposit_percent', 'other_ext.max_temp', 'cold_chain.', 'cold-chain.max', 'Cold_chain.max'] as $key) {
            $this->assertBroken(['settings' => [$key => ['default' => 1]]] + $this->valid(), 'is not allowed');
        }
    }

    public function testSettingDefinitionsAreChecked(): void
    {
        $setting = static fn (array $spec): array => ['settings' => ['cold_chain.max_temp' => $spec]];

        $this->assertBroken($setting(['default' => 1, 'color' => 'x']) + $this->valid(), 'unknown field(s): color');
        $this->assertBroken($setting(['min' => 1]) + $this->valid(), 'needs a "default"');
        $this->assertBroken($setting(['default' => 1.5]) + $this->valid(), 'needs a "default"');
        $this->assertBroken($setting(['default' => null]) + $this->valid(), 'needs a "default"');
        $this->assertBroken($setting(['default' => 5, 'type' => 'string']) + $this->valid(), 'match the default');
        $this->assertBroken($setting(['default' => 5, 'type' => 'float']) + $this->valid(), 'match the default');
        $this->assertBroken($setting(['default' => 'x', 'min' => 1]) + $this->valid(), 'only apply to whole numbers');
        $this->assertBroken($setting(['default' => 5, 'min' => 9, 'max' => 3]) + $this->valid(), '"min" is larger than "max"');
        $this->assertBroken($setting(['default' => 50, 'min' => 0, 'max' => 10]) + $this->valid(), 'outside min and max');
        $this->assertBroken($setting(['default' => 5, 'min' => '0']) + $this->valid(), '"min" must be a whole number');
        $this->assertBroken($setting(['default' => 5, 'label' => '']) + $this->valid(), '"label"');
        $this->assertBroken($setting(['default' => 5, 'label' => str_repeat('x', 121)]) + $this->valid(), '"label"');
        $this->assertBroken($setting([5]) + $this->valid(), 'must be an object');
    }

    public function testNamespacesAreChecked(): void
    {
        $this->assertBroken(['namespace' => ['Acme\\ColdChain' => 'src/']] + $this->valid(), 'must end with a backslash');
        $this->assertBroken(['namespace' => ['Acme\\ColdChain\\' => '../src']] + $this->valid(), 'plain folder inside the extension');
        $this->assertBroken(['namespace' => ['Acme\\ColdChain\\' => '/src']] + $this->valid(), 'plain folder inside the extension');
        $this->assertBroken(['namespace' => ['Acme\\ColdChain\\']] + $this->valid(), 'must be an object');
    }

    public function testBooleanAndTextSettingsAreSupported(): void
    {
        $manifest = $this->parse(['settings' => [
            'cold_chain.alerts' => ['default' => true, 'label' => 'Send alerts'],
            'cold_chain.sender' => ['default' => 'front desk'],
        ]] + $this->valid());

        $this->assertSame('bool', $manifest->settings['cold_chain.alerts']['type']);
        $this->assertSame('string', $manifest->settings['cold_chain.sender']['type']);
        $this->assertSame('cold_chain.sender', $manifest->settings['cold_chain.sender']['label'], 'the label defaults to the key');
    }

    public function testSettingValuesAreValidatedByType(): void
    {
        $manifest = $this->parse(['settings' => [
            'cold_chain.max_temp' => ['default' => 8, 'min' => 0, 'max' => 30],
            'cold_chain.alerts' => ['default' => true],
            'cold_chain.sender' => ['default' => 'x'],
        ]] + $this->valid());

        $this->assertNull($manifest->settingError('cold_chain.max_temp', 12));
        $this->assertNull($manifest->settingError('cold_chain.max_temp', '12'));
        $this->assertNull($manifest->settingError('cold_chain.max_temp', 0));
        foreach ([31, -1, '1.5', 'abc', null, true, [], '99999999999'] as $bad) {
            $this->assertSame('Enter a whole number from 0 to 30.', $manifest->settingError('cold_chain.max_temp', $bad), json_encode($bad));
        }

        $this->assertNull($manifest->settingError('cold_chain.alerts', false));
        $this->assertNotNull($manifest->settingError('cold_chain.alerts', 'yes'));
        $this->assertNull($manifest->settingError('cold_chain.sender', 'front desk'));
        $this->assertNotNull($manifest->settingError('cold_chain.sender', 5));
        $this->assertNotNull($manifest->settingError('cold_chain.sender', str_repeat('x', 501)));
        $this->assertNotNull($manifest->settingError('cold_chain.other', 1));
    }
}

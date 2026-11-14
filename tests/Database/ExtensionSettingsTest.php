<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Extension\ExtensionLoader;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Repository\OrganizationRepository;
use Gfh\Core\Repository\SettingsRepository;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Service\SettingsService;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TemplateTree;

final class ExtensionSettingsTest extends DatabaseTestCase
{
    private TemplateTree $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new TemplateTree();
    }

    protected function tearDown(): void
    {
        if (isset($this->tree)) {
            $this->tree->remove();
        }
        parent::tearDown();
    }

    public function testOnlySwitchedOnFeaturesAreListed(): void
    {
        $organization = $this->kit->organization('ext-feat');
        $insert = $this->core->prepare('INSERT INTO organization_features (organization_id, feature_key, enabled) VALUES (:organization, :feature, :enabled)');
        $insert->execute(['organization' => $organization->id, 'feature' => 'made_to_order', 'enabled' => 'true']);
        $insert->execute(['organization' => $organization->id, 'feature' => 'cold_chain', 'enabled' => 'false']);
        $insert->execute(['organization' => $organization->id, 'feature' => 'batches', 'enabled' => 'true']);

        $repository = new OrganizationRepository($this->core);

        $this->assertSame(['batches', 'made_to_order'], $repository->enabledFeatureKeys($organization->id));
        $this->assertSame([], $repository->enabledFeatureKeys('00000000-0000-0000-0000-000000000000'));
    }

    public function testAnExtensionSettingIsStoredReadBackAndSeenByTheExtension(): void
    {
        $this->tree->put('orgs/acme/extensions/cold-chain/extension.json', (string) json_encode([
            'key' => 'cold-chain',
            'name' => 'Cold chain',
            'version' => '1.0.0',
            'entry' => 'register.php',
            'hooks' => ['navigation.admin'],
            'settings' => ['cold_chain.max_temp' => ['default' => 8, 'min' => 0, 'max' => 30]],
        ]));
        $this->tree->put(
            'orgs/acme/extensions/cold-chain/register.php',
            '<?php return function ($x) { $x->hook("navigation.admin", fn (array $items) => [...$items, $x->setting("cold_chain.max_temp")]); };',
        );

        $settings = new SettingsService(new SettingsRepository($this->org), new AuditService($this->core), $this->org);
        $hooks = new Hooks();

        $report = (new ExtensionLoader($this->tree->root))->load('acme', $hooks, $settings, []);

        $this->assertSame(['loaded' => ['cold-chain'], 'failed' => []], $report);
        $this->assertSame(8, $settings->get('cold_chain.max_temp'), 'the default until a value is stored');
        $this->assertSame([8], $hooks->filter(Hooks::NAVIGATION_ADMIN, []));

        $settings->set('cold_chain.max_temp', 12, null);

        $this->assertSame(12, $settings->get('cold_chain.max_temp'));
        $this->assertSame([12], $hooks->filter(Hooks::NAVIGATION_ADMIN, []));

        $this->assertHttpStatus(422, fn () => $settings->set('cold_chain.max_temp', 99, null));
        $this->assertSame(12, $settings->get('cold_chain.max_temp'), 'a refused value changes nothing');
    }
}

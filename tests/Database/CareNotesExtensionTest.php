<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Extension\ExtensionLoader;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\AuditService;
use Gfh\Core\Service\SettingsService;
use Gfh\Core\Repository\SettingsRepository;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Organization;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;

/**
 * Batch 8.2: the care-notes demonstration extension, at HTTP level through the real App, router and middleware.
 *
 * The feature care_notes is switched on and off with the row in organization_features, exactly as the documented steps
 * do; every request builds its Services again, so the next request sees the new state.
 */
final class CareNotesExtensionTest extends DatabaseTestCase
{
    private const NOTE = 'Wipe with a damp cloth <b>only</b>';

    private App $app;
    private Organization $organization;
    private StaffUser $admin;
    private StaffUser $cashier;
    private string $slug;
    /** @var array{id: string, slug: string} */
    private array $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 2));
        $this->slug = 'care-' . bin2hex(random_bytes(3));
        $this->organization = $this->kit->organization($this->slug);
        $this->admin = $this->kit->staff('alma@care.test', TestKit::PASSWORD, null, 'active', 'Alma Admin');
        $this->kit->join($this->organization, $this->admin, 'admin');
        $this->cashier = $this->kit->staff('casey@care.test', TestKit::PASSWORD, null, 'active', 'Casey Cashier');
        $this->kit->join($this->organization, $this->cashier, 'cashier');
        $this->product = $this->shop->services->catalog()->createProduct([
            'name' => 'Linen Throw',
            'status' => 'active',
            'attributes' => ['colour' => 'Sand'],
            'variants' => [['sku' => 'LIN-' . bin2hex(random_bytes(2)), 'name' => 'Default', 'retail_price' => '900.00']],
        ], null);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function feature(bool $on): void
    {
        $this->core->prepare(
            'INSERT INTO organization_features (organization_id, feature_key, enabled) VALUES (:o, :f, :e)
             ON CONFLICT (organization_id, feature_key) DO UPDATE SET enabled = EXCLUDED.enabled',
        )->execute(['o' => $this->organization->id, 'f' => 'care_notes', 'e' => $on ? 'true' : 'false']);
    }

    /** @return array{services: Services, session: Session} */
    private function desk(?StaffUser $actor): array
    {
        $session = Session::inMemory('staff', $this->app->config(), $actor === null ? [] : ['user_id' => $actor->id]);

        return ['services' => new Services($this->app, false, $this->core, $this->org, testSessions: ['staff' => $session]), 'session' => $session];
    }

    /** @param array<string, string|array<int, string>> $post */
    private function admin(StaffUser $actor, string $method, string $path, array $post = []): Response
    {
        $desk = $this->desk($actor);
        if ($method === 'POST') {
            $post['_csrf'] = $desk['services']->csrf($desk['session'])->token();
        }

        return $this->app->handle(new Request($method, '/' . $this->slug . '/admin' . $path, post: $post), $desk['services']);
    }

    private function shopPage(): string
    {
        $response = $this->app->handle(new Request('GET', '/' . $this->slug . '/shop/products/' . $this->product['slug']), new Services($this->app, false, $this->core, $this->org));
        $this->assertSame(200, $response->status());

        return $response->body();
    }

    /** @param array<string, string|array<mixed>> $fields */
    private function save(array $fields, ?StaffUser $actor = null): Response
    {
        return $this->admin($actor ?? $this->admin, 'POST', '/products/' . $this->product['id'], $fields);
    }

    private function storedAttributes(): array
    {
        $statement = $this->org->prepare('SELECT attributes FROM products WHERE id = ?');
        $statement->execute([$this->product['id']]);

        return json_decode((string) $statement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------------ feature on

    public function testWithTheFeatureOnTheNavigationTabNoteAndShopSectionAllAppear(): void
    {
        $this->feature(true);

        $dashboard = $this->admin($this->admin, 'GET', '');
        $this->assertSame(200, $dashboard->status());
        $this->assertStringContainsString('>Care notes<', $dashboard->body(), 'the Admin navigation holds the item');

        $form = $this->admin($this->admin, 'GET', '/products/' . $this->product['id'] . '?tab=care-notes');
        $this->assertSame(200, $form->status());
        $this->assertStringContainsString('name="care_notes"', $form->body(), 'the product form shows the tab with its field');
        $this->assertStringContainsString('maxlength="200"', $form->body());

        $saved = $this->save(['care_notes' => '  ' . self::NOTE . '  ', 'tab' => 'care-notes']);
        $this->assertSame(302, $saved->status(), 'saving the note from its own tab succeeds');
        $this->assertStringContainsString('tab=care-notes', (string) $saved->header('Location'), 'the person stays on the tab');
        $this->assertSame(self::NOTE, $this->storedAttributes()['ext_care_notes']);

        $page = $this->shopPage();
        $this->assertStringContainsString('Care notes', $page);
        $this->assertStringContainsString('Wipe with a damp cloth &lt;b&gt;only&lt;/b&gt;', $page, 'the note is escaped');
        $this->assertStringNotContainsString('<b>only</b>', $page);
        $this->assertStringNotContainsString('ext_care_notes', $page, 'the attribute table never shows an extension attribute');

        $reopened = $this->admin($this->admin, 'GET', '/products/' . $this->product['id'] . '?tab=care-notes');
        $this->assertStringContainsString('value="Wipe with a damp cloth &lt;b&gt;only&lt;/b&gt;"', $reopened->body());
        $this->assertStringNotContainsString('ext_care_notes', $reopened->body(), 'the Admin attribute list never shows it either');
    }

    public function testAShopPageWithoutANoteHasNoSectionAndEmptyingTheNoteRemovesIt(): void
    {
        $this->feature(true);
        $this->assertStringNotContainsString('Care notes', $this->shopPage(), 'no note: the section prints nothing');

        $this->save(['care_notes' => 'Machine wash cold', 'tab' => 'care-notes']);
        $this->assertStringContainsString('Machine wash cold', $this->shopPage());

        $this->assertSame(302, $this->save(['care_notes' => '   ', 'tab' => 'care-notes'])->status());
        $this->assertArrayNotHasKey('ext_care_notes', $this->storedAttributes());
        $this->assertStringNotContainsString('Care notes', $this->shopPage());
    }

    public function testTheTabSavesOnlyTheNoteAndTheDetailsFormKeepsTheNote(): void
    {
        $this->feature(true);
        $this->save(['care_notes' => 'Dry flat', 'tab' => 'care-notes']);

        $product = $this->shop->services->catalog()->getProduct($this->product['id']);
        $this->assertSame('Linen Throw', $product['name'], 'a tab that posts one field leaves the other fields alone');
        $this->assertSame('Sand', $this->storedAttributes()['colour']);

        // The Details form posts its own fields and attribute rows, never the note.
        $this->save(['name' => 'Linen Throw 2', 'description' => '', 'brand_id' => '', 'category_id' => '', 'supplier_id' => '', 'attributes' => ['colour' => 'Oat']]);
        $stored = $this->storedAttributes();
        $this->assertSame('Oat', $stored['colour']);
        $this->assertSame('Dry flat', $stored['ext_care_notes'], 'saving the Details form does not erase the note');
    }

    public function testATooLongNoteIsRefusedWithAMessageAndKeepsTheOldNote(): void
    {
        $this->feature(true);
        $this->save(['care_notes' => 'Original', 'tab' => 'care-notes']);

        $tooLong = str_repeat('a', 201);
        $response = $this->save(['care_notes' => $tooLong, 'tab' => 'care-notes']);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('Enter up to 200 characters.', $response->body());
        $this->assertStringContainsString($tooLong, $response->body(), 'the rejected text is shown again');
        $this->assertSame('Original', $this->storedAttributes()['ext_care_notes']);

        $this->assertSame(422, $this->save(["care_notes" => "bad\x07note", 'tab' => 'care-notes'])->status(), 'control characters are refused');
        $this->assertSame(302, $this->save(['care_notes' => str_repeat('é', 200), 'tab' => 'care-notes'])->status(), '200 characters are allowed, counted as characters');
    }

    public function testTheSectionHeadingIsADeclaredSetting(): void
    {
        $this->feature(true);
        $this->save(['care_notes' => 'Dry flat', 'tab' => 'care-notes']);
        $this->assertStringContainsString('>Care notes<', $this->shopPage());

        $settings = new SettingsService(new SettingsRepository($this->org), new AuditService($this->core), $this->org);
        $report = (new ExtensionLoader(dirname(__DIR__, 2)))->load($this->slug, new Hooks(), $settings, ['care_notes']);
        $this->assertSame(['care-notes'], $report['loaded']);
        $settings->set('care_notes.section_title', 'How to look after it', null);

        $page = $this->shopPage();
        $this->assertStringContainsString('How to look after it', $page);
        $this->assertStringNotContainsString('>Care notes<', $page);
    }

    public function testPeopleWithoutTheEditPermissionDoNotSeeTheNavigationItem(): void
    {
        $this->feature(true);

        $cashier = $this->admin($this->cashier, 'GET', '');
        $this->assertStringNotContainsString('>Care notes<', $cashier->body());
        $this->assertStringContainsString('>Care notes<', $this->admin($this->admin, 'GET', '')->body());
        $this->assertContains($this->save(['care_notes' => 'x', 'tab' => 'care-notes'], $this->cashier)->status(), [302, 403], 'a cashier is stopped by the existing permission check');
        $this->assertArrayNotHasKey('ext_care_notes', $this->storedAttributes());
    }

    // ------------------------------------------------------------------ feature off

    public function testWithTheFeatureOffNoneOfTheThreeAppearsAndAnEarlierNoteIsKeptAndHidden(): void
    {
        $this->feature(true);
        $this->save(['care_notes' => 'Keep out of the sun', 'tab' => 'care-notes']);
        $this->assertStringContainsString('Keep out of the sun', $this->shopPage());

        $this->feature(false);

        $this->assertStringNotContainsString('>Care notes<', $this->admin($this->admin, 'GET', '')->body(), 'no navigation item');
        $form = $this->admin($this->admin, 'GET', '/products/' . $this->product['id'] . '?tab=care-notes');
        $this->assertSame(200, $form->status(), 'an unknown tab name falls back to Details');
        $this->assertStringNotContainsString('name="care_notes"', $form->body(), 'no tab');
        $page = $this->shopPage();
        $this->assertStringNotContainsString('Keep out of the sun', $page, 'no section, and the note is nowhere on the page');
        $this->assertStringNotContainsString('Care notes', $page);
        $this->assertSame('Keep out of the sun', $this->storedAttributes()['ext_care_notes'], 'the note is kept');

        // A Details save while the feature is off keeps it too.
        $this->save(['name' => 'Linen Throw', 'description' => '', 'brand_id' => '', 'category_id' => '', 'supplier_id' => '', 'attributes' => ['colour' => 'Sand']]);
        $this->assertSame('Keep out of the sun', $this->storedAttributes()['ext_care_notes']);

        $this->feature(true);
        $this->assertStringContainsString('Keep out of the sun', $this->shopPage(), 'switched on again, the note is back');
    }

    public function testTheSwitchedOffFeatureDoesNotAcceptANoteEither(): void
    {
        $this->feature(false);
        $this->assertSame(302, $this->save(['care_notes' => 'Should not stick', 'tab' => 'care-notes'])->status());
        $this->assertArrayNotHasKey('ext_care_notes', $this->storedAttributes(), 'without the extension there is no hook that stores the field');
    }

    // ------------------------------------------------------------------ the ext_ convention in core

    public function testExtensionAttributesAreNotPublicFiltersNorPartOfTheApiAnswer(): void
    {
        $this->feature(true);
        $this->save(['care_notes' => 'Dry flat', 'tab' => 'care-notes']);

        $listing = $this->app->handle(new Request('GET', '/' . $this->slug . '/shop/products'), new Services($this->app, false, $this->core, $this->org));
        $this->assertSame(200, $listing->status());
        $this->assertStringNotContainsString('ext_care_notes', $listing->body());
        $this->assertStringNotContainsString('Ext care notes', $listing->body());

        $api = $this->app->handle(new Request('GET', '/' . $this->slug . '/api/v1/catalog/products/' . $this->product['slug']), new Services($this->app, false, $this->core, $this->org));
        $this->assertSame(200, $api->status());
        $this->assertStringNotContainsString('ext_care_notes', $api->body());
        $this->assertStringContainsString('"colour"', $api->body(), 'the real attributes are still there');
    }

    public function testANameStartingWithExtIsReservedInTheAdminAttributeList(): void
    {
        $response = $this->save(['name' => 'Linen Throw', 'description' => '', 'brand_id' => '', 'category_id' => '', 'supplier_id' => '', 'attributes' => ['colour' => 'Sand'], 'attr_new_key' => ['ext_sneaky'], 'attr_new_val' => ['x']]);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('are kept for extensions', $response->body());
        $this->assertArrayNotHasKey('ext_sneaky', $this->storedAttributes());
    }
}

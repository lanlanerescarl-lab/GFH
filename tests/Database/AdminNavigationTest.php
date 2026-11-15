<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\AdminNavigationService;
use Gfh\Core\View\AppView;
use Gfh\Core\View\AssetUrls;
use Gfh\Core\View\TemplateLocator;
use Gfh\Tests\Support\DatabaseTestCase;

/** The Admin sidebar shows only what PermissionService::can() allows, and extensions extend it through navigation.admin. */
final class AdminNavigationTest extends DatabaseTestCase
{
    /** @return list<string> */
    private function keys(AdminNavigationService $navigation, \Gfh\Core\Auth\StaffUser $user, \Gfh\Core\Tenancy\Organization $organization, string $current = ''): array
    {
        return array_column($navigation->items($user, $organization, '', $current), 'key');
    }

    public function testTheSidebarFollowsThePermissionMatrix(): void
    {
        $organization = $this->kit->organization('nav-a');
        $navigation = new AdminNavigationService($this->kit->permissions, new Hooks());

        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $staff = $this->kit->member($organization, 'staff@example.test', 'staff');
        $cashier = $this->kit->member($organization, 'cashier@example.test', 'cashier');

        $this->assertSame(array_column(AdminNavigationService::ITEMS, 'key'), $this->keys($navigation, $admin, $organization));

        $staffKeys = $this->keys($navigation, $staff, $organization);
        $this->assertContains('inventory', $staffKeys);
        $this->assertNotContains('reports', $staffKeys, 'staff have no reports_admin view');
        $this->assertNotContains('team', $staffKeys);
        $this->assertNotContains('settings', $staffKeys);

        // A cashier works in the POS: the default matrix gives no Admin module at all.
        $this->assertSame([], $this->keys($navigation, $cashier, $organization));
    }

    public function testEveryShownItemIsOneTheUserMayView(): void
    {
        $organization = $this->kit->organization('nav-b');
        $navigation = new AdminNavigationService($this->kit->permissions, new Hooks(), true);
        $modules = array_column([...AdminNavigationService::ITEMS, AdminNavigationService::UI_KIT_ITEM], 'module', 'key');

        foreach (['admin', 'manager', 'staff', 'cashier'] as $role) {
            $user = $this->kit->member($organization, $role . '@example.test', $role);
            foreach ($this->keys($navigation, $user, $organization) as $key) {
                $this->assertTrue($this->kit->permissions->can($user, $organization, $modules[$key], 'view'), "$role sees $key");
            }
        }
    }

    public function testNobodyFromAnotherOrganizationGetsASidebar(): void
    {
        $organization = $this->kit->organization('nav-c');
        $other = $this->kit->organization('nav-d');
        $outsider = $this->kit->member($other, 'outsider@example.test', 'admin');

        $this->assertSame([], $this->keys(new AdminNavigationService($this->kit->permissions, new Hooks()), $outsider, $organization));
    }

    public function testTheUiKitLinkIsOptional(): void
    {
        $organization = $this->kit->organization('nav-e');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');

        $this->assertNotContains('ui-kit', $this->keys(new AdminNavigationService($this->kit->permissions, new Hooks()), $admin, $organization));
        $this->assertContains('ui-kit', $this->keys(new AdminNavigationService($this->kit->permissions, new Hooks(), true), $admin, $organization));
    }

    public function testAnExtensionAddsItemsThroughTheHookAndTheyArePermissionChecked(): void
    {
        $organization = $this->kit->organization('nav-f');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $staff = $this->kit->member($organization, 'staff@example.test', 'staff');

        $hooks = new Hooks();
        $hooks->add(Hooks::NAVIGATION_ADMIN, static function (array $items, array $context): array {
            $items[] = ['key' => 'cold-chain', 'label' => 'Cold chain', 'url' => $context['base_url'] . '/cold-chain', 'group' => 'Stock', 'module' => 'inventory'];
            $items[] = ['key' => 'payroll', 'label' => 'Payroll', 'url' => $context['base_url'] . '/payroll', 'module' => 'reports_admin'];
            // The context can() lets an extension decide for itself, too.
            if (($context['can'])('products', 'edit')) {
                $items[] = ['key' => 'price-lists', 'label' => 'Price lists', 'url' => $context['base_url'] . '/price-lists'];
            }
            $items[] = ['key' => 'bad-1', 'label' => 'Outside', 'url' => 'https://evil.example/x'];
            $items[] = ['key' => 'bad-2', 'label' => 'Protocol relative', 'url' => '//evil.example/x'];
            $items[] = ['key' => 'bad-3', 'label' => 'Script', 'url' => 'javascript:alert(1)'];
            $items[] = ['key' => 'bad-4', 'label' => '   ', 'url' => '/nav-f/admin/blank'];
            $items[] = 'not an item';

            return $items;
        });
        $navigation = new AdminNavigationService($this->kit->permissions, $hooks);

        $adminKeys = $this->keys($navigation, $admin, $organization);
        $this->assertContains('cold-chain', $adminKeys);
        $this->assertContains('payroll', $adminKeys);
        $this->assertContains('price-lists', $adminKeys);
        foreach (['bad-1', 'bad-2', 'bad-3', 'bad-4'] as $bad) {
            $this->assertNotContains($bad, $adminKeys);
        }

        $staffKeys = $this->keys($navigation, $staff, $organization);
        $this->assertContains('cold-chain', $staffKeys, 'staff may view inventory');
        $this->assertNotContains('payroll', $staffKeys, 'an extension item is checked with can() too');
        $this->assertNotContains('price-lists', $staffKeys);
    }

    public function testAnExtensionCanRemoveABuiltInItem(): void
    {
        $organization = $this->kit->organization('nav-g');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');

        $hooks = new Hooks();
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): array => array_values(array_filter($items, static fn (array $item): bool => $item['key'] !== 'procurement')));

        $this->assertNotContains('procurement', $this->keys(new AdminNavigationService($this->kit->permissions, $hooks), $admin, $organization));
    }

    public function testTheCurrentPageIsMarked(): void
    {
        $organization = $this->kit->organization('nav-h');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $navigation = new AdminNavigationService($this->kit->permissions, new Hooks());

        $current = static fn (array $items): array => array_column(array_filter($items, static fn (array $i): bool => $i['current']), 'key');

        $this->assertSame(['products'], $current($navigation->items($admin, $organization, '', '/nav-h/admin/products/12/edit')));
        $this->assertSame(['dashboard'], $current($navigation->items($admin, $organization, '', '/nav-h/admin')));
        $this->assertSame([], $current($navigation->items($admin, $organization, '', '/nav-h/admin/account')), 'the dashboard is not a catch-all');
        $this->assertSame(['inventory'], $current($navigation->items($admin, $organization, '/gfh', '/gfh/nav-h/admin/inventory')));
    }

    public function testTheAdminLayoutPrintsTheFilteredSidebar(): void
    {
        $organization = $this->kit->organization('nav-i');
        $staff = $this->kit->member($organization, 'staff@example.test', 'staff');
        $items = (new AdminNavigationService($this->kit->permissions, new Hooks()))->items($staff, $organization, '', '/nav-i/admin/inventory');

        $project = dirname(__DIR__, 2);
        $view = new AppView(new TemplateLocator($project), 'nav-i', 'admin', [
            'organizationName' => 'Nav I',
            'homeUrl' => '/nav-i/admin',
            'assets' => new AssetUrls($project . '/public', '', 'nav-i', 'admin'),
        ]);
        $html = $view->partial('partials/nav', ['navigation' => $items]);

        $this->assertStringContainsString('<a href="/nav-i/admin/inventory" aria-current="page">Inventory</a>', $html);
        $this->assertStringContainsString('>Stock</p>', $html, 'items are grouped');
        $this->assertStringNotContainsString('/nav-i/admin/team', $html);
        $this->assertStringNotContainsString('/nav-i/admin/settings', $html);
    }
}

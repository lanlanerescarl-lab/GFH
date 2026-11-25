<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\ShopAccountMenuService;
use PHPUnit\Framework\TestCase;

/**
 * The account.menu hook: the built-in items are the default, extensions add after them, a broken item is dropped, and
 * a callback that throws when the hook runs (the extension loader only catches failures while an entry file loads)
 * leaves the built-in menu in place.
 */
final class ShopAccountMenuServiceTest extends TestCase
{
    private const BUILT_IN = ['Profile', 'Addresses', 'Password', 'My orders'];

    private function items(Hooks $hooks, string $path = '/acme/shop/account'): array
    {
        return (new ShopAccountMenuService($hooks))->items('', 'acme', $path);
    }

    public function testTheHookIsRegisteredByName(): void
    {
        $this->assertContains('account.menu', Hooks::NAMES);
        $this->assertSame('account.menu', Hooks::ACCOUNT_MENU);
    }

    public function testWithNothingRegisteredTheMenuIsTheBuiltInItems(): void
    {
        $items = $this->items(new Hooks());

        $this->assertSame(self::BUILT_IN, array_column($items, 'label'));
        $this->assertSame(['/acme/shop/account/profile', '/acme/shop/account/addresses', '/acme/shop/account/password', '/acme/shop/orders'], array_column($items, 'url'));
        $this->assertSame([false, false, false, false], array_column($items, 'current'));
    }

    public function testTheBasePathIsPartOfEveryAddress(): void
    {
        $items = (new ShopAccountMenuService(new Hooks()))->items('/app', 'acme', '/app/acme/shop/account/password');

        $this->assertSame('/app/acme/shop/account/profile', $items[0]['url']);
        $this->assertSame([false, false, true, false], array_column($items, 'current'));
    }

    public function testTheCurrentPageIsMarkedAndAnAddressEditFormBelongsToAddresses(): void
    {
        $this->assertSame([false, true, false, false], array_column($this->items(new Hooks(), '/acme/shop/account/addresses'), 'current'));
        $this->assertSame([false, true, false, false], array_column($this->items(new Hooks(), '/acme/shop/account/addresses/00000000-0000-4000-8000-000000000001/edit'), 'current'));
        $this->assertSame([false, false, false, true], array_column($this->items(new Hooks(), '/acme/shop/orders/abc'), 'current'));
    }

    public function testAnExtensionItemComesAfterTheBuiltInOnesAndGetsOnlyText(): void
    {
        $hooks = new Hooks();
        $received = null;
        $hooks->add(Hooks::ACCOUNT_MENU, static function (array $items, array $context) use (&$received): array {
            $received = ['items' => $items, 'context' => $context];

            return [...$items, ['label' => ' Rewards ', 'url' => $context['base_url'] . '/rewards'], ['label' => 'Pinned', 'url' => '/acme/shop/x', 'current' => true]];
        });

        $items = $this->items($hooks, '/acme/shop/rewards');

        $this->assertSame([...self::BUILT_IN, 'Rewards', 'Pinned'], array_column($items, 'label'));
        $this->assertSame(['organization' => 'acme', 'base_url' => '/acme/shop'], $received['context'], 'text values only');
        $this->assertSame(self::BUILT_IN, array_column($received['items'], 'label'), 'the value starts as the built-in items');
        $this->assertTrue($items[4]['current'], 'current when its address is the page');
        $this->assertTrue($items[5]['current'], 'or when the extension says so');
        $this->assertFalse($items[0]['current']);
    }

    public function testABrokenItemIsDropped(): void
    {
        $hooks = new Hooks();
        $hooks->add(Hooks::ACCOUNT_MENU, static fn (array $items): array => [
            ...$items,
            ['label' => 'Away', 'url' => 'https://evil.test/x'],
            ['label' => 'Away too', 'url' => '//evil.test/x'],
            ['label' => 'Script', 'url' => 'javascript:alert(1)'],
            ['label' => 'Back', 'url' => '/a\\b'],
            ['label' => 'Spaced', 'url' => '/a b'],
            ['label' => '   ', 'url' => '/blank'],
            ['label' => 'No url'],
            ['url' => '/no-label'],
            ['label' => 5, 'url' => '/number'],
            'junk',
            null,
        ]);

        $this->assertSame(self::BUILT_IN, array_column($this->items($hooks), 'label'));
    }

    public function testACallbackThatReturnsNotAnArrayLeavesNoMenuItemsButDoesNotBreak(): void
    {
        $hooks = new Hooks();
        $hooks->add(Hooks::ACCOUNT_MENU, static fn (array $items): string => 'oops');

        $this->assertSame([], $this->items($hooks));
    }

    public function testACallbackThatThrowsWhenTheHookRunsLeavesTheBuiltInMenu(): void
    {
        $hooks = new Hooks();
        $hooks->add(Hooks::ACCOUNT_MENU, static function (array $items): array {
            throw new \RuntimeException('extension bug');
        });
        $hooks->add(Hooks::ACCOUNT_MENU, static function (array $items): array {
            throw new \TypeError('type bug');
        }, 20);

        $this->assertSame(self::BUILT_IN, array_column($this->items($hooks), 'label'));
    }
}

<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Hooks\Hooks;
use PHPUnit\Framework\TestCase;

final class HooksTest extends TestCase
{
    public function testAHookWithNothingRegisteredPassesTheValueThrough(): void
    {
        $hooks = new Hooks();

        $this->assertSame(['a'], $hooks->filter(Hooks::NAVIGATION_ADMIN, ['a']));
        $this->assertFalse($hooks->has(Hooks::NAVIGATION_ADMIN));
    }

    public function testCallbacksRunInPriorityOrderAndReceiveTheContext(): void
    {
        $hooks = new Hooks();
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items, array $context): array => [...$items, 'late:' . $context['who']], 20);
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): array => [...$items, 'early'], 5);

        $this->assertSame(['base', 'early', 'late:me'], $hooks->filter(Hooks::NAVIGATION_ADMIN, ['base'], ['who' => 'me']));
    }

    public function testUnknownHookNamesAreRefused(): void
    {
        $hooks = new Hooks();

        $this->expectException(\InvalidArgumentException::class);
        $hooks->add('navigation.adnim', static fn (mixed $v): mixed => $v);
    }
}

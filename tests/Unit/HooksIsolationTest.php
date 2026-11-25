<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Extension\ExtensionLoader;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\HttpException;
use Gfh\Core\Service\SettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Batch 8.3 Part A: a callback that throws when a hook runs is caught, logged once and skipped; an HttpException
 * still passes; a callback that changes the type of the value is ignored. Nothing registered changes nothing.
 */
final class HooksIsolationTest extends TestCase
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $logged = [];

    private function hooks(): Hooks
    {
        $this->logged = [];

        return new Hooks(function (string $message, array $context): void {
            $this->logged[] = ['message' => $message, 'context' => $context];
        });
    }

    /** @return iterable<string, array{string}> */
    public static function everyHookName(): iterable
    {
        foreach (Hooks::NAMES as $name) {
            yield $name => [$name];
        }
    }

    /** @return iterable<string, array{\Closure}> */
    public static function throwables(): iterable
    {
        yield 'RuntimeException' => [static fn (): never => throw new \RuntimeException('boom')];
        yield 'LogicException' => [static fn (): never => throw new \LogicException('boom')];
        yield 'InvalidArgumentException' => [static fn (): never => throw new \InvalidArgumentException('boom')];
        yield 'Error' => [static fn (): never => throw new \Error('boom')];
        yield 'TypeError from a wrong signature' => [static fn (string $notAnArray): array => []];
        yield 'ArgumentCountError' => [static fn (array $a, array $b, array $c): array => $a];
        yield 'DivisionByZeroError' => [static fn (): int => intdiv(1, 0)];
        yield 'ErrorException from a warning turned into an exception' => [static fn (): never => throw new \ErrorException('Undefined array key "x"', 0, E_WARNING)];
        yield 'JsonException' => [static fn (): mixed => json_decode('{', true, 512, JSON_THROW_ON_ERROR)];
        yield 'PDOException' => [static fn (): never => throw new \PDOException('SQLSTATE[42P01]: Undefined table')];
    }

    /** @return iterable<string, array{mixed}> */
    public static function wrongTypes(): iterable
    {
        yield 'null' => [null];
        yield 'a string' => ['not a list'];
        yield 'an int' => [3];
        yield 'a float' => [1.5];
        yield 'a bool' => [false];
        yield 'an object' => [new \stdClass()];
    }

    // ---------------------------------------------------------------- nothing registered, nothing thrown

    #[DataProvider('everyHookName')]
    public function testNothingRegisteredLeavesEveryValueUnchanged(string $name): void
    {
        $hooks = $this->hooks();

        foreach ([[], ['a', 'b'], ['k' => ['x' => 1]], 'text', 7, null] as $value) {
            $this->assertSame($value, $hooks->filter($name, $value, ['any' => 'context']));
        }
        $this->assertSame([], $this->logged);
    }

    public function testWhenNothingThrowsTheResultIsExactlyWhatTheCallbacksReturn(): void
    {
        $hooks = $this->hooks();
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): array => [...$items, 'late'], 20);
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): array => [...$items, 'early'], 5);
        // A list may come back as a map and an empty array as a full one: both are still an array.
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): array => ['k' => $items], 30);

        $this->assertSame(['k' => ['base', 'early', 'late']], $hooks->filter(Hooks::NAVIGATION_ADMIN, ['base']));
        $this->assertSame([], $this->logged);
    }

    public function testNoHookMayChangeTheTypeOfItsValueToday(): void
    {
        $this->assertSame([], Hooks::MAY_CHANGE_TYPE, 'docs/extensions.md says no hook may change type; change both together');
    }

    // ---------------------------------------------------------------- a callback that throws

    #[DataProvider('throwables')]
    public function testAThrowingCallbackIsSkippedAndTheValueFromBeforeItIsKept(\Closure $broken): void
    {
        $hooks = $this->hooks();
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): array => [...$items, 'first'], 5);
        $hooks->add(Hooks::NAVIGATION_ADMIN, $broken, 10);
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): array => [...$items, 'last'], 20);

        $this->assertSame(['base', 'first', 'last'], $hooks->filter(Hooks::NAVIGATION_ADMIN, ['base']));
        $this->assertCount(1, $this->logged);
    }

    #[DataProvider('everyHookName')]
    public function testEveryHookIsolatesAThrowingCallback(string $name): void
    {
        $hooks = $this->hooks();
        $hooks->add($name, static fn (array $v): never => throw new \RuntimeException('boom'));
        $hooks->add($name, static fn (array $v): array => [...$v, 'after'], 50);

        $this->assertSame(['x', 'after'], $hooks->filter($name, ['x']));
    }

    public function testAnHttpExceptionPassesThroughWithItsStatusAndFields(): void
    {
        $hooks = $this->hooks();
        $ran = [];
        $hooks->add(Hooks::PRODUCT_FORM_SAVE, static function (array $data) use (&$ran): array {
            $ran[] = 'validator';
            throw HttpException::unprocessable(['care_notes' => 'The note is too long.']);
        }, 5);
        $hooks->add(Hooks::PRODUCT_FORM_SAVE, static function (array $data) use (&$ran): array {
            $ran[] = 'later';

            return $data;
        }, 10);

        try {
            $hooks->filter(Hooks::PRODUCT_FORM_SAVE, ['name' => 'x']);
            $this->fail('the HttpException must reach the caller');
        } catch (HttpException $refusal) {
            $this->assertSame(422, $refusal->status());
            $this->assertSame(['care_notes' => 'The note is too long.'], $refusal->fields());
        }
        $this->assertSame(['validator'], $ran, 'a refusal ends the hook; later callbacks do not run');
        $this->assertSame([], $this->logged, 'a refusal is not a failure and is not logged');
    }

    public function testEveryHttpExceptionStatusPassesThrough(): void
    {
        foreach ([HttpException::badRequest(), HttpException::unauthorized(), HttpException::unprocessable(['a' => 'b'])] as $refusal) {
            $hooks = $this->hooks();
            $hooks->add(Hooks::STOCK_PICK_STRATEGY, static fn (array $picks): array => throw $refusal);

            try {
                $hooks->filter(Hooks::STOCK_PICK_STRATEGY, []);
                $this->fail('expected the HttpException');
            } catch (HttpException $caught) {
                $this->assertSame($refusal, $caught);
            }
        }
    }

    // ---------------------------------------------------------------- the log entry

    public function testTheLogEntryNamesTheHookTheExtensionTheClassAndTheMessageOnly(): void
    {
        $hooks = $this->hooks();
        $hooks->add(Hooks::NAVIGATION_SHOP, static fn (array $items, array $context): array => throw new \DomainException('the menu exploded'), 10, 'menu-pack');

        $hooks->filter(Hooks::NAVIGATION_SHOP, [['label' => 'Shop']], ['customer_note' => 'FAKE-CONTEXT-VALUE']);

        $this->assertCount(1, $this->logged);
        $entry = $this->logged[0];
        $this->assertStringContainsString('navigation.shop', $entry['message']);
        $this->assertStringContainsString('menu-pack', $entry['message']);
        $this->assertStringContainsString('DomainException', $entry['message']);
        $this->assertStringContainsString('the menu exploded', $entry['message']);
        $this->assertSame('navigation.shop', $entry['context']['hook']);
        $this->assertSame('menu-pack', $entry['context']['extension']);

        $all = json_encode($this->logged, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('FAKE-CONTEXT-VALUE', $all, 'the context of the hook is never logged');
        $this->assertDoesNotMatchRegularExpression('/#\d+ |Stack trace|\.php:\d+\)/', $entry['message'], 'no stack trace');
    }

    public function testACallbackWithoutAnExtensionIsNamedByItsClosureOrMethod(): void
    {
        $hooks = $this->hooks();
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): never => throw new \RuntimeException('x'));
        $hooks->add(Hooks::NAVIGATION_ADMIN, [self::class, 'brokenStatic']);
        $hooks->filter(Hooks::NAVIGATION_ADMIN, []);

        $this->assertCount(2, $this->logged);
        $this->assertStringContainsString('closure in HooksIsolationTest.php:', $this->logged[0]['message']);
        $this->assertStringContainsString(self::class . '::brokenStatic', $this->logged[1]['message']);
        $this->assertNull($this->logged[0]['context']['extension']);
    }

    /** @param array<mixed> $items */
    public static function brokenStatic(array $items): never
    {
        throw new \RuntimeException('static callback failed');
    }

    public function testTheSameCallbackIsLoggedOncePerRequestNoMatterHowOftenTheHookRuns(): void
    {
        $hooks = $this->hooks();
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): never => throw new \RuntimeException('again'), 10, 'noisy');

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(['a'], $hooks->filter(Hooks::NAVIGATION_ADMIN, ['a']));
        }
        $this->assertCount(1, $this->logged);

        // A second broken callback of the same extension is a second callback and gets its own entry.
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): never => throw new \RuntimeException('other'), 11, 'noisy');
        $hooks->filter(Hooks::NAVIGATION_ADMIN, []);
        $hooks->filter(Hooks::NAVIGATION_ADMIN, []);
        $this->assertCount(2, $this->logged);

        // Another request builds another registry, which reports again.
        $second = $this->hooks();
        $second->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): never => throw new \RuntimeException('again'), 10, 'noisy');
        $second->filter(Hooks::NAVIGATION_ADMIN, []);
        $this->assertCount(1, $this->logged);
    }

    public function testTheMessageIsOneShortLineWithoutCredentials(): void
    {
        $hooks = $this->hooks();
        $message = "connect failed for postgresql://app_user:FAKE-NOT-A-SECRET@db.internal:5432/x\npassword=FAKE-PASSWORD-VALUE token: FAKE-TOKEN-VALUE " . str_repeat('long ', 200);
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): never => throw new \RuntimeException($message), 10, 'leaky');

        $hooks->filter(Hooks::NAVIGATION_ADMIN, []);

        $line = $this->logged[0]['message'];
        foreach (['FAKE-NOT-A-SECRET', 'FAKE-PASSWORD-VALUE', 'FAKE-TOKEN-VALUE'] as $secret) {
            $this->assertStringNotContainsString($secret, $line);
        }
        $this->assertStringContainsString('postgresql://***@db.internal', $line);
        $this->assertStringNotContainsString("\n", $line);
        $this->assertLessThan(450, mb_strlen($line), 'the exception message is cut at 300 characters');
    }

    public function testABrokenLogNeverBreaksTheHook(): void
    {
        $hooks = new Hooks(static function (): never {
            throw new \RuntimeException('log is down');
        });
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): never => throw new \RuntimeException('x'));
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): array => [...$items, 'ok'], 20);

        $this->assertSame(['ok'], $hooks->filter(Hooks::NAVIGATION_ADMIN, []));
    }

    public function testWithoutALogTheCallbackIsStillSkipped(): void
    {
        $hooks = new Hooks();
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): never => throw new \RuntimeException('x'));
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): array => [...$items, 'ok'], 20);

        $this->assertSame(['ok'], $hooks->filter(Hooks::NAVIGATION_ADMIN, []));
    }

    // ---------------------------------------------------------------- a callback with the wrong return type

    #[DataProvider('wrongTypes')]
    public function testAResultOfAnotherTypeIsIgnoredAndLoggedOnce(mixed $wrong): void
    {
        $hooks = $this->hooks();
        $seen = [];
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): array => [...$items, 'first'], 5);
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (array $items): mixed => $wrong, 10, 'sloppy');
        $hooks->add(Hooks::NAVIGATION_ADMIN, static function (array $items) use (&$seen): array {
            $seen = $items;

            return [...$items, 'last'];
        }, 20);

        $this->assertSame(['base', 'first', 'last'], $hooks->filter(Hooks::NAVIGATION_ADMIN, ['base']));
        $this->assertSame(['base', 'first'], $seen, 'the next callback gets the value from before the bad one');
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('sloppy', $this->logged[0]['message']);
        $this->assertStringContainsString('where array was expected', $this->logged[0]['message']);

        $hooks->filter(Hooks::NAVIGATION_ADMIN, ['base']);
        $this->assertCount(1, $this->logged, 'still once');
    }

    public function testTheTypeIsComparedWithTheValueTheCallbackReceived(): void
    {
        $hooks = $this->hooks();
        // A string stays a string, so this is fine even though no list is involved.
        $hooks->add(Hooks::NAVIGATION_ADMIN, static fn (string $v): string => $v . '!');
        $this->assertSame('hi!', $hooks->filter(Hooks::NAVIGATION_ADMIN, 'hi'));
        $this->assertSame([], $this->logged);

        // And an array may not become a string.
        $other = $this->hooks();
        $other->add(Hooks::NAVIGATION_ADMIN, static fn (array $v): string => 'x');
        $this->assertSame(['a'], $other->filter(Hooks::NAVIGATION_ADMIN, ['a']));
        $this->assertCount(1, $this->logged);
    }

    // ---------------------------------------------------------------- the loader names the extension

    public function testTheLoaderRegistersEachCallbackUnderItsExtensionKey(): void
    {
        $root = dirname(__DIR__) . '/fixtures/extension-root';
        $this->logged = [];
        $hooks = $this->hooks();
        $settings = (new \ReflectionClass(SettingsService::class))->newInstanceWithoutConstructor();

        $report = (new ExtensionLoader($root))->load('acme', $hooks, $settings, ['throwing_hooks']);
        $this->assertSame(['throwing-hooks'], $report['loaded']);

        $items = $hooks->filter(Hooks::NAVIGATION_ADMIN, [['label' => 'Base', 'url' => '/x']]);
        $this->assertSame(['Base', 'Before', 'After'], array_column($items, 'label'));
        $this->assertCount(1, $this->logged);
        $this->assertSame('throwing-hooks', $this->logged[0]['context']['extension']);
        $this->assertStringContainsString('fixture callback exploded', $this->logged[0]['message']);
    }
}

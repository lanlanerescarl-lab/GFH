<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Repository\PosRepository;
use Gfh\Core\Service\PosReceiptLineService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Batch 8.3b: the rules a pos.receipt.lines callback's lines must obey before they reach a terminal's snapshot. */
final class PosReceiptLineServiceTest extends TestCase
{
    private const A = '11111111-1111-4111-8111-111111111111';
    private const B = '22222222-2222-4222-8222-222222222222';
    private const STRANGER = '33333333-3333-4333-8333-333333333333';

    /** @var list<string> */
    private array $problems = [];

    /** @param list<mixed> $raw */
    private function normalize(array $raw): array
    {
        $this->problems = [];

        return PosReceiptLineService::normalize($raw, [self::A, self::B], function (string $reason): void {
            $this->problems[] = $reason;
        });
    }

    /** @return iterable<string, array{mixed}> */
    public static function badLines(): iterable
    {
        yield 'not an object' => ['Warranty: 12 months'];
        yield 'a list instead of an object' => [['Warranty']];
        yield 'no text' => [['variant_id' => self::A]];
        yield 'empty text' => [['variant_id' => self::A, 'text' => '']];
        yield 'blank text' => [['variant_id' => self::A, 'text' => "   \t "]];
        yield 'text that is not a string' => [['variant_id' => self::A, 'text' => 12]];
        yield 'text as a list' => [['variant_id' => self::A, 'text' => ['Warranty']]];
        yield '121 characters' => [['variant_id' => self::A, 'text' => str_repeat('x', 121)]];
        yield '121 multibyte characters' => [['variant_id' => self::A, 'text' => str_repeat('é', 121)]];
        yield 'a line break' => [['variant_id' => self::A, 'text' => "Warranty\n12 months"]];
        yield 'a carriage return' => [['variant_id' => self::A, 'text' => "Warranty\r12 months"]];
        yield 'a tab' => [['variant_id' => self::A, 'text' => "Warranty:\t12 months"]];
        // A NUL at the very edge is trimmed away with the spaces (the result is clean); one inside the text is refused.
        yield 'a null byte inside the text' => [['variant_id' => self::A, 'text' => "War\0ranty"]];
        yield 'an escape character' => [['variant_id' => self::A, 'text' => "Warranty\x1B[31m"]];
        yield 'bad UTF-8' => [['variant_id' => self::A, 'text' => "caf\xE9"]];
        yield 'a tag' => [['variant_id' => self::A, 'text' => 'Warranty <b>12</b> months']];
        yield 'a script' => [['variant_id' => self::A, 'text' => '<script>alert(1)</script>']];
        yield 'a closing tag' => [['variant_id' => self::A, 'text' => 'x </p>']];
        yield 'a comment' => [['variant_id' => self::A, 'text' => 'x <!-- y -->']];
        yield 'a variant that is not in the snapshot' => [['variant_id' => self::STRANGER, 'text' => 'Warranty: 12 months']];
        yield 'a variant id that is not a uuid' => [['variant_id' => 'sku-1', 'text' => 'Warranty: 12 months']];
        yield 'a variant id that is a number' => [['variant_id' => 7, 'text' => 'Warranty: 12 months']];
        yield 'a variant id that is a list' => [['variant_id' => [self::A], 'text' => 'Warranty: 12 months']];
    }

    #[DataProvider('badLines')]
    public function testALineThatBreaksARuleIsDroppedAndReportedAndTheGoodOnesStay(mixed $bad): void
    {
        $result = $this->normalize([$bad, ['variant_id' => self::A, 'text' => 'Warranty: 12 months'], ['text' => 'Keep this receipt.']]);

        $this->assertSame(['variants' => [self::A => ['Warranty: 12 months']], 'footer' => ['Keep this receipt.']], $result);
        $this->assertCount(1, $this->problems);
        $this->assertStringContainsString('ignored', $this->problems[0]);
    }

    public function testAVariantLineAndAFooterLineAreKeptAsGiven(): void
    {
        $result = $this->normalize([
            ['variant_id' => self::A, 'text' => 'Warranty: 12 months'],
            ['variant_id' => self::B, 'text' => 'Warranty: 24 months'],
            ['text' => 'Keep this receipt for warranty claims.'],
            ['variant_id' => null, 'text' => 'Also under the totals.'],
            ['variant_id' => '', 'text' => 'And this one.'],
        ]);

        $this->assertSame([
            'variants' => [self::A => ['Warranty: 12 months'], self::B => ['Warranty: 24 months']],
            'footer' => ['Keep this receipt for warranty claims.', 'Also under the totals.', 'And this one.'],
        ], $result);
        $this->assertSame([], $this->problems);
    }

    public function testTheTextIsTrimmedAndTheLimitIs120CharactersNotBytes(): void
    {
        $result = $this->normalize([
            ['variant_id' => self::A, 'text' => "  Warranty: 12 months \n"],
            ['variant_id' => self::B, 'text' => str_repeat('é', 120)],
        ]);

        $this->assertSame(['Warranty: 12 months'], $result['variants'][self::A]);
        $this->assertSame([str_repeat('é', 120)], $result['variants'][self::B], '120 characters (240 bytes) pass');
        $this->assertSame([], $this->problems);
        $this->assertSame(120, PosReceiptLineService::MAX_LENGTH);
    }

    public function testAtMostThreeLinesPerVariantAndThreeInTheFooterAndTheRestIsReportedOnce(): void
    {
        $raw = [];
        foreach (['one', 'two', 'three', 'four', 'five'] as $text) {
            $raw[] = ['variant_id' => self::A, 'text' => $text];
            $raw[] = ['text' => 'footer ' . $text];
        }
        $raw[] = ['variant_id' => self::B, 'text' => 'only line'];

        $result = $this->normalize($raw);

        $this->assertSame(['one', 'two', 'three'], $result['variants'][self::A]);
        $this->assertSame(['only line'], $result['variants'][self::B]);
        $this->assertSame(['footer one', 'footer two', 'footer three'], $result['footer']);
        $this->assertSame(3, PosReceiptLineService::MAX_PER_VARIANT);
        $this->assertSame(3, PosReceiptLineService::MAX_FOOTER);
        $this->assertCount(4, $this->problems, 'every dropped line is reported to the caller, which decides how many to log');
    }

    public function testARepeatedLineForTheSameTargetIsKeptOnce(): void
    {
        $result = $this->normalize([
            ['variant_id' => self::A, 'text' => 'Warranty: 12 months'],
            ['variant_id' => self::A, 'text' => 'Warranty: 12 months'],
            ['text' => 'Thanks'],
            ['text' => 'Thanks'],
        ]);

        $this->assertSame(['variants' => [self::A => ['Warranty: 12 months']], 'footer' => ['Thanks']], $result);
        $this->assertSame([], $this->problems);
    }

    public function testNothingValidGivesNothingNotEvenAnEmptyKey(): void
    {
        $this->assertSame([], $this->normalize([]));
        $this->assertSame([], $this->normalize([['text' => '']]));
        $this->assertSame(['footer' => ['Only a footer']], $this->normalize([['text' => 'Only a footer']]));
        $this->assertSame(['variants' => [self::A => ['Only a line']]], $this->normalize([['variant_id' => self::A, 'text' => 'Only a line']]));
    }

    public function testUnknownKeysAreIgnoredAndCannotSmuggleAnything(): void
    {
        $result = $this->normalize([['variant_id' => self::A, 'text' => 'Warranty: 12 months', 'html' => '<b>x</b>', 'style' => 'color:red', 'qty' => 5]]);

        $this->assertSame(['variants' => [self::A => ['Warranty: 12 months']]], $result);
    }

    public function testWithNoCallbackRegisteredNothingIsAddedAndTheDatabaseIsNotTouched(): void
    {
        // A stub connection: the repository would fail on it if it were used, so the service must stop at has().
        // (No database driver is needed, and the project requires only pdo_pgsql.)
        $pdo = $this->createStub(\PDO::class);
        $service = new PosReceiptLineService(new Hooks(), new PosRepository($pdo));

        $this->assertNull($service->forSnapshot(['id' => 't', 'code' => 'T3', 'name' => 'n', 'branch_id' => 'b'], [['variant_id' => self::A]]));
    }
}

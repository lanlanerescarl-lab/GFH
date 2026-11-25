<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Service\CheckoutFieldService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Batch 8.3 Part B: the checkout field definitions the hook may return, and the rules for the posted values. */
final class CheckoutFieldServiceTest extends TestCase
{
    /** @var list<string> */
    private array $logged = [];

    private function service(array ...$definitions): CheckoutFieldService
    {
        $this->logged = [];
        $hooks = new Hooks(function (string $message): void {
            $this->logged[] = $message;
        });
        $hooks->add(Hooks::CHECKOUT_FIELDS, static fn (array $fields): array => [...$fields, ...$definitions], 10, 'fixture');

        return new CheckoutFieldService($hooks);
    }

    /** @return list<array<string, mixed>> */
    private static function defs(): array
    {
        return CheckoutFieldService::normalize([
            ['id' => 'note', 'label' => 'Note', 'type' => 'textarea', 'max_length' => 10],
            ['id' => 'company', 'label' => 'Company', 'type' => 'text', 'required' => true],
            ['id' => 'slot', 'label' => 'Slot', 'type' => 'select', 'options' => [['value' => 'am', 'label' => 'Morning'], ['value' => 'pm', 'label' => 'Afternoon']]],
            ['id' => 'wrap', 'label' => 'Wrap', 'type' => 'checkbox'],
            ['id' => 'terms', 'label' => 'Terms', 'type' => 'checkbox', 'required' => true],
        ]);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function badDefinitions(): iterable
    {
        yield 'not an object' => ['text', 'not an object'];
        yield 'no id' => [['label' => 'x', 'type' => 'text'], 'id must be'];
        yield 'upper-case id' => [['id' => 'Gift', 'label' => 'x', 'type' => 'text'], 'id must be'];
        yield 'id with a space' => [['id' => 'gift note', 'label' => 'x', 'type' => 'text'], 'id must be'];
        yield 'id with a dash' => [['id' => 'gift-note', 'label' => 'x', 'type' => 'text'], 'id must be'];
        yield 'id too long' => [['id' => str_repeat('a', 41), 'label' => 'x', 'type' => 'text'], 'id must be'];
        yield 'empty id' => [['id' => '', 'label' => 'x', 'type' => 'text'], 'id must be'];
        yield 'no label' => [['id' => 'a', 'type' => 'text'], 'label must be plain text'];
        yield 'blank label' => [['id' => 'a', 'label' => '  ', 'type' => 'text'], 'label must be plain text'];
        yield 'label with a tag' => [['id' => 'a', 'label' => 'Hi <b>there</b>', 'type' => 'text'], 'label must be plain text'];
        yield 'label too long' => [['id' => 'a', 'label' => str_repeat('x', 81), 'type' => 'text'], 'label must be plain text'];
        yield 'unknown type' => [['id' => 'a', 'label' => 'x', 'type' => 'radio'], 'type must be'];
        yield 'no type' => [['id' => 'a', 'label' => 'x'], 'type must be'];
        yield 'required is not a bool' => [['id' => 'a', 'label' => 'x', 'type' => 'text', 'required' => 'yes'], 'required must be'];
        yield 'help with a tag' => [['id' => 'a', 'label' => 'x', 'type' => 'text', 'help' => '<script>1</script>'], 'help must be plain text'];
        yield 'help too long' => [['id' => 'a', 'label' => 'x', 'type' => 'text', 'help' => str_repeat('x', 201)], 'help must be plain text'];
        yield 'select without options' => [['id' => 'a', 'label' => 'x', 'type' => 'select'], 'a select needs'];
        yield 'select with an empty list' => [['id' => 'a', 'label' => 'x', 'type' => 'select', 'options' => []], 'a select needs'];
        yield 'option that is not an object' => [['id' => 'a', 'label' => 'x', 'type' => 'select', 'options' => ['am']], 'a select needs'];
        yield 'option with an empty value' => [['id' => 'a', 'label' => 'x', 'type' => 'select', 'options' => [['value' => '', 'label' => 'x']]], 'a select needs'];
        yield 'option without a label' => [['id' => 'a', 'label' => 'x', 'type' => 'select', 'options' => [['value' => 'v']]], 'a select needs'];
        yield 'duplicate option values' => [['id' => 'a', 'label' => 'x', 'type' => 'select', 'options' => [['value' => 'v', 'label' => 'One'], ['value' => 'v', 'label' => 'Two']]], 'a select needs'];
        yield 'option value with a tag' => [['id' => 'a', 'label' => 'x', 'type' => 'select', 'options' => [['value' => '<i>', 'label' => 'x']]], 'a select needs'];
        yield 'too many options' => [['id' => 'a', 'label' => 'x', 'type' => 'select', 'options' => array_map(static fn (int $i): array => ['value' => "v$i", 'label' => "L$i"], range(1, 51))], 'a select needs'];
        yield 'max_length zero' => [['id' => 'a', 'label' => 'x', 'type' => 'text', 'max_length' => 0], 'max_length must be'];
        yield 'max_length too big' => [['id' => 'a', 'label' => 'x', 'type' => 'text', 'max_length' => 501], 'max_length must be'];
        yield 'max_length as text' => [['id' => 'a', 'label' => 'x', 'type' => 'textarea', 'max_length' => '20'], 'max_length must be'];
    }

    // ---------------------------------------------------------------- definitions

    public function testNoFieldsMeansNoDefinitionsAndNothingLogged(): void
    {
        $service = new CheckoutFieldService(new Hooks());

        $this->assertSame([], $service->definitions());
    }

    public function testEachTypeIsNormalizedWithItsDefaults(): void
    {
        $defs = self::defs();

        $this->assertSame(['note', 'company', 'slot', 'wrap', 'terms'], array_column($defs, 'id'));
        $this->assertSame(['textarea', 'text', 'select', 'checkbox', 'checkbox'], array_column($defs, 'type'));
        $this->assertSame([10, 150, null, null, null], array_column($defs, 'max_length'));
        $this->assertSame([false, true, false, false, true], array_column($defs, 'required'));
        $this->assertSame([['value' => 'am', 'label' => 'Morning'], ['value' => 'pm', 'label' => 'Afternoon']], $defs[2]['options']);
        $this->assertSame([], $defs[0]['options']);
        $this->assertSame('', $defs[0]['help']);
        $this->assertSame(500, CheckoutFieldService::normalize([['id' => 'n', 'label' => 'N', 'type' => 'textarea']])[0]['max_length']);
        $this->assertSame('Printed on a card.', CheckoutFieldService::normalize([['id' => 'n', 'label' => 'N', 'type' => 'text', 'help' => ' Printed on a card. ']])[0]['help']);
    }

    public function testTheHookDefinitionsAreUsedInTheOrderGiven(): void
    {
        $service = $this->service(['id' => 'b', 'label' => 'B', 'type' => 'text'], ['id' => 'a', 'label' => 'A', 'type' => 'checkbox']);

        $this->assertSame(['b', 'a'], array_column($service->definitions(), 'id'));
        $this->assertSame([], $this->logged);
    }

    #[DataProvider('badDefinitions')]
    public function testABadDefinitionIsIgnoredAndLoggedAndTheGoodOnesStay(mixed $bad, string $reason): void
    {
        $this->logged = [];
        $hooks = new Hooks(function (string $message): void {
            $this->logged[] = $message;
        });
        $hooks->add(Hooks::CHECKOUT_FIELDS, static fn (array $fields): array => [...$fields, ['id' => 'fine', 'label' => 'Fine', 'type' => 'text'], $bad], 10);

        $definitions = (new CheckoutFieldService($hooks))->definitions();

        $this->assertSame(['fine'], array_column($definitions, 'id'));
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('Hook value problem: checkout.fields', $this->logged[0]);
        $this->assertStringContainsString($reason, $this->logged[0]);
    }

    public function testADuplicateIdKeepsTheFirstAndTheLogIsOncePerRequest(): void
    {
        $service = $this->service(
            ['id' => 'a', 'label' => 'First', 'type' => 'text'],
            ['id' => 'a', 'label' => 'Second', 'type' => 'textarea'],
        );

        for ($i = 0; $i < 3; $i++) {
            $definitions = $service->definitions();
        }

        $this->assertSame(['First'], array_column($definitions, 'label'));
        $this->assertCount(1, $this->logged, 'three reads of the hook, one log line');
        $this->assertStringContainsString('already used', $this->logged[0]);
    }

    public function testNoMoreThanTenFieldsAreShown(): void
    {
        $many = array_map(static fn (int $i): array => ['id' => "f$i", 'label' => "Field $i", 'type' => 'text'], range(1, 14));
        $service = $this->service(...$many);

        $this->assertCount(10, $service->definitions());
        $this->assertCount(1, $this->logged, 'the extra ones are reported once, not four times');
        $this->assertStringContainsString('at most 10', $this->logged[0]);
    }

    public function testAHookThatThrowsOrReturnsTheWrongTypeLeavesNoFields(): void
    {
        $logged = [];
        $hooks = new Hooks(function (string $message) use (&$logged): void {
            $logged[] = $message;
        });
        $hooks->add(Hooks::CHECKOUT_FIELDS, static fn (array $f): array => throw new \RuntimeException('no'), 10, 'broken');
        $hooks->add(Hooks::CHECKOUT_FIELDS, static fn (array $f): string => 'oops', 20, 'sloppy');

        $this->assertSame([], (new CheckoutFieldService($hooks))->definitions());
        $this->assertCount(2, $logged);
    }

    // ---------------------------------------------------------------- the posted values

    public function testNothingPostedGivesBlankValuesForEveryDeclaredIdAndOnlyTheRequiredOnesFail(): void
    {
        $result = CheckoutFieldService::validate(self::defs(), []);

        $this->assertSame(['note' => '', 'company' => '', 'slot' => '', 'wrap' => false, 'terms' => false], $result['values']);
        $this->assertSame(['checkout.company', 'checkout.terms'], array_keys($result['errors']));
        $this->assertSame('Company is required.', $result['errors']['checkout.company']);
        $this->assertSame('Terms must be ticked.', $result['errors']['checkout.terms']);
    }

    public function testNoDefinitionsAndNothingPostedIsFine(): void
    {
        $this->assertSame(['values' => [], 'errors' => []], CheckoutFieldService::validate([], []));
    }

    public function testValidValuesPassTrimmedAndTyped(): void
    {
        $result = CheckoutFieldService::validate(self::defs(), ['company' => '  Acme Ltd  ', 'note' => "One\r\nTwo", 'slot' => 'pm', 'wrap' => true, 'terms' => 'on']);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['note' => "One\nTwo", 'company' => 'Acme Ltd', 'slot' => 'pm', 'wrap' => true, 'terms' => true], $result['values']);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function checkboxValues(): iterable
    {
        yield 'true' => [true, true];
        yield 'one' => ['1', true];
        yield 'on' => ['on', true];
        yield 'text true' => ['true', true];
        yield 'false' => [false, false];
        yield 'zero' => ['0', false];
        yield 'empty text' => ['', false];
        yield 'text false' => ['false', false];
        yield 'null' => [null, false];
    }

    #[DataProvider('checkboxValues')]
    public function testCheckboxValuesAreTrueOrFalse(mixed $posted, bool $expected): void
    {
        $result = CheckoutFieldService::validate(self::defs(), ['company' => 'x', 'terms' => true, 'wrap' => $posted]);

        $this->assertSame([], $result['errors']);
        $this->assertSame($expected, $result['values']['wrap']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function notCheckboxValues(): iterable
    {
        yield 'a number' => [2];
        yield 'any other text' => ['yes please'];
        yield 'a list' => [['x']];
    }

    #[DataProvider('notCheckboxValues')]
    public function testACheckboxValueThatIsNeitherTickedNorEmptyIsRefusedAndTreatedAsUnticked(mixed $posted): void
    {
        $result = CheckoutFieldService::validate(self::defs(), ['company' => 'x', 'terms' => true, 'wrap' => $posted]);

        $this->assertSame(['checkout.wrap'], array_keys($result['errors']));
        $this->assertFalse($result['values']['wrap']);
    }

    public function testAnUnknownIdIsRefusedAndNeverReachesTheValues(): void
    {
        $result = CheckoutFieldService::validate(self::defs(), ['company' => 'x', 'terms' => true, 'surprise' => 'hello', 'checkout' => 'x']);

        $this->assertArrayHasKey('checkout.surprise', $result['errors']);
        $this->assertArrayHasKey('checkout.checkout', $result['errors']);
        $this->assertSame('This field is not part of the checkout.', $result['errors']['checkout.surprise']);
        $this->assertArrayNotHasKey('surprise', $result['values']);
    }

    public function testAnUnknownKeyThatIsNotAValidIdIsReportedWithoutEchoingIt(): void
    {
        $result = CheckoutFieldService::validate(self::defs(), ['<script>alert(1)</script>' => 'x', 0 => 'a', 'company' => 'x', 'terms' => true]);

        $this->assertSame(['checkout'], array_keys($result['errors']));
        $this->assertStringNotContainsString('script', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testNoDefinitionsRefuseEveryPostedId(): void
    {
        $result = CheckoutFieldService::validate([], ['anything' => 'x']);

        $this->assertSame(['checkout.anything'], array_keys($result['errors']));
        $this->assertSame([], $result['values']);
    }

    public function testTheLengthLimitCountsCharactersNotBytes(): void
    {
        $this->assertSame([], CheckoutFieldService::validate(self::defs(), ['company' => 'x', 'terms' => true, 'note' => str_repeat('é', 10)])['errors']);

        $result = CheckoutFieldService::validate(self::defs(), ['company' => 'x', 'terms' => true, 'note' => str_repeat('é', 11)]);
        $this->assertSame('Note must be at most 10 characters.', $result['errors']['checkout.note']);
        $this->assertSame('', $result['values']['note'], 'a value that fails a rule never reaches a hook');

        $text = CheckoutFieldService::validate(self::defs(), ['company' => str_repeat('c', 151), 'terms' => true]);
        $this->assertSame('Company must be at most 150 characters.', $text['errors']['checkout.company']);
    }

    public function testASelectValueMustBeOneOfTheOptions(): void
    {
        foreach (['AM', 'am ', 'noon', '0', 'am,pm'] as $wrong) {
            $result = CheckoutFieldService::validate(self::defs(), ['company' => 'x', 'terms' => true, 'slot' => $wrong]);
            // "am " is trimmed to "am" first, so it is the one value in this list that is fine.
            if ($wrong === 'am ') {
                $this->assertSame([], $result['errors']);
                continue;
            }
            $this->assertSame('Choose one of the listed options for Slot.', $result['errors']['checkout.slot'], $wrong);
            $this->assertSame('', $result['values']['slot']);
        }
        $this->assertSame([], CheckoutFieldService::validate(self::defs(), ['company' => 'x', 'terms' => true, 'slot' => ''])['errors'], 'an empty choice is allowed when the field is optional');
    }

    public function testARequiredSelectNeedsAChoice(): void
    {
        $defs = CheckoutFieldService::normalize([['id' => 'slot', 'label' => 'Slot', 'type' => 'select', 'required' => true, 'options' => [['value' => 'am', 'label' => 'Morning']]]]);

        $this->assertSame('Slot is required.', CheckoutFieldService::validate($defs, ['slot' => ''])['errors']['checkout.slot']);
        $this->assertSame([], CheckoutFieldService::validate($defs, ['slot' => 'am'])['errors']);
    }

    /** @return iterable<string, array{string}> */
    public static function notPlainText(): iterable
    {
        yield 'a tag' => ['Hello <b>you</b>'];
        yield 'a script' => ['<script>alert(1)</script>'];
        yield 'a closing tag' => ['x </div>'];
        yield 'a comment' => ['x <!-- y -->'];
        yield 'a processing instruction' => ['<?php echo 1; ?>'];
        yield 'a null byte' => ["bad\0byte"];
        yield 'a bell' => ["bad\x07note"];
        yield 'a line break in a one-line field' => ["one\ntwo"];
        yield 'bad UTF-8' => ["caf\xE9"];
    }

    #[DataProvider('notPlainText')]
    public function testOneLineTextMustBePlain(string $text): void
    {
        $result = CheckoutFieldService::validate(self::defs(), ['company' => $text, 'terms' => true]);

        $this->assertSame('Company must be plain text: no tags and no control characters.', $result['errors']['checkout.company']);
        $this->assertSame('', $result['values']['company']);
    }

    public function testAMultilineFieldKeepsLineBreaksAndTabsButNotOtherControlCharacters(): void
    {
        $ok = CheckoutFieldService::validate(self::defs(), ['company' => 'x', 'terms' => true, 'note' => "a\n\tb"]);
        $this->assertSame([], $ok['errors']);

        $bad = CheckoutFieldService::validate(self::defs(), ['company' => 'x', 'terms' => true, 'note' => "a\x07b"]);
        $this->assertArrayHasKey('checkout.note', $bad['errors']);
    }

    public function testPlainTextMayStillHoldLessThanSignsAndQuotes(): void
    {
        $result = CheckoutFieldService::validate(self::defs(), ['company' => 'Smith & Sons, 3 < 5, "quoted" \'single\'', 'terms' => true]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('Smith & Sons, 3 < 5, "quoted" \'single\'', $result['values']['company'], 'stored as typed; the page escapes it when it prints it');
    }

    /** @return iterable<string, array{mixed}> */
    public static function notText(): iterable
    {
        yield 'a number' => [5];
        yield 'a list' => [['a']];
        yield 'a bool' => [true];
    }

    #[DataProvider('notText')]
    public function testATextFieldNeedsText(mixed $posted): void
    {
        $result = CheckoutFieldService::validate(self::defs(), ['company' => $posted, 'terms' => true]);

        $this->assertSame('Company must be plain text.', $result['errors']['checkout.company']);
    }

    public function testTheRequiredRuleCanBeSwitchedOffButEveryOtherRuleStays(): void
    {
        $result = CheckoutFieldService::validate(self::defs(), ['note' => str_repeat('n', 11)], false);

        $this->assertSame(['checkout.note'], array_keys($result['errors']), 'no error for the missing company or terms');
    }
}

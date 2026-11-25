<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Support\ExtensionAttributes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionAttributesTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function keys(): array
    {
        return [
            'extension key' => ['ext_care_notes', true],
            'bare prefix' => ['ext_', true],
            'plain key' => ['colour', false],
            'prefix inside' => ['next_day', false],
            'upper case is not the prefix' => ['EXT_care', false],
        ];
    }

    #[DataProvider('keys')]
    public function testOnlyAKeyThatStartsWithExtUnderscoreIsAnExtensionKey(string $key, bool $expected): void
    {
        $this->assertSame($expected, ExtensionAttributes::isExtensionKey($key));
    }

    public function testVisibleAndHiddenSplitTheSetWithoutLosingAnything(): void
    {
        $all = ['colour' => 'Sand', 'ext_care_notes' => 'Dry flat', 'size' => 'L', 'ext_other' => 'x'];

        $this->assertSame(['colour' => 'Sand', 'size' => 'L'], ExtensionAttributes::visible($all));
        $this->assertSame(['ext_care_notes' => 'Dry flat', 'ext_other' => 'x'], ExtensionAttributes::hidden($all));
        $this->assertSame([], ExtensionAttributes::visible([]));
        $this->assertSame([], ExtensionAttributes::hidden([]));
    }
}

<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Support\Decimal;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function testParsesStringsIntegersAndFloatsExactly(): void
    {
        $this->assertSame(1250, Decimal::parse('12.5', 2));
        $this->assertSame(1200, Decimal::parse(12, 2));
        $this->assertSame(1999, Decimal::parse(19.99, 2));
        $this->assertSame(-5000, Decimal::parse('-5', 3));
        $this->assertSame(100, Decimal::parse(' 1.00 ', 2));
        $this->assertSame(250, Decimal::parse('0.250', 3));
    }

    public function testRefusesAnythingThatIsNotAPlainDecimalWithinTheScale(): void
    {
        foreach (['12.555', '1e3', 'abc', '', '1,000.00', '12.', '.5', '1234567890123'] as $bad) {
            $this->assertNull(Decimal::parse($bad, 2), "'$bad' should be refused at scale 2");
        }
        $this->assertNull(Decimal::parse(1.005, 2), 'a float with a hidden third decimal is refused');
        $this->assertNull(Decimal::parse(null, 2));
        $this->assertNull(Decimal::parse([], 2));
        $this->assertNull(Decimal::parse('1.5', 0));
    }

    public function testFormatsWithTheScale(): void
    {
        $this->assertSame('12.50', Decimal::format(1250, 2));
        $this->assertSame('0.05', Decimal::format(5, 2));
        $this->assertSame('-0.005', Decimal::format(-5, 3));
        $this->assertSame('0.00', Decimal::format(0, 2));
        $this->assertSame('1.0001', Decimal::format(10001, 4));
    }

    public function testMulDivRoundsHalfAwayFromZero(): void
    {
        $this->assertSame(1, Decimal::mulDiv(1, 1, 2));
        $this->assertSame(-1, Decimal::mulDiv(-1, 1, 2));
        $this->assertSame(8929, Decimal::mulDiv(10000, 100, 112));
        $this->assertSame(2, Decimal::mulDiv(5, 1, 3));
        $this->assertSame(1, Decimal::mulDiv(4, 1, 3));
        $this->assertSame(0, Decimal::mulDiv(0, 100, 112));
    }

    public function testOverflowIsRefusedInsteadOfWrappingAround(): void
    {
        $this->expectException(\RangeException::class);
        Decimal::mul(PHP_INT_MAX, 2);
    }
}

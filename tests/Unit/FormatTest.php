<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Support\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testPesoFormatsAmountsWithGroupingAndNegatives(): void
    {
        $this->assertSame('₱0.00', Format::peso(null));
        $this->assertSame('₱0.00', Format::peso(''));
        $this->assertSame('₱1,234.50', Format::peso('1234.5'));
        $this->assertSame('₱42.00', Format::peso(42));
        $this->assertSame('-₱250.75', Format::peso('-250.75'));
    }

    public function testQtyTrimsTrailingZerosAndPreservesDecimals(): void
    {
        $this->assertSame('0', Format::qty(null));
        $this->assertSame('0', Format::qty(''));
        $this->assertSame('1,200', Format::qty('1200.000'));
        $this->assertSame('2.5', Format::qty('2.500'));
        $this->assertSame('0.25', Format::qty('0.250'));
    }

    public function testPctFormatsTwoDecimalsAndHandlesEmpty(): void
    {
        $this->assertSame('—', Format::pct(null));
        $this->assertSame('—', Format::pct(''));
        $this->assertSame('100.00%', Format::pct(100));
        $this->assertSame('87.50%', Format::pct('87.5'));
    }

    public function testDateAndDateTimeUseManilaTimezoneAndDdMonYyyyFormat(): void
    {
        $this->assertSame('—', Format::date(null));
        $this->assertSame('—', Format::date('not-a-date'));
        $this->assertSame('15 Nov 2026', Format::date('2026-11-15'));

        // UTC 2026-11-15T18:30:00Z is 2026-11-16 02:30 in Asia/Manila (+08:00).
        $this->assertSame('16 Nov 2026', Format::date('2026-11-15T18:30:00Z'));
        $this->assertSame('16 Nov 2026, 02:30', Format::dateTime('2026-11-15T18:30:00Z'));
    }
}

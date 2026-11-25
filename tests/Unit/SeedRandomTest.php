<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Seeds\Demo\SeedRandom;
use PHPUnit\Framework\TestCase;

/** The demo history seed is only repeatable if its random numbers are. */
final class SeedRandomTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/database/seeds/demo/support/SeedRandom.php';
    }

    public function testTheSameSeedGivesTheSameNumbers(): void
    {
        $a = new SeedRandom(811020);
        $b = new SeedRandom(811020);
        $c = new SeedRandom(811021);
        $differs = false;
        for ($i = 0; $i < 50; $i++) {
            $x = $a->int(0, 1000);
            $this->assertSame($x, $b->int(0, 1000));
            $differs = $differs || $x !== $c->int(0, 1000);
        }
        $this->assertTrue($differs, 'Another seed gives another sequence.');
    }

    public function testTheSequenceIsPinned(): void
    {
        // If this fails the generator changed, and every demo database seeded from now on would differ from earlier ones.
        $rng = new SeedRandom(1);
        $this->assertSame([4, 2, 4, 6, 6, 5, 5, 1], array_map(static fn (): int => $rng->int(1, 6), range(1, 8)));
    }

    public function testRangesAndWeightsAreRespected(): void
    {
        $rng = new SeedRandom(7);
        for ($i = 0; $i < 500; $i++) {
            $n = $rng->int(3, 9);
            $this->assertGreaterThanOrEqual(3, $n);
            $this->assertLessThanOrEqual(9, $n);
            $f = $rng->float();
            $this->assertGreaterThanOrEqual(0.0, $f);
            $this->assertLessThan(1.0, $f);
        }
        $hits = ['heavy' => 0, 'light' => 0];
        for ($i = 0; $i < 2000; $i++) {
            $hits[$rng->weighted(['heavy' => 9, 'light' => 1])]++;
        }
        $this->assertGreaterThan($hits['light'] * 5, $hits['heavy']);
    }
}

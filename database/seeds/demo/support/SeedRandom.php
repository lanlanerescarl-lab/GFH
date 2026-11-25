<?php

declare(strict_types=1);

namespace Gfh\Seeds\Demo;

/** A small xorshift32 generator: the same seed gives the same numbers on every machine and PHP version. */
final class SeedRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0xFFFFFFFF) ?: 2463534242;
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;

        return $this->state = $x;
    }

    /** A whole number from $min to $max, both included. */
    public function int(int $min, int $max): int
    {
        return $min + $this->next() % ($max - $min + 1);
    }

    /** A number from 0 up to, not including, 1. */
    public function float(): float
    {
        return $this->next() / 4294967296;
    }

    public function chance(float $probability): bool
    {
        return $this->float() < $probability;
    }

    /** @template T @param non-empty-list<T> $items @return T */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /** @param non-empty-array<int|string, int|float> $weights @return int|string the chosen key */
    public function weighted(array $weights): int|string
    {
        $total = array_sum($weights);
        $roll = $this->float() * $total;
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll < 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}

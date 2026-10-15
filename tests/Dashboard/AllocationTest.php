<?php

declare(strict_types=1);

namespace Casamiza\Tests\Dashboard;

use Casamiza\Domain\Dashboard\Allocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationTest extends TestCase
{
    /**
     * @param non-empty-list<int> $weights
     * @param list<int> $expected
     */
    #[DataProvider('cases')]
    public function testSplitsWithoutLosingACent(int $total, array $weights, array $expected): void
    {
        $shares = Allocation::split($total, $weights);

        self::assertSame($expected, $shares);
        self::assertSame($total, array_sum($shares));
    }

    /** @return iterable<string, array{int, non-empty-list<int>, list<int>}> */
    public static function cases(): iterable
    {
        yield 'prorata exact' => [1000, [3, 1], [750, 250]];
        yield 'le plus fort reste gagne le centime' => [1700, [7, 1], [1488, 212]];
        yield 'égalité : le premier' => [100, [1, 1, 1], [34, 33, 33]];
        yield 'poids nuls : parts égales' => [101, [0, 0], [51, 50]];
        yield 'un seul logement' => [4857, [5], [4857]];
        yield 'avoir réparti comme son opposé' => [-100, [1, 1, 1], [-34, -33, -33]];
        yield 'montant nul' => [0, [2, 3], [0, 0]];
    }
}

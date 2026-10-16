<?php

declare(strict_types=1);

namespace Casamiza\Tests\Statement;

use Casamiza\Domain\Statement\BillingMonth;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Un mois commence et finit à minuit à Paris ; les bornes sont des instants UTC. */
final class BillingMonthTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function bounds(): iterable
    {
        yield 'septembre (heure d\'été, UTC+2)' => ['2026-09', '2026-08-31 22:00:00', '2026-09-30 22:00:00'];
        yield 'octobre (passage à l\'heure d\'hiver le 25)' => ['2026-10', '2026-09-30 22:00:00', '2026-10-31 23:00:00'];
        yield 'janvier (heure d\'hiver, UTC+1)' => ['2026-01', '2025-12-31 23:00:00', '2026-01-31 23:00:00'];
        yield 'mars (passage à l\'heure d\'été le 29)' => ['2026-03', '2026-02-28 23:00:00', '2026-03-31 22:00:00'];
    }

    #[DataProvider('bounds')]
    public function testBoundsAreMidnightInParisExpressedInUtc(string $month, string $from, string $before): void
    {
        [$start, $end] = BillingMonth::bounds($month);

        self::assertSame($from, $start->format('Y-m-d H:i:s'));
        self::assertSame($before, $end->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $start->getTimezone()->getName());
    }

    public function testADepartureAfterTwoOClockUtcOnTheLastDayBelongsToTheNextMonthInParis(): void
    {
        [, $septemberEnd] = BillingMonth::bounds('2026-09');
        $late = new DateTimeImmutable('2026-09-30 22:30:00 UTC'); // 00:30 le 1er octobre à Paris
        $early = new DateTimeImmutable('2026-09-30 21:59:00 UTC'); // 23:59 le 30 septembre à Paris

        self::assertTrue($late >= $septemberEnd);
        self::assertTrue($early < $septemberEnd);
        self::assertTrue($late >= BillingMonth::bounds('2026-10')[0]);
    }

    public function testAMonthIsFinishedWhenParisReachesTheNextOne(): void
    {
        // 30/09 22:30 UTC = 1er octobre 00:30 à Paris : septembre est terminé.
        self::assertSame('2026-09', $this->last('2026-09-30 22:30:00 UTC'));
        // 30/09 21:30 UTC = 23:30 à Paris : septembre n'est pas terminé.
        self::assertSame('2026-08', $this->last('2026-09-30 21:30:00 UTC'));
    }

    public function testThePreviousMonthStartsAtMidnightInParis(): void
    {
        $start = BillingMonth::startOfPreviousMonth(new DateTimeImmutable('2026-10-16 12:00:00 UTC'));

        self::assertSame('2026-08-31 22:00:00', $start->format('Y-m-d H:i:s'));
    }

    private function last(string $now): string
    {
        return implode('', array_slice(BillingMonth::until(new DateTimeImmutable($now)), -1));
    }
}

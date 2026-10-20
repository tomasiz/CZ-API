<?php

declare(strict_types=1);

namespace Casamiza\Domain\Statement;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Mois de facturation (`AAAA-MM`). Le premier est janvier 2025 ; un mois est facturable une fois terminé.
 * Un mois commence et finit à minuit **à Paris** (c'est la date que le propriétaire lit sur son PDF) ; la base reste en UTC.
 */
final class BillingMonth
{
    public const FIRST = '2025-01';
    public const TIMEZONE = 'Europe/Paris';

    /**
     * Les deux instants UTC qui bornent un mois : début inclus, fin exclue (minuit à Paris, donc 22 h ou 23 h UTC la veille).
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public static function bounds(string $month): array
    {
        $paris = new DateTimeZone(self::TIMEZONE);
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($month . '-01 00:00:00', $paris);

        return [$start->setTimezone($utc), $start->modify('first day of next month')->setTimezone($utc)];
    }

    /** Début (UTC) du mois précédent `$now`, à minuit à Paris. */
    public static function startOfPreviousMonth(DateTimeImmutable $now): DateTimeImmutable
    {
        $paris = $now->setTimezone(new DateTimeZone(self::TIMEZONE));

        return self::bounds($paris->modify('first day of last month')->format('Y-m'))[0];
    }

    /** Mois (`AAAA-MM`) auquel appartient un instant : celui de sa date à Paris. */
    public static function of(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m');
    }

    public static function isValid(string $month): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 && $month >= self::FIRST;
    }

    /**
     * Tous les mois facturables à `$now` : de janvier 2025 au mois précédent, du plus ancien au plus récent.
     *
     * @return list<string>
     */
    public static function until(DateTimeImmutable $now): array
    {
        $last = $now->setTimezone(new DateTimeZone(self::TIMEZONE))->modify('first day of last month')->format('Y-m');
        $months = [];
        for ($month = self::FIRST; $month <= $last; $month = (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m')) {
            $months[] = $month;
        }

        return $months;
    }
}

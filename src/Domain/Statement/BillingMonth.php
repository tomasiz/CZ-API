<?php

declare(strict_types=1);

namespace Casamiza\Domain\Statement;

use DateTimeImmutable;

/** Mois de facturation (`AAAA-MM`). Le premier est janvier 2025 ; un mois est facturable une fois terminé. */
final class BillingMonth
{
    public const FIRST = '2025-01';

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
        $last = $now->modify('first day of last month')->format('Y-m');
        $months = [];
        for ($month = self::FIRST; $month <= $last; $month = (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m')) {
            $months[] = $month;
        }

        return $months;
    }
}

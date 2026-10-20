<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Période d'un relevé Beyond : du 12 (inclus) au 12 du mois suivant (exclu), en jours calendaires. Beyond rattache une ligne à
 * la date où elle est facturée (prise de réservation, annulation ou modification), pas au séjour.
 */
final readonly class BeyondPeriod
{
    /** Jour du mois où commence chaque relevé. */
    public const START_DAY = 12;

    private function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {}

    /** Le relevé du mois `$month` de `$year` (il commence le 12 de ce mois). */
    public static function of(int $year, int $month): self
    {
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, self::START_DAY), $utc);

        return new self($start, $start->modify('+1 month'));
    }

    /** Le relevé qui commence ce jour-là ; null si ce n'est pas un 12. */
    public static function startingOn(DateTimeImmutable $day): ?self
    {
        return (int) $day->format('j') === self::START_DAY ? self::of((int) $day->format('Y'), (int) $day->format('n')) : null;
    }

    /** `AAAA-MM-JJ` du premier jour : identifie le relevé dans l'URL. */
    public function key(): string
    {
        return $this->start->format('Y-m-d');
    }

    /** La période est terminée : aujourd'hui (à Paris) est le jour de fin ou plus tard, le relevé est complet. */
    public function isClosedAt(DateTimeImmutable $now): bool
    {
        return $now->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d') >= $this->end->format('Y-m-d');
    }
}

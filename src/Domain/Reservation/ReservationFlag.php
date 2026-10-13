<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

/** Signalement d'un champ financier, à relire à la main avant de facturer. */
final readonly class ReservationFlag
{
    public function __construct(
        public FinancialField $field,
        public FlagReason $reason,
    ) {}
}

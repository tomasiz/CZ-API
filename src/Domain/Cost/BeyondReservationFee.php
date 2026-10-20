<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use DateTimeImmutable;

/** Ce qu'un relevé apporte à une réservation : ses lignes et leur total (centimes d'euro signés). */
final readonly class BeyondReservationFee
{
    /** @param list<BeyondFeeLine> $lines */
    public function __construct(
        public string $guestyId,
        public string $listingTitle,
        public string $guest,
        public DateTimeImmutable $checkIn,
        public DateTimeImmutable $checkOut,
        /** Réservation absente de Casamiza, récupérée chez Guesty par cet import */
        public bool $fetched,
        public array $lines,
        public int $totalCents,
    ) {}
}

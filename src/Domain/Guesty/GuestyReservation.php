<?php

declare(strict_types=1);

namespace Casamiza\Domain\Guesty;

use DateTimeImmutable;

/** Réservation confirmée telle que Guesty la décrit (lecture seule, montants bruts). */
final readonly class GuestyReservation
{
    /**
     * @param list<string> $integrationKeys clés de l'objet `integration`
     * @param array<array-key, mixed> $payments `money.payments`
     * @param array<array-key, mixed> $confirmationTaxes `atTimeOfConfirmation.taxes`
     */
    public function __construct(
        public string $guestyId,
        public string $listingGuestyId,
        /** Valeur brute de `source` (casse d'origine). */
        public string $source,
        public array $integrationKeys,
        /** Référence côté plateforme (Booking : `reservationId`, Airbnb : `id`) ; vide sinon. */
        public string $platformRef,
        public string $guest,
        public DateTimeImmutable $checkIn,
        public DateTimeImmutable $checkOut,
        public int $nights,
        public int $guests,
        public float $hostPayout,
        public float $hostServiceFee,
        public float $totalPaymentFees,
        public float $totalTaxes,
        public float $fareCleaning,
        public float $totalFees,
        public float $transientOccupancyTax,
        public float $fareAccommodationAdjusted,
        public array $payments,
        public array $confirmationTaxes,
    ) {}
}

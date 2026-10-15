<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

use Casamiza\Domain\Reservation\Platform;
use Casamiza\Domain\Reservation\Reservation;
use DateTimeImmutable;

/** L'activité d'une réservation facturée, figée avec la facture (futur `invoice_reservations`). */
final readonly class DraftReservation
{
    public function __construct(
        // Nul pour une facture reprise de Facturiza dont la réservation n'existe plus dans Casamiza.
        public ?string $reservationId,
        public string $propertyId,
        public Platform $platform,
        public DateTimeImmutable $checkIn,
        public DateTimeImmutable $checkOut,
        public int $nights,
        public float $totalPayout,
        public float $channelCommission,
        public float $paymentFee,
        public float $touristTax,
        public float $cleaningFee,
        public float $netPayout,
        public float $conciergeFee,
        public float $netOwnerRevenue,
    ) {}

    public static function from(Reservation $reservation): self
    {
        return new self(
            $reservation->id,
            $reservation->propertyId,
            $reservation->platform,
            $reservation->checkIn,
            $reservation->checkOut,
            $reservation->nights,
            $reservation->totalPayout,
            $reservation->channelCommission,
            $reservation->paymentFee,
            $reservation->touristTax,
            $reservation->cleaningFee,
            $reservation->netPayout,
            $reservation->conciergeFee,
            $reservation->netOwnerRevenue,
        );
    }
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

/** Résultat du calcul d'une réservation : montants calculés et signalements. */
final readonly class CalculatedAmounts
{
    /** @param list<ReservationFlag> $flags */
    public function __construct(
        public float $totalPayout,
        public float $channelCommission,
        public float $paymentFee,
        public float $touristTax,
        public float $cleaningFee,
        public float $netPayout,
        public float $conciergeFee,
        public float $netOwnerRevenue,
        public array $flags,
    ) {}

    public static function zero(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, []);
    }
}

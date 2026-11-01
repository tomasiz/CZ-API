<?php

declare(strict_types=1);

namespace Casamiza\Domain\OwnerStats;

/** Cumul en cours d'un logement pour un mois (centimes entiers), transformé en `MonthStats` à la fin. */
final class StatsCell
{
    private int $reservations = 0;
    private int $nights = 0;
    private int $ownerNights = 0;
    private int $payout = 0;
    private int $platformFee = 0;
    private int $touristTax = 0;
    private int $cleaning = 0;
    private int $concierge = 0;

    public function __construct(public readonly ?string $propertyId, public readonly string $month) {}

    public function addActivity(StatsActivity $activity): void
    {
        $this->reservations += $activity->reservations;
        $this->nights += $activity->nights;
        $this->payout += $activity->payoutCents;
        $this->platformFee += $activity->platformFeeCents;
        $this->touristTax += $activity->touristTaxCents;
    }

    public function addBilled(StatsPoste $poste, int $ttcCents): void
    {
        if ($poste === StatsPoste::Concierge) {
            $this->concierge += $ttcCents;
        } else {
            $this->cleaning += $ttcCents;
        }
    }

    public function addOwnerNights(int $nights): void
    {
        $this->ownerNights += $nights;
    }

    public function toMonthStats(): MonthStats
    {
        return new MonthStats(
            $this->propertyId,
            $this->month,
            $this->reservations,
            $this->nights,
            $this->ownerNights,
            $this->payout,
            $this->platformFee,
            $this->touristTax,
            $this->cleaning,
            $this->concierge,
        );
    }
}

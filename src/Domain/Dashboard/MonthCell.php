<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

/** Cumul en cours d'un logement pour un mois (centimes entiers), transformé en `PropertyMonth` à la fin. */
final class MonthCell
{
    private int $reservations = 0;
    private int $nights = 0;
    private int $payout = 0;
    private int $ownerNet = 0;
    /** @var array<string, array{ht: int, ttc: int}> par catégorie */
    private array $billed = [];

    public function __construct(private readonly ?string $propertyId, private readonly string $month) {}

    public function addActivity(int $reservations, int $nights, int $payoutCents, int $ownerNetCents): void
    {
        $this->reservations += $reservations;
        $this->nights += $nights;
        $this->payout += $payoutCents;
        $this->ownerNet += $ownerNetCents;
    }

    public function addBilled(BilledCategory $category, int $ttcCents, int $htCents): void
    {
        $current = $this->billed[$category->value] ?? ['ht' => 0, 'ttc' => 0];
        $this->billed[$category->value] = ['ht' => $current['ht'] + $htCents, 'ttc' => $current['ttc'] + $ttcCents];
    }

    /**
     * @param int $guestyCostCents abonnement Guesty du logement et du mois (la ligne « sans logement » reçoit les annonces qu'aucun logement ne porte)
     * @param int $beyondCostCents frais Beyond du logement et du mois (même règle pour la ligne « sans logement »)
     */
    public function toRecord(int $guestyCostCents, int $beyondCostCents): PropertyMonth
    {
        $amount = fn(BilledCategory $type, string $basis): int => $this->billed[$type->value][$basis] ?? 0;

        return new PropertyMonth(
            $this->propertyId,
            $this->month,
            $this->reservations,
            $this->nights,
            $this->payout,
            $this->ownerNet,
            $amount(BilledCategory::Concierge, 'ht'),
            $amount(BilledCategory::Concierge, 'ttc'),
            $amount(BilledCategory::Linen, 'ht'),
            $amount(BilledCategory::Linen, 'ttc'),
            $amount(BilledCategory::Disbursement, 'ht'),
            $amount(BilledCategory::Disbursement, 'ttc'),
            $guestyCostCents,
            $beyondCostCents,
        );
    }
}

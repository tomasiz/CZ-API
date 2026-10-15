<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

use Casamiza\Domain\Extra\ExtraType;

/** Cumul en cours d'un logement pour un mois (centimes entiers), transformé en `PropertyMonth` à la fin. */
final class MonthCell
{
    private int $reservations = 0;
    private int $nights = 0;
    private int $payout = 0;
    /** @var array<string, array{ht: int, ttc: int}> par catégorie */
    private array $billed = [];

    public function __construct(private readonly ?string $propertyId, private readonly string $month) {}

    public function addActivity(int $reservations, int $nights, int $payoutCents): void
    {
        $this->reservations += $reservations;
        $this->nights += $nights;
        $this->payout += $payoutCents;
    }

    public function addBilled(ExtraType $category, int $ttcCents, int $htCents): void
    {
        $current = $this->billed[$category->value] ?? ['ht' => 0, 'ttc' => 0];
        $this->billed[$category->value] = ['ht' => $current['ht'] + $htCents, 'ttc' => $current['ttc'] + $ttcCents];
    }

    public function toRecord(int $softwareCostCents): PropertyMonth
    {
        $amount = fn(ExtraType $type, string $basis): int => $this->billed[$type->value][$basis] ?? 0;

        return new PropertyMonth(
            $this->propertyId,
            $this->month,
            $this->reservations,
            $this->nights,
            $this->payout,
            $amount(ExtraType::Concierge, 'ht'),
            $amount(ExtraType::Concierge, 'ttc'),
            $amount(ExtraType::Linen, 'ht'),
            $amount(ExtraType::Linen, 'ttc'),
            $amount(ExtraType::Cleaning, 'ht'),
            $amount(ExtraType::Cleaning, 'ttc'),
            $this->propertyId === null ? 0 : $softwareCostCents,
        );
    }
}

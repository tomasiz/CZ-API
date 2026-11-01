<?php

declare(strict_types=1);

namespace Casamiza\Domain\OwnerStats;

/** Activité d'un logement pour un mois et une plateforme. */
final readonly class PlatformMonth
{
    public function __construct(
        public string $propertyId,
        public string $month,
        public string $platform,
        public int $reservations,
        public int $nights,
        public int $payoutCents,
    ) {}
}

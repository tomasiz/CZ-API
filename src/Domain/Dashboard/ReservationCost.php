<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

use DateTimeImmutable;

/**
 * Coût interne (commission Beyond, en centimes) : logement et instant du check-out, pour le rattacher à un mois. Porté par une
 * réservation, ou par une ligne de relevé sans réservation (jour du check-out déduit du check-in et des nuits ; logement nul si
 * aucun logement de Casamiza ne porte le titre de l'annonce).
 */
final readonly class ReservationCost
{
    public function __construct(
        public ?string $propertyId,
        public DateTimeImmutable $checkOut,
        public int $cents,
    ) {}
}

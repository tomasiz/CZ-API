<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use DateTimeImmutable;

/**
 * Ligne de relevé Beyond enregistrée, avec le relevé, la réservation et le logement auxquels elle est rattachée.
 *
 * Sans réservation (nulle) : la réservation n'existe ni dans Casamiza ni chez Guesty ; le coût compte alors pour le logement et
 * le mois du séjour. Sans logement (nul) : aucun logement de Casamiza ne porte le titre de l'annonce.
 */
final readonly class BeyondFeeItem
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string $statementId
     */
    public function __construct(
        public string $id,
        public string $statementId,
        public BeyondFeeLine $line,
        public ?string $reservationId,
        public ?string $propertyId,
        public DateTimeImmutable $importedAt,
    ) {}
}

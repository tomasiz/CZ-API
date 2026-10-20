<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use DateTimeImmutable;

/** Une ligne lue dans le CSV « par réservation » de Beyond (montants signés, en centimes d'euro). */
final readonly class BeyondFeeLine
{
    public function __construct(
        /** Numéro de la ligne dans le fichier (l'en-tête est la ligne 1), pour les avertissements */
        public int $row,
        /** « BP StatementItem ID » : identifiant unique de la ligne chez Beyond */
        public int $statementItemId,
        /** Identifiant Guesty de la réservation (`reservations.guesty_id`) */
        public string $pmsReservationId,
        public string $listingTitle,
        public BeyondFeeKind $kind,
        public string $product,
        public DateTimeImmutable $bookingDate,
        public ?DateTimeImmutable $updatedOn,
        public DateTimeImmutable $checkIn,
        public int $nights,
        public int $earningsCents,
        public int $amountCents,
    ) {}

    /** Jour où Beyond facture la ligne, donc relevé auquel elle appartient : la dernière mise à jour, sinon la prise de réservation. */
    public function billedOn(): DateTimeImmutable
    {
        return $this->updatedOn ?? $this->bookingDate;
    }
}

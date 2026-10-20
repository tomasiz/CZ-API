<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

/** Bilan du traitement d'un relevé Beyond (ou de sa simulation, `applied = false`). Montants en centimes d'euro. */
final readonly class BeyondImportReport
{
    /**
     * @param list<array{row: int, code: string}> $warnings
     * @param list<BeyondReservationFee> $reservations une entrée par réservation du relevé
     */
    public function __construct(
        public bool $applied,
        public BeyondPeriod $period,
        public int $linesRead,
        public int $linesNew,
        public int $linesKnown,
        /** Réservations récupérées chez Guesty parce qu'elles manquaient à Casamiza */
        public int $reservationsFetched,
        public int $amountCents,
        public array $warnings,
        public array $reservations,
        /** Lignes de séjours terminés avant la facturation de Casamiza : lues dans le fichier, ni contrôlées ni enregistrées */
        public int $linesIgnored = 0,
        /** @var list<BeyondFeeLine> lignes sans réservation (ni dans Casamiza ni chez Guesty) : comptées pour le logement et le mois du séjour */
        public array $unmatched = [],
        /** Parmi elles, celles dont aucun logement de Casamiza ne porte le titre : comptées « sans logement » */
        public int $unmatchedWithoutProperty = 0,
    ) {}
}

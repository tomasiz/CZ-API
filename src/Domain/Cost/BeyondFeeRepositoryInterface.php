<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

/** Lignes de relevé Beyond du tenant courant, et coût Beyond porté par chaque réservation (`reservations.beyond_fee`). */
interface BeyondFeeRepositoryInterface
{
    /**
     * Parmi ces identifiants de ligne Beyond, ceux déjà enregistrés, avec le relevé qui les porte.
     *
     * @param list<int> $statementItemIds
     * @return array<int, string> identifiant de ligne Beyond => identifiant du relevé
     */
    public function statementIdsOfItems(array $statementItemIds): array;

    /**
     * Les identifiants de ligne Beyond déjà enregistrés pour ce relevé.
     *
     * @return list<int>
     */
    public function itemIdsOfStatement(string $statementId): array;

    /**
     * Réservations (archivées comprises) retrouvées par leur identifiant Guesty.
     *
     * @param list<string> $guestyIds
     * @return array<string, ReservationLink> par identifiant Guesty
     */
    public function reservationsByGuestyId(array $guestyIds): array;

    /** @param list<BeyondFeeItem> $items */
    public function addItems(array $items): void;

    /**
     * Réécrit `reservations.beyond_fee` de ces réservations comme la somme de leurs lignes (une annulation y compense la commission).
     *
     * @param list<string> $reservationIds
     */
    public function recomputeReservationFees(array $reservationIds): void;
}

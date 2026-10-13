<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

use DateTimeImmutable;

/** Réservations du tenant courant (signalements inclus). */
interface ReservationRepositoryInterface
{
    /**
     * Réservations **non archivées** dont le check-out tombe dans `[$from, $to[`, triées par check-out.
     *
     * @return list<Reservation>
     */
    public function checkingOutBetween(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Toutes les réservations dont le check-out est postérieur ou égal à `$from`, archivées comprises (pour la synchronisation).
     *
     * @return list<Reservation>
     */
    public function allCheckingOutSince(DateTimeImmutable $from): array;

    /**
     * Recherche paginée, filtrée et triée côté base. Le texte libre porte sur tout élément de la réservation : voyageur,
     * logement (nom, adresse), propriétaire (nom, raison sociale), plateforme et sa référence, identifiant Guesty, dates
     * (`jj/mm/aaaa` ou `aaaa-mm-jj`), nombre de nuits et de voyageurs, et tous les montants.
     */
    public function search(ReservationQuery $query): ReservationPage;

    public function findByGuestyId(string $guestyId): ?Reservation;

    /** Insère la réservation et ses signalements. */
    public function add(Reservation $reservation): void;

    /** Réécrit la réservation et remplace ses signalements. */
    public function update(Reservation $reservation): void;
}

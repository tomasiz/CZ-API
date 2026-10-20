<?php

declare(strict_types=1);

namespace Casamiza\Domain\Guesty;

use DateTimeImmutable;

/** Réservations Guesty du tenant courant. Parcourt **toutes** les pages : une liste partielle ne doit jamais être renvoyée. */
interface GuestyReservationsInterface
{
    /**
     * Réservations `confirmed` dont le check-out est postérieur ou égal à `$from` et antérieur à `$before` (sans borne haute si
     * `$before` est nul : tous les mois à venir).
     *
     * @return list<GuestyReservation>
     * @throws \Casamiza\Shared\Exception\ApiException Guesty injoignable, identifiants refusés, limite atteinte…
     */
    public function reservations(DateTimeImmutable $from, ?DateTimeImmutable $before = null): array;

    /**
     * Ces réservations, **quel que soit leur statut** (une annulation reste lisible, `confirmed = false`). Un identifiant que
     * Guesty ne connaît pas est simplement absent du résultat.
     *
     * @param list<string> $guestyIds
     * @return list<GuestyReservation>
     * @throws \Casamiza\Shared\Exception\ApiException
     */
    public function reservationsByIds(array $guestyIds): array;
}

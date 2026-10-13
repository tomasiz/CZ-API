<?php

declare(strict_types=1);

namespace Casamiza\Domain\Guesty;

use DateTimeImmutable;

/** Réservations Guesty du tenant courant. Parcourt **toutes** les pages : une liste partielle ne doit jamais être renvoyée. */
interface GuestyReservationsInterface
{
    /**
     * Réservations `confirmed` dont le check-out est postérieur ou égal à `$from` (sans borne haute : tous les mois à venir).
     *
     * @return list<GuestyReservation>
     * @throws \Casamiza\Shared\Exception\ApiException Guesty injoignable, identifiants refusés, limite atteinte…
     */
    public function reservations(DateTimeImmutable $from): array;
}

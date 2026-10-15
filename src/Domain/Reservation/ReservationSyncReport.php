<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

use Casamiza\Domain\Guesty\SyncCounts;
use DateTimeImmutable;

final readonly class ReservationSyncReport
{
    public function __construct(
        public SyncCounts $reservations,
        /** Réservations ignorées : leur logement est inconnu de Casamiza (jamais synchronisé). */
        public int $skipped,
        /** Lectures effectuées chez Stripe pendant cette synchronisation. */
        public int $stripeLookups,
        public DateTimeImmutable $from,
        public DateTimeImmutable $syncedAt,
        /** Borne haute (exclue) de la fenêtre ; nulle = tous les mois à venir. */
        public ?DateTimeImmutable $before = null,
    ) {}
}

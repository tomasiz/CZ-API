<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

/** Frais de paiement lus chez Stripe pour une réservation (somme des frais des paiements Stripe réussis). */
final readonly class StripeFees
{
    public function __construct(
        public float $amount,
        /** Code d'erreur Stripe si au moins une lecture a échoué ; les frais déjà lus restent comptés. */
        public ?string $errorCode = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

/** Lecture des frais d'un paiement chez Stripe, avec la clé du propriétaire. */
interface StripeFeesGatewayInterface
{
    /**
     * Frais retenus par Stripe sur un paiement (PaymentIntent). Une erreur n'est pas une exception : elle revient dans
     * `StripeFees::$errorCode` (code Stripe, ou `unreachable` / `no_balance_transaction`), car elle ne doit pas bloquer
     * le calcul des autres réservations.
     *
     * @param non-empty-string $apiKey clé secrète Stripe du propriétaire, en clair (ne jamais la journaliser)
     */
    public function paymentIntentFees(string $apiKey, string $paymentIntentId): StripeFees;
}

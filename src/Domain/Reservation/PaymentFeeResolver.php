<?php

declare(strict_types=1);

namespace Casamiza\Domain\Reservation;

use Casamiza\Domain\Owner\Owner;
use Casamiza\Infrastructure\Security\Encryptor;
use RuntimeException;

/**
 * Frais de paiement d'une réservation lus chez Stripe, avec la clé du propriétaire, quand Guesty n'en donne pas.
 * Renvoie null quand aucune lecture n'est utile ou possible (frais déjà connus, aucun paiement Stripe, pas de clé).
 */
final readonly class PaymentFeeResolver
{
    public function __construct(
        private StripeFeesGatewayInterface $stripe,
        private Encryptor $encryptor,
    ) {}

    public function resolve(Reservation $reservation, ?Owner $owner): ?StripeFees
    {
        // Airbnb : frais inclus dans la commission, forcés à 0 par le calculateur. Séjour propriétaire : aucun montant.
        if ($reservation->totalPaymentFees != 0 || $reservation->platform === Platform::Airbnb || $reservation->platform === Platform::Owner) {
            return null;
        }
        $intents = ReservationCalculator::paymentIntentIds($reservation);
        if ($intents === [] || $owner === null || $owner->stripeSecretEncrypted === null) {
            return null;
        }

        try {
            $key = $this->encryptor->decrypt($owner->stripeSecretEncrypted);
        } catch (RuntimeException) {
            return new StripeFees(0.0, 'stripe_key_unreadable');
        }
        if ($key === '') {
            return null;
        }

        $amount = 0.0;
        $error = null;
        foreach ($intents as $intent) {
            $fees = $this->stripe->paymentIntentFees($key, $intent);
            $amount += $fees->amount;
            $error ??= $fees->errorCode;
        }

        return new StripeFees(round($amount, 2), $error);
    }
}

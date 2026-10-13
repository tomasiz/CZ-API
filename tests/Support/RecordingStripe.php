<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Reservation\StripeFees;
use Casamiza\Domain\Reservation\StripeFeesGatewayInterface;

/** Faux Stripe : frais connus par paiement, appels enregistrés. */
final class RecordingStripe implements StripeFeesGatewayInterface
{
    /** @var list<array{string, string}> (clé, paiement) de chaque appel */
    public array $calls = [];

    public function paymentIntentFees(string $apiKey, string $paymentIntentId): StripeFees
    {
        $this->calls[] = [$apiKey, $paymentIntentId];

        return match ($paymentIntentId) {
            'pi_A' => new StripeFees(10.10),
            'pi_B' => new StripeFees(5.25),
            default => new StripeFees(0.0, 'resource_missing'),
        };
    }
}

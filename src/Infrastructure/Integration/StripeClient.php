<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Integration;

use Casamiza\Domain\Reservation\StripeFees;
use Casamiza\Domain\Reservation\StripeFeesGatewayInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Frais de paiement d'un PaymentIntent Stripe : somme des `fee_details` de la transaction de solde de sa dernière charge.
 * Appel direct à l'API REST (pas de SDK) avec la clé du propriétaire, lecture seule.
 *
 * @see https://docs.stripe.com/api/payment_intents/retrieve
 */
final readonly class StripeClient implements StripeFeesGatewayInterface
{
    private const BASE_URI = 'https://api.stripe.com';

    public function __construct(private ClientInterface $http) {}

    public function paymentIntentFees(string $apiKey, string $paymentIntentId): StripeFees
    {
        try {
            $response = $this->http->request('GET', self::BASE_URI . '/v1/payment_intents/' . rawurlencode($paymentIntentId), [
                'query' => ['expand' => ['latest_charge.balance_transaction']],
                'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
                'http_errors' => false,
                'timeout' => 20,
            ]);
        } catch (GuzzleException) {
            return new StripeFees(0.0, 'unreachable');
        }

        $body = json_decode((string) $response->getBody(), true);
        $body = is_array($body) ? $body : [];

        if ($response->getStatusCode() !== 200) {
            $error = is_array($body['error'] ?? null) ? $body['error'] : [];
            $code = $error['code'] ?? $error['type'] ?? null;

            return new StripeFees(0.0, is_string($code) && $code !== '' ? $code : 'http_' . $response->getStatusCode());
        }

        $charge = $body['latest_charge'] ?? null;
        $transaction = is_array($charge) ? ($charge['balance_transaction'] ?? null) : null;
        $details = is_array($transaction) ? ($transaction['fee_details'] ?? null) : null;
        if (!is_array($details)) {
            // Paiement sans charge ou sans transaction de solde (pas encore réglé) : les frais sont inconnus.
            return new StripeFees(0.0, 'no_balance_transaction');
        }

        $cents = 0;
        foreach ($details as $detail) {
            if (is_array($detail) && is_numeric($detail['amount'] ?? null)) {
                $cents += (int) $detail['amount'];
            }
        }

        return new StripeFees($cents / 100);
    }
}

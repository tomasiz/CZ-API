<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Integration;

use Casamiza\Domain\Integration\AccessToken;
use Casamiza\Domain\Integration\CheckFailure;
use Casamiza\Domain\Integration\CheckResult;
use Casamiza\Domain\Integration\CredentialField;
use Casamiza\Domain\Integration\IntegrationProviderInterface;
use Casamiza\Domain\Integration\Provider;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * MailerSend : clé d'API et identifiant du modèle d'e-mail (le message est la variable `text` du modèle).
 * Pas de jeton à renouveler : la clé est envoyée telle quelle. Le contrôle lit les domaines du compte.
 *
 * @see https://developers.mailersend.com/api/v1/domains.html
 */
final readonly class MailerSendProvider implements IntegrationProviderInterface
{
    public const BASE_URI = 'https://api.mailersend.com';

    public function __construct(private ClientInterface $http) {}

    public function provider(): Provider
    {
        return Provider::MailerSend;
    }

    public function fields(): array
    {
        return [
            new CredentialField('apiKey', secret: true),
            new CredentialField('templateId', maxLength: 64),
        ];
    }

    public function check(array $values, ?AccessToken $cachedToken): CheckResult
    {
        try {
            $response = $this->http->request('GET', self::BASE_URI . '/v1/domains', [
                'query' => ['limit' => 10],
                'headers' => ['Authorization' => 'Bearer ' . ($values['apiKey'] ?? ''), 'Accept' => 'application/json'],
                'http_errors' => false,
                'timeout' => 10,
            ]);
        } catch (GuzzleException) {
            return CheckResult::failed(CheckFailure::Unreachable);
        }

        return match (true) {
            $response->getStatusCode() === 200 => CheckResult::connected(),
            $response->getStatusCode() === 401 => CheckResult::failed(CheckFailure::InvalidCredentials),
            // Une clé limitée à l'envoi n'a pas le droit de lire les domaines : elle reste valable pour envoyer.
            $response->getStatusCode() === 403 => CheckResult::connected(),
            $response->getStatusCode() === 429 => CheckResult::failed(CheckFailure::RateLimited),
            default => CheckResult::failed(CheckFailure::Unreachable),
        };
    }
}

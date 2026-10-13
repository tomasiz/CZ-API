<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Integration;

use Casamiza\Domain\Integration\AccessToken;
use Casamiza\Domain\Integration\CheckFailure;
use Casamiza\Domain\Integration\CheckResult;
use Casamiza\Domain\Integration\CredentialField;
use Casamiza\Domain\Integration\IntegrationProviderInterface;
use Casamiza\Domain\Integration\Provider;
use Casamiza\Shared\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Guesty Open API : OAuth2 `client_credentials`. Le jeton vaut 24 h mais Guesty n'en délivre que 5 par 24 h et par
 * client_id : il est donc mis en cache (par IntegrationService) et réutilisé, y compris par les contrôles de la page.
 *
 * @see https://open-api-docs.guesty.com/docs/authentication
 */
final readonly class GuestyProvider implements IntegrationProviderInterface
{
    public const BASE_URI = 'https://open-api.guesty.com';

    public function __construct(
        private ClientInterface $http,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private Settings $settings,
    ) {}

    public function provider(): Provider
    {
        return Provider::Guesty;
    }

    public function fields(): array
    {
        return [
            new CredentialField('clientId', secret: true),
            new CredentialField('clientSecret', secret: true),
        ];
    }

    public function check(array $values, ?AccessToken $cachedToken): CheckResult
    {
        $token = $cachedToken !== null && $cachedToken->isValidAt($this->clock->now()) ? $cachedToken : null;
        $fresh = false;
        if ($token === null) {
            $issued = $this->requestToken($values['clientId'] ?? '', $values['clientSecret'] ?? '');
            if ($issued instanceof CheckFailure) {
                return CheckResult::failed($issued);
            }
            $token = $issued;
            $fresh = true;
        }

        $status = $this->probe($token);
        if ($status === 401 && !$fresh) {
            // Jeton révoqué côté Guesty malgré une date valide : un seul renouvellement, sans boucle.
            $issued = $this->requestToken($values['clientId'] ?? '', $values['clientSecret'] ?? '');
            if ($issued instanceof CheckFailure) {
                return CheckResult::failed($issued);
            }
            $token = $issued;
            $status = $this->probe($token);
        }

        return match (true) {
            $status === 200 => CheckResult::connected($token),
            $status === 401 => CheckResult::failed(CheckFailure::InvalidCredentials),
            $status === 403 => CheckResult::failed(CheckFailure::Forbidden, $token),
            $status === 429 => CheckResult::failed(CheckFailure::RateLimited, $token),
            default => CheckResult::failed(CheckFailure::Unreachable, $token),
        };
    }

    private function requestToken(string $clientId, string $clientSecret): AccessToken|CheckFailure
    {
        try {
            $response = $this->http->request('POST', self::BASE_URI . '/oauth2/token', [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                    'scope' => 'open-api',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ],
                'headers' => ['Accept' => 'application/json'],
                'http_errors' => false,
                'timeout' => 10,
            ]);
        } catch (GuzzleException) {
            return CheckFailure::Unreachable;
        }

        $status = $response->getStatusCode();
        if ($status === 200) {
            $body = $this->decode($response);
            $value = $body['access_token'] ?? null;
            $ttl = $body['expires_in'] ?? null;
            if (is_string($value) && $value !== '' && is_int($ttl)) {
                return new AccessToken($value, $this->clock->now()->modify("+{$ttl} seconds"));
            }

            return CheckFailure::Unreachable;
        }

        return match (true) {
            $status === 429 => CheckFailure::RateLimited,
            $status === 400 || $status === 401 || $status === 403 => CheckFailure::InvalidCredentials,
            default => CheckFailure::Unreachable,
        };
    }

    /** Appel minimal qui prouve que le jeton ouvre bien l'API : lecture du compte Guesty lié au jeton. */
    private function probe(AccessToken $token): int
    {
        try {
            $response = $this->http->request('GET', self::BASE_URI . '/v1/accounts/me', [
                'headers' => ['Authorization' => 'Bearer ' . $token->value, 'Accept' => 'application/json'],
                'http_errors' => false,
                'timeout' => 10,
            ]);
        } catch (GuzzleException) {
            return 0;
        }

        // Dev uniquement (journal de l'API) : en production, la réponse du tiers n'est jamais journalisée.
        if (!$this->settings->isProd()) {
            $this->logger->debug('Guesty GET /v1/accounts/me', [
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getBody(),
            ]);
        }

        return $response->getStatusCode();
    }

    /** @return array<array-key, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}

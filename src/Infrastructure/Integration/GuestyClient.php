<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Integration;

use Casamiza\Domain\Guesty\GuestyDirectoryInterface;
use Casamiza\Domain\Guesty\GuestyListing;
use Casamiza\Domain\Guesty\GuestyOwner;
use Casamiza\Domain\Integration\CheckFailure;
use Casamiza\Domain\Integration\IntegrationService;
use Casamiza\Domain\Integration\Provider;
use Casamiza\Domain\Owner\OwnerType;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Lecture des propriétaires et des logements de Guesty (Open API). Le jeton vient d'IntegrationService (cache chiffré,
 * 5 jetons par jour seulement) ; sur un 401 il est renouvelé une fois, pas davantage.
 *
 * Pagination : `/v1/listings` répond `{results, count, limit, skip}` ; `/v1/owners` ne la renvoie que si
 * `pagination=true` (sinon un tableau brut de tous les propriétaires, sans tenir compte de `limit`/`skip`).
 * Un tableau brut est donc traité comme une liste complète.
 *
 * @see https://open-api-docs.guesty.com/reference/ownersopenapicontroller_getall
 */
final readonly class GuestyClient implements GuestyDirectoryInterface
{
    private const PAGE_SIZE = 100;

    /** Garde-fou contre une pagination qui ne se termine jamais (20 000 éléments). */
    private const MAX_PAGES = 200;

    /** Logements interrogés au plus pour retrouver les identifiants des champs de coûts. */
    private const FEE_LOOKUPS = 3;

    public function __construct(
        private ClientInterface $http,
        private IntegrationService $integrations,
    ) {}

    public function owners(): array
    {
        $rows = $this->fetchAll($this->session(), '/v1/owners', [
            'pagination' => 'true',
            'fields' => '_id fullName firstName lastName email phone address personalAddress active businessInformation',
        ]);

        $owners = [];
        foreach ($rows as $row) {
            $id = self::string($row['_id'] ?? null);
            if ($id === '') {
                continue;
            }
            $email = self::string($row['email'] ?? null);
            $name = self::string($row['fullName'] ?? null);
            if ($name === '') {
                $name = trim(self::string($row['firstName'] ?? null) . ' ' . self::string($row['lastName'] ?? null));
            }
            // Convention de nommage du compte Guesty : « P - Prénom Nom » ; le préfixe n'a pas sa place chez nous.
            $name = trim((string) preg_replace('/^P\s*-\s*/u', '', $name));
            $business = self::map($row['businessInformation'] ?? null);
            $commission = $business['ownerCommission'] ?? null;
            $businessType = self::string(self::map($business['businessType'] ?? null)['type'] ?? null);

            $owners[] = new GuestyOwner(
                guestyId: $id,
                name: $name !== '' ? $name : $email,
                email: $email,
                phone: self::string($row['phone'] ?? null),
                address: self::firstNonEmpty(
                    self::string($row['address'] ?? null),
                    self::string(self::map($row['personalAddress'] ?? null)['full'] ?? null),
                    self::string(self::map($business['address'] ?? null)['full'] ?? null),
                ),
                // INDIVIDUAL, ou rien de renseigné : particulier ; CORPORATION, OTHER (SAS…) : entreprise.
                type: $businessType === '' || $businessType === 'INDIVIDUAL' ? OwnerType::Individual : OwnerType::Company,
                commission: is_int($commission) || is_float($commission) ? (float) $commission : null,
                active: ($row['active'] ?? true) !== false,
            );
        }

        return $owners;
    }

    public function listings(): array
    {
        $session = $this->session();
        $rows = $this->fetchAll($session, '/v1/listings', ['fields' => '_id title nickname address active owners customFields']);
        $fieldIds = $this->feeFieldIds($session, $rows);

        $listings = [];
        foreach ($rows as $row) {
            $id = self::string($row['_id'] ?? null);
            if ($id === '') {
                continue;
            }
            $owners = $row['owners'] ?? null;
            $ownerId = null;
            foreach (is_array($owners) ? $owners : [] as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $ownerId = $candidate;
                    break;
                }
            }
            $name = self::firstNonEmpty(self::string($row['nickname'] ?? null), self::string($row['title'] ?? null));

            $listings[] = new GuestyListing(
                guestyId: $id,
                name: $name !== '' ? $name : $id,
                address: self::string(self::map($row['address'] ?? null)['full'] ?? null),
                active: ($row['active'] ?? true) !== false,
                ownerGuestyId: $ownerId,
                cleaningFee: self::customFieldAmount($row['customFields'] ?? null, $fieldIds['cleaning']),
                linenFee: self::customFieldAmount($row['customFields'] ?? null, $fieldIds['linen']),
            );
        }

        return $listings;
    }

    /**
     * Identifiants des champs personnalisés `cout_ménage` et `cout_linge`. Guesty ne donne les noms des champs que
     * logement par logement : on interroge celui qui en renseigne le plus (il les porte presque sûrement tous), et on
     * en essaie quelques autres si l'un manque. Les identifiants sont ceux du compte, identiques pour tous les logements.
     *
     * @param list<array<array-key, mixed>> $rows
     * @return array{cleaning: string|null, linen: string|null}
     */
    private function feeFieldIds(GuestySession $session, array $rows): array
    {
        $candidates = array_filter($rows, static fn(array $row): bool => is_array($row['customFields'] ?? null) && $row['customFields'] !== []);
        usort($candidates, static fn(array $a, array $b): int => count(self::map($b['customFields'])) <=> count(self::map($a['customFields'])));

        $ids = ['cleaning' => null, 'linen' => null];
        foreach (array_slice($candidates, 0, self::FEE_LOOKUPS) as $row) {
            $body = $this->getJson($session, '/v1/properties-api/custom-fields/' . rawurlencode(self::string($row['_id'] ?? null)));
            foreach (self::rows(self::map($body['customFields'] ?? null)) as $field) {
                $fieldId = self::string($field['fieldId'] ?? null);
                // « cout_ménage » : Guesty en garde parfois la version sans accent dans `displayName`.
                $names = array_map(
                    static fn(mixed $name): string => (string) preg_replace('/[^a-z_]/', '', strtolower(self::string($name))),
                    [$field['key'] ?? null, $field['displayName'] ?? null],
                );
                if ($fieldId === '') {
                    continue;
                }
                if (array_intersect($names, ['cout_mnage', 'cout_menage']) !== []) {
                    $ids['cleaning'] = $fieldId;
                } elseif (in_array('cout_linge', $names, true)) {
                    $ids['linen'] = $fieldId;
                }
            }
            if ($ids['cleaning'] !== null && $ids['linen'] !== null) {
                break;
            }
        }

        return $ids;
    }

    /** Montant d'un champ personnalisé numérique d'un logement ; 0 s'il n'est pas renseigné ou pas numérique. */
    private static function customFieldAmount(mixed $customFields, ?string $fieldId): float
    {
        if ($fieldId === null) {
            return 0.0;
        }
        foreach (self::rows(self::map($customFields)) as $field) {
            if (($field['fieldId'] ?? null) === $fieldId) {
                $value = $field['value'] ?? null;

                return is_numeric($value) ? max(0.0, (float) $value) : 0.0;
            }
        }

        return 0.0;
    }

    /**
     * Parcourt toutes les pages d'une ressource.
     *
     * @param array<string, string> $query
     * @return list<array<array-key, mixed>>
     * @throws \Casamiza\Shared\Exception\ApiException
     */
    private function fetchAll(GuestySession $session, string $path, array $query): array
    {
        $items = [];
        $skip = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->getJson($session, $path, $query + ['limit' => (string) self::PAGE_SIZE, 'skip' => (string) $skip]);

            if (array_is_list($body)) {
                // Tableau brut : Guesty n'a pas paginé, c'est la liste complète.
                return array_merge($items, self::rows($body));
            }
            $results = $body['results'] ?? null;
            if (!is_array($results)) {
                throw CheckFailure::Unreachable->toException();
            }
            $rows = self::rows($results);
            $items = array_merge($items, $rows);
            $skip += count($results);

            $total = $body['count'] ?? null;
            if ($results === [] || (is_int($total) && $skip >= $total)) {
                return $items;
            }
        }

        throw CheckFailure::Unreachable->toException();
    }

    /**
     * GET authentifié ; sur un 401 le jeton est renouvelé une seule fois pour toute la session, puis l'appel rejoué.
     *
     * @param array<string, string> $params
     * @return array<array-key, mixed>
     * @throws \Casamiza\Shared\Exception\ApiException
     */
    private function getJson(GuestySession $session, string $path, array $params = []): array
    {
        $response = $this->get($path, $params, $session->token);
        if ($response->getStatusCode() === 401 && !$session->renewed) {
            $session->token = $this->integrations->accessToken(Provider::Guesty->value, renew: true);
            $session->renewed = true;
            $response = $this->get($path, $params, $session->token);
        }

        return $this->decode($response);
    }

    private function session(): GuestySession
    {
        return new GuestySession($this->integrations->accessToken(Provider::Guesty->value));
    }

    /** @param array<string, string> $params */
    private function get(string $path, array $params, string $token): ResponseInterface
    {
        try {
            return $this->http->request('GET', GuestyProvider::BASE_URI . $path, [
                'query' => $params,
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                'http_errors' => false,
                'timeout' => 30,
            ]);
        } catch (GuzzleException) {
            throw CheckFailure::Unreachable->toException();
        }
    }

    /** @return array<array-key, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        if ($status !== 200) {
            $failure = match ($status) {
                401 => CheckFailure::InvalidCredentials,
                403 => CheckFailure::Forbidden,
                429 => CheckFailure::RateLimited,
                default => CheckFailure::Unreachable,
            };

            throw $failure->toException();
        }
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw CheckFailure::Unreachable->toException();
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $items
     * @return list<array<array-key, mixed>>
     */
    private static function rows(array $items): array
    {
        return array_values(array_filter($items, is_array(...)));
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}

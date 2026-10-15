<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Integration;

use Casamiza\Domain\Guesty\GuestyDirectoryInterface;
use Casamiza\Domain\Guesty\GuestyListing;
use Casamiza\Domain\Guesty\GuestyOwner;
use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Guesty\GuestyReservationsInterface;
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
final readonly class GuestyClient implements GuestyDirectoryInterface, GuestyReservationsInterface
{
    private const PAGE_SIZE = 100;

    /** Pause entre deux pages d'une même ressource (bien en deçà des 15 requêtes par seconde de Guesty). */
    private const PAGE_PAUSE_MICROSECONDS = 100_000;

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
            'fields' => '_id fullName firstName lastName email phone address personalAddress active businessInformation listings',
        ]);

        $owners = [];
        foreach ($rows as $row) {
            $id = self::string($row['_id'] ?? null);
            if ($id === '') {
                continue;
            }
            $email = self::string($row['email'] ?? null);
            $firstName = self::withoutOwnerPrefix(self::string($row['firstName'] ?? null));
            $lastName = self::withoutOwnerPrefix(self::string($row['lastName'] ?? null), false);
            $name = self::withoutOwnerPrefix(self::string($row['fullName'] ?? null));
            if ($name === '') {
                $name = self::withoutOwnerPrefix($firstName . ' ' . $lastName, false);
            }
            if ($firstName === '' && $lastName === '' && $name !== '') {
                // Guesty ne sépare pas le nom : le dernier mot est le nom de famille.
                $cut = mb_strrpos($name, ' ');
                $firstName = $cut === false ? '' : trim(mb_substr($name, 0, $cut));
                $lastName = $cut === false ? $name : trim(mb_substr($name, $cut + 1));
            }
            $business = self::map($row['businessInformation'] ?? null);
            $commission = $business['ownerCommission'] ?? null;
            $businessType = self::string(self::map($business['businessType'] ?? null)['type'] ?? null);

            $owners[] = new GuestyOwner(
                guestyId: $id,
                name: $name !== '' ? $name : $email,
                firstName: $firstName,
                lastName: $lastName,
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
                hasActiveListing: self::hasActiveListing($row['listings'] ?? null),
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

    public function reservations(\DateTimeImmutable $from): array
    {
        $filters = json_encode([
            ['field' => 'status', 'operator' => '$eq', 'value' => 'confirmed'],
            ['field' => 'checkOut', 'operator' => '$gte', 'value' => $from->format('Y-m-d')],
        ], JSON_THROW_ON_ERROR);
        // Sous-champs de `money` listés un à un : demander `money` en entier renvoie des centaines de lignes de détail.
        $rows = $this->fetchAll($this->session(), '/v1/reservations', [
            'filters' => $filters,
            'sort' => 'checkOut',
            'fields' => '_id listingId source status checkIn checkOut guest.fullName integration nightsCount guestsCount'
                . ' money.hostPayout money.hostServiceFee money.totalPaymentFees money.totalTaxes money.fareCleaning money.totalFees'
                . ' money.transientOccupancyTax money.fareAccommodationAdjusted money.payments atTimeOfConfirmation.taxes',
        ]);

        $reservations = [];
        foreach ($rows as $row) {
            $id = self::string($row['_id'] ?? null);
            $checkIn = self::date($row['checkIn'] ?? null);
            $checkOut = self::date($row['checkOut'] ?? null);
            if ($id === '' || $checkIn === null || $checkOut === null) {
                continue;
            }
            $listing = self::string($row['listingId'] ?? null);
            $integration = self::map($row['integration'] ?? null);
            $money = self::map($row['money'] ?? null);

            $reservations[] = new GuestyReservation(
                guestyId: $id,
                listingGuestyId: $listing !== '' ? $listing : self::string(self::map($row['listing'] ?? null)['_id'] ?? null),
                source: self::string($row['source'] ?? null),
                integrationKeys: array_map(strval(...), array_keys($integration)),
                platformRef: self::platformRef($integration),
                guest: self::string(self::map($row['guest'] ?? null)['fullName'] ?? null),
                checkIn: $checkIn,
                checkOut: $checkOut,
                nights: self::int($row['nightsCount'] ?? null),
                guests: self::int($row['guestsCount'] ?? null),
                hostPayout: self::amount($money['hostPayout'] ?? null),
                hostServiceFee: self::amount($money['hostServiceFee'] ?? null),
                totalPaymentFees: self::amount($money['totalPaymentFees'] ?? null),
                totalTaxes: self::amount($money['totalTaxes'] ?? null),
                fareCleaning: self::amount($money['fareCleaning'] ?? null),
                totalFees: self::amount($money['totalFees'] ?? null),
                transientOccupancyTax: self::amount($money['transientOccupancyTax'] ?? null),
                fareAccommodationAdjusted: self::amount($money['fareAccommodationAdjusted'] ?? null),
                payments: self::map($money['payments'] ?? null),
                confirmationTaxes: self::map(self::map($row['atTimeOfConfirmation'] ?? null)['taxes'] ?? null),
            );
        }

        return $reservations;
    }

    /**
     * Référence de la réservation côté plateforme : `integration.<platform>.reservationId` (Booking.com) ou `.id` (Airbnb).
     * La clé est `integration.platform` (`bookingCom`, `airbnb2`), pas `source` (« Booking.com »). Vide pour Abritel et les
     * réservations manuelles.
     *
     * @param array<array-key, mixed> $integration
     */
    private static function platformRef(array $integration): string
    {
        $details = self::map($integration[self::string($integration['platform'] ?? null)] ?? null);
        $ref = $details['reservationId'] ?? $details['id'] ?? null;

        return is_string($ref) || is_int($ref) ? trim((string) $ref) : '';
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function amount(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
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
            if ($page > 0) {
                // Open API : 15 requêtes par seconde, 120 par minute, 5 000 par heure.
                usleep(self::PAGE_PAUSE_MICROSECONDS);
            }
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

    /** Vrai si un des logements embarqués dans le propriétaire est actif (un `active` absent compte comme actif). */
    private static function hasActiveListing(mixed $listings): bool
    {
        foreach (self::rows(self::map($listings)) as $listing) {
            if (($listing['active'] ?? true) !== false) {
                return true;
            }
        }

        return false;
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

    /**
     * Nettoie un nom Guesty : espaces superflus retirés (Guesty en laisse en fin de prénom) et, pour un prénom ou un nom
     * complet, préfixe « P - » (convention du compte : « P - Prénom Nom ») qui n'a sa place ni en base ni sur les PDF.
     */
    private static function withoutOwnerPrefix(string $name, bool $prefix = true): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return $prefix ? trim((string) preg_replace('/^P\s*-\s*/u', '', $name)) : $name;
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

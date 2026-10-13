<?php

declare(strict_types=1);

namespace Casamiza\Tests\Reservation;

use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\CalculatedAmounts;
use Casamiza\Domain\Reservation\FinancialField;
use Casamiza\Domain\Reservation\FlagReason;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Domain\Reservation\ReservationFlag;
use Casamiza\Tests\Auth\AuthTestCase;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;

/** `GET /reservations` : recherche, filtres, tri et pagination côté API. */
final class ReservationListTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->context->set('tenant-a');
        $this->owners->add(new Owner('owner-1', 'GO1', 'Alice Martin', 'Alice', 'Martin', '', '', '', OwnerType::Company, 'SCI Les Pins', '', 20.0, null, null));
        $this->owners->add(new Owner('owner-2', 'GO2', 'Bob Durand', 'Bob', 'Durand', '', '', '', OwnerType::Individual, '', '', 15.0, null, null));
        $this->properties->add(new Property('prop-1', 'L1', 'owner-1', 'Villa Santa', '2 quai Napoléon, Ajaccio', 80.0, 20.0, null));
        $this->properties->add(new Property('prop-2', 'L2', 'owner-2', 'Studio Port', '9 cours Grandval, Ajaccio', 50.0, 10.0, null));
        // Septembre 2026 : trois séjours ; octobre : un ; un séjour archivé.
        $this->add('res-1', 'owner-1', 'prop-1', 'airbnb2', 'Jean Dupont', '2026-09-02', '2026-09-05', 602.5, 84.1, 336.4, ref: 'HMKDKMHT42');
        $this->add('res-2', 'owner-2', 'prop-2', 'Booking.com', 'Chloé Marchetti', '2026-09-10', '2026-09-14', 451.84, 46.76, 165.8, ref: '4411829837', flagged: true);
        $this->add('res-3', 'owner-1', 'prop-1', 'website', 'Zoé Ferrari', '2026-09-20', '2026-09-30', 1200.0, 180.0, 800.0);
        $this->add('res-4', 'owner-1', 'prop-1', 'airbnb2', 'Paul Martin', '2026-10-01', '2026-10-04', 300.0, 40.0, 100.0);
        $this->add('res-old', 'owner-1', 'prop-1', 'airbnb2', 'Annulé Annie', '2026-09-03', '2026-09-06', 999.0, 1.0, 1.0, archived: true);
        $this->context->reset();

        $this->context->set('tenant-b');
        $this->owners->add(new Owner('owner-b', 'GOB', 'Autre Propriétaire', 'Autre', 'Propriétaire', '', '', '', OwnerType::Individual, '', '', 10.0, null, null));
        $this->properties->add(new Property('prop-b', 'LB', 'owner-b', 'Chalet', '', 0.0, 0.0, null));
        $this->add('res-b', 'owner-b', 'prop-b', 'airbnb2', 'Jean Dupont', '2026-09-08', '2026-09-12', 100.0, 10.0, 50.0);
        $this->context->reset();
    }

    /** @param non-empty-string $id */
    private function add(string $id, string $ownerId, string $propertyId, string $source, string $guest, string $checkIn, string $checkOut, float $total, float $concierge, float $revenue, string $ref = '', bool $flagged = false, bool $archived = false): void
    {
        $reservation = Reservation::fromGuesty($id, new GuestyReservation(
            'G-' . $id,
            'L',
            $source,
            [],
            $ref,
            $guest,
            new DateTimeImmutable($checkIn . ' 14:00:00 UTC'),
            new DateTimeImmutable($checkOut . ' 08:00:00 UTC'),
            (int) (new DateTimeImmutable($checkIn))->diff(new DateTimeImmutable($checkOut))->days,
            2,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            [],
            [],
        ), $ownerId, $propertyId)->withCalculation(new CalculatedAmounts(
            $total,
            round($total / 10, 2),
            0.0,
            12.0,
            80.0,
            round($total - $total / 10 - 12, 2),
            $concierge,
            $revenue,
            $flagged ? [new ReservationFlag(FinancialField::TouristTax, FlagReason::PossibleVat)] : [],
        ));
        $this->reservations->add($archived ? $reservation->archived(new DateTimeImmutable('2026-10-01 UTC')) : $reservation);
    }

    private function get(string $query = '', string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, 'GET', '/api/v1/reservations' . ($query === '' ? '' : '?' . $query), $this->bearer($this->accessToken($email)));
    }

    /** @return array<string, mixed> */
    private function page(string $query = '', string $email = 'alice@a.test'): array
    {
        $response = $this->get($query, $email);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    /** @return list<string> identifiants des réservations d'une page, dans l'ordre */
    private function ids(string $query = ''): array
    {
        $items = $this->page($query)['items'];
        self::assertIsArray($items);

        return array_values(array_map(static fn(mixed $item): string => is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : '', $items));
    }

    public function testListNeedsAnAuthenticatedUser(): void
    {
        self::assertSame(401, $this->request($this->app, 'GET', '/api/v1/reservations')->getStatusCode());
    }

    public function testManagersCanReadReservations(): void
    {
        self::assertSame(3, $this->page('month=2026-09', 'marc@a.test')['total']);
    }

    public function testAMonthListsReservationsByCheckOutOrderedByCheckOutWithoutArchivedOnes(): void
    {
        $page = $this->page('month=2026-09');

        self::assertSame(['res-1', 'res-2', 'res-3'], $this->ids('month=2026-09'));
        self::assertSame(3, $page['total']);
        self::assertSame(1, $page['page']);
        self::assertSame(50, $page['perPage']);
        // Une arrivée en septembre et un départ en octobre appartiennent à octobre.
        self::assertSame(['res-4'], $this->ids('month=2026-10'));
        // Sans mois : tous les mois.
        self::assertSame(['res-1', 'res-2', 'res-3', 'res-4'], $this->ids());
    }

    public function testAReservationIsDescribedWithItsAmountsOwnerPropertyAndFlags(): void
    {
        $items = $this->page('month=2026-09&flagged=flagged')['items'];

        self::assertIsArray($items);
        self::assertEquals([[
            'id' => 'res-2',
            'guestyId' => 'G-res-2',
            'ownerId' => 'owner-2',
            'ownerName' => 'Bob Durand',
            'propertyId' => 'prop-2',
            'property' => 'Studio Port',
            'guest' => 'Chloé Marchetti',
            'platform' => 'booking',
            'platformRef' => '4411829837',
            'checkIn' => '2026-09-10T14:00:00+00:00',
            'checkOut' => '2026-09-14T08:00:00+00:00',
            'nights' => 4,
            'guests' => 2,
            'totalPaid' => 451.84,
            'platformCommission' => 45.18,
            'paymentFees' => 0,
            'touristTax' => 12,
            'cleaningFee' => 80,
            'netToOwner' => 394.66,
            'conciergeFee' => 46.76,
            'ownerRevenue' => 165.8,
            'flags' => [['field' => 'tourist_tax', 'reason' => 'possible_vat']],
        ]], $items);
    }

    public function testTotalsCoverEveryMatchingReservationNotJustThePage(): void
    {
        $page = $this->page('month=2026-09&perPage=1');

        self::assertCount(1, $page['items'] ?? []);
        self::assertSame(3, $page['total']);
        self::assertEquals(['totalPaid' => 2254.34, 'conciergeFee' => 310.86, 'ownerRevenue' => 1302.2], $page['totals']);
    }

    public function testPaginationSlicesTheResults(): void
    {
        self::assertSame(['res-1', 'res-2'], $this->ids('perPage=2&page=1'));
        self::assertSame(['res-3', 'res-4'], $this->ids('perPage=2&page=2'));
        self::assertSame([], $this->ids('perPage=2&page=3'));
        self::assertSame(4, $this->page('perPage=2&page=3')['total']);
    }

    public function testResultsCanBeSortedByAWhitelistedColumn(): void
    {
        self::assertSame(['res-3', 'res-1', 'res-2', 'res-4'], $this->ids('sort=totalPaid&order=desc'));
        self::assertSame(['res-1', 'res-3', 'res-4', 'res-2'], $this->ids('sort=owner&order=asc'));
        self::assertSame(['res-4', 'res-3', 'res-2', 'res-1'], $this->ids('sort=checkIn&order=desc'));
    }

    public function testResultsCanBeFilteredByOwnerPropertyPlatformAndFlag(): void
    {
        self::assertSame(['res-2'], $this->ids('ownerId=owner-2'));
        self::assertSame(['res-1', 'res-2', 'res-3', 'res-4'], $this->ids('ownerId=owner-1,owner-2'));
        self::assertSame(['res-1', 'res-3', 'res-4'], $this->ids('propertyId=prop-1'));
        self::assertSame(['res-1', 'res-4'], $this->ids('platform=airbnb'));
        self::assertSame(['res-2', 'res-3'], $this->ids('platform=booking,website'));
        self::assertSame(['res-2'], $this->ids('flagged=flagged'));
        self::assertSame(['res-1', 'res-3', 'res-4'], $this->ids('flagged=ok'));
        self::assertSame(['res-3'], $this->ids('month=2026-09&ownerId=owner-1&platform=website'));
    }

    public function testSearchLooksAtEveryElementOfTheReservation(): void
    {
        self::assertSame(['res-2'], $this->ids('q=marchetti'), 'voyageur');
        self::assertSame(['res-1', 'res-3', 'res-4'], $this->ids('q=martin'), 'propriétaire (et voyageur Paul Martin)');
        self::assertSame(['res-1', 'res-3', 'res-4'], $this->ids('q=pins'), 'raison sociale');
        self::assertSame(['res-2'], $this->ids('q=studio'), 'logement');
        self::assertSame(['res-2'], $this->ids('q=grandval'), 'adresse du logement');
        self::assertSame(['res-1'], $this->ids('q=HMKDKMHT'), 'référence plateforme');
        self::assertSame(['res-3'], $this->ids('q=G-res-3'), 'identifiant Guesty');
        self::assertSame(['res-2'], $this->ids('q=booking'), 'plateforme');
        self::assertSame(['res-3'], $this->ids('q=website'), 'source Guesty');
        self::assertSame(['res-1'], $this->ids('q=05/09/2026'), 'date de départ jj/mm/aaaa');
        self::assertSame(['res-2'], $this->ids('q=2026-09-10'), 'date d’arrivée aaaa-mm-jj');
        self::assertSame(['res-1'], $this->ids('q=602,5'), 'montant saisi avec une virgule');
        self::assertSame(['res-3'], $this->ids('q=1200'), 'montant');
        self::assertSame(['res-4'], $this->ids('q=300'), 'montant');
    }

    public function testSearchTermsAreCombinedAndCombinedWithTheFilters(): void
    {
        self::assertSame(['res-4'], $this->ids('q=martin+paul'), 'chaque mot doit se retrouver, dans des éléments différents');
        self::assertSame(['res-1'], $this->ids('q=alice+airbnb&month=2026-09'));
        self::assertSame([], $this->ids('q=alice+booking'));
        self::assertSame([], $this->ids('q=introuvable'));
        // Les caractères joker du texte cherché n'en sont pas.
        self::assertSame([], $this->ids('q=%25'));
    }

    public function testInvalidParametersAreRejectedWithTheirFieldCodes(): void
    {
        foreach ([
            ['month=2026-13', 'month'],
            ['month=septembre', 'month'],
            ['platform=airbnb,pigeon', 'platform'],
            ['flagged=maybe', 'flagged'],
            ['sort=password', 'sort'],
            ['order=sideways', 'order'],
            ['page=0', 'page'],
            ['perPage=0', 'perPage'],
            ['perPage=501', 'perPage'],
            ['page=abc', 'page'],
            ['inconnu=1', 'inconnu'],
        ] as [$query, $field]) {
            $response = $this->get($query);
            self::assertSame(422, $response->getStatusCode(), $query);
            self::assertSame('validation_failed', $this->errorCode($response), $query);
            $error = $this->json($response)['error'];
            self::assertIsArray($error);
            self::assertIsArray($error['fields'] ?? null);
            self::assertArrayHasKey($field, $error['fields'], $query);
        }
    }

    public function testTenantsNeverSeeEachOthersReservations(): void
    {
        self::assertSame(['res-b'], array_map(
            static fn(mixed $item): string => is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : '',
            (array) $this->page('', 'bob@b.test')['items'],
        ));
        // L'identifiant d'un propriétaire d'un autre tenant ne donne rien.
        self::assertSame(0, $this->page('ownerId=owner-1', 'bob@b.test')['total']);
        self::assertSame(0, $this->page('propertyId=prop-1&q=Dupont', 'bob@b.test')['total']);
        self::assertNotContains('res-b', $this->ids());
    }
}

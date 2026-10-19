<?php

declare(strict_types=1);

namespace Casamiza\Tests\Search;

use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\CalculatedAmounts;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Tests\Auth\AuthTestCase;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;

/** `GET /search` : propriétaires, logements, réservations et factures, rôles et isolation des tenants. */
final class SearchTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->context->set('tenant-a');
        $this->owners->add(new Owner('owner-1', 'GO1', 'Hélène Dupont', 'Hélène', 'Dupont', 'helene@example.test', '0612345678', '', OwnerType::Company, 'SCI Les Pins', '12345678901234', 20.0, null, null));
        $this->owners->add(new Owner('owner-2', 'GO2', 'Bob Durand', 'Bob', 'Durand', 'bob@example.test', '', '', OwnerType::Individual, '', '', 15.0, null, new DateTimeImmutable('2026-01-01 UTC')));
        $this->properties->add(new Property('prop-1', 'L1', 'owner-1', 'Villa Santa', '2 quai Napoléon, Ajaccio', 80.0, 20.0, null));
        $this->properties->add(new Property('prop-2', 'L2', 'owner-1', 'Studio Port', '9 cours Grandval, Ajaccio', 50.0, 10.0, new DateTimeImmutable('2026-01-01 UTC')));
        $this->properties->add(new Property('prop-3', 'L3', null, 'Orphelin', '', 0.0, 0.0, null));
        $this->reservation('res-1', 'owner-1', 'prop-1', 'Jean Marchetti', 'HMKDKMHT42', '2026-09-02', '2026-09-05');
        $this->reservation('res-2', 'owner-1', 'prop-1', 'Chloé Marchetti', '', '2026-10-10', '2026-10-14');
        $this->invoice('inv-1', 412, 'owner-1', '2026-08');
        $this->invoice('inv-2', 4120, 'owner-1', '2026-09');
        $this->context->reset();

        $this->context->set('tenant-b');
        $this->owners->add(new Owner('owner-b', 'GOB', 'Hélène Dupont-B', 'Hélène', 'Dupont-B', '', '', '', OwnerType::Individual, 'SCI Autre', '99999999999999', 10.0, null, null));
        $this->properties->add(new Property('prop-b', 'LB', 'owner-b', 'Villa Santa B', '', 0.0, 0.0, null));
        $this->reservation('res-b', 'owner-b', 'prop-b', 'Jean Marchetti', 'REFB', '2026-09-08', '2026-09-12');
        $this->invoice('inv-b', 412, 'owner-b', '2026-08');
        $this->context->reset();
    }

    /** @param non-empty-string $id */
    private function reservation(string $id, string $ownerId, string $propertyId, string $guest, string $ref, string $checkIn, string $checkOut): void
    {
        $this->reservations->add(Reservation::fromGuesty($id, new GuestyReservation(
            'G-' . $id,
            'L',
            'airbnb2',
            [],
            $ref,
            $guest,
            new DateTimeImmutable($checkIn . ' 14:00:00 UTC'),
            new DateTimeImmutable($checkOut . ' 08:00:00 UTC'),
            3,
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
        ), $ownerId, $propertyId)->withCalculation(new CalculatedAmounts(100.0, 10.0, 0.0, 0.0, 0.0, 90.0, 10.0, 80.0, [])));
    }

    private function invoice(string $id, int $number, string $ownerId, string $month): void
    {
        $day = new DateTimeImmutable($month . '-28 UTC');
        $this->invoices->add(new Invoice($id, $number, $ownerId, $month, $day, $day, $day, $day->modify('+1 month'), 100.0, 20.0, 120.0), [], []);
    }

    private function get(string $query, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, 'GET', '/api/v1/search?' . $query, $this->bearer($this->accessToken($email)));
    }

    /** @return array<string, array{items: list<array<string, mixed>>, total: int}> */
    private function results(string $query, string $email = 'alice@a.test'): array
    {
        $response = $this->get($query, $email);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array{items: list<array<string, mixed>>, total: int} $group
     * @return list<mixed>
     */
    private static function ids(array $group): array
    {
        return array_map(static fn(array $item): mixed => $item['id'], $group['items']);
    }

    public function testSearchNeedsAnAuthenticatedUser(): void
    {
        self::assertSame(401, $this->request($this->app, 'GET', '/api/v1/search?q=dupont')->getStatusCode());
    }

    public function testTheQueryIsRequiredAndAtLeastTwoCharactersLong(): void
    {
        $missing = $this->get('');
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($missing));

        $short = $this->get('q=d');
        self::assertSame(422, $short->getStatusCode());
        self::assertSame(['q' => ['too_short']], $this->json($short)['error']['fields']);

        self::assertSame(422, $this->get('q=dupont&limit=99')->getStatusCode());
        self::assertSame(422, $this->get('q=dupont&other=1')->getStatusCode());
    }

    public function testOwnersAreFoundWithoutAccentsOrCaseAndInactiveOnesAreMarked(): void
    {
        $owners = $this->results('q=helene')['owners'];
        self::assertSame(['owner-1'], self::ids($owners));
        self::assertSame(1, $owners['total']);
        self::assertSame(2, $owners['items'][0]['propertyCount']);
        self::assertTrue($owners['items'][0]['active']);

        $inactive = $this->results('q=durand')['owners'];
        self::assertSame(['owner-2'], self::ids($inactive));
        self::assertFalse($inactive['items'][0]['active']);
        // Chaque mot doit se retrouver : prénom et e-mail de deux champs différents.
        self::assertSame(['owner-1'], self::ids($this->results('q=' . rawurlencode('hélène example.test'))['owners']));
        self::assertSame([], $this->results('q=helene+durand')['owners']['items']);
    }

    public function testAdminsAlsoSearchTheCompanyNameAndSiretButManagersDoNot(): void
    {
        self::assertSame(['owner-1'], self::ids($this->results('q=' . rawurlencode('SCI Les Pins'))['owners']));
        self::assertSame(['owner-1'], self::ids($this->results('q=12345678901234')['owners']));

        $manager = $this->results('q=12345678901234', 'marc@a.test');
        self::assertSame([], $manager['owners']['items']);
        self::assertSame([], $this->results('q=' . rawurlencode('SCI Les Pins'), 'marc@a.test')['owners']['items']);
        // Même trouvé par un autre critère, un gestionnaire ne reçoit pas la raison sociale.
        self::assertNull($this->results('q=helene', 'marc@a.test')['owners']['items'][0]['companyName']);
        self::assertSame('SCI Les Pins', $this->results('q=helene')['owners']['items'][0]['companyName']);
    }

    public function testPropertiesAreFoundByNameAddressAndOwnerName(): void
    {
        self::assertSame(['prop-1'], self::ids($this->results('q=santa')['properties']));
        self::assertSame(['prop-1', 'prop-2'], self::ids($this->results('q=ajaccio')['properties']));
        // Par le nom du propriétaire ; le logement inactif passe après et porte son état.
        $byOwner = $this->results('q=dupont')['properties'];
        self::assertSame(['prop-1', 'prop-2'], self::ids($byOwner));
        self::assertSame('Hélène Dupont', $byOwner['items'][0]['ownerName']);
        self::assertFalse($byOwner['items'][1]['active']);
    }

    public function testReservationsAreFoundByGuestAndReferenceNewestFirst(): void
    {
        $byGuest = $this->results('q=marchetti')['reservations'];
        self::assertSame(['res-2', 'res-1'], self::ids($byGuest));
        self::assertSame(2, $byGuest['total']);
        self::assertSame('Chloé Marchetti', $byGuest['items'][0]['guest']);
        self::assertNull($byGuest['items'][0]['platformRef']);

        $byRef = $this->results('q=hmkdkmht42')['reservations'];
        self::assertSame(['res-1'], self::ids($byRef));
        self::assertSame('HMKDKMHT42', $byRef['items'][0]['platformRef']);
        // Managers aussi.
        self::assertSame(['res-1'], self::ids($this->results('q=hmkdkmht42', 'marc@a.test')['reservations']));
    }

    public function testTheLimitCapsTheItemsButNotTheTotal(): void
    {
        $reservations = $this->results('q=marchetti&limit=1')['reservations'];
        self::assertCount(1, $reservations['items']);
        self::assertSame(2, $reservations['total']);
    }

    public function testInvoicesAreFoundByNumberWithTheExactNumberFirst(): void
    {
        foreach (['FZ0000412', 'fz412', '412'] as $query) {
            $invoices = $this->results('q=' . $query)['invoices'];
            self::assertSame(['inv-1', 'inv-2'], self::ids($invoices), $query);
            self::assertSame('FZ0000412', $invoices['items'][0]['displayNumber']);
        }
        self::assertSame(['inv-2', 'inv-1'], self::ids($this->results('q=dupont')['invoices']));
        self::assertSame(['inv-2'], self::ids($this->results('q=2026-09')['invoices']));
        self::assertSame('unpaid', $this->results('q=2026-09')['invoices']['items'][0]['status']);
    }

    public function testManagersNeverReceiveInvoices(): void
    {
        $data = $this->results('q=412', 'marc@a.test');

        self::assertArrayNotHasKey('invoices', $data);
        self::assertArrayHasKey('owners', $data);
    }

    public function testNothingOfAnotherTenantIsFound(): void
    {
        $data = $this->results('q=dupont');
        self::assertSame(['owner-1'], self::ids($data['owners']));
        self::assertSame([], self::ids($this->results('q=' . rawurlencode('Villa Santa B'))['properties']));
        self::assertSame([], self::ids($this->results('q=REFB')['reservations']));
        self::assertSame([], self::ids($this->results('q=SCI+Autre')['owners']));
        self::assertSame(2, $this->results('q=412')['invoices']['total']);

        // Et réciproquement : le tenant B ne voit que les siens.
        $b = $this->results('q=dupont', 'bob@b.test');
        self::assertSame(['owner-b'], self::ids($b['owners']));
        self::assertSame(['res-b'], self::ids($this->results('q=marchetti', 'bob@b.test')['reservations']));
        self::assertSame(['inv-b'], self::ids($this->results('q=412', 'bob@b.test')['invoices']));
    }

    public function testLikeWildcardsAreNotInterpreted(): void
    {
        $data = $this->results('q=' . rawurlencode('%%'));

        self::assertSame(0, $data['owners']['total']);
        self::assertSame(0, $data['properties']['total']);
        self::assertSame(0, $data['reservations']['total']);
    }
}

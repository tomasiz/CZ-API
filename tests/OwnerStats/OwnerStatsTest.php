<?php

declare(strict_types=1);

namespace Casamiza\Tests\OwnerStats;

use Casamiza\Domain\Extra\Extra;
use Casamiza\Domain\Extra\ExtraType;
use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\CalculatedAmounts;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Tests\Auth\AuthTestCase;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;

/** `GET /owners/{id}/stats` (administrateurs seulement) : les chiffres figés des factures non annulées d'un propriétaire. */
final class OwnerStatsTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->context->set('tenant-a');
        $this->owners->add(new Owner('owner-1', 'GO1', 'Alice Martin', 'Alice', 'Martin', '', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->properties->add(new Property('prop-1', 'L1', 'owner-1', 'Villa Santa', '', 48.0, 17.0, null));
        $this->properties->add(new Property('prop-2', 'L2', 'owner-1', 'Studio Port', '', 30.0, 0.0, null));
        $this->reservation('res-1', 'prop-1', '2026-09-02', '2026-09-05', 27.86);
        $this->reservation('res-2', 'prop-1', '2026-09-10', '2026-09-14', 77.72);
        $this->reservation('res-3', 'prop-2', '2026-09-20', '2026-09-21', 12.0);
        // Un séjour propriétaire en septembre (facturé) et un en octobre (pas facturé) : 3 nuits chacun.
        $this->reservation('res-own', 'prop-1', '2026-09-24', '2026-09-27', 0.0, 'owner');
        $this->reservation('res-own-oct', 'prop-1', '2026-10-10', '2026-10-13', 0.0, 'owner');
        // Deux prestations sans logement : 17 € de linge et un avoir de ménage.
        $this->extras->add(Extra::priced('extra-1', 'owner-1', '2026-09', ExtraType::Linen, 'Linge', 17.0, 20.0));
        $this->extras->add(Extra::priced('extra-2', 'owner-1', '2026-09', ExtraType::Cleaning, 'Avoir ménage', -10.0, 20.0));
        $this->context->reset();

        $this->context->set('tenant-b');
        $this->owners->add(new Owner('owner-b', 'GOB', 'Autre', 'Autre', 'Propriétaire', '', '', '', OwnerType::Individual, '', '', 10.0, null, null));
        $this->properties->add(new Property('prop-b', 'LB', 'owner-b', 'Chalet', '', 10.0, 0.0, null));
        $this->reservation('res-b', 'prop-b', '2026-09-08', '2026-09-12', 100.0);
        $this->context->reset();
    }

    /** @param non-empty-string $id */
    private function reservation(string $id, string $propertyId, string $checkIn, string $checkOut, float $conciergeFee, string $source = 'airbnb2'): void
    {
        $owner = $propertyId === 'prop-b' ? 'owner-b' : 'owner-1';
        $this->reservations->add(Reservation::fromGuesty($id, new GuestyReservation(
            'G-' . $id,
            'L',
            $source,
            [],
            '',
            'Voyageur',
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
        ), $owner, $propertyId)->withCalculation(new CalculatedAmounts(500.0, 50.0, 5.0, 12.0, 48.0, 433.0, $conciergeFee, 300.0, [])));
    }

    /** @param array<string, mixed>|null $body */
    private function call(string $method, string $path, ?array $body = null, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, $method, '/api/v1' . $path, $this->bearer($this->accessToken($email)), $body);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    private function issue(string $ownerId = 'owner-1', string $month = '2026-09', string $email = 'alice@a.test'): void
    {
        $response = $this->call('POST', '/invoices', ['ownerId' => $ownerId, 'month' => $month], $email);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    /** @return list<array<string, mixed>> */
    private function records(string $ownerId = 'owner-1', string $email = 'alice@a.test'): array
    {
        /** @var list<array<string, mixed>> $records */
        $records = $this->data($this->call('GET', '/owners/' . $ownerId . '/stats', null, $email))['records'];

        return $records;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array<string, mixed>
     */
    private function forProperty(array $records, string $propertyId): array
    {
        return array_values(array_filter($records, static fn(array $r): bool => $r['propertyId'] === $propertyId))[0];
    }

    public function testIsReservedToAdministrators(): void
    {
        self::assertSame(401, $this->request($this->app, 'GET', '/api/v1/owners/owner-1/stats')->getStatusCode());
        $manager = $this->call('GET', '/owners/owner-1/stats', null, 'marc@a.test');
        self::assertSame(403, $manager->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($manager));
    }

    public function testNothingIsInvoicedYet(): void
    {
        $data = $this->data($this->call('GET', '/owners/owner-1/stats'));

        self::assertSame([], $data['records']);
        self::assertSame([], $data['platforms']);
        self::assertSame(['Studio Port', 'Villa Santa'], array_map(static fn(array $p): string => (string) $p['name'], $this->sortedByName($data['properties'])));
    }

    /**
     * @param mixed $properties
     * @return list<array<string, mixed>>
     */
    private function sortedByName(mixed $properties): array
    {
        self::assertIsArray($properties);
        /** @var list<array<string, mixed>> $properties */
        usort($properties, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $properties;
    }

    public function testTheSixPostsAddUpToTheTotalPaidToTheCent(): void
    {
        $this->issue();
        $records = $this->records();

        self::assertCount(2, $records);
        $totals = array_fill_keys(['totalPaid', 'platformFees', 'touristTax', 'cleaning', 'concierge', 'ownerNet'], 0);
        foreach ($records as $record) {
            foreach ($totals as $field => $cents) {
                $totals[$field] = $cents + (int) round(((float) $record[$field]) * 100);
            }
            $posts = (int) round(((float) $record['platformFees'] + (float) $record['touristTax'] + (float) $record['cleaning'] + (float) $record['concierge'] + (float) $record['ownerNet']) * 100);
            self::assertSame((int) round(((float) $record['totalPaid']) * 100), $posts);
        }

        // 3 séjours à 500 €, 55 € de frais de plateforme et 12 € de taxe de séjour chacun.
        self::assertSame(150000, $totals['totalPaid']);
        self::assertSame(16500, $totals['platformFees']);
        self::assertSame(3600, $totals['touristTax']);
        // Ménage et linge TTC : 2 × (48 + 17) + 30, plus les prestations (17 − 10).
        self::assertSame(13000 + 3000 + 700, $totals['cleaning']);
        // Conciergerie TTC des trois séjours.
        self::assertSame(2786 + 7772 + 1200, $totals['concierge']);
    }

    public function testExtrasAreSharedBetweenThePropertiesByNights(): void
    {
        $this->issue();
        $villa = $this->forProperty($this->records(), 'prop-1');

        // 7 nuits sur la villa, 1 sur le studio : 7 € de prestations de ménage et linge répartis 6,12 / 0,88.
        self::assertEqualsWithDelta(130.0 + 6.13, $villa['cleaning'], 0.011);
        self::assertSame([2, 7], [$villa['reservations'], $villa['nights']]);
    }

    public function testOwnerNightsOfABilledMonthAreCountedApartFromSoldNights(): void
    {
        $this->issue();
        $records = $this->records();

        self::assertSame(3, $this->forProperty($records, 'prop-1')['ownerNights']);
        self::assertSame(0, $this->forProperty($records, 'prop-2')['ownerNights']);
        // Les nuits propriétaire ne sont ni vendues, ni payées.
        self::assertSame(7, $this->forProperty($records, 'prop-1')['nights']);
        self::assertEqualsWithDelta(1000.0, $this->forProperty($records, 'prop-1')['totalPaid'], 0.001);
        // Octobre n'est pas facturé : son séjour propriétaire n'apparaît pas.
        self::assertSame(['2026-09'], array_values(array_unique(array_map(static fn(array $r): mixed => $r['month'], $records))));
    }

    public function testPlatformsAreBrokenDownPerPropertyAndMonth(): void
    {
        $this->issue();
        /** @var list<array<string, mixed>> $platforms */
        $platforms = $this->data($this->call('GET', '/owners/owner-1/stats'))['platforms'];

        self::assertCount(2, $platforms);
        self::assertSame(['airbnb', 2, 7], [$platforms[0]['platform'], $platforms[0]['reservations'], $platforms[0]['nights']]);
        self::assertEqualsWithDelta(1000.0, $platforms[0]['totalPaid'], 0.001);
    }

    public function testAnAbritelCommissionIsCountedOnceAsAPlatformFee(): void
    {
        $this->context->set('tenant-a');
        $this->reservation('res-abritel', 'prop-2', '2026-08-10', '2026-08-12', 20.0, 'homeaway2');
        $this->context->reset();
        $this->issue('owner-1', '2026-08');

        $august = array_values(array_filter($this->records(), static fn(array $r): bool => $r['month'] === '2026-08'));

        self::assertCount(1, $august);
        // Commission (50) + frais de paiement (5) une seule fois ; la ligne de rétrocommission n'est ni conciergerie ni ménage.
        self::assertEqualsWithDelta(55.0, $august[0]['platformFees'], 0.001);
        self::assertEqualsWithDelta(20.0, $august[0]['concierge'], 0.001);
        self::assertEqualsWithDelta(30.0, $august[0]['cleaning'], 0.001);
    }

    public function testAnUnknownOwnerIsNotFound(): void
    {
        $response = $this->call('GET', '/owners/nope/stats');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('owner_not_found', $this->errorCode($response));
    }

    public function testATenantNeverSeesAnotherTenantsFigures(): void
    {
        $this->issue();
        $this->issue('owner-b', '2026-09', 'bob@b.test');

        // Le propriétaire d'un autre tenant est introuvable, ses chiffres aussi.
        $foreign = $this->call('GET', '/owners/owner-b/stats');
        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame('owner_not_found', $this->errorCode($foreign));

        $theirs = $this->records('owner-b', 'bob@b.test');
        self::assertSame(['prop-b'], array_map(static fn(array $r): mixed => $r['propertyId'], $theirs));
        self::assertSame(['prop-1', 'prop-2'], array_map(static fn(array $r): mixed => $r['propertyId'], $this->sortedRecords($this->records())));
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private function sortedRecords(array $records): array
    {
        usort($records, static fn(array $a, array $b): int => strcmp((string) $a['propertyId'], (string) $b['propertyId']));

        return $records;
    }
}

<?php

declare(strict_types=1);

namespace Casamiza\Tests\Dashboard;

use Casamiza\Domain\Dashboard\GuestyCost;
use Casamiza\Domain\Dashboard\ReservationCost;
use Casamiza\Domain\Extra\Extra;
use Casamiza\Domain\Extra\ExtraType;
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

/** `GET /dashboard` (administrateurs seulement) : les chiffres figés des factures non annulées. */
final class DashboardTest extends AuthTestCase
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

    /** @return array<string, mixed> */
    private function issue(string $ownerId = 'owner-1', string $email = 'alice@a.test'): array
    {
        $response = $this->call('POST', '/invoices', ['ownerId' => $ownerId, 'month' => '2026-09'], $email);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    /** @return list<array<string, mixed>> */
    private function records(string $email = 'alice@a.test'): array
    {
        /** @var list<array<string, mixed>> $records */
        $records = $this->data($this->call('GET', '/dashboard', null, $email))['records'];

        return $records;
    }

    public function testIsReservedToAdministrators(): void
    {
        self::assertSame(401, $this->request($this->app, 'GET', '/api/v1/dashboard')->getStatusCode());
        $manager = $this->call('GET', '/dashboard', null, 'marc@a.test');
        self::assertSame(403, $manager->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($manager));
    }

    public function testNothingIsInvoicedYet(): void
    {
        $data = $this->data($this->call('GET', '/dashboard'));

        self::assertSame([], $data['records']);
        // Les logements du tenant sont connus même sans facture (menu de sélection).
        self::assertSame(['Studio Port', 'Villa Santa'], array_map(static fn(array $p): string => (string) $p['name'], $this->properties($data)));
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function properties(array $data): array
    {
        /** @var list<array<string, mixed>> $properties */
        $properties = $data['properties'];
        usort($properties, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $properties;
    }

    public function testAMonthPerPropertyWithActivityAndBilledAmounts(): void
    {
        $this->issue();
        $records = $this->records();

        self::assertCount(2, $records);
        $villa = $records[0]['propertyId'] === 'prop-1' ? $records[0] : $records[1];
        $studio = $records[0]['propertyId'] === 'prop-2' ? $records[0] : $records[1];
        self::assertSame(['2026-09', 2, 7], [$villa['month'], $villa['reservations'], $villa['nights']]);
        self::assertSame(['2026-09', 1, 1], [$studio['month'], $studio['reservations'], $studio['nights']]);
        self::assertEqualsWithDelta(1000.0, $villa['payout'], 0.001);
        // Net propriétaire figé : 300 € par séjour.
        self::assertEqualsWithDelta(600.0, $villa['ownerNet'], 0.001);
        self::assertEqualsWithDelta(300.0, $studio['ownerNet'], 0.001);
        // Conciergerie des deux séjours de la villa : 27,86 + 77,72 TTC.
        self::assertEqualsWithDelta(105.58, $villa['conciergeTtc'], 0.001);
        self::assertEqualsWithDelta(12.0, $studio['conciergeTtc'], 0.001);
    }

    public function testTheGuestySubscriptionCountsPerPropertyAndBilledMonth(): void
    {
        $this->issue();
        $this->dashboard->guestyCosts = [
            new GuestyCost('prop-1', '2026-09', 1790),
            // Deux lignes d'un même logement et d'un même mois (mois entier + prorata) s'additionnent
            new GuestyCost('prop-2', '2026-09', 1790),
            new GuestyCost('prop-2', '2026-09', 210),
            // Octobre n'est pas facturé : pas de ligne, donc pas de coût
            new GuestyCost('prop-1', '2026-10', 2100),
        ];

        $records = $this->records();

        self::assertCount(2, $records);
        $villa = array_values(array_filter($records, static fn(array $r): bool => $r['propertyId'] === 'prop-1'))[0];
        $studio = array_values(array_filter($records, static fn(array $r): bool => $r['propertyId'] === 'prop-2'))[0];
        self::assertEqualsWithDelta(17.9, $villa['guestyCost'], 0.001);
        self::assertEqualsWithDelta(0.0, $villa['beyondCost'], 0.001);
        self::assertEqualsWithDelta(17.9, $villa['softwareCost'], 0.001);
        self::assertEqualsWithDelta(20.0, $studio['guestyCost'], 0.001);
    }

    public function testAListingBilledByGuestyWithoutAnyRevenueStillCostsInABilledMonth(): void
    {
        $this->issue();
        $this->dashboard->guestyCosts = [
            // Logement sans réservation facturée ce mois-là (inactif, vacant) : sa ligne existe pour porter le coût
            new GuestyCost('prop-idle', '2026-09', 2000),
            // Annonce qu'aucun logement ne porte : ligne « sans logement »
            new GuestyCost(null, '2026-09', 250),
            new GuestyCost(null, '2026-12', 999),
        ];

        $records = $this->records();

        self::assertCount(4, $records);
        $idle = array_values(array_filter($records, static fn(array $r): bool => $r['propertyId'] === 'prop-idle'))[0];
        $none = array_values(array_filter($records, static fn(array $r): bool => $r['propertyId'] === null))[0];
        self::assertSame([0, 0], [$idle['reservations'], $idle['nights']]);
        self::assertEqualsWithDelta(20.0, $idle['softwareCost'], 0.001);
        self::assertEqualsWithDelta(2.5, $none['guestyCost'], 0.001);
        self::assertEqualsWithDelta(0.0, $none['conciergeTtc'], 0.001);
    }

    public function testBeyondFeesAreAddedToTheSoftwareCostOfTheMonthOfTheCheckOutInParis(): void
    {
        $this->issue();
        $this->dashboard->guestyCosts = [new GuestyCost('prop-1', '2026-09', 1790), new GuestyCost('prop-2', '2026-09', 1790)];
        $cost = static fn(string $property, string $checkOut, int $cents): ReservationCost => new ReservationCost($property, new DateTimeImmutable($checkOut . ' UTC'), $cents);
        $this->dashboard->beyondFees = [
            $cost('prop-1', '2026-09-14 08:00:00', 545),
            // 22 h 30 UTC le 31/08 = 00 h 30 le 1er septembre à Paris : septembre
            $cost('prop-1', '2026-08-31 22:30:00', 100),
            // 22 h 30 UTC le 30/09 = 00 h 30 le 1er octobre à Paris : octobre, mois pas facturé, donc sans ligne
            $cost('prop-1', '2026-09-30 22:30:00', 999),
            // Annulation nette : un coût négatif réduit le coût du mois
            $cost('prop-2', '2026-09-10 08:00:00', -245),
        ];
        // Une ligne de relevé sans logement connu : ligne « sans logement » du mois facturé (septembre), rien pour un mois pas facturé.
        $this->dashboard->beyondFees[] = new ReservationCost(null, new DateTimeImmutable('2026-09-20 00:00:00 UTC'), 300);
        $this->dashboard->beyondFees[] = new ReservationCost(null, new DateTimeImmutable('2026-12-20 00:00:00 UTC'), 700);

        $records = $this->records();

        self::assertCount(3, $records);
        $villa = array_values(array_filter($records, static fn(array $r): bool => $r['propertyId'] === 'prop-1'))[0];
        $studio = array_values(array_filter($records, static fn(array $r): bool => $r['propertyId'] === 'prop-2'))[0];
        $none = array_values(array_filter($records, static fn(array $r): bool => $r['propertyId'] === null))[0];
        self::assertEqualsWithDelta(17.9 + 5.45 + 1.0, $villa['softwareCost'], 0.001);
        // Le coût se détaille : l'abonnement Guesty et les frais Beyond.
        self::assertEqualsWithDelta(17.9, $villa['guestyCost'], 0.001);
        self::assertEqualsWithDelta(6.45, $villa['beyondCost'], 0.001);
        self::assertEqualsWithDelta(0.0, $none['guestyCost'], 0.001);
        self::assertEqualsWithDelta(17.9 - 2.45, $studio['softwareCost'], 0.001);
        self::assertEqualsWithDelta(3.0, $none['softwareCost'], 0.001, 'seulement Beyond sans logement');
    }

    public function testABeyondFeeOnAListingWithoutRevenueStillHasItsLineInABilledMonth(): void
    {
        $this->issue();
        $this->dashboard->beyondFees = [
            // Annulation facturée par Beyond sur un logement sans revenu facturé en septembre : sa ligne porte le coût
            new ReservationCost('prop-idle', new DateTimeImmutable('2026-09-10 08:00:00 UTC'), 450),
            // Mois pas facturé : rien
            new ReservationCost('prop-idle', new DateTimeImmutable('2026-12-10 08:00:00 UTC'), 999),
        ];

        $records = $this->records();

        self::assertCount(3, $records);
        $idle = array_values(array_filter($records, static fn(array $r): bool => $r['propertyId'] === 'prop-idle'))[0];
        self::assertSame([0, 0], [$idle['reservations'], $idle['nights']]);
        self::assertEqualsWithDelta(4.5, $idle['beyondCost'], 0.001);
        self::assertEqualsWithDelta(4.5, $idle['softwareCost'], 0.001);
    }

    public function testTheSumOfTheRecordsEqualsTheInvoiceTotalsToTheCent(): void
    {
        $invoice = $this->issue();
        $records = $this->records();

        $ttc = $ht = 0;
        foreach ($records as $record) {
            foreach (['concierge', 'linen', 'disbursement'] as $category) {
                $ttc += (int) round(((float) $record[$category . 'Ttc']) * 100);
                $ht += (int) round(((float) $record[$category . 'Ht']) * 100);
            }
        }

        // Prestations comprises (réparties entre les logements) : rien n'est perdu ni compté deux fois.
        self::assertSame((int) round(((float) $invoice['amountTtc']) * 100), $ttc);
        self::assertSame((int) round(((float) $invoice['amountHt']) * 100), $ht);
    }

    public function testExtrasAreSharedBetweenThePropertiesOfTheInvoiceByNights(): void
    {
        $this->issue();
        $records = $this->records();

        $linen = array_sum(array_map(static fn(array $r): float => (float) $r['linenTtc'], $records));
        $cleaning = array_sum(array_map(static fn(array $r): float => (float) $r['disbursementTtc'], $records));
        // Linge des séjours : 2 × 17 € sur la villa ; prestation de 17 € répartie 7 nuits / 1 nuit (1488 + 212 cents).
        self::assertEqualsWithDelta(51.0, $linen, 0.001);
        // Ménage : 2 × 48 + 30 − 10 (avoir).
        self::assertEqualsWithDelta(116.0, $cleaning, 0.001);
        $villa = array_values(array_filter($records, static fn(array $r): bool => $r['propertyId'] === 'prop-1'))[0];
        self::assertEqualsWithDelta(34.0 + 14.88, $villa['linenTtc'], 0.001);
    }

    public function testExtrasOnlyInvoicesAreSharedBetweenTheActivePropertiesOfTheOwner(): void
    {
        // Août : aucune réservation, une prestation de 40 €.
        $this->context->set('tenant-a');
        $this->extras->add(Extra::priced('extra-3', 'owner-1', '2026-08', ExtraType::Concierge, 'Dépannage', 40.0, 20.0));
        $this->context->reset();
        $response = $this->call('POST', '/invoices', ['ownerId' => 'owner-1', 'month' => '2026-08']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $august = array_values(array_filter($this->records(), static fn(array $r): bool => $r['month'] === '2026-08'));

        self::assertCount(2, $august);
        self::assertEqualsWithDelta(40.0, array_sum(array_map(static fn(array $r): float => (float) $r['conciergeTtc'], $august)), 0.001);
        self::assertSame([0, 0], array_map(static fn(array $r): mixed => $r['nights'], $august));
        self::assertEqualsWithDelta(20.0, $august[0]['conciergeTtc'], 0.001);
    }

    public function testPlatformCommissionsAreDisbursementsNotRevenue(): void
    {
        // Août : un séjour Abritel (commission de 50 € refacturée) sur le studio, dont le ménage est de 30 €.
        $this->context->set('tenant-a');
        $this->reservation('res-abritel', 'prop-2', '2026-08-10', '2026-08-12', 20.0, 'homeaway2');
        $this->context->reset();
        $response = $this->call('POST', '/invoices', ['ownerId' => 'owner-1', 'month' => '2026-08']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $august = array_values(array_filter($this->records(), static fn(array $r): bool => $r['month'] === '2026-08'));

        self::assertCount(1, $august);
        // Revenu : seulement les frais de conciergerie ; débours : ménage (30) + commission Abritel (50).
        self::assertEqualsWithDelta(20.0, $august[0]['conciergeTtc'], 0.001);
        self::assertEqualsWithDelta(80.0, $august[0]['disbursementTtc'], 0.001);
    }

    public function testACancelledInvoiceIsIgnored(): void
    {
        $invoice = $this->issue();
        $this->context->reset();
        $this->context->set('tenant-a');
        $key = 'tenant-a|' . $invoice['id'];
        $stored = $this->invoices->rows[$key];
        $this->invoices->rows[$key] = new Invoice(...[...get_object_vars($stored), 'cancelledAt' => new DateTimeImmutable('2026-10-07 UTC')]);
        $this->context->reset();

        self::assertSame([], $this->records());
    }

    public function testATenantNeverSeesAnotherTenantsFigures(): void
    {
        $this->issue();
        $this->issue('owner-b', 'bob@b.test');

        $mine = $this->data($this->call('GET', '/dashboard'));
        $theirs = $this->data($this->call('GET', '/dashboard', null, 'bob@b.test'));

        self::assertSame(['prop-1', 'prop-2'], array_map(static fn(array $r): mixed => $r['propertyId'], $this->sorted($mine['records'])));
        self::assertSame(['prop-b'], array_map(static fn(array $r): mixed => $r['propertyId'], $this->sorted($theirs['records'])));
        self::assertSame(['Chalet'], array_map(static fn(array $p): mixed => $p['name'], $this->properties($theirs)));
        // Aucun coût logiciel importé chez l'autre tenant.
        self::assertEqualsWithDelta(0.0, $this->sorted($theirs['records'])[0]['softwareCost'], 0.001);
    }

    /**
     * @param mixed $records
     * @return list<array<string, mixed>>
     */
    private function sorted(mixed $records): array
    {
        self::assertIsArray($records);
        /** @var list<array<string, mixed>> $records */
        usort($records, static fn(array $a, array $b): int => strcmp((string) $a['propertyId'], (string) $b['propertyId']));

        return $records;
    }
}

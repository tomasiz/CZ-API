<?php

declare(strict_types=1);

namespace Casamiza\Tests\Reservation;

use Casamiza\Console\SyncReservationsCommand;
use Casamiza\Domain\Integration\Provider;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\Platform;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Infrastructure\Security\Encryptor;
use Casamiza\Tests\Auth\AuthTestCase;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Console\Tester\CommandTester;

final class ReservationSyncTest extends AuthTestCase
{
    /** @var list<Response> réponses de Stripe, ajoutées après celles de Guesty au prochain `syncWith()` */
    private array $stripeResponses = [];

    /** @param array<array-key, mixed> $body */
    private static function ok(array $body): Response
    {
        return new Response(200, [], (string) json_encode($body));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function reservation(string $id, string $listing = 'L1', string $source = 'airbnb2', string $checkOut = '2026-10-04T08:00:00.000Z', array $overrides = []): array
    {
        return $overrides + [
            '_id' => $id,
            'listingId' => $listing,
            'source' => $source,
            'status' => 'confirmed',
            'checkIn' => '2026-09-30T14:00:00.000Z',
            'checkOut' => $checkOut,
            'nightsCount' => 4,
            'guestsCount' => 2,
            'guest' => ['fullName' => 'Jean Dupont'],
            'integration' => ['platform' => 'airbnb2', 'airbnb2' => ['id' => 'HMABC'], 'bookingCom' => []],
            'money' => ['hostPayout' => 500.5, 'hostServiceFee' => 90, 'totalPaymentFees' => 0, 'totalTaxes' => 0, 'fareCleaning' => 80, 'totalFees' => 80, 'transientOccupancyTax' => 12, 'fareAccommodationAdjusted' => 600, 'payments' => [['amount' => 602.5, 'status' => 'SUCCEEDED']]],
            'atTimeOfConfirmation' => ['taxes' => [['type' => 'CITY_TAX', 'amount' => 5.5]]],
        ];
    }

    /** @param list<array<string, mixed>> $results */
    private static function page(array $results): Response
    {
        return self::ok(['results' => $results, 'count' => count($results), 'limit' => 100, 'skip' => 0]);
    }

    private function connect(string $email, string $tenantId): void
    {
        $this->thirdParty->append(self::ok(['access_token' => 't', 'expires_in' => 86400, 'token_type' => 'Bearer']), self::ok(['_id' => 'acc-1']));
        $response = $this->request($this->app, 'PUT', '/api/v1/settings/integrations/guesty', $this->bearer($this->accessToken($email)), ['clientId' => 'id-1', 'clientSecret' => 'secret-1']);
        self::assertSame(200, $response->getStatusCode());
        $this->tenants->integrations[$tenantId] = [Provider::Guesty];
        $this->context->reset();
        $this->context->set($tenantId);
        $this->properties->add(new Property('prop-' . $tenantId, 'L1', 'owner-' . $tenantId, 'Villa', '', 80.0, 0.0, null));
        $this->context->reset();
    }

    /** @param list<array<string, mixed>> $results */
    private function syncWith(array $results, string ...$args): CommandTester
    {
        // Réponses de Guesty, puis celles de Stripe (lues pendant le traitement des réservations).
        $this->thirdParty->append(self::ok(['_id' => 'acc-1']), self::page($results), ...$this->stripeResponses);
        $this->stripeResponses = [];
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $command = $container->get(SyncReservationsCommand::class);
        self::assertInstanceOf(SyncReservationsCommand::class, $command);
        $tester = new CommandTester($command);
        $tester->execute($args === [] ? [] : ['--since' => $args[0]]);

        return $tester;
    }

    public function testReservationsAreImportedWithTheirRawAmounts(): void
    {
        $this->connect('alice@a.test', 'tenant-a');

        $tester = $this->syncWith([
            self::reservation('R1'),
            self::reservation('R2', source: 'owner', checkOut: '2026-10-09T08:00:00.000Z'),
            self::reservation('R3', listing: 'UNKNOWN'),
        ], '2026-09-01');

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('tenant-a : réservations +2 ~0 -0 =0 (ignorées : 1, lectures Stripe : 0), check-out depuis le 2026-09-01', $tester->getDisplay());
        $r1 = $this->reservations->byGuestyId('tenant-a', 'R1');
        self::assertNotNull($r1);
        self::assertSame('prop-tenant-a', $r1->propertyId);
        self::assertSame('owner-tenant-a', $r1->ownerId);
        self::assertSame(Platform::Airbnb, $r1->platform);
        self::assertSame('airbnb2', $r1->source);
        self::assertSame(['platform', 'airbnb2', 'bookingCom'], $r1->integrationKeys);
        self::assertSame('HMABC', $r1->platformRef);
        self::assertSame('Jean Dupont', $r1->guest);
        self::assertSame('2026-10-04T08:00:00+00:00', $r1->checkOut->format('c'));
        self::assertSame(4, $r1->nights);
        self::assertSame(500.5, $r1->hostPayout);
        self::assertSame(12.0, $r1->transientOccupancyTax);
        self::assertSame('CITY_TAX', $r1->confirmationTaxes[0]['type'] ?? null);
        self::assertSame('SUCCEEDED', $r1->payments[0]['status'] ?? null);
        self::assertSame(0.0, $r1->conciergeFee);
        self::assertSame(Platform::Owner, $this->reservations->byGuestyId('tenant-a', 'R2')?->platform);
        self::assertNull($this->reservations->byGuestyId('tenant-a', 'R3'));
    }

    public function testBookingReferenceComesFromTheIntegrationPlatformKey(): void
    {
        $this->connect('alice@a.test', 'tenant-a');

        $this->syncWith([self::reservation('R1', source: 'Booking.com', overrides: ['integration' => ['platform' => 'bookingCom', 'bookingCom' => ['reservationId' => '5905443071', 'id' => 6169394356]]])]);

        self::assertSame('5905443071', $this->reservations->byGuestyId('tenant-a', 'R1')?->platformRef);
        self::assertSame(Platform::Booking, $this->reservations->byGuestyId('tenant-a', 'R1')->platform);
    }

    public function testTheQueryAsksForConfirmedReservationsFromTheWindowStartWithoutUpperBound(): void
    {
        $this->connect('alice@a.test', 'tenant-a');
        $before = count($this->thirdPartyCalls);

        $this->syncWith([], '2025-01-01');

        $calls = array_slice($this->thirdPartyCalls->getArrayCopy(), $before);
        $reservationCall = $calls[count($calls) - 1]['request']->getUri();
        self::assertSame('/v1/reservations', $reservationCall->getPath());
        parse_str($reservationCall->getQuery(), $query);
        self::assertSame('[{"field":"status","operator":"$eq","value":"confirmed"},{"field":"checkOut","operator":"$gte","value":"2025-01-01"}]', $query['filters'] ?? null);
        self::assertSame('100', $query['limit'] ?? null);
    }

    public function testTheRouteResyncsOnlyTheReservationsCheckingOutInTheGivenMonth(): void
    {
        $this->connect('alice@a.test', 'tenant-a');
        // Septembre et octobre importés ; seule la fenêtre d'octobre sera relue.
        $this->syncWith([
            self::reservation('SEP', checkOut: '2026-09-20T08:00:00.000Z'),
            self::reservation('OCT', checkOut: '2026-10-04T08:00:00.000Z'),
            self::reservation('OCT-GONE', checkOut: '2026-10-09T08:00:00.000Z'),
        ], '2026-09-01');
        $before = count($this->thirdPartyCalls);

        // Guesty ne renvoie plus que OCT (modifiée) : OCT-GONE est archivée, SEP (hors fenêtre) reste intacte.
        // Le filtre de Guesty est au jour près : 'NOV-PARIS' (23:30 UTC le 31/10 = 00:30 le 1er novembre à Paris) revient aussi, mais est écartée.
        $this->thirdParty->append(self::ok(['_id' => 'acc-1']), self::page([
            self::reservation('OCT', overrides: ['guestsCount' => 5]),
            self::reservation('NOV-PARIS', checkOut: '2026-10-31T23:30:00.000Z'),
        ]));
        // Un gestionnaire peut la lancer, comme la synchronisation des propriétaires et logements.
        $response = $this->request($this->app, 'POST', '/api/v1/sync/reservations', $this->bearer($this->accessToken('marc@a.test')), ['month' => '2026-10']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);
        self::assertSame(['created' => 0, 'updated' => 1, 'archived' => 1, 'unchanged' => 0], $data['reservations']);
        self::assertSame(5, $this->reservations->byGuestyId('tenant-a', 'OCT')?->guests);
        self::assertTrue($this->reservations->byGuestyId('tenant-a', 'OCT-GONE')?->isArchived());
        self::assertFalse($this->reservations->byGuestyId('tenant-a', 'SEP')?->isArchived());
        self::assertNull($this->reservations->byGuestyId('tenant-a', 'NOV-PARIS'));

        // Octobre à Paris = du 30/09 22:00 UTC au 31/10 23:00 UTC : le filtre de Guesty (au jour près) est élargi au jour entier.
        $calls = array_slice($this->thirdPartyCalls->getArrayCopy(), $before);
        parse_str($calls[count($calls) - 1]['request']->getUri()->getQuery(), $query);
        self::assertSame('[{"field":"status","operator":"$eq","value":"confirmed"},{"field":"checkOut","operator":"$gte","value":"2026-09-30"},{"field":"checkOut","operator":"$lt","value":"2026-11-01"}]', $query['filters'] ?? null);
    }

    public function testTheRouteValidatesTheMonthAndNeedsAnAccount(): void
    {
        $this->connect('alice@a.test', 'tenant-a');
        $token = $this->bearer($this->accessToken('marc@a.test'));

        foreach ([[], ['month' => '2026-13'], ['month' => '2024-12'], ['month' => '2026-10', 'since' => 'x']] as $body) {
            $response = $this->request($this->app, 'POST', '/api/v1/sync/reservations', $token, $body);
            self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('validation_failed', $this->errorCode($response));
        }
        self::assertSame(401, $this->request($this->app, 'POST', '/api/v1/sync/reservations', [], ['month' => '2026-10'])->getStatusCode());
    }

    public function testASecondRunUpdatesArchivesAndRestores(): void
    {
        $this->connect('alice@a.test', 'tenant-a');
        $this->syncWith([self::reservation('R1'), self::reservation('R2', checkOut: '2026-10-09T08:00:00.000Z')], '2026-09-01');
        // R1 modifiée, R2 annulée (n'est plus renvoyée).
        $tester = $this->syncWith([self::reservation('R1', overrides: ['guestsCount' => 3])], '2026-09-01');

        self::assertStringContainsString('+0 ~1 -1 =0', $tester->getDisplay());
        self::assertSame(3, $this->reservations->byGuestyId('tenant-a', 'R1')?->guests);
        self::assertTrue($this->reservations->byGuestyId('tenant-a', 'R2')?->isArchived());

        // Rien ne change : tout est « inchangé » (R2 archivée compte aussi comme telle).
        $tester = $this->syncWith([self::reservation('R1', overrides: ['guestsCount' => 3])], '2026-09-01');
        self::assertStringContainsString('+0 ~0 -0 =2', $tester->getDisplay());

        // R2 revient : rétablie.
        $tester = $this->syncWith([self::reservation('R1', overrides: ['guestsCount' => 3]), self::reservation('R2', checkOut: '2026-10-09T08:00:00.000Z')], '2026-09-01');
        self::assertStringContainsString('+0 ~1 -0 =1', $tester->getDisplay());
        self::assertFalse($this->reservations->byGuestyId('tenant-a', 'R2')?->isArchived());
    }

    private function stored(string $guestyId): Reservation
    {
        $reservation = $this->reservations->byGuestyId('tenant-a', $guestyId);
        self::assertNotNull($reservation);

        return $reservation;
    }

    private function addOwner(?float $commission, ?string $stripeKey = null): void
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $encryptor = $container->get(Encryptor::class);
        self::assertInstanceOf(Encryptor::class, $encryptor);
        $this->context->set('tenant-a');
        $this->owners->add(new Owner('owner-tenant-a', 'G-O1', 'Alice Martin', 'Alice', 'Martin', '', '', '', OwnerType::Individual, '', '', $commission, $stripeKey === null ? null : $encryptor->encrypt($stripeKey), null));
        $this->context->reset();
    }

    public function testAmountsAreCalculatedWithTheOwnerCommissionAndFollowItsChanges(): void
    {
        $this->connect('alice@a.test', 'tenant-a');
        $this->addOwner(20.0);

        $this->syncWith([self::reservation('R1')], '2026-09-01');

        // Airbnb : total 500,5 + 90 + 12 = 602,5 ; net 500,5 ; frais = 20 % × (500,5 − 80) = 84,1.
        $r1 = $this->reservations->byGuestyId('tenant-a', 'R1');
        self::assertNotNull($r1);
        self::assertSame(602.5, $r1->totalPayout);
        self::assertSame(84.1, $r1->conciergeFee);
        self::assertSame(336.4, $r1->netOwnerRevenue);
        self::assertSame([], $r1->flags);

        // Commission changée dans Guesty : la synchro suivante recalcule (mois pas encore facturé).
        $this->owners->rows['tenant-a|owner-tenant-a'] = new Owner('owner-tenant-a', 'G-O1', 'Alice Martin', 'Alice', 'Martin', '', '', '', OwnerType::Individual, '', '', 15.0, null, null);
        $tester = $this->syncWith([self::reservation('R1')], '2026-09-01');

        self::assertStringContainsString('+0 ~1 -0 =0', $tester->getDisplay());
        self::assertSame(63.08, $this->stored('R1')->conciergeFee);

        $tester = $this->syncWith([self::reservation('R1')], '2026-09-01');
        self::assertStringContainsString('+0 ~0 -0 =1', $tester->getDisplay());
    }

    /** @return array<string, mixed> */
    private static function stripePayment(): array
    {
        return ['money' => ['hostPayout' => 500, 'hostServiceFee' => 0, 'totalPaymentFees' => 0, 'totalTaxes' => 0, 'fareCleaning' => 80, 'totalFees' => 80, 'transientOccupancyTax' => 0, 'fareAccommodationAdjusted' => 600, 'payments' => [['status' => 'SUCCEEDED', 'confirmationCode' => 'pi_ABC', 'amount' => 500]]]];
    }

    public function testMissingPaymentFeesAreReadFromStripeOnceAndRemembered(): void
    {
        $this->connect('alice@a.test', 'tenant-a');
        $this->addOwner(20.0, 'sk_live_secret123456');
        $stripeCalls = fn(): int => count(array_filter($this->thirdPartyPaths(), static fn(string $path): bool => str_starts_with($path, '/v1/payment_intents/')));

        // Une lecture Stripe (frais de 12,50 €), puis plus aucune : le résultat est mémorisé.
        $this->stripeResponses = [self::ok(['latest_charge' => ['balance_transaction' => ['fee_details' => [['amount' => 1250]]]]])];
        $first = $this->syncWith([self::reservation('R1', source: 'manual', overrides: self::stripePayment())], '2026-09-01');
        $second = $this->syncWith([self::reservation('R1', source: 'manual', overrides: self::stripePayment())], '2026-09-01');

        self::assertStringContainsString('lectures Stripe : 1', $first->getDisplay());
        self::assertStringContainsString('lectures Stripe : 0', $second->getDisplay());
        self::assertSame(1, $stripeCalls());
        $r1 = $this->reservations->byGuestyId('tenant-a', 'R1');
        self::assertNotNull($r1);
        self::assertSame(12.5, $r1->paymentFee);
        self::assertSame(12.5, $r1->stripeFee);
        self::assertNull($r1->stripeError);
    }

    public function testAFailedStripeReadIsRetriedOnTheNextSync(): void
    {
        $this->connect('alice@a.test', 'tenant-a');
        $this->addOwner(20.0, 'sk_live_secret123456');

        $this->stripeResponses = [new Response(404, [], (string) json_encode(['error' => ['code' => 'resource_missing']]))];
        $this->syncWith([self::reservation('R1', source: 'website', overrides: self::stripePayment())], '2026-09-01');
        $failed = $this->reservations->byGuestyId('tenant-a', 'R1');
        self::assertNotNull($failed);
        self::assertSame('resource_missing', $failed->stripeError);
        self::assertSame(0.0, $failed->paymentFee);
        self::assertSame('stripe_error', $failed->flags[0]->reason->value ?? null);

        $this->stripeResponses = [self::ok(['latest_charge' => ['balance_transaction' => ['fee_details' => [['amount' => 800]]]]])];
        $tester = $this->syncWith([self::reservation('R1', source: 'website', overrides: self::stripePayment())], '2026-09-01');

        self::assertStringContainsString('lectures Stripe : 1', $tester->getDisplay());
        self::assertSame(8.0, $this->stored('R1')->paymentFee);
        self::assertNull($this->stored('R1')->stripeError);
    }

    public function testAChangeOfPaymentsMakesStripeBeReadAgain(): void
    {
        $this->connect('alice@a.test', 'tenant-a');
        $this->addOwner(20.0, 'sk_live_secret123456');
        $this->stripeResponses = [self::ok(['latest_charge' => ['balance_transaction' => ['fee_details' => [['amount' => 1250]]]]])];
        $this->syncWith([self::reservation('R1', source: 'manual', overrides: self::stripePayment())], '2026-09-01');

        $changed = self::stripePayment();
        $changed['money']['payments'][] = ['status' => 'SUCCEEDED', 'confirmationCode' => 'pi_DEF', 'amount' => 100];
        $this->stripeResponses = [
            self::ok(['latest_charge' => ['balance_transaction' => ['fee_details' => [['amount' => 1250]]]]]),
            self::ok(['latest_charge' => ['balance_transaction' => ['fee_details' => [['amount' => 300]]]]]),
        ];
        $this->syncWith([self::reservation('R1', source: 'manual', overrides: $changed)], '2026-09-01');

        self::assertSame(15.5, $this->stored('R1')->paymentFee);
    }

    public function testReservationsBeforeTheWindowAreNeverArchived(): void
    {
        $this->connect('alice@a.test', 'tenant-a');
        $this->syncWith([self::reservation('OLD', checkOut: '2026-08-10T08:00:00.000Z')], '2026-08-01');

        // Fenêtre plus récente, Guesty ne renvoie plus rien avant le 1er octobre : l'ancienne reste intacte.
        $tester = $this->syncWith([], '2026-10-01');

        self::assertStringContainsString('+0 ~0 -0 =0', $tester->getDisplay());
        self::assertFalse($this->reservations->byGuestyId('tenant-a', 'OLD')?->isArchived());
    }

    public function testTenantsNeverSeeEachOthersReservations(): void
    {
        $this->connect('alice@a.test', 'tenant-a');
        $this->connect('bob@b.test', 'tenant-b');
        // Le même identifiant Guesty chez les deux tenants : lignes distinctes. Les tenants sont lus dans l'ordre de `integrations`.
        $this->thirdParty->append(
            self::ok(['_id' => 'acc-1']),
            self::page([self::reservation('R1')]),
            self::ok(['_id' => 'acc-1']),
            self::page([self::reservation('R1', overrides: ['guestsCount' => 9])]),
        );
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $command = $container->get(SyncReservationsCommand::class);
        self::assertInstanceOf(SyncReservationsCommand::class, $command);
        $tester = new CommandTester($command);
        $tester->execute(['--since' => '2026-09-01']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(2, $this->reservations->byGuestyId('tenant-a', 'R1')?->guests);
        self::assertSame(9, $this->reservations->byGuestyId('tenant-b', 'R1')?->guests);
        self::assertNotSame($this->reservations->byGuestyId('tenant-a', 'R1')?->id, $this->reservations->byGuestyId('tenant-b', 'R1')->id);
        self::assertSame('prop-tenant-b', $this->reservations->byGuestyId('tenant-b', 'R1')?->propertyId);
    }
}

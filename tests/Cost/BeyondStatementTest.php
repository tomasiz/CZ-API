<?php

declare(strict_types=1);

namespace Casamiza\Tests\Cost;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Integration\Provider;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Tests\Auth\AuthTestCase;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * Relevés Beyond Pricing du 12 au 12 (CSV « par réservation ») : `/costs/beyond/statements`, administrateurs seulement.
 * Les lignes sont celles des exports réels (aucune donnée voyageur).
 */
final class BeyondStatementTest extends AuthTestCase
{
    private const HEADER = 'BP StatementItem ID,Channel,Reservation ID,Reservation Reference,Reservation Channel ID,PMS Reservation ID,Booking Date,Updated Date,Description,Listing Title,Check-In Date,Nights,Currency,Earnings,Beyond Fees,Amount,Product';

    // Relevé du 12/10 au 12/11/2026
    private const CUNEO = '48930515,Guesty,143884485,HMT4WBNWZ2,6ad5cb1d138dc5c0adf4ba8d,6ad5cb1d138dc5c0adf4ba8d,2026-10-19,,Reservation,Cuneo,"Oct 20, 2026",2,EUR,145.63,1.45,1.45,pro_package';
    private const TB165 = '48905354,Guesty,143808068,BC-qpZZXGY1D,6ad3913962e58ac58eb9f7fc,6ad3913962e58ac58eb9f7fc,2026-10-17,,Reservation,Tb165,"Dec 27, 2026",9,EUR,544.55,5.44,5.44,pro_package';
    private const TB165_CANCELLED = '48905353,Guesty,142901167,BC-pKww8llp1,6ac7e802d4b186c7930209a0,6ac7e802d4b186c7930209a0,2026-10-08,2026-10-17,Cancellation,Tb165,"Dec 26, 2026",9,EUR,-471.62,-4.71,-4.71,pro_package';
    private const AGOSTA_CANCELLATION = '48896214,Guesty,134476869,HMAYR2AMY4,6a47f8a0a27076e2e9845e86,6a47f8a0a27076e2e9845e86,2026-07-03,2026-10-17,Cancellation,Agosta 2,"Oct 15, 2026",3,EUR,-245.47,-2.45,-2.45,pro_package';
    private const AGOSTA = '48896213,Guesty,134476869,HMAYR2AMY4,6a47f8a0a27076e2e9845e86,6a47f8a0a27076e2e9845e86,2026-07-03,2026-10-17,Reservation,Agosta 2,"Oct 15, 2026",3,EUR,490.94,4.90,4.90,pro_package';
    // Relevé du 12/09 au 12/10/2026 : une ligne « Update »
    private const CUNEO_UPDATE = '48705113,Guesty,140548134,BC-qo6w5oAwG,6aaa32cfe75c0b730427094d,6aaa32cfe75c0b730427094d,2026-09-16,2026-10-05,Update,Cuneo,"Sep 17, 2026",1,EUR,1.29,0.01,0.01,pro_package';

    private const CUNEO_GUESTY = '6ad5cb1d138dc5c0adf4ba8d';
    private const TB165_GUESTY = '6ad3913962e58ac58eb9f7fc';
    private const TB165_OLD_GUESTY = '6ac7e802d4b186c7930209a0';
    private const AGOSTA_GUESTY = '6a47f8a0a27076e2e9845e86';
    private const OCTOBER = '2026-10-12';
    private const SEPTEMBER = '2026-09-12';

    protected function setUp(): void
    {
        parent::setUp();
        // Le 13/11/2026 : les relevés jusqu'à celui du 12/10 sont terminés.
        $this->clock->advance('+37 days');

        $this->context->set('tenant-a');
        $this->owners->add(new Owner('owner-1', 'GO1', 'Alice Martin', 'Alice', 'Martin', '', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        foreach (['prop-cuneo' => 'Cuneo', 'prop-tb165' => 'Tb165', 'prop-agosta' => 'Agosta 2'] as $id => $name) {
            $this->properties->add(new Property($id, 'L-' . $id, 'owner-1', $name, '', 0.0, 0.0, null));
        }
        $this->reservation('res-cuneo', self::CUNEO_GUESTY, 'prop-cuneo');
        $this->reservation('res-agosta', self::AGOSTA_GUESTY, 'prop-agosta');
        $this->context->reset();
        $this->tenants->integrations['tenant-a'] = [Provider::Guesty];

        // Un autre tenant, avec la même réservation : jamais rapprochée.
        $this->context->set('tenant-b');
        $this->owners->add(new Owner('owner-b', 'GOB', 'Autre', 'Autre', 'Propriétaire', '', '', '', OwnerType::Individual, '', '', 10.0, null, null));
        $this->properties->add(new Property('prop-b', 'LB', 'owner-b', 'Cuneo', '', 0.0, 0.0, null));
        $this->reservation('res-b', self::CUNEO_GUESTY, 'prop-b', 'owner-b');
        $this->context->reset();

        $this->connectGuesty();
    }

    private function connectGuesty(): void
    {
        $this->thirdParty->append(self::ok(['access_token' => 't', 'expires_in' => 86400, 'token_type' => 'Bearer']), self::ok(['_id' => 'acc-1']));
        $response = $this->request($this->app, 'PUT', '/api/v1/settings/integrations/guesty', $this->bearer($this->accessToken()), ['clientId' => 'id-1', 'clientSecret' => 'secret-1']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /** @param array<array-key, mixed> $body */
    private static function ok(array $body): Response
    {
        return new Response(200, [], (string) json_encode($body));
    }

    /**
     * Ce que Guesty répond à la lecture par identifiants : le contrôle d'accès, puis la page.
     *
     * @param list<array<string, mixed>> $results
     */
    private function guestyReturns(array $results): void
    {
        $this->thirdParty->append(self::ok(['_id' => 'acc-1']), self::ok(['results' => $results, 'count' => count($results), 'limit' => 100, 'skip' => 0]));
    }

    /** @return array<string, mixed> */
    private static function guesty(string $id, string $listing, string $status = 'confirmed', string $guest = 'Jean Dupont'): array
    {
        return [
            '_id' => $id,
            'listingId' => $listing,
            'source' => 'airbnb2',
            'status' => $status,
            'checkIn' => '2026-12-26T14:00:00.000Z',
            'checkOut' => '2027-01-04T08:00:00.000Z',
            'nightsCount' => 9,
            'guestsCount' => 2,
            'guest' => ['fullName' => $guest],
            'integration' => ['platform' => 'airbnb2', 'airbnb2' => ['id' => 'HMABC']],
            'money' => ['hostPayout' => 500.5, 'hostServiceFee' => 90, 'totalPaymentFees' => 0, 'totalTaxes' => 0, 'fareCleaning' => 80, 'totalFees' => 80, 'transientOccupancyTax' => 12, 'fareAccommodationAdjusted' => 600, 'payments' => []],
        ];
    }

    /** Les deux réservations de Tb165 que Casamiza ne connaît pas : une confirmée, une annulée. */
    private function guestyKnowsTb165(): void
    {
        $this->guestyReturns([
            self::guesty(self::TB165_GUESTY, 'L-prop-tb165'),
            self::guesty(self::TB165_OLD_GUESTY, 'L-prop-tb165', 'canceled', 'Marie Curie'),
        ]);
    }

    /** @param non-empty-string $id */
    private function reservation(string $id, string $guestyId, string $propertyId, string $ownerId = 'owner-1'): void
    {
        $this->reservations->add(Reservation::fromGuesty($id, new GuestyReservation(
            $guestyId,
            'L',
            'airbnb2',
            [],
            '',
            'Voyageur',
            new DateTimeImmutable('2026-10-20 14:00:00 UTC'),
            new DateTimeImmutable('2026-10-22 08:00:00 UTC'),
            2,
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
        ), $ownerId, $propertyId));
    }

    /** @param list<string> $lines */
    private static function csv(array $lines, string $header = self::HEADER): string
    {
        return $header . "\n" . implode("\n", $lines) . "\n";
    }

    private function import(string $csv, bool $apply, string $start = self::OCTOBER, string $email = 'alice@a.test', ?string $name = null): ResponseInterface
    {
        // Le nom que Beyond donne au fichier : les deux bornes de la période.
        $date = date_create_immutable($start);
        $name ??= $date === false ? 'export.csv' : sprintf('transactions-%s_%s.csv', $start, $date->modify('+1 month')->format('Y-m-d'));
        $file = new UploadedFile((new StreamFactory())->createStream($csv), $name, 'text/csv', strlen($csv), UPLOAD_ERR_OK);
        $request = (new ServerRequestFactory())->createServerRequest('POST', "/api/v1/costs/beyond/statements/{$start}/import", ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken($email))
            ->withParsedBody(['apply' => $apply ? 'true' : 'false'])
            ->withUploadedFiles(['file' => $file]);

        return $this->app->handle($request);
    }

    private function get(string $path, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, 'GET', '/api/v1/costs/beyond/statements' . $path, $this->bearer($this->accessToken($email)));
    }

    /** @return array<array-key, mixed> */
    private function data(ResponseInterface $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    /** @return array<string, list<string>> */
    private function fields(ResponseInterface $response): array
    {
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('validation_failed', $this->errorCode($response));
        $fields = $this->json($response)['error']['fields'];
        self::assertIsArray($fields);

        return $fields;
    }

    private function feeOf(string $reservationId, string $tenant = 'tenant-a'): int
    {
        $this->context->reset();
        $this->context->set($tenant);
        $fee = $this->beyondFees->feeOf($reservationId);
        $this->context->reset();

        return $fee;
    }

    private function rows(string $what, string $tenant = 'tenant-a'): int
    {
        $this->context->reset();
        $this->context->set($tenant);
        $count = $what === 'items' ? $this->beyondFees->count() : $this->beyondStatements->count();
        $this->context->reset();

        return $count;
    }

    private function storedFiles(): int
    {
        return count($this->beyondFiles->files);
    }

    private function october(): string
    {
        return self::csv([self::CUNEO, self::TB165, self::TB165_CANCELLED, self::AGOSTA_CANCELLATION, self::AGOSTA]);
    }

    public function testManagersAndVisitorsCannotUseTheStatements(): void
    {
        self::assertSame(401, $this->request($this->app, 'GET', '/api/v1/costs/beyond/statements')->getStatusCode());

        $responses = [
            $this->get('', 'marc@a.test'),
            $this->get('/' . self::OCTOBER, 'marc@a.test'),
            $this->get('/' . self::OCTOBER . '/file', 'marc@a.test'),
            $this->import($this->october(), true, email: 'marc@a.test'),
            $this->request($this->app, 'POST', '/api/v1/costs/beyond/statements/' . self::OCTOBER . '/reprocess', $this->bearer($this->accessToken('marc@a.test')), ['apply' => true]),
        ];
        foreach ($responses as $response) {
            self::assertSame(403, $response->getStatusCode());
            self::assertSame('forbidden', $this->errorCode($response));
        }
        self::assertSame(0, $this->rows('statements'));
    }

    public function testTheYearListsTwelveStatementsFromTheTwelfthToTheTwelfth(): void
    {
        $rows = $this->data($this->get('?year=2026'));

        self::assertCount(12, $rows);
        self::assertSame(['2026-01-12', '2026-02-12'], [$rows[0]['start'], $rows[1]['start']]);
        self::assertSame(['2026-12-12', '2027-01-12'], [$rows[11]['start'], $rows[11]['end']]);
        // Nous sommes le 13/11/2026 : le relevé d'octobre (jusqu'au 12/11) est terminé, celui de novembre non.
        self::assertSame([true, true, false], [$rows[9]['closed'], $rows[9]['start'] === self::OCTOBER, $rows[10]['closed']]);
        self::assertSame([false, null], [$rows[9]['processed'], $rows[9]['amount']]);
        self::assertSame(['year' => ['out_of_range']], $this->fields($this->get('?year=1999')));
        self::assertSame(['year' => ['invalid_value']], $this->fields($this->get('?year=abc')));
    }

    public function testAStatementOnlyStartsOnTheTwelfth(): void
    {
        foreach (['2026-10-13', '2026-02-30', 'octobre'] as $start) {
            $response = $this->import($this->october(), true, $start);
            self::assertSame(404, $response->getStatusCode(), $start);
            self::assertSame('statement_not_found', $this->errorCode($response), $start);
        }
        self::assertSame('statement_not_found', $this->errorCode($this->get('/2026-10-13')));
    }

    public function testAPeriodThatIsNotOverCannotBeImported(): void
    {
        // Le 13/11, le relevé du 12/11 au 12/12 est en cours.
        $response = $this->import($this->october(), true, '2026-11-12');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('statement_not_closed', $this->errorCode($response));
        self::assertSame(0, $this->rows('statements'));
    }

    public function testASimulationFetchesFromGuestyAndWritesNothing(): void
    {
        $this->guestyKnowsTb165();

        $report = $this->data($this->import($this->october(), false));

        self::assertFalse($report['applied']);
        self::assertSame([5, 5, 0, 2, 4], [$report['linesRead'], $report['linesNew'], $report['linesKnown'], $report['reservationsFetched'], $report['reservationsCount']]);
        // 1,45 + 5,44 − 4,71 − 2,45 + 4,90
        self::assertSame(4.63, round((float) $report['amount'], 2));
        self::assertSame(0, $this->rows('items'));
        self::assertSame(0, $this->rows('statements'));
        self::assertSame(0, $this->storedFiles());
        self::assertNull($this->reservations->byGuestyId('tenant-a', self::TB165_GUESTY), 'Une simulation n’enregistre pas les réservations lues chez Guesty.');
        self::assertSame(0, $this->feeOf('res-cuneo'));
        self::assertSame([], $this->audit->of(AuditAction::BeyondFeesImported));

        $tb165 = array_values(array_filter($report['reservations'], static fn(array $r): bool => $r['guestyId'] === self::TB165_GUESTY));
        self::assertSame(['Jean Dupont', true, 5.44], [$tb165[0]['guest'], $tb165[0]['fetched'], $tb165[0]['amount']]);
    }

    public function testGuestyIsAskedForTheMissingReservationsWhateverTheirStatus(): void
    {
        $this->guestyKnowsTb165();
        $before = count($this->thirdPartyCalls);

        $this->import($this->october(), false);

        $call = $this->thirdPartyCalls->getArrayCopy()[count($this->thirdPartyCalls) - 1]['request']->getUri();
        self::assertSame('/v1/reservations', $call->getPath());
        parse_str($call->getQuery(), $query);
        self::assertSame('[{"field":"_id","operator":"$in","value":["' . self::TB165_GUESTY . '","' . self::TB165_OLD_GUESTY . '"]}]', $query['filters'] ?? null, 'Pas de filtre de statut : une annulation doit rester lisible.');
        self::assertIsString($query['fields'] ?? null);
        self::assertStringContainsString('status', $query['fields']);
        // Contrôle d'accès + une page : les réservations déjà connues (Cuneo, Agosta 2) ne sont pas redemandées.
        self::assertSame(2, count($this->thirdPartyCalls) - $before);
    }

    public function testImportingStoresLinesFetchedReservationsFileAndStatement(): void
    {
        $this->guestyKnowsTb165();

        $report = $this->data($this->import($this->october(), true));

        self::assertTrue($report['applied']);
        self::assertSame([5, 2], [$this->rows('items'), $report['reservationsFetched']]);
        self::assertSame(145, $this->feeOf('res-cuneo'));
        // L'annulation (−2,45 €) compense une partie de la commission (4,90 €) de la même réservation.
        self::assertSame(245, $this->feeOf('res-agosta'));
        $tb165 = $this->reservations->byGuestyId('tenant-a', self::TB165_GUESTY);
        $old = $this->reservations->byGuestyId('tenant-a', self::TB165_OLD_GUESTY);
        self::assertNotNull($tb165);
        self::assertNotNull($old);
        self::assertSame(544, $this->feeOf($tb165->id));
        self::assertSame(-471, $this->feeOf($old->id));
        self::assertSame('prop-tb165', $tb165->propertyId);
        self::assertFalse($tb165->isArchived());
        self::assertTrue($old->isArchived(), 'Une annulation chez Guesty est enregistrée archivée.');
        // Le coût de l'autre tenant n'est pas touché.
        self::assertSame(0, $this->feeOf('res-b', 'tenant-b'));
        self::assertSame(0, $this->rows('items', 'tenant-b'));

        self::assertSame(1, $this->rows('statements'));
        self::assertSame(1, $this->storedFiles());
        $this->context->set('tenant-a');
        $statement = $this->beyondStatements->statements['tenant-a|2026-10-12'];
        $this->context->reset();
        self::assertSame([5, 4, 463], [$statement->linesCount, $statement->reservationsCount, $statement->amountCents]);
        self::assertSame(hash('sha256', $this->october()), $statement->fileSha256);
    }

    public function testApplyingIsAuditedWithCountersOnly(): void
    {
        $this->guestyKnowsTb165();

        $this->import($this->october(), true);

        $entries = $this->audit->of(AuditAction::BeyondFeesImported);
        self::assertCount(1, $entries);
        self::assertSame(['beyond_statement', '2026-10-12'], [$entries[0]->targetType, $entries[0]->targetLabel]);
        self::assertSame(['lines' => 5, 'new' => 5, 'known' => 0, 'fetched' => 2, 'reservationsCount' => 4, 'fileReplaced' => false], $entries[0]->context);
    }

    public function testTheListAndTheDetailShowTheProcessedStatement(): void
    {
        $this->guestyKnowsTb165();
        $this->import($this->october(), true);

        $rows = $this->data($this->get('?year=2026'));
        $october = $rows[9];
        self::assertSame([true, 'transactions-2026-10-12_2026-11-12.csv', 5, 4, 4.63], [$october['processed'], $october['sourceFile'], $october['linesCount'], $october['reservationsCount'], $october['amount']]);
        self::assertNotNull($october['processedAt']);

        $detail = $this->data($this->get('/' . self::OCTOBER));
        self::assertSame([5, 4, 4.63], [$detail['linesRead'], $detail['reservationsCount'], $detail['amount']]);
        $agosta = array_values(array_filter($detail['reservations'], static fn(array $r): bool => $r['guestyId'] === self::AGOSTA_GUESTY))[0];
        self::assertSame(2.45, $agosta['amount']);
        self::assertSame(['cancellation', -2.45], [$agosta['lines'][0]['kind'], $agosta['lines'][0]['amount']]);
        self::assertSame('2026-10-17', $agosta['lines'][0]['billedOn']);
        self::assertSame('Agosta 2', $agosta['listingTitle']);
    }

    public function testReimportingTheSameFileChangesNothingAndAsksGuestyForNothing(): void
    {
        $this->guestyKnowsTb165();
        $this->import($this->october(), true);
        $calls = count($this->thirdPartyCalls);

        $again = $this->data($this->import($this->october(), true));

        self::assertSame([5, 0, 5, 0], [$again['linesRead'], $again['linesNew'], $again['linesKnown'], $again['reservationsFetched']]);
        self::assertSame(5, $this->rows('items'));
        self::assertSame(1, $this->rows('statements'));
        self::assertSame(245, $this->feeOf('res-agosta'), 'Le total n’est jamais additionné à l’aveugle.');
        self::assertSame($calls, count($this->thirdPartyCalls));
        self::assertSame(1, $this->storedFiles(), 'L’ancien fichier est remplacé, pas empilé.');
        $entries = $this->audit->of(AuditAction::BeyondFeesImported);
        self::assertTrue($entries[1]->context['fileReplaced']);
    }

    public function testAReplacementFileAddsItsNewLinesButMustKeepTheKnownOnes(): void
    {
        $this->guestyKnowsTb165();
        $this->import($this->october(), true);

        // Une ligne de plus : acceptée.
        $extra = str_replace(['48930515', '145.63,1.45,1.45', '2026-10-19'], ['48930999', '100.00,1.00,1.00', '2026-10-20'], self::CUNEO);
        $more = $this->data($this->import($this->october() . $extra . "\n", true));
        self::assertSame([6, 1, 5], [$more['linesRead'], $more['linesNew'], $more['linesKnown']]);
        self::assertSame(245, $this->feeOf('res-cuneo'), '1,45 € + 1,00 €');

        // Une ligne connue manque : probablement un autre fichier.
        $fields = $this->fields($this->import(self::csv([self::CUNEO, self::AGOSTA]), true));
        self::assertSame(['file' => ['missing_lines']], $fields);
        self::assertSame(6, $this->rows('items'));
    }

    public function testAFileNamedForAnotherPeriodOrCarryingAnotherStatementsLinesIsRefused(): void
    {
        $this->guestyKnowsTb165();
        $this->import($this->october(), true);

        // Le fichier d'octobre déposé sur septembre : son nom porte une autre période.
        $september = $this->import($this->october(), true, self::SEPTEMBER, name: 'transactions-2026-10-12_2026-11-12.csv');
        self::assertSame(['file' => ['period_mismatch']], $this->fields($september));

        // Un fichier sans nom reconnaissable passe ce contrôle, mais une ligne déjà portée par octobre est refusée.
        $elsewhere = $this->import(self::csv([self::CUNEO_UPDATE, self::CUNEO]), true, self::SEPTEMBER, name: 'export.csv');
        self::assertSame(['line_3' => ['item_in_other_statement']], $this->fields($elsewhere));

        self::assertSame(1, $this->rows('statements'));
        self::assertSame(5, $this->rows('items'));
    }

    public function testLinesOfStaysEndedBefore2025AreIgnored(): void
    {
        // Séjour du 28/12/2024 au 31/12/2024 : écarté sans interroger Guesty. Séjour du 30/12/2024 au 01/01/2025 : gardé.
        $old = str_replace(['2026-09-16,2026-10-05,Update', '48705113', '"Sep 17, 2026",1'], ['2026-09-20,,Reservation', '48705301', '"Dec 28, 2024",3'], self::CUNEO_UPDATE);
        $edge = str_replace(['2026-09-16,2026-10-05,Update', '48705113', '"Sep 17, 2026",1'], ['2026-09-20,,Reservation', '48705302', '"Dec 30, 2024",2'], self::CUNEO_UPDATE);
        $this->guestyReturns([self::guesty('6aaa32cfe75c0b730427094d', 'L-prop-cuneo')]);

        $report = $this->data($this->import(self::csv([$old, $edge]), true, self::SEPTEMBER));

        self::assertSame([1, 1, 1], [$report['linesRead'], $report['linesIgnored'], $this->rows('items')]);
        self::assertSame(0.01, $report['amount']);
    }

    public function testNonBillableAdjustmentsAreSkipped(): void
    {
        $adjustment = '26581631,Smily,86771024,0BUFGS,19895500,19895500,2025-01-09,2025-02-11,Non-Billable,Kennedy,"Jan 10, 2025",2,EUR,0.00,0.00,-1.99,pro_package';

        $report = $this->data($this->import(self::csv([self::CUNEO, $adjustment]), true));

        self::assertSame([1, 1, 1.45], [$report['linesRead'], $report['linesIgnored'], $report['amount']]);
    }

    public function testALineBilledLongAfterItsBookingStaysInTheStatementThatCarriesIt(): void
    {
        // Beyond facture parfois des semaines plus tard (prise le 24/07, relevé du 12/08) : la date ne désigne pas le relevé.
        $this->guestyReturns([self::guesty('6aaa32cfe75c0b730427094d', 'L-prop-cuneo')]);
        $late = str_replace(['2026-09-16,2026-10-05,Update', '48705113'], ['2026-07-24,,Reservation', '48705199'], self::CUNEO_UPDATE);

        self::assertSame(1, $this->data($this->import(self::csv([$late]), true, self::SEPTEMBER))['linesRead']);
    }

    public function testAnUpdateLineIsReadAndAddedToTheReservation(): void
    {
        $this->guestyReturns([self::guesty('6aaa32cfe75c0b730427094d', 'L-prop-cuneo')]);

        $report = $this->data($this->import(self::csv([self::CUNEO_UPDATE]), true, self::SEPTEMBER));

        self::assertSame([1, 1, 0.01], [$report['linesRead'], $report['reservationsFetched'], $report['amount']]);
        self::assertSame('update', $report['reservations'][0]['lines'][0]['kind']);
        self::assertSame('2026-10-05', $report['reservations'][0]['lines'][0]['billedOn']);
        self::assertSame([], $report['warnings']);
    }

    public function testALineWhoseReservationGuestyDoesNotKnowIsKeptForItsPropertyAndMonth(): void
    {
        // Guesty ne renvoie que l'une des deux réservations manquantes (l'autre a disparu avec son logement).
        $this->guestyReturns([self::guesty(self::TB165_GUESTY, 'L-prop-tb165')]);

        $report = $this->data($this->import($this->october(), true));

        self::assertSame([5, 5, 5], [$report['linesRead'], $report['linesNew'], $this->rows('items')]);
        self::assertSame(4.63, $report['amount'], '9,34 € des autres lignes − 4,71 €');
        self::assertSame([['listingTitle' => 'Tb165', 'checkIn' => '2026-12-26', 'kind' => 'cancellation', 'billedOn' => '2026-10-17', 'amount' => -4.71]], $report['unmatched']);
        self::assertSame(0, $report['unmatchedWithoutProperty'], 'le titre « Tb165 » désigne le logement Tb165');
        $orphan = $this->beyondFees->items['tenant-a|48905353'];
        self::assertNull($orphan->reservationId);
        self::assertSame('prop-tb165', $orphan->propertyId);
    }

    public function testALineWhoseListingTitleMatchesNoPropertyIsKeptWithoutProperty(): void
    {
        $this->guestyReturns([self::guesty(self::TB165_GUESTY, 'L-prop-tb165')]);
        $renamed = str_replace('Tb165,"Dec 26, 2026"', 'Villa Disparue,"Dec 26, 2026"', self::TB165_CANCELLED);

        $report = $this->data($this->import(self::csv([self::CUNEO, self::TB165, $renamed, self::AGOSTA_CANCELLATION, self::AGOSTA]), true));

        self::assertSame(1, $report['unmatchedWithoutProperty']);
        self::assertNull($this->beyondFees->items['tenant-a|48905353']->propertyId);
    }

    public function testAnUnknownPropertyRefusesTheWholeStatementAndNamesIt(): void
    {
        $this->guestyReturns([
            self::guesty(self::TB165_GUESTY, 'L-unknown'),
            self::guesty(self::TB165_OLD_GUESTY, 'L-unknown', 'canceled'),
        ]);

        $response = $this->import($this->october(), true);

        self::assertSame(['listing:Tb165' => ['property_unknown']], $this->fields($response));
        $this->assertNothingWritten();
    }

    public function testGuestyNotConfiguredRefusesWhenReservationsAreMissing(): void
    {
        $this->tenants->integrations = [];
        $this->integrations->rows = [];
        $response = $this->import($this->october(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('integration_not_configured', $this->errorCode($response));
        $this->assertNothingWritten();
    }

    private function assertNothingWritten(): void
    {
        self::assertSame(0, $this->rows('items'));
        self::assertSame(0, $this->rows('statements'));
        self::assertSame(0, $this->storedFiles());
        self::assertNull($this->reservations->byGuestyId('tenant-a', self::TB165_GUESTY));
        self::assertSame(0, $this->feeOf('res-cuneo'));
        self::assertSame([], $this->audit->of(AuditAction::BeyondFeesImported));
    }

    public function testAFeeThatIsNotOnePercentIsAWarningNotAnError(): void
    {
        $odd = str_replace('145.63,1.45,1.45', '145.63,1.50,1.50', self::CUNEO);

        $report = $this->data($this->import(self::csv([$odd]), true));

        self::assertSame([['row' => 2, 'code' => 'fee_not_one_percent']], $report['warnings']);
        self::assertSame(150, $this->feeOf('res-cuneo'));
    }

    public function testAFaultyFileIsRefusedAsAWholeAndWritesNothing(): void
    {
        $cases = [
            'wrong currency' => [self::csv([self::CUNEO, str_replace('EUR', 'USD', self::TB165)]), 'line_3', 'unsupported_currency'],
            'fee and amount differ' => [self::csv([str_replace('1.45,1.45', '1.45,1.44', self::CUNEO)]), 'line_2', 'amount_mismatch'],
            'unreadable amount' => [self::csv([str_replace('145.63', '145.631', self::CUNEO)]), 'line_2', 'invalid_amount'],
            'invalid date' => [self::csv([str_replace('2026-10-19', '2026-02-30', self::CUNEO)]), 'line_2', 'invalid_date'],
            'unknown kind' => [self::csv([str_replace('Reservation,Cuneo', 'Refund,Cuneo', self::CUNEO)]), 'line_2', 'invalid_kind'],
            'duplicate line' => [self::csv([self::CUNEO, self::CUNEO]), 'line_3', 'duplicate_item'],
        ];
        foreach ($cases as $label => [$csv, $field, $code]) {
            self::assertSame([$field => [$code]], $this->fields($this->import($csv, true)), $label);
        }
        self::assertSame(['file' => ['invalid_header']], $this->fields($this->import(self::csv([self::CUNEO], str_replace('Amount,', 'Montant,', self::HEADER)), true)));
        self::assertSame(['file' => ['empty']], $this->fields($this->import(self::HEADER . "\n", true)));
        self::assertSame(['file' => ['invalid_encoding']], $this->fields($this->import("\xFF\xFE\x00", true)));
        self::assertSame(0, $this->rows('items'));
        self::assertSame(0, $this->storedFiles());
    }

    public function testTheRequestIsValidated(): void
    {
        $token = $this->bearer($this->accessToken());
        $url = '/api/v1/costs/beyond/statements/' . self::OCTOBER . '/import';
        $noFile = $this->app->handle((new ServerRequestFactory())->createServerRequest('POST', $url, ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Authorization', $token['Authorization'])
            ->withParsedBody(['apply' => 'false']));
        self::assertSame(['file' => ['required']], $this->fields($noFile));

        $content = $this->october();
        $file = new UploadedFile((new StreamFactory())->createStream($content), 'x.csv', 'text/csv', strlen($content), UPLOAD_ERR_OK);
        $noApply = $this->app->handle((new ServerRequestFactory())->createServerRequest('POST', $url, ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Authorization', $token['Authorization'])
            ->withUploadedFiles(['file' => $file]));
        self::assertSame(['apply' => ['required']], $this->fields($noApply));

        $big = str_repeat('x', 2_097_153);
        $tooLarge = $this->app->handle((new ServerRequestFactory())->createServerRequest('POST', $url, ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Authorization', $token['Authorization'])
            ->withParsedBody(['apply' => 'false'])
            ->withUploadedFiles(['file' => new UploadedFile((new StreamFactory())->createStream($big), 'big.csv', 'text/csv', strlen($big), UPLOAD_ERR_OK)]));
        self::assertSame(['file' => ['too_large']], $this->fields($tooLarge));
    }

    public function testTheStoredFileCanBeDownloadedAndReprocessed(): void
    {
        $this->guestyKnowsTb165();
        $this->import($this->october(), true);

        $file = $this->get('/' . self::OCTOBER . '/file');
        self::assertSame(200, $file->getStatusCode());
        self::assertSame($this->october(), (string) $file->getBody());
        self::assertStringContainsString('transactions-2026-10-12_2026-11-12.csv', $file->getHeaderLine('Content-Disposition'));
        self::assertSame('private, no-store', $file->getHeaderLine('Cache-Control'));

        $calls = count($this->thirdPartyCalls);
        $again = $this->request($this->app, 'POST', '/api/v1/costs/beyond/statements/' . self::OCTOBER . '/reprocess', $this->bearer($this->accessToken()), ['apply' => true]);
        $report = $this->data($again);
        self::assertSame([true, 5, 0, 5], [$report['applied'], $report['linesRead'], $report['linesNew'], $report['linesKnown']]);
        self::assertSame(1, $this->storedFiles());
        self::assertSame($calls, count($this->thirdPartyCalls));
        self::assertSame(1, $this->rows('statements'));

        $missing = $this->request($this->app, 'POST', '/api/v1/costs/beyond/statements/' . self::SEPTEMBER . '/reprocess', $this->bearer($this->accessToken()), ['apply' => false]);
        self::assertSame('statement_not_found', $this->errorCode($missing));
        $noApply = $this->request($this->app, 'POST', '/api/v1/costs/beyond/statements/' . self::OCTOBER . '/reprocess', $this->bearer($this->accessToken()), []);
        self::assertSame(['apply' => ['required']], $this->fields($noApply));
    }

    public function testAnotherTenantSeesNothingOfTheStatements(): void
    {
        $this->guestyKnowsTb165();
        $this->import($this->october(), true);

        $rows = $this->data($this->get('?year=2026', 'bob@b.test'));
        self::assertSame([false, null], [$rows[9]['processed'], $rows[9]['amount']]);
        foreach (['/' . self::OCTOBER, '/' . self::OCTOBER . '/file'] as $path) {
            $response = $this->get($path, 'bob@b.test');
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertSame('statement_not_found', $this->errorCode($response), $path);
        }
        // Le tenant B peut traiter sa propre période, avec ses propres réservations.
        $b = $this->import(self::csv([self::CUNEO]), true, email: 'bob@b.test');
        self::assertSame(200, $b->getStatusCode(), (string) $b->getBody());
        self::assertSame(145, $this->feeOf('res-b', 'tenant-b'));
        self::assertSame([1, 1, 5, 1], [$this->rows('statements'), $this->rows('statements', 'tenant-b'), $this->rows('items'), $this->rows('items', 'tenant-b')]);
    }
}

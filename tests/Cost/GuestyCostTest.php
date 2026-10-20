<?php

declare(strict_types=1);

namespace Casamiza\Tests\Cost;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Tests\Auth\AuthTestCase;
use Casamiza\Tests\Support\XlsxBuilder;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * Factures de Guesty (export xlsx, en dollars) : `/costs/guesty`, administrateurs seulement. Les lignes reprennent la forme des
 * exports réels (mois entier « Guesty PMS », prorata sans libellé).
 */
final class GuestyCostTest extends AuthTestCase
{
    private const OCTOBER = 'invoice_INV00814774.xlsx';
    private const PRORATA = 'invoice_INV00796660.xlsx';

    protected function setUp(): void
    {
        parent::setUp();
        $this->context->set('tenant-a');
        $this->owners->add(new Owner('owner-1', 'GO1', 'Alice Martin', 'Alice', 'Martin', '', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        foreach (['prop-kennedy' => 'Kennedy', 'prop-bonaparte' => 'Bonaparte 8 T2', 'prop-santa' => 'Santa Lina 714'] as $id => $name) {
            $this->properties->add(new Property($id, 'L-' . $id, 'owner-1', $name, '', 0.0, 0.0, null));
        }
        // Deux logements de même nom : l'actif est choisi.
        $this->properties->add(new Property('prop-sagone-old', 'L-old', 'owner-1', 'Sagone', '', 0.0, 0.0, new DateTimeImmutable('2026-01-01 UTC')));
        $this->properties->add(new Property('prop-sagone', 'L-sagone', 'owner-1', 'Sagone', '', 0.0, 0.0, null));
        $this->context->reset();

        $this->context->set('tenant-b');
        $this->owners->add(new Owner('owner-b', 'GOB', 'Autre', 'Autre', 'Propriétaire', '', '', '', OwnerType::Individual, '', '', 10.0, null, null));
        $this->properties->add(new Property('prop-b', 'LB', 'owner-b', 'Kennedy', '', 0.0, 0.0, null));
        $this->context->reset();

        $this->exchangeRates->default = '0.885110';
    }

    /** @return list<array<int, string>> */
    private static function october(): array
    {
        return [
            ['Kennedy', 'Guesty PMS', '2026-10-01', '2026-10-31', '22'],
            ['Bonaparte8 T2', 'Guesty PMS', '2026-10-01', '2026-10-31', '22'],
            ['Sagone', 'Guesty PMS', '2026-10-01', '2026-10-31', '22'],
            ['RUE714', 'Guesty PMS', '2026-10-01', '2026-10-31', '22'],
            ['Kennedy', '', '2026-09-29', '2026-09-30', '2'],
        ];
    }

    private function import(string $bytes, bool $apply, string $name = self::OCTOBER, string $email = 'alice@a.test'): ResponseInterface
    {
        $file = new UploadedFile((new StreamFactory())->createStream($bytes), $name, 'application/vnd.ms-excel', strlen($bytes), UPLOAD_ERR_OK);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/costs/guesty/import', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken($email))
            ->withParsedBody(['apply' => $apply ? 'true' : 'false'])
            ->withUploadedFiles(['file' => $file]);

        return $this->app->handle($request);
    }

    private function get(string $path, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, 'GET', '/api/v1/costs/guesty' . $path, $this->bearer($this->accessToken($email)));
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

    private function storedInvoices(string $tenant = 'tenant-a'): int
    {
        $this->context->reset();
        $this->context->set($tenant);
        $count = count($this->guestyCosts->invoices());
        $this->context->reset();

        return $count;
    }

    public function testManagersAndVisitorsCannotUseTheInvoices(): void
    {
        self::assertSame(401, $this->request($this->app, 'GET', '/api/v1/costs/guesty/invoices')->getStatusCode());

        foreach ([
            $this->get('/invoices', 'marc@a.test'),
            $this->get('/invoices/INV00814774', 'marc@a.test'),
            $this->get('/invoices/INV00814774/file', 'marc@a.test'),
            $this->import(XlsxBuilder::build(self::october()), true, email: 'marc@a.test'),
        ] as $response) {
            self::assertSame(403, $response->getStatusCode());
            self::assertSame('forbidden', $this->errorCode($response));
        }
        self::assertSame(0, $this->storedInvoices());
    }

    public function testASimulationReportsEverythingAndWritesNothing(): void
    {
        $report = $this->data($this->import(XlsxBuilder::build(self::october()), false));

        self::assertFalse($report['applied']);
        self::assertSame(['INV00814774', '2026-10-01', '0.885110', 5], [$report['number'], $report['invoiceDate'], $report['rate'], $report['linesRead']]);
        self::assertEqualsWithDelta(90.0, $report['amountUsd'], 0.001);
        // 22 $ × 0,88511 = 19,47 € par ligne de mois, 2 $ → 1,77 € : chaque ligne est arrondie, le total est la somme des lignes.
        self::assertEqualsWithDelta(4 * 19.47 + 1.77, $report['amount'], 0.001);
        self::assertSame(['2026-10-01'], $this->exchangeRates->asked);
        self::assertSame([0, 0], [$report['duplicates'], $report['replaced'] ? 1 : 0]);
        self::assertSame(0, $this->storedInvoices());
        self::assertSame([], $this->guestyCostFiles->files);
    }

    public function testApplyingStoresTheInvoiceItsFileAndAnAuditEntry(): void
    {
        $bytes = XlsxBuilder::build(self::october());
        $report = $this->data($this->import($bytes, true));

        self::assertTrue($report['applied']);
        self::assertSame(1, $this->storedInvoices());
        self::assertCount(1, $this->guestyCostFiles->files);

        $list = $this->data($this->get('/invoices'));
        self::assertCount(1, $list);
        self::assertSame(['INV00814774', self::OCTOBER, '2026-10-01', '0.885110', 5], [$list[0]['number'], $list[0]['sourceFile'], $list[0]['invoiceDate'], $list[0]['rate'], $list[0]['linesCount']]);
        // Période couverte (de la plus ancienne ligne à la plus récente) et annonces distinctes
        self::assertSame(['2026-09-29', '2026-10-31', 4], [$list[0]['periodStart'], $list[0]['periodEnd'], $list[0]['listingsCount']]);
        self::assertEqualsWithDelta($report['amount'], $list[0]['amount'], 0.001);
        self::assertEqualsWithDelta(90.0, $list[0]['amountUsd'], 0.001);

        $detail = $this->data($this->get('/invoices/INV00814774'));
        self::assertCount(5, $detail['items']);
        self::assertSame(['monthly', 'prorata'], [$detail['items'][0]['kind'], $detail['items'][4]['kind']]);

        $file = $this->get('/invoices/INV00814774/file');
        self::assertSame(200, $file->getStatusCode());
        self::assertSame($bytes, (string) $file->getBody());
        self::assertSame('private, no-store', $file->getHeaderLine('Cache-Control'));

        $entries = $this->audit->of(AuditAction::GuestyCostsImported);
        self::assertCount(1, $entries);
        self::assertSame(['guesty_cost_invoice', 'INV00814774'], [$entries[0]->targetType, $entries[0]->targetLabel]);
    }

    public function testListingsAreMatchedToPropertiesByNameAliasAndActivity(): void
    {
        $report = $this->data($this->import(XlsxBuilder::build(self::october()), true));

        $byListing = [];
        foreach ($report['items'] as $item) {
            $byListing[$item['listing'] . '|' . $item['kind']] = $item['property'];
        }
        self::assertSame('Kennedy', $byListing['Kennedy|monthly']);
        // « Bonaparte8 T2 » retrouve « Bonaparte 8 T2 » (espaces et ponctuation ignorés)
        self::assertSame('Bonaparte 8 T2', $byListing['Bonaparte8 T2|monthly']);
        // Entre deux logements « Sagone », l'actif
        self::assertSame('Sagone', $byListing['Sagone|monthly']);
        $this->context->reset();
        $this->context->set('tenant-a');
        $sagone = array_values(array_filter($this->guestyCosts->items($this->guestyCosts->findInvoice('INV00814774')->id ?? ''), static fn($i) => $i->line->nickname === 'Sagone'))[0];
        $this->context->reset();
        self::assertSame('prop-sagone', $sagone->propertyId);
        // Aucun logement ne porte « RUE714 »
        self::assertNull($byListing['RUE714|monthly']);
        self::assertSame(1, $report['unmatched']);

        // Un nom rattaché à la main (annonce renommée depuis) trouve son logement
        $this->context->reset();
        $this->context->set('tenant-a');
        $this->guestyCosts->saveAlias('rue714', 'prop-santa');
        $this->context->reset();
        $again = $this->data($this->import(XlsxBuilder::build(self::october()), false));
        self::assertSame(0, $again['unmatched']);
        $rue = array_values(array_filter($again['items'], static fn(array $i): bool => $i['listing'] === 'RUE714'))[0];
        self::assertSame('Santa Lina 714', $rue['property']);
    }

    public function testAProrataOnlyInvoiceIsDatedTheDayAfterItsLastPeriod(): void
    {
        $report = $this->data($this->import(XlsxBuilder::build([
            ['Kennedy', '', '2026-08-24', '2026-08-31', '6'],
            ['Bonaparte 8 T2', '', '2026-08-28', '2026-08-31', '3'],
        ]), false, self::PRORATA));

        self::assertSame('2026-09-01', $report['invoiceDate']);
        self::assertSame(['2026-09-01'], $this->exchangeRates->asked);
    }

    public function testALineSeenOnTwoInvoicesCountsOnce(): void
    {
        $first = XlsxBuilder::build([['Kennedy', '', '2026-05-13', '2026-05-31', '12'], ['Sagone', '', '2026-05-26', '2026-05-31', '4']]);
        // Réémission : mêmes montants, période étendue au 30 juin
        $second = XlsxBuilder::build([['Kennedy', '', '2026-05-13', '2026-06-30', '12'], ['Sagone', '', '2026-05-26', '2026-06-30', '4']]);

        $this->data($this->import($first, true, 'invoice_INV00497806.xlsx'));
        $report = $this->data($this->import($second, true, 'invoice_INV00534368.xlsx'));

        self::assertSame(2, $report['duplicates']);
        self::assertEqualsWithDelta(0.0, $report['amount'], 0.001);
        self::assertEqualsWithDelta(16.0, $report['amountUsd'], 0.001);
        self::assertFalse($report['items'][0]['counted']);
        $list = $this->data($this->get('/invoices'));
        self::assertEqualsWithDelta(0.0, $list[0]['amount'], 0.001);
        self::assertGreaterThan(0, $list[1]['amount']);
    }

    public function testImportingAnInvoiceAgainReplacesItsLines(): void
    {
        $this->data($this->import(XlsxBuilder::build(self::october()), true));
        $changed = self::october();
        $changed[0][4] = '25';
        $report = $this->data($this->import(XlsxBuilder::build($changed), true));

        self::assertTrue($report['replaced']);
        self::assertSame(1, $this->storedInvoices());
        self::assertCount(1, $this->guestyCostFiles->files, 'l\'ancien fichier est supprimé');
        $detail = $this->data($this->get('/invoices/INV00814774'));
        self::assertCount(5, $detail['items']);
        self::assertEqualsWithDelta(93.0, $detail['amountUsd'], 0.001);
        // Les lignes de la facture remplacée ne sont pas prises pour des doublons d'elles-mêmes.
        self::assertSame(0, $detail['duplicates']);
    }

    public function testARefusedFileWritesNothing(): void
    {
        $badDate = self::october();
        $badDate[1][2] = '';
        $badPeriod = self::october();
        $badPeriod[2][3] = '2026-09-01';
        $badAmount = self::october();
        $badAmount[3][4] = 'abc';
        $noListing = self::october();
        $noListing[0][0] = '';

        self::assertSame(['file' => ['invalid_header']], $this->fields($this->import(XlsxBuilder::build(self::october(), ['Nom', 'Item', 'Du', 'Au', 'Prix']), true)));
        self::assertSame(['line_3' => ['invalid_date']], $this->fields($this->import(XlsxBuilder::build($badDate), true)));
        self::assertSame(['line_4' => ['invalid_period']], $this->fields($this->import(XlsxBuilder::build($badPeriod), true)));
        self::assertSame(['line_5' => ['invalid_amount']], $this->fields($this->import(XlsxBuilder::build($badAmount), true)));
        self::assertSame(['line_2' => ['invalid_listing']], $this->fields($this->import(XlsxBuilder::build($noListing), true)));
        self::assertSame(['file' => ['empty']], $this->fields($this->import(XlsxBuilder::build([]), true)));
        self::assertSame(['file' => ['invalid_file']], $this->fields($this->import('pas un xlsx', true)));
        self::assertSame(['file' => ['invalid_file_name']], $this->fields($this->import(XlsxBuilder::build(self::october()), true, 'export.xlsx')));

        self::assertSame(0, $this->storedInvoices());
        self::assertSame([], $this->guestyCostFiles->files);
    }

    public function testAnUnavailableExchangeRateRefusesTheInvoiceWithoutWritingAnything(): void
    {
        $this->exchangeRates->down = true;

        $response = $this->import(XlsxBuilder::build(self::october()), true);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('exchange_rate_unavailable', $this->errorCode($response));
        self::assertSame(0, $this->storedInvoices());
    }

    public function testATenantNeverSeesAnotherTenantsInvoices(): void
    {
        $this->data($this->import(XlsxBuilder::build(self::october()), true));

        self::assertSame([], $this->data($this->get('/invoices', 'bob@b.test')));
        foreach (['/invoices/INV00814774', '/invoices/INV00814774/file'] as $path) {
            $response = $this->get($path, 'bob@b.test');
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('invoice_not_found', $this->errorCode($response));
        }

        // Le même numéro est libre chez l'autre tenant, qui ne rattache que ses propres logements.
        $report = $this->data($this->import(XlsxBuilder::build(self::october()), true, email: 'bob@b.test'));
        self::assertFalse($report['replaced']);
        $kennedy = array_values(array_filter($report['items'], static fn(array $i): bool => $i['listing'] === 'Kennedy'))[0];
        self::assertSame('Kennedy', $kennedy['property']);
        self::assertSame(1, $this->storedInvoices('tenant-a'));
        self::assertSame(1, $this->storedInvoices('tenant-b'));
    }

    private function delete(string $number, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, 'DELETE', '/api/v1/costs/guesty/invoices/' . $number, $this->bearer($this->accessToken($email)));
    }

    public function testDeletingAnInvoiceRemovesItsLinesItsFileAndIsAudited(): void
    {
        $this->data($this->import(XlsxBuilder::build(self::october()), true));
        self::assertCount(1, $this->guestyCostFiles->files);

        $response = $this->delete('INV00814774');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(0, $this->storedInvoices());
        self::assertSame([], $this->guestyCostFiles->files);
        self::assertSame([], $this->data($this->get('/invoices')));
        self::assertSame(404, $this->get('/invoices/INV00814774')->getStatusCode());
        $entries = $this->audit->of(AuditAction::GuestyCostInvoiceDeleted);
        self::assertCount(1, $entries);
        self::assertSame('INV00814774', $entries[0]->targetLabel);

        // Elle peut être importée de nouveau.
        $again = $this->data($this->import(XlsxBuilder::build(self::october()), true));
        self::assertFalse($again['replaced']);
    }

    public function testDeletingTheInvoiceThatCountedALineHandsTheCostToTheDuplicate(): void
    {
        $line = ['Kennedy', '', '2026-05-13', '2026-05-31', '12'];
        $this->data($this->import(XlsxBuilder::build([$line]), true, 'invoice_INV00497806.xlsx'));
        $duplicate = $this->data($this->import(XlsxBuilder::build([['Kennedy', '', '2026-05-13', '2026-06-30', '12']]), true, 'invoice_INV00534368.xlsx'));
        self::assertEqualsWithDelta(0.0, $duplicate['amount'], 0.001);

        self::assertSame(204, $this->delete('INV00497806')->getStatusCode());

        $detail = $this->data($this->get('/invoices/INV00534368'));
        self::assertTrue($detail['items'][0]['counted']);
        self::assertSame(0, $detail['duplicates']);
        // 12 $ × 0,88511 : le coût n'a pas disparu avec la facture supprimée
        self::assertEqualsWithDelta(10.62, $detail['amount'], 0.001);
        self::assertEqualsWithDelta(10.62, $this->data($this->get('/invoices'))[0]['amount'], 0.001);
    }

    public function testOnlyAnAdministratorOfTheTenantCanDeleteAnInvoice(): void
    {
        $this->data($this->import(XlsxBuilder::build(self::october()), true));

        $manager = $this->delete('INV00814774', 'marc@a.test');
        self::assertSame(403, $manager->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($manager));

        $other = $this->delete('INV00814774', 'bob@b.test');
        self::assertSame(404, $other->getStatusCode());
        self::assertSame('invoice_not_found', $this->errorCode($other));
        self::assertSame(404, $this->delete('abc')->getStatusCode());
        self::assertSame(1, $this->storedInvoices());
    }

    public function testAnUnknownInvoiceNumberIs404(): void
    {
        foreach (['/invoices/INV99999999', '/invoices/abc'] as $path) {
            $response = $this->get($path);
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('invoice_not_found', $this->errorCode($response));
        }
    }
}

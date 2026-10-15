<?php

declare(strict_types=1);

namespace Casamiza\Tests\Import;

use Casamiza\Domain\Extra\ExtraType;
use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Import\FacturizaInvoiceImporter;
use Casamiza\Domain\Import\InvoiceImportReport;
use Casamiza\Domain\Invoice\Invoice;
use Casamiza\Domain\Invoice\InvoiceLineKind;
use Casamiza\Domain\Invoice\InvoiceSource;
use Casamiza\Domain\Invoice\PaymentMethod;
use Casamiza\Domain\Invoice\PaymentStatus;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\Platform;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Shared\TenantContext;
use Casamiza\Tests\Support\ImmediateTransaction;
use Casamiza\Tests\Support\InMemoryExtras;
use Casamiza\Tests\Support\InMemoryInvoiceFiles;
use Casamiza\Tests\Support\InMemoryInvoices;
use Casamiza\Tests\Support\InMemoryOwners;
use Casamiza\Tests\Support\InMemoryProperties;
use Casamiza\Tests\Support\InMemoryReservations;
use Casamiza\Tests\Support\InMemoryStatements;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Import de l'historique de Facturiza sur un mini-export (jamais la vraie base) : 3 factures, dont une sans JSON (n° 1) et une
 * annulée qui double une facture du même mois (n° 3), avec leurs PDF, une prestation et un « pas de facture ».
 */
final class FacturizaInvoiceImporterTest extends TestCase
{
    private TenantContext $context;
    private InMemoryInvoices $invoices;
    private InMemoryOwners $owners;
    private InMemoryProperties $properties;
    private InMemoryReservations $reservations;
    private InMemoryExtras $extras;
    private InMemoryStatements $statements;
    private InMemoryInvoiceFiles $files;
    private FacturizaInvoiceImporter $importer;
    private string $directory;

    protected function setUp(): void
    {
        $this->context = new TenantContext();
        $this->context->set('tenant-a');
        $this->invoices = new InMemoryInvoices($this->context);
        $this->owners = new InMemoryOwners($this->context);
        $this->properties = new InMemoryProperties($this->context);
        $this->reservations = new InMemoryReservations($this->context);
        $this->extras = new InMemoryExtras($this->context);
        $this->statements = new InMemoryStatements($this->context);
        $this->files = new InMemoryInvoiceFiles();
        $this->importer = new FacturizaInvoiceImporter($this->invoices, $this->owners, $this->properties, $this->reservations, $this->extras, $this->statements, $this->files, new ImmediateTransaction());

        $this->owners->add(new Owner('owner-1', 'GO1', 'Alice Martin', 'Alice', 'Martin', '', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->properties->add(new Property('prop-1', 'L1', 'owner-1', 'Villa Santa', '', 48.0, 17.0, null));

        $this->directory = sys_get_temp_dir() . '/casamiza-import-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        foreach (['a', 'b', 'c', 'd'] as $name) {
            file_put_contents($this->directory . "/$name.pdf", "%PDF-1.4 $name");
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    /** @return array<string, mixed> */
    private static function json(): array
    {
        $amounts = static fn(float $ht, float $ttc): array => ['Montant_HT' => $ht, 'TVA' => 20, 'Montant_TTC' => $ttc];

        return [
            'facture_information' => ['numero_facture' => 'FZ0000002', 'devise' => 'EUR', 'date_facture' => '2026-05-04', 'periode_start' => '2026-04-01', 'periode_end' => '2026-04-30', 'note' => 'Merci', 'client' => []],
            'facturation_par_listing' => [[
                'listing_nickname' => 'Villa Santa',
                'listing_guesty_id' => 'L1',
                'listing_reservations' => [[
                    'reservation_details' => ['guesty_res_id' => 'GR1', 'guesty_listing_id' => 'L1', 'checkin' => '2026-04-02T14:00:00.000Z', 'checkout' => '2026-04-05T08:00:00.000Z', 'channel' => 'Abritel', 'nights_count' => 3, 'total_payout' => 500, 'channel_comission' => 36, 'paiement_fee' => 5, 'taxes' => 12, 'cleaning_fee' => 48, 'net_payout' => 433, 'conciergerie_fee' => 60, 'net_owner_revenue' => 300],
                    'facturation_details' => ['frais_conciergerie' => $amounts(50, 60), 'frais_linge' => $amounts(14.17, 17), 'frais_menage' => $amounts(0, 0), 'frais_comission_plateforme' => $amounts(30, 36)],
                ], [
                    'reservation_details' => ['guesty_res_id' => 'GR-GONE', 'guesty_listing_id' => 'L1', 'checkin' => '2026-04-10T14:00:00.000Z', 'checkout' => '2026-04-12T08:00:00.000Z', 'channel' => 'Booking.com (M)', 'nights_count' => 2, 'total_payout' => 200, 'channel_comission' => 0, 'paiement_fee' => 0, 'taxes' => 0, 'cleaning_fee' => 0, 'net_payout' => 200, 'conciergerie_fee' => 30, 'net_owner_revenue' => 170],
                    'facturation_details' => ['frais_conciergerie' => $amounts(25, 30), 'frais_linge' => $amounts(14.17, 17), 'frais_menage' => $amounts(0, 0), 'frais_comission_plateforme' => []],
                ]],
            ]],
            'facturation_supplementaire' => ['conciergerie' => [], 'linge' => [['description' => 'Linge le 19/04', 'Montant_HT' => 14.17, 'TVA' => 20, 'Montant_TTC' => 17]], 'menage' => []],
            'totaux_par_categorie' => [],
            'facture_totaux' => ['total_ht' => 233.34, 'total_tva' => 46.67, 'total_ttc' => 280.0],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, string|int|float|null>
     */
    private static function row(int $id, array $overrides = []): array
    {
        /** @var array<string, string|int|float|null> */
        return $overrides + [
            'id' => $id, 'user_id' => 2, 'guesty_owner_id' => 'GO1', 'document_id' => 11, 'recap_document_id' => 0,
            'start' => '2025-01-01', 'end' => '2025-01-31', 'month' => '2025-01', 'total_ht' => 100.0, 'total_vat' => 20.0, 'total_ttc' => 120.0,
            'json_invoice' => '', 'datetime_created' => '2025-02-28 16:02:07', 'paid' => 0, 'paiement_type' => '', 'date_paid' => '0000-00-00', 'cancelled' => 0,
        ];
    }

    /** @return list<array<string, string|int|float|null>> */
    private static function documents(): array
    {
        return [
            ['id' => 11, 'filename' => 'a.pdf', 'type' => 'invoice'],
            ['id' => 12, 'filename' => 'b.pdf', 'type' => 'invoice'],
            ['id' => 13, 'filename' => 'c.pdf', 'type' => 'recap'],
            ['id' => 14, 'filename' => 'd.pdf', 'type' => 'invoice'],
        ];
    }

    /** @return list<array<string, string|int|float|null>> */
    private static function invoiceRows(): array
    {
        return [
            self::row(1, ['paid' => 1, 'paiement_type' => 'wire', 'date_paid' => '2025-03-10']),
            self::row(2, ['document_id' => 12, 'recap_document_id' => 13, 'start' => '2026-04-01', 'end' => '2026-04-30', 'month' => '2026-04', 'total_ht' => 233.333, 'total_vat' => 46.667, 'total_ttc' => 280.0, 'json_invoice' => (string) json_encode(self::json()), 'datetime_created' => '2026-05-04 09:12:00']),
            self::row(3, ['document_id' => 14, 'start' => '2026-04-01', 'end' => '2026-04-30', 'month' => '2026-04', 'datetime_created' => '2026-05-02 10:00:00', 'cancelled' => 1]),
        ];
    }

    /** @return list<array<string, string|int|float|null>> */
    private static function tasks(): array
    {
        return [
            ['id' => 5, 'owner_id' => 'GO1', 'date_performed' => '2026-04-15', 'type' => 'linen', 'text' => 'Linge le 19/04', 'price_ht' => 14.1667, 'price_ttc' => 17.0, 'removed' => 0],
            ['id' => 6, 'owner_id' => 'GO1', 'date_performed' => '2026-04-15', 'type' => 'cleaning', 'text' => 'Supprimée', 'price_ht' => 40.0, 'price_ttc' => 48.0, 'removed' => 1],
            ['id' => 7, 'owner_id' => 'GO1', 'date_performed' => '2026-06-15', 'type' => 'conciergerie', 'text' => 'Débours', 'price_ht' => 100.0, 'price_ttc' => 100.0, 'removed' => 0],
        ];
    }

    /** @return list<array<string, string|int|float|null>> */
    private static function noInvoice(): array
    {
        return [
            ['id' => 1, 'guesty_owner_id' => 'GO1', 'month' => '2025-02', 'active' => 1],
            ['id' => 2, 'guesty_owner_id' => 'GO1', 'month' => '2025-03', 'active' => 0],
            ['id' => 3, 'guesty_owner_id' => 'GO1', 'month' => '2026-04', 'active' => 1],
            ['id' => 4, 'guesty_owner_id' => 'GO-GONE', 'month' => '2025-02', 'active' => 1],
        ];
    }

    /**
     * @param list<array<string, string|int|float|null>>|null $invoiceRows
     * @param list<array<string, string|int|float|null>>|null $documents
     */
    private function importWith(bool $apply, ?array $invoiceRows = null, ?array $documents = null): InvoiceImportReport
    {
        return $this->importer->import($invoiceRows ?? self::invoiceRows(), $documents ?? self::documents(), self::tasks(), self::noInvoice(), $this->directory, $apply);
    }

    private function assertNothingWritten(): void
    {
        self::assertSame([], $this->invoices->rows);
        self::assertSame([], $this->files->files);
        self::assertSame([], $this->extras->rows);
        self::assertSame([], $this->statements->rows);
    }

    public function testASimulationWritesNothingButCountsEverything(): void
    {
        $report = $this->importWith(false);

        self::assertSame([], $report->errors);
        self::assertFalse($report->applied);
        // 3 factures ; 6 lignes et 2 réservations (seule la n° 2 a un JSON) ; 4 PDF ; 2 prestations actives ; la prochaine facture portera le n° 4.
        self::assertSame([3, 6, 2, 4, 2, 4], [$report->invoices, $report->lines, $report->reservations, $report->files, $report->extras, $report->nextNumber]);
        self::assertSame(1, $report->nothingToInvoice, 'un seul mois « pas de facture » valable : 2025-02 (2025-03 inactif, 2026-04 déjà facturé, propriétaire inconnu)');
        $this->assertNothingWritten();
        self::assertSame(1, $this->invoices->nextNumber(), 'la séquence n\'a pas bougé');
    }

    public function testTheImportKeepsNumbersDatesTotalsPaymentCancellationAndFiles(): void
    {
        $report = $this->importWith(true);

        self::assertSame([], $report->errors);
        self::assertTrue($report->applied);
        $this->context->reset();
        $this->context->set('tenant-a');

        $all = array_reverse($this->invoices->all());
        self::assertSame([1, 2, 3], array_map(static fn($i): int => $i->number, $all));
        [$first, $second, $third] = $all;

        // N° 1 : sans JSON, payée par virement ; HT/TVA/TTC de la table arrondis au centime.
        self::assertSame([PaymentStatus::Paid, PaymentMethod::Wire, '2025-03-10', InvoiceSource::FacturizaImport, 1], [$first->paymentStatus, $first->paymentMethod, $first->paymentDate?->format('Y-m-d'), $first->source, $first->legacyId]);
        self::assertSame([null, '2025-02-28', '2025-03-28'], [$first->snapshot, $first->invoiceDate->format('Y-m-d'), $first->dueDate->format('Y-m-d')]);
        self::assertSame([100.0, 20.0, 120.0], [$first->amountHt, $first->vat, $first->amountTtc]);

        // N° 2 : JSON conservé tel quel, montants imprimés, date de facture du JSON, non payée (0000-00-00 → NULL).
        self::assertSame([PaymentStatus::Unpaid, null, null, '2026-05-04', 'Merci'], [$second->paymentStatus, $second->paymentMethod, $second->paymentDate, $second->invoiceDate->format('Y-m-d'), $second->note]);
        self::assertSame([233.34, 46.67, 280.0], [$second->amountHt, $second->vat, $second->amountTtc]);
        self::assertEquals(self::json(), json_decode((string) json_encode($second->snapshot), true));

        // N° 3 : annulée, ne bloque pas le mois de la n° 2.
        self::assertTrue($third->isCancelled());
        self::assertTrue($this->invoices->hasActive('owner-1', '2026-04'));

        // PDF : facture et récap pour la n° 2, facture seule pour les autres ; empreinte du contenu.
        self::assertCount(4, $this->files->files);
        self::assertNotNull($second->pdfPath);
        self::assertNotNull($second->recapPdfPath);
        self::assertSame(hash('sha256', '%PDF-1.4 b'), $second->pdfSha256);
        self::assertSame('%PDF-1.4 c', $this->files->files[$second->recapPdfPath]);
        self::assertNull($first->recapPdfPath);
        self::assertSame('2026', substr((string) $second->pdfPath, strlen('invoices/test/'), 4));

        // La séquence continue après la dernière facture reprise.
        self::assertSame(4, $this->invoices->nextNumber());
    }

    public function testLinesAndReservationActivityComeFromTheJsonAsPrinted(): void
    {
        $this->reservations->add(self::reservation('res-1', 'GR1'));
        $this->importWith(true);
        $this->context->reset();
        $this->context->set('tenant-a');

        $second = null;
        foreach ($this->invoices->all() as $invoice) {
            if ($invoice->number === 2) {
                $second = $invoice;
            }
        }
        self::assertNotNull($second);
        $key = 'tenant-a|' . $second->id;
        $lines = $this->invoices->lines[$key];

        // Réservation 1 : conciergerie, commission Abritel, linge (ménage à 0 € omis) ; réservation 2 : conciergerie, linge ; puis la prestation.
        self::assertSame(
            [[InvoiceLineKind::Reservation, ExtraType::Concierge, 60.0, 50.0], [InvoiceLineKind::PlatformCommission, ExtraType::Concierge, 36.0, 30.0], [InvoiceLineKind::Reservation, ExtraType::Linen, 17.0, 14.17], [InvoiceLineKind::Reservation, ExtraType::Concierge, 30.0, 25.0], [InvoiceLineKind::Reservation, ExtraType::Linen, 17.0, 14.17], [InvoiceLineKind::Extra, ExtraType::Linen, 17.0, 14.17]],
            array_map(static fn($l): array => [$l->kind, $l->category, $l->priceTtc, $l->priceHt], $lines),
        );
        self::assertSame(range(1, 6), array_map(static fn($l): int => $l->position, $lines));
        self::assertSame(['prop-1', 'res-1'], [$lines[0]->propertyId, $lines[0]->reservationId]);
        self::assertNull($lines[5]->propertyId);
        self::assertSame('Linge le 19/04', $lines[5]->label);

        // La ligne de prestation pointe vers la prestation reprise.
        $linen = array_values(array_filter($this->extras->rows, static fn($e): bool => $e->description === 'Linge le 19/04'));
        self::assertCount(1, $linen);
        self::assertSame($linen[0]->id, $lines[5]->extraId);

        $activity = $this->invoices->reservations[$key];
        self::assertSame([Platform::Abritel, Platform::Booking], array_map(static fn($r): Platform => $r->platform, $activity));
        self::assertSame(['res-1', null], array_map(static fn($r): ?string => $r->reservationId, $activity), 'la réservation disparue de Casamiza n\'a pas de lien');
        self::assertSame(['prop-1', 3, 500.0, 36.0], [$activity[0]->propertyId, $activity[0]->nights, $activity[0]->totalPayout, $activity[0]->channelCommission]);
        self::assertSame('2026-04-02 14:00', $activity[0]->checkIn->format('Y-m-d H:i'));
    }

    public function testExtrasAndNothingToInvoiceAreImportedOnceWithoutTheRemovedOnes(): void
    {
        $report = $this->importWith(true);

        self::assertSame([2, 1], [$report->extras, $report->nothingToInvoice]);
        $this->context->reset();
        $this->context->set('tenant-a');
        $extras = array_values($this->extras->rows);
        usort($extras, static fn($a, $b): int => $a->month <=> $b->month);
        self::assertSame(['2026-04', '2026-06'], array_map(static fn($e): string => $e->month, $extras));
        self::assertSame([ExtraType::Linen, 17.0, 20.0], [$extras[0]->type, $extras[0]->priceTtc, $extras[0]->vatRate]);
        self::assertEqualsWithDelta(14.166667, $extras[0]->priceHt, 0.000001);
        // Sans TVA : HT = TTC (taux déduit des deux montants).
        self::assertSame([ExtraType::Concierge, 100.0, 0.0, 100.0], [$extras[1]->type, $extras[1]->priceTtc, $extras[1]->vatRate, $extras[1]->priceHt]);

        $statement = $this->statements->find('owner-1', '2025-02');
        self::assertTrue($statement?->nothingToInvoice);
        self::assertNull($this->statements->find('owner-1', '2025-03'), 'inactif');
        self::assertNull($this->statements->find('owner-1', '2026-04'), 'ce mois est facturé');
    }

    public function testRunningTheImportAgainChangesNothing(): void
    {
        $this->importWith(true);
        $again = $this->importWith(true);

        self::assertSame([], $again->errors);
        self::assertSame([0, 3, 0, 2, 0], [$again->invoices, $again->invoicesAlreadyImported, $again->extras, $again->extrasAlreadyImported, $again->files]);
        $this->context->reset();
        $this->context->set('tenant-a');
        self::assertCount(3, $this->invoices->all());
        self::assertCount(2, $this->extras->rows);
        self::assertCount(4, $this->files->files);
    }

    public function testAFailedVerificationStopsEverythingEvenWithApply(): void
    {
        $missingFile = self::documents();
        $missingFile[0]['filename'] = 'absent.pdf';
        $gap = self::invoiceRows();
        $gap[2]['id'] = 5;
        $twoActive = self::invoiceRows();
        $twoActive[2]['cancelled'] = 0;
        $unknownOwner = self::invoiceRows();
        $unknownOwner[0]['guesty_owner_id'] = 'GO-GONE';
        $unbalanced = self::invoiceRows();
        $unbalanced[0]['total_vat'] = 25.0;
        $unknownMethod = self::invoiceRows();
        $unknownMethod[0]['paiement_type'] = 'cheque';
        $brokenJson = self::invoiceRows();
        $brokenJson[1]['json_invoice'] = '{pas du json';

        $cases = [
            'PDF absent' => [self::invoiceRows(), $missingFile, 'PDF'],
            'numéros non contigus' => [$gap, null, 'non contigus'],
            'deux factures actives' => [$twoActive, null, 'deux factures non annulées'],
            'propriétaire inconnu' => [$unknownOwner, null, 'propriétaire Guesty GO-GONE introuvable'],
            'HT + TVA ≠ TTC' => [$unbalanced, null, '≠ TTC'],
            'mode de paiement inconnu' => [$unknownMethod, null, 'mode de paiement'],
            'JSON illisible' => [$brokenJson, null, 'JSON illisible'],
        ];
        foreach ($cases as $name => [$rows, $documents, $expected]) {
            $report = $this->importWith(true, $rows, $documents);
            self::assertNotSame([], $report->errors, $name);
            self::assertStringContainsString($expected, implode(' | ', $report->errors), $name);
            self::assertFalse($report->applied, $name);
            $this->assertNothingWritten();
        }
        self::assertSame(1, $this->invoices->nextNumber(), 'la séquence n\'a pas bougé');
    }

    public function testAnUnknownListingStopsTheImport(): void
    {
        $this->properties->rows = [];
        $report = $this->importWith(true);

        self::assertStringContainsString('logement Guesty L1 introuvable', implode(' | ', $report->errors));
        $this->assertNothingWritten();
    }

    public function testATenantThatAlreadyIssuedInvoicesRefusesTheHistory(): void
    {
        $this->invoices->nextNumber();
        $this->invoices->add(new Invoice('own', 1, 'owner-1', '2026-09', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'), new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-11-01'), 1.0, 0.2, 1.2), [], []);

        $report = $this->importWith(true);

        self::assertStringContainsString('a déjà émis des factures', implode(' | ', $report->errors));
        self::assertCount(1, $this->invoices->all());
    }

    public function testAnInterruptedWriteLeavesNoFileBehind(): void
    {
        $this->files->failAt = 3;

        try {
            $this->importWith(true);
            self::fail('Une exception était attendue.');
        } catch (RuntimeException $e) {
            self::assertSame('Disk full.', $e->getMessage());
        }

        self::assertSame([], $this->files->files, 'les PDF déjà copiés sont supprimés');
    }

    /** @param non-empty-string $id */
    private static function reservation(string $id, string $guestyId): Reservation
    {
        return Reservation::fromGuesty($id, new GuestyReservation($guestyId, 'L1', 'abritel', [], '', 'Voyageur', new DateTimeImmutable('2026-04-02 14:00:00 UTC'), new DateTimeImmutable('2026-04-05 08:00:00 UTC'), 3, 2, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, [], []), 'owner-1', 'prop-1');
    }
}

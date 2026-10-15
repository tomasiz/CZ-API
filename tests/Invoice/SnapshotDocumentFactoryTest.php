<?php

declare(strict_types=1);

namespace Casamiza\Tests\Invoice;

use Casamiza\Domain\Invoice\Document\InvoiceHeadingRow;
use Casamiza\Domain\Invoice\Document\InvoiceItemRow;
use Casamiza\Domain\Invoice\Document\SnapshotDocumentFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Passage de l'instantané légal d'une facture aux documents imprimés. */
final class SnapshotDocumentFactoryTest extends TestCase
{
    /**
     * @param array<string, mixed> $details
     * @param array<string, mixed> $billing
     * @return array<string, mixed>
     */
    private static function reservation(string $checkIn, string $checkOut, array $details = [], array $billing = []): array
    {
        return [
            'reservation_details' => $details + [
                'channel' => 'AirBnB',
                'ref_id' => 'HM53WF5YNK',
                'checkin' => $checkIn,
                'checkout' => $checkOut,
                'guest_fullname' => 'Bettina Paul',
                'guests_count' => 5,
                'nights_count' => 6,
                'total_payout' => 797.21,
                'channel_comission' => 131.52,
                'paiement_fee' => 10.83,
                'taxes' => 23.58000000000004,
                'net_payout' => 631.28,
                'cleaning_fee' => 59,
                'conciergerie_fee' => 125.9,
                'net_owner_revenue' => 446.38,
            ],
            'facturation_details' => $billing + [
                'frais_conciergerie' => ['Montant_HT' => 104.92, 'TVA' => 20, 'Montant_TTC' => 125.9],
                'frais_linge' => ['Montant_HT' => 14.17, 'TVA' => 20, 'Montant_TTC' => 17],
                'frais_menage' => ['Montant_HT' => 35, 'TVA' => 20, 'Montant_TTC' => 42],
                'frais_comission_plateforme' => [],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $listings
     * @param array<string, mixed> $extras
     * @return array<string, mixed>
     */
    private static function snapshot(array $listings, array $extras = [], float $ht = 100.0, float $vat = 20.0, float $ttc = 120.0): array
    {
        return [
            'facture_information' => [
                'numero_facture' => 'FZ0000650',
                'date_facture' => '2026-09-08',
                'periode_start' => '2026-08-01',
                'periode_end' => '2026-08-31',
                'note' => 'Merci',
                'client' => ['client_name' => 'P - Julien Djadjoua', 'client_siret' => null, 'client_legal_name' => 'SCI Les Pins', 'client_address' => 'Porticcio'],
            ],
            'facturation_par_listing' => $listings,
            'facturation_supplementaire' => $extras + ['conciergerie' => [], 'linge' => [], 'menage' => []],
            'facture_totaux' => ['total_ht' => $ht, 'total_tva' => $vat, 'total_ttc' => $ttc],
        ];
    }

    private static function due(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-08');
    }

    public function testTheHeaderComesFromTheSnapshotWithoutTheGuestyPrefixAndTheTotalsAreTheLegalOnes(): void
    {
        $document = new SnapshotDocumentFactory()->invoice(self::snapshot([]), self::due());

        self::assertSame('FZ0000650', $document->number);
        self::assertSame(['2026-09-08', '2026-10-08', '2026-08-01', '2026-08-31'], [$document->date->format('Y-m-d'), $document->dueDate->format('Y-m-d'), $document->periodStart->format('Y-m-d'), $document->periodEnd->format('Y-m-d')]);
        self::assertSame('Merci', $document->note);
        self::assertSame(['Julien Djadjoua', 'SCI Les Pins', '', 'Porticcio'], [$document->recipient->name, $document->recipient->legalName, $document->recipient->siret, $document->recipient->address]);
        self::assertSame([100.0, 20.0, 120.0], [$document->totalHt, $document->totalVat, $document->totalTtc]);
        self::assertSame(['Frais de conciergerie', 'Frais de linge', 'Frais de ménage'], array_map(static fn($section): string => $section->title, $document->sections));
        self::assertSame([[], [], []], array_map(static fn($section): array => $section->rows, $document->sections));
        self::assertCount(1, $document->vat);
        self::assertSame(['Normale', 100.0, 20.0, 20.0], [$document->vat[0]->code, $document->vat[0]->baseHt, $document->vat[0]->rate, $document->vat[0]->vat]);
    }

    public function testStaysAreListedByDatesInParisAndCostsAreGroupedPerProperty(): void
    {
        // 22:00 UTC en été = minuit à Paris : le séjour se termine le lendemain.
        $stay = self::reservation('2026-07-25T14:00:00.000Z', '2026-08-01T08:00:00.000Z');
        $late = self::reservation('2026-08-01T14:00:00.000Z', '2026-08-03T22:00:00.000Z');
        $document = new SnapshotDocumentFactory()->invoice(self::snapshot([['listing_nickname' => 'TB66', 'listing_reservations' => [$stay, $late]]]), self::due());

        [$concierge, $linen, $cleaning] = array_map(static fn($section): array => $section->rows, $document->sections);
        self::assertEquals(new InvoiceItemRow('Du 25/07/2026 au 01/08/2026', 1, 104.92, 20.0, 125.9, 125.9), $concierge[0]);
        self::assertSame('Du 01/08/2026 au 04/08/2026', $concierge[1] instanceof InvoiceItemRow ? $concierge[1]->label : null);
        self::assertCount(2, $concierge, 'Un seul logement : pas de titre de logement.');
        self::assertEquals([new InvoiceItemRow('TB66 - Prestation de linge', 2, 14.17, 20.0, 17.0, 34.0)], $linen);
        self::assertEquals([new InvoiceItemRow('TB66 - Prestation de ménage', 2, 35.0, 20.0, 42.0, 84.0)], $cleaning);
    }

    public function testSeveralPropertiesGetATitleOnlyWhenTheyHaveStaysAndAbritelAddsItsCommission(): void
    {
        $abritel = self::reservation('2026-08-02T14:00:00.000Z', '2026-08-05T08:00:00.000Z', ['channel' => 'Abritel'], [
            'frais_comission_plateforme' => ['Montant_HT' => 50.0, 'TVA' => 20, 'Montant_TTC' => 60.0],
        ]);
        $document = new SnapshotDocumentFactory()->invoice(self::snapshot([
            ['listing_nickname' => 'Villa', 'listing_reservations' => [$abritel]],
            ['listing_nickname' => 'Studio', 'listing_reservations' => []],
        ]), self::due());

        $concierge = $document->sections[0]->rows;
        self::assertEquals(new InvoiceHeadingRow('Villa'), $concierge[0]);
        self::assertCount(3, $concierge, 'Titre, séjour, commission Abritel ; rien pour le logement sans séjour.');
        self::assertEquals(new InvoiceItemRow('Commission Abritel (18%)', 1, 50.0, 20.0, 60.0, 60.0), $concierge[2]);
        self::assertCount(1, $document->sections[1]->rows, 'Pas de ligne de linge pour un logement sans séjour.');
    }

    public function testExtrasFollowTheirCategoryAndMixedVatRatesAreDetailedWhileKeepingTheLegalTotals(): void
    {
        $document = new SnapshotDocumentFactory()->invoice(self::snapshot(
            [['listing_nickname' => 'Villa', 'listing_reservations' => [self::reservation('2026-08-02T14:00:00.000Z', '2026-08-05T08:00:00.000Z')]]],
            [
                'conciergerie' => [['description' => 'Remise des clés', 'Montant_HT' => 25.0, 'TVA' => 20, 'Montant_TTC' => 30.0]],
                'menage' => [['description' => 'Ménage de fin de séjour', 'Montant_HT' => 100.0, 'TVA' => 0, 'Montant_TTC' => 100.0]],
            ],
            ht: 276.09,
            vat: 25.0,
            ttc: 301.09,
        ), self::due());

        $concierge = $document->sections[0]->rows;
        self::assertEquals(new InvoiceHeadingRow('Prestations supplémentaires'), $concierge[1]);
        self::assertEquals(new InvoiceItemRow('Remise des clés', 1, 25.0, 20.0, 30.0, 30.0), $concierge[2]);
        $cleaning = $document->sections[2]->rows;
        self::assertEquals(new InvoiceItemRow('Ménage de fin de séjour', 1, 100.0, 0.0, 100.0, 100.0), $cleaning[1]);

        // 20 % : 125,90 + 17 + 42 + 30 = 214,90 TTC ; 0 % : 100 TTC. Σ HT = 276,09 et Σ TVA = 25 comme dans les totaux légaux.
        self::assertCount(2, $document->vat);
        self::assertSame(['Normale', 'Exonérée'], array_map(static fn($line): string => $line->code, $document->vat));
        self::assertSame(276.09, round(array_sum(array_map(static fn($line): float => $line->baseHt, $document->vat)), 2));
        self::assertSame(25.0, round(array_sum(array_map(static fn($line): float => $line->vat, $document->vat)), 2));
        self::assertSame([0.0, 100.0], [$document->vat[1]->vat, $document->vat[1]->baseHt]);
    }

    public function testTheRecapHasOneCardPerStayWithPluralsRetrocommissionAndParisDates(): void
    {
        $classic = self::reservation('2026-07-25T14:00:00.000Z', '2026-08-01T08:00:00.000Z', ['guests_count' => 1, 'nights_count' => 1, 'ref_id' => null]);
        $manual = self::reservation('2026-08-02T14:00:00.000Z', '2026-08-04T22:00:00.000Z', ['channel' => 'Manuel']);
        $recap = new SnapshotDocumentFactory()->recap(self::snapshot([['listing_nickname' => 'TB66', 'listing_reservations' => [$classic, $manual]]]));

        self::assertSame('Julien Djadjoua', $recap->recipient->name);
        self::assertCount(2, $recap->cards);
        self::assertSame('TB66 - Réservation du 25/07/2026 au 01/08/2026', $recap->cards[0]->title);
        self::assertSame('Réf.  AirBnB - Bettina Paul - 1 voyageur - 1 nuit', $recap->cards[0]->subtitle);
        self::assertFalse($recap->cards[0]->retrocommission);
        self::assertSame('TB66 - Réservation du 02/08/2026 au 05/08/2026', $recap->cards[1]->title);
        self::assertSame('Réf. HM53WF5YNK Manuel - Bettina Paul - 5 voyageurs - 6 nuits', $recap->cards[1]->subtitle);
        self::assertTrue($recap->cards[1]->retrocommission);
        self::assertSame([797.21, 131.52, 10.83, 446.38], [$recap->cards[0]->totalPaid, $recap->cards[0]->channelCommission, $recap->cards[0]->paymentFee, $recap->cards[0]->netOwnerRevenue]);
    }
}

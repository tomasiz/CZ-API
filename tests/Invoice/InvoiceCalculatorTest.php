<?php

declare(strict_types=1);

namespace Casamiza\Tests\Invoice;

use Casamiza\Domain\Extra\Extra;
use Casamiza\Domain\Extra\ExtraType;
use Casamiza\Domain\Invoice\DraftLine;
use Casamiza\Domain\Invoice\InvoiceCalculator;
use Casamiza\Domain\Invoice\InvoiceDraft;
use Casamiza\Domain\Invoice\InvoiceLineKind;
use Casamiza\Domain\Invoice\PropertyBilling;
use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Reservation\Platform;
use Casamiza\Domain\Reservation\Reservation;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calcul d'une facture : lignes, répartition des centimes et totaux. Les cas réels (`golden-invoices.json`) sont des factures
 * de Facturiza (n° 376 et suivants, modèle actuel : ménage à 20 %) réduites à leurs montants : ni propriétaire, ni voyageur,
 * ni identifiant. Les totaux HT et TTC doivent être **identiques** à ceux imprimés. La TVA imprimée par Facturiza peut être
 * 1 centime au-dessus de la nôtre quand le HT tombe sur une demi-centime (il arrondit le HT et la TVA chacun de son côté,
 * son HT + sa TVA dépasse alors le TTC) : ici TVA = TTC − HT, la facture s'additionne. La facture n° 685 est écartée : son HT
 * tombe sur une demi-centime et Facturiza l'arrondit à l'inférieur à cause du flottant.
 */
final class InvoiceCalculatorTest extends TestCase
{
    private static int $sequence = 0;

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function realInvoices(): iterable
    {
        /** @var list<array<string, mixed>> $cases */
        $cases = json_decode((string) file_get_contents(__DIR__ . '/golden-invoices.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            yield 'facture ' . $case['number'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('realInvoices')]
    public function testMatchesTheTotalsPrintedByFacturiza(array $case): void
    {
        /** @var list<array{linen: float, cleaning: float, reservations: list<array{abritel: bool, conciergeFee: float, channelCommission: float}>}> $properties */
        $properties = $case['properties'];
        /** @var list<array{type: string, ttc: float, vatRate: float}> $extras */
        $extras = $case['extras'];
        /** @var array{ht: float, vat: float, ttc: float} $expected */
        $expected = $case['expected'];

        $billings = [];
        foreach ($properties as $index => $property) {
            $reservations = [];
            foreach ($property['reservations'] as $reservation) {
                $reservations[] = self::reservation("p$index", $reservation['conciergeFee'], $reservation['abritel'] ? Platform::Abritel : Platform::Booking, $reservation['channelCommission']);
            }
            $billings[] = new PropertyBilling(self::property("p$index", $property['linen'], $property['cleaning']), $reservations);
        }
        $prestations = [];
        foreach ($extras as $index => $extra) {
            $prestations[] = Extra::priced("e$index", 'owner', '2026-01', ExtraType::from($extra['type']), 'Prestation', $extra['ttc'], $extra['vatRate']);
        }

        $draft = (new InvoiceCalculator())->build($billings, $prestations);

        self::assertEqualsWithDelta($expected['ht'], $draft->totals->ht, 0.0001);
        self::assertEqualsWithDelta($expected['ttc'], $draft->totals->ttc, 0.0001);
        self::assertEqualsWithDelta($draft->totals->ttc - $draft->totals->ht, $draft->totals->vat, 0.0001);
        self::assertEqualsWithDelta($expected['vat'], $draft->totals->vat, 0.0101);
        self::assertAddsUp($draft);
    }

    public function testInvoice427SpreadsTheMissingCentsOnTheFirstLinesWithTheLargestRemainder(): void
    {
        $draft = (new InvoiceCalculator())->build(
            [new PropertyBilling(self::property('p', 17, 48), [
                self::reservation('p', 27.86, Platform::Airbnb),
                self::reservation('p', 77.72, Platform::Booking),
            ])],
            [
                Extra::priced('e1', 'owner', '2026-04', ExtraType::Linen, 'Linge le 19/04', 17, 20),
                Extra::priced('e2', 'owner', '2026-04', ExtraType::Cleaning, 'Ménage le 19/04', 48, 20),
            ],
        );

        // HT exacts : 23,2167 · 14,1667 · 40 · 64,7667 · 14,1667 · 40 · 14,1667 · 40 → 250,48 au total, soit 3 centimes à répartir.
        self::assertSame([23.22, 14.17, 40.0, 64.77, 14.16, 40.0, 14.16, 40.0], array_map(static fn(DraftLine $l): float => $l->priceHt, $draft->lines));
        self::assertSame([27.86, 17.0, 48.0, 77.72, 17.0, 48.0, 17.0, 48.0], array_map(static fn(DraftLine $l): float => $l->priceTtc, $draft->lines));
        self::assertSame([250.48, 50.1, 300.58], [$draft->totals->ht, $draft->totals->vat, $draft->totals->ttc]);
        self::assertSame(['reservation', 'reservation', 'reservation', 'reservation', 'reservation', 'reservation', 'extra', 'extra'], array_map(static fn(DraftLine $l): string => $l->kind->value, $draft->lines));
        self::assertSame(['concierge', 'linen', 'cleaning', 'concierge', 'linen', 'cleaning', 'linen', 'cleaning'], array_map(static fn(DraftLine $l): string => $l->category->value, $draft->lines));
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_map(static fn(DraftLine $l): int => $l->position, $draft->lines));
        self::assertSame('Linge le 19/04', $draft->lines[6]->label);
        self::assertSame('e1', $draft->lines[6]->extraId);
        self::assertNull($draft->lines[6]->propertyId, 'une prestation appartient au propriétaire, pas à un logement');
        self::assertCount(2, $draft->reservations);
    }

    public function testAnAbritelReservationAddsItsCommissionToTheConciergeLines(): void
    {
        $draft = (new InvoiceCalculator())->build(
            [new PropertyBilling(self::property('p', 17, 48), [self::reservation('p', 60.0, Platform::Abritel, 36.0)])],
            [],
        );

        self::assertSame(
            [[InvoiceLineKind::Reservation, ExtraType::Concierge, 60.0], [InvoiceLineKind::PlatformCommission, ExtraType::Concierge, 36.0], [InvoiceLineKind::Reservation, ExtraType::Linen, 17.0], [InvoiceLineKind::Reservation, ExtraType::Cleaning, 48.0]],
            array_map(static fn(DraftLine $l): array => [$l->kind, $l->category, $l->priceTtc], $draft->lines),
        );
        self::assertSame(161.0, $draft->totals->ttc);
        self::assertAddsUp($draft);
    }

    public function testAPropertyWithoutLinenOrCleaningFeesProducesNoSuchLines(): void
    {
        $draft = (new InvoiceCalculator())->build(
            [new PropertyBilling(self::property('p', 0, 30), [self::reservation('p', 10.0, Platform::Booking), self::reservation('p', 0.0, Platform::Booking)])],
            [],
        );

        // Les frais de conciergerie à 0 € restent (la réservation figure sur la facture) ; le linge à 0 € disparaît.
        self::assertSame(
            [ExtraType::Concierge, ExtraType::Cleaning, ExtraType::Concierge, ExtraType::Cleaning],
            array_map(static fn(DraftLine $l): ExtraType => $l->category, $draft->lines),
        );
        self::assertSame(70.0, $draft->totals->ttc);
        self::assertSame(58.33, $draft->totals->ht);
        self::assertCount(2, $draft->reservations);
    }

    public function testAnExtraWithoutVatAndACreditNoteAreHandledPerRate(): void
    {
        $draft = (new InvoiceCalculator())->build([], [
            Extra::priced('e1', 'owner', '2026-04', ExtraType::Cleaning, 'Débours', 100.0, 0),
            Extra::priced('e2', 'owner', '2026-04', ExtraType::Concierge, 'Avoir', -70.4, 20),
            Extra::priced('e3', 'owner', '2026-04', ExtraType::Concierge, 'Frais', 48.0, 20),
        ]);

        // Ordre : conciergerie (Avoir, Frais), linge, ménage (Débours).
        self::assertSame(['Avoir', 'Frais', 'Débours'], array_map(static fn(DraftLine $l): string => $l->label, $draft->lines));
        self::assertSame([-58.67, 40.0, 100.0], array_map(static fn(DraftLine $l): float => $l->priceHt, $draft->lines));
        // 20 % : −70,40 + 48,00 = −22,40 TTC → −18,67 HT, soit −3,73 de TVA ; sans TVA : 100,00.
        self::assertSame([81.33, -3.73, 77.6], [$draft->totals->ht, $draft->totals->vat, $draft->totals->ttc]);
        self::assertAddsUp($draft);
    }

    public function testAnEmptyInvoiceIsZero(): void
    {
        $draft = (new InvoiceCalculator())->build([], []);

        self::assertSame([], $draft->lines);
        self::assertSame([0.0, 0.0, 0.0], [$draft->totals->ht, $draft->totals->vat, $draft->totals->ttc]);
    }

    public function testRandomInvoicesAlwaysAddUp(): void
    {
        mt_srand(20261015);
        for ($run = 0; $run < 1500; $run++) {
            $properties = [];
            for ($p = 0, $count = mt_rand(0, 3); $p < $count; $p++) {
                $reservations = [];
                for ($r = 0, $n = mt_rand(0, 6); $r < $n; $r++) {
                    $reservations[] = self::reservation("p$p", mt_rand(0, 40_000) / 100, mt_rand(0, 4) === 0 ? Platform::Abritel : Platform::Booking, mt_rand(0, 20_000) / 100);
                }
                $properties[] = new PropertyBilling(self::property("p$p", mt_rand(0, 5_000) / 100, mt_rand(0, 15_000) / 100), $reservations);
            }
            $extras = [];
            for ($e = 0, $n = mt_rand(0, 5); $e < $n; $e++) {
                $extras[] = Extra::priced("e$e", 'owner', '2026-01', ExtraType::cases()[mt_rand(0, 2)], 'x', mt_rand(-20_000, 30_000) / 100, [0.0, 5.5, 10.0, 20.0][mt_rand(0, 3)]);
            }

            self::assertAddsUp((new InvoiceCalculator())->build($properties, $extras));
        }
    }

    /** HT + TVA = TTC, la somme des lignes est le total, et chaque HT est à moins d'un centime de sa valeur exacte. */
    private static function assertAddsUp(InvoiceDraft $draft): void
    {
        $ht = 0;
        $ttc = 0;
        foreach ($draft->lines as $line) {
            $ht += (int) round($line->priceHt * 100);
            $ttc += (int) round($line->priceTtc * 100);
            $exact = $line->priceTtc / (1 + $line->vatRate / 100);
            self::assertLessThanOrEqual(0.0100001, abs($line->priceHt - $exact), 'HT de la ligne ' . $line->position);
        }
        self::assertSame((int) round($draft->totals->ht * 100), $ht, 'Σ HT des lignes = HT du total');
        self::assertSame((int) round($draft->totals->ttc * 100), $ttc, 'Σ TTC des lignes = TTC du total');
        self::assertSame((int) round($draft->totals->ttc * 100), (int) round(($draft->totals->ht + $draft->totals->vat) * 100), 'HT + TVA = TTC');
    }

    /** @param non-empty-string $id */
    private static function property(string $id, float $linen, float $cleaning): Property
    {
        return new Property($id, 'guesty-' . $id, 'owner', 'Logement ' . $id, '', $cleaning, $linen, null);
    }

    private static function reservation(string $propertyId, float $conciergeFee, Platform $platform, float $channelCommission = 0.0): Reservation
    {
        $number = ++self::$sequence;

        return new Reservation(
            id: 'reservation-' . $number,
            guestyId: 'guesty-' . $number,
            ownerId: 'owner',
            propertyId: $propertyId,
            platform: $platform,
            source: '',
            integrationKeys: [],
            platformRef: '',
            guest: '',
            checkIn: new DateTimeImmutable('2026-01-10 14:00:00 UTC'),
            checkOut: new DateTimeImmutable('2026-01-14 08:00:00 UTC'),
            nights: 4,
            guests: 2,
            hostPayout: 0.0,
            hostServiceFee: 0.0,
            totalPaymentFees: 0.0,
            totalTaxes: 0.0,
            fareCleaning: 0.0,
            totalFees: 0.0,
            transientOccupancyTax: 0.0,
            fareAccommodationAdjusted: 0.0,
            payments: [],
            confirmationTaxes: [],
            totalPayout: 0.0,
            channelCommission: $channelCommission,
            paymentFee: 0.0,
            touristTax: 0.0,
            cleaningFee: 0.0,
            netPayout: 0.0,
            conciergeFee: $conciergeFee,
            netOwnerRevenue: 0.0,
        );
    }
}

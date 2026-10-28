<?php

declare(strict_types=1);

namespace Casamiza\Tests\Reservation;

use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Reservation\CalculatedAmounts;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Domain\Reservation\ReservationCalculator;
use Casamiza\Domain\Reservation\StripeFees;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Cas calculés à la main d'après les règles de Facturiza (FACTURIZA.md §4.5 à §4.8) : un cas par branche. */
final class ReservationCalculatorTest extends TestCase
{
    private ReservationCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ReservationCalculator();
    }

    /**
     * @param array<string, mixed> $values champs de GuestyReservation à remplacer
     */
    private static function reservation(string $source, array $values = []): Reservation
    {
        $guesty = new GuestyReservation(...($values + [
            'guestyId' => 'R1',
            'listingGuestyId' => 'L1',
            'source' => $source,
            'integrationKeys' => [],
            'platformRef' => '',
            'guest' => 'Jean Dupont',
            'checkIn' => new DateTimeImmutable('2026-09-10 14:00:00 UTC'),
            'checkOut' => new DateTimeImmutable('2026-09-14 08:00:00 UTC'),
            'nights' => 4,
            'guests' => 2,
            'hostPayout' => 1000.0,
            'hostServiceFee' => 150.0,
            'totalPaymentFees' => 14.0,
            'totalTaxes' => 0.0,
            'fareCleaning' => 80.0,
            'totalFees' => 80.0,
            'transientOccupancyTax' => 0.0,
            'fareAccommodationAdjusted' => 600.0,
            'payments' => [],
            'confirmationTaxes' => [['type' => 'CITY_TAX', 'units' => 'PERCENTAGE', 'quantifier' => 'PER_STAY', 'amount' => 5.5]],
        ]));

        return Reservation::fromGuesty('res-1', $guesty, 'owner-1', 'prop-1');
    }

    /** @return array<string, string> motif de chaque champ signalé */
    private static function flags(CalculatedAmounts $amounts): array
    {
        $flags = [];
        foreach ($amounts->flags as $flag) {
            $flags[$flag->field->value] = $flag->reason->value;
        }
        ksort($flags);

        return $flags;
    }

    /** @param array{total: float, commission: float, fee: float, tax: float, cleaning: float, net: float, concierge: float, revenue: float} $expected */
    private static function assertAmounts(array $expected, CalculatedAmounts $actual): void
    {
        self::assertEqualsWithDelta($expected, [
            'total' => $actual->totalPayout,
            'commission' => $actual->channelCommission,
            'fee' => $actual->paymentFee,
            'tax' => $actual->touristTax,
            'cleaning' => $actual->cleaningFee,
            'net' => $actual->netPayout,
            'concierge' => $actual->conciergeFee,
            'revenue' => $actual->netOwnerRevenue,
        ], 0.0001);
    }

    public function testAirbnbAddsTheCityTaxToTheTotalAndHasNoPaymentFees(): void
    {
        $result = $this->calculator->calculate(
            self::reservation('airbnb2', ['hostPayout' => 500.5, 'hostServiceFee' => 90.0, 'totalPaymentFees' => 0.0, 'transientOccupancyTax' => 12.0]),
            20.0,
            80.0,
        );

        self::assertAmounts(['total' => 602.5, 'commission' => 90.0, 'fee' => 0.0, 'tax' => 12.0, 'cleaning' => 80.0, 'net' => 500.5, 'concierge' => 84.1, 'revenue' => 336.4], $result);
        self::assertSame([], self::flags($result));
    }

    public function testAirbnbPaymentFeesAreForcedToZero(): void
    {
        $result = $this->calculator->calculate(self::reservation('airbnb2', ['hostPayout' => 500.5, 'hostServiceFee' => 90.0, 'totalPaymentFees' => 7.0, 'transientOccupancyTax' => 12.0]), 20.0, 80.0);

        self::assertSame(0.0, $result->paymentFee);
    }

    public function testAirbnbIncoherentCityTaxIsRecomputedAsAPercentageOfTheFare(): void
    {
        $result = $this->calculator->calculate(
            self::reservation('airbnb2', ['hostPayout' => 500.5, 'hostServiceFee' => 90.0, 'totalPaymentFees' => 0.0, 'transientOccupancyTax' => 100.0]),
            20.0,
            80.0,
        );

        // 100 ≥ 7 % de 590,5 : taxe recalculée, 5,5 % de 600 = 33.
        self::assertSame(33.0, $result->touristTax);
        self::assertSame(623.5, $result->totalPayout);
        self::assertSame(['tourist_tax' => 'tax_computed_percent'], self::flags($result));
    }

    public function testAirbnbIncoherentCityTaxCanBeRecomputedPerGuestAndNight(): void
    {
        $result = $this->calculator->calculate(
            self::reservation('airbnb2', [
                'hostPayout' => 500.5,
                'hostServiceFee' => 90.0,
                'totalPaymentFees' => 0.0,
                'transientOccupancyTax' => 100.0,
                'confirmationTaxes' => [['type' => 'VAT', 'amount' => 10], ['type' => 'CITY_TAX', 'units' => 'FIXED', 'quantifier' => 'PER_GUEST_PER_NIGHT', 'amount' => 2]],
            ]),
            20.0,
            80.0,
        );

        // 2 € × 2 voyageurs × 4 nuits.
        self::assertSame(16.0, $result->touristTax);
        self::assertSame(['tourist_tax' => 'tax_computed_per_guest_night'], self::flags($result));
    }

    public function testBookingTakesTheGapWithThePaymentsAsCityTax(): void
    {
        $result = $this->calculator->calculate(
            self::reservation('Booking.com', ['payments' => [['amount' => 1030, 'status' => 'SUCCEEDED'], ['amount' => 500, 'status' => 'FAILED']]]),
            20.0,
            80.0,
        );

        self::assertAmounts(['total' => 1030.0, 'commission' => 150.0, 'fee' => 14.0, 'tax' => 30.0, 'cleaning' => 80.0, 'net' => 836.0, 'concierge' => 151.2, 'revenue' => 604.8], $result);
        self::assertSame([], self::flags($result));
    }

    public function testBookingRecomputesTheTaxWhenTheGapIsAtLeastTenPercent(): void
    {
        $result = $this->calculator->calculate(self::reservation('Booking.com', ['payments' => [['amount' => 1200, 'status' => 'SUCCEEDED']]]), 20.0, 80.0);

        self::assertAmounts(['total' => 1033.0, 'commission' => 150.0, 'fee' => 14.0, 'tax' => 33.0, 'cleaning' => 80.0, 'net' => 836.0, 'concierge' => 151.2, 'revenue' => 604.8], $result);
        self::assertSame(['tourist_tax' => 'tax_computed_percent'], self::flags($result));
    }

    public function testBookingWithoutPaymentsRecomputesTheTaxAndEstimatesTheFeesAtOnePointFourPercent(): void
    {
        $result = $this->calculator->calculate(self::reservation('Booking.com', ['totalPaymentFees' => 0.0]), 20.0, 80.0);

        self::assertSame(1033.0, $result->totalPayout);
        self::assertSame(33.0, $result->touristTax);
        self::assertSame(14.46, $result->paymentFee);
        self::assertSame(['payment_fee' => 'payment_fee_computed_1_4_percent', 'tourist_tax' => 'tax_computed_percent'], self::flags($result));
    }

    public function testBookingWithPaymentsNotAbovePayoutIsLeftAsIs(): void
    {
        $result = $this->calculator->calculate(self::reservation('Booking.com', ['payments' => [['amount' => 1000, 'status' => 'SUCCEEDED']]]), 20.0, 80.0);

        self::assertSame(1000.0, $result->totalPayout);
        self::assertSame(0.0, $result->touristTax);
        self::assertSame(['tourist_tax' => 'empty_or_zero_from_guesty'], self::flags($result));
    }

    public function testBookingWithATaxMaySeemToIncludeVat(): void
    {
        $result = $this->calculator->calculate(self::reservation('Booking.com', ['totalTaxes' => 20.0]), 20.0, 80.0);

        self::assertSame(816.0, $result->netPayout);
        self::assertSame(['tourist_tax' => 'possible_vat'], self::flags($result));
    }

    public function testBookingWithoutCommissionIsFlagged(): void
    {
        $result = $this->calculator->calculate(self::reservation('Booking.com', ['hostServiceFee' => 0.0, 'totalTaxes' => 20.0]), 20.0, 80.0);

        self::assertSame('empty_or_zero_from_guesty', self::flags($result)['channel_commission'] ?? null);
    }

    public function testAbritelWithoutCommissionUsesEighteenPercentAndTheRetrocommissionFormula(): void
    {
        $result = $this->calculator->calculate(self::reservation('HomeAway NL', ['hostServiceFee' => 0.0, 'totalTaxes' => 20.0]), 20.0, 80.0);

        self::assertAmounts(['total' => 1000.0, 'commission' => 176.4, 'fee' => 14.0, 'tax' => 20.0, 'cleaning' => 80.0, 'net' => 986.0, 'concierge' => 141.92, 'revenue' => 567.68], $result);
        self::assertSame([], self::flags($result));
    }

    public function testWebsiteUsesTheRetrocommissionFormulaAndNeedsNeitherCommissionNorPaymentFees(): void
    {
        $result = $this->calculator->calculate(self::reservation('website', ['hostServiceFee' => 0.0, 'totalPaymentFees' => 0.0]), 20.0, 80.0);

        self::assertAmounts(['total' => 1000.0, 'commission' => 0.0, 'fee' => 0.0, 'tax' => 0.0, 'cleaning' => 80.0, 'net' => 1000.0, 'concierge' => 184.0, 'revenue' => 736.0], $result);
        self::assertSame(['tourist_tax' => 'empty_or_zero_from_guesty'], self::flags($result));
    }

    public function testManualIsFlaggedWhenItHasNoPaymentFees(): void
    {
        $result = $this->calculator->calculate(self::reservation('manual', ['hostServiceFee' => 0.0, 'totalPaymentFees' => 0.0]), 20.0, 80.0);

        self::assertSame(['channel_commission' => 'empty_or_zero_from_guesty', 'payment_fee' => 'empty_or_zero_from_guesty', 'tourist_tax' => 'empty_or_zero_from_guesty'], self::flags($result));
    }

    public function testStripeFeesFillMissingPaymentFees(): void
    {
        $result = $this->calculator->calculate(self::reservation('manual', ['hostServiceFee' => 0.0, 'totalPaymentFees' => 0.0]), 20.0, 80.0, new StripeFees(25.5));

        self::assertSame(25.5, $result->paymentFee);
        self::assertArrayNotHasKey('payment_fee', self::flags($result));
    }

    public function testStripeFeesAreIgnoredWhenGuestyProvidesThem(): void
    {
        $result = $this->calculator->calculate(self::reservation('manual', ['hostServiceFee' => 0.0]), 20.0, 80.0, new StripeFees(25.5, 'stripe_error'));

        self::assertSame(14.0, $result->paymentFee);
        self::assertArrayNotHasKey('payment_fee', self::flags($result));
    }

    public function testAStripeErrorIsFlaggedWhereNoOtherFlagCoversThePaymentFee(): void
    {
        // Site internet : pas de signalement « frais manquants », celui de l'erreur Stripe subsiste.
        $website = $this->calculator->calculate(self::reservation('website', ['hostServiceFee' => 0.0, 'totalPaymentFees' => 0.0]), 20.0, 80.0, new StripeFees(0.0, 'card_error'));
        // Manuel : le signalement « frais manquants » le remplace (le dernier posé l'emporte).
        $manual = $this->calculator->calculate(self::reservation('manual', ['hostServiceFee' => 0.0, 'totalPaymentFees' => 0.0]), 20.0, 80.0, new StripeFees(0.0, 'card_error'));

        self::assertSame('stripe_error', self::flags($website)['payment_fee'] ?? null);
        self::assertSame('empty_or_zero_from_guesty', self::flags($manual)['payment_fee'] ?? null);
    }

    public function testCleaningFallsBackToTotalFeesWhenGuestyHasNoCleaningFare(): void
    {
        $result = $this->calculator->calculate(self::reservation('Booking.com', ['fareCleaning' => 0.0, 'totalFees' => 95.0, 'totalTaxes' => 20.0]), 20.0, 80.0);

        self::assertSame(95.0, $result->cleaningFee);
        self::assertSame('cleaning_fee_mismatch', self::flags($result)['cleaning_fee'] ?? null);
    }

    public function testCleaningBilledToTheGuestMustEqualThePropertyCleaningPlusLinen(): void
    {
        $matching = $this->calculator->calculate(self::reservation('Booking.com', ['fareCleaning' => 100.0, 'totalTaxes' => 20.0]), 20.0, 80.0, null, 20.0);
        $different = $this->calculator->calculate(self::reservation('Booking.com', ['fareCleaning' => 80.0, 'totalTaxes' => 20.0]), 20.0, 80.0, null, 20.0);
        $noFeeAtAll = $this->calculator->calculate(self::reservation('Booking.com', ['fareCleaning' => 0.0, 'totalFees' => 0.0, 'totalTaxes' => 20.0]), 20.0, 0.0, null, 0.0);

        self::assertArrayNotHasKey('cleaning_fee', self::flags($matching));
        self::assertSame('cleaning_fee_mismatch', self::flags($different)['cleaning_fee'] ?? null);
        self::assertArrayNotHasKey('cleaning_fee', self::flags($noFeeAtAll));
    }

    public function testMissingCleaningIsFlaggedOnlyIfThePropertyHasACleaningFee(): void
    {
        $values = ['fareCleaning' => 0.0, 'totalFees' => 0.0, 'totalTaxes' => 20.0];

        $withFee = $this->calculator->calculate(self::reservation('Booking.com', $values), 20.0, 80.0);
        $withoutFee = $this->calculator->calculate(self::reservation('Booking.com', $values), 20.0, 0.0);

        self::assertSame('empty_or_zero_from_guesty', self::flags($withFee)['cleaning_fee'] ?? null);
        self::assertArrayNotHasKey('cleaning_fee', self::flags($withoutFee));
    }

    public function testConciergeFeeIsNeverNegative(): void
    {
        $result = $this->calculator->calculate(
            self::reservation('Direct', ['hostPayout' => 100.0, 'hostServiceFee' => 0.0, 'totalPaymentFees' => 0.0, 'fareCleaning' => 150.0]),
            20.0,
            150.0,
        );

        // Sans plancher : 20 % × (100 − 150) = −10.
        self::assertSame(0.0, $result->conciergeFee);
        self::assertSame(-50.0, $result->netOwnerRevenue);
        self::assertSame(['concierge_fee' => 'concierge_fee_zero', 'tourist_tax' => 'empty_or_zero_from_guesty'], self::flags($result));
    }

    public function testDirectFollowsTheClassicFormula(): void
    {
        $result = $this->calculator->calculate(self::reservation('Direct', ['hostServiceFee' => 0.0, 'totalPaymentFees' => 0.0, 'totalTaxes' => 20.0]), 20.0, 80.0);

        // Classique : net = total − commission − frais − taxe ; frais = 20 % × (net − ménage).
        self::assertAmounts(['total' => 1000.0, 'commission' => 0.0, 'fee' => 0.0, 'tax' => 20.0, 'cleaning' => 80.0, 'net' => 980.0, 'concierge' => 180.0, 'revenue' => 720.0], $result);
    }

    public function testAnOwnerStayHasNoAmountsAndNoFlags(): void
    {
        $result = $this->calculator->calculate(self::reservation('owner'), 20.0, 80.0);

        self::assertEquals(CalculatedAmounts::zero(), $result);
    }

    public function testAZeroCommissionNeverFlagsTheConciergeFee(): void
    {
        $result = $this->calculator->calculate(self::reservation('Booking.com', ['totalTaxes' => 20.0]), 0.0, 80.0);

        self::assertSame(0.0, $result->conciergeFee);
        self::assertArrayNotHasKey('concierge_fee', self::flags($result));
    }
}

<?php

declare(strict_types=1);

namespace Casamiza\Tests\Reservation;

use Casamiza\Domain\Guesty\GuestyReservation;
use Casamiza\Domain\Reservation\Reservation;
use Casamiza\Domain\Reservation\ReservationCalculator;
use Casamiza\Domain\Reservation\StripeFees;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Réservations réelles, figées dans `golden-reservations.json` : entrées brutes de Guesty (anonymisées : ni voyageur ni
 * identifiant), commission du propriétaire, ménage du logement et frais Stripe lus, face aux montants **imprimés par
 * Facturiza** sur ses factures (JSON des factures 376 et suivantes). Deux cas au plus par famille (plateforme, signalements,
 * Stripe) ; seules les réservations que Casamiza calcule à l'identique de Facturiza y figurent. Les signalements attendus
 * sont ceux de Casamiza (Facturiza n'en conserve pas dans ses factures).
 */
final class ReservationGoldenTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cases(): iterable
    {
        /** @var list<array<string, mixed>> $cases */
        $cases = json_decode((string) file_get_contents(__DIR__ . '/golden-reservations.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            yield (string) $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testMatchesTheAmountsPrintedByFacturiza(array $case): void
    {
        /** @var array<string, mixed> $raw */
        $raw = $case['raw'];
        $guesty = new GuestyReservation(...($raw + [
            'guestyId' => 'golden',
            'listingGuestyId' => 'listing',
            'source' => $case['source'],
            'integrationKeys' => [],
            'platformRef' => '',
            'guest' => 'Anonyme',
            'checkIn' => new DateTimeImmutable('2026-01-10 14:00:00 UTC'),
            'checkOut' => new DateTimeImmutable('2026-01-14 08:00:00 UTC'),
            'nights' => $case['nights'],
            'guests' => $case['guests'],
        ]));
        $stripe = $case['stripeFee'] === null ? null : new StripeFees((float) $case['stripeFee']);

        $result = (new ReservationCalculator())->calculate(
            Reservation::fromGuesty('golden-1', $guesty, 'owner', 'property'),
            (float) $case['commission'],
            (float) $case['propertyCleaningFee'],
            $stripe,
        );

        /** @var array<string, float> $expected */
        $expected = $case['facturiza'];
        self::assertEqualsWithDelta($expected, [
            'total' => $result->totalPayout,
            'commission' => $result->channelCommission,
            'fee' => $result->paymentFee,
            'tax' => $result->touristTax,
            'cleaning' => $result->cleaningFee,
            'net' => $result->netPayout,
            'concierge' => $result->conciergeFee,
            'revenue' => $result->netOwnerRevenue,
        ], 0.004);

        $flags = [];
        foreach ($result->flags as $flag) {
            $flags[$flag->field->value] = $flag->reason->value;
        }
        ksort($flags);
        self::assertSame($case['flags'], $flags);
    }
}

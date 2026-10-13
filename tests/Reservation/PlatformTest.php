<?php

declare(strict_types=1);

namespace Casamiza\Tests\Reservation;

use Casamiza\Domain\Reservation\Platform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlatformTest extends TestCase
{
    /** @return iterable<string, array{string, Platform}> valeurs relevées dans Guesty (guesty:sources) */
    public static function guestySources(): iterable
    {
        yield 'Booking.com' => ['Booking.com', Platform::Booking];
        yield 'airbnb2' => ['airbnb2', Platform::Airbnb];
        yield 'Abritel' => ['Abritel', Platform::Abritel];
        yield 'HomeAway NL' => ['HomeAway NL', Platform::Abritel];
        yield 'website' => ['website', Platform::Website];
        yield 'manual' => ['manual', Platform::Manual];
        yield 'Direct' => ['Direct', Platform::Direct];
        yield 'owner' => ['owner', Platform::Owner];
        yield 'owner-guest' => ['owner-guest', Platform::Owner];
        yield 'inconnue' => ['quelque chose', Platform::Direct];
        yield 'vide' => ['', Platform::Direct];
    }

    #[DataProvider('guestySources')]
    public function testMapsTheGuestySource(string $source, Platform $expected): void
    {
        self::assertSame($expected, Platform::fromGuestySource($source));
    }

    public function testOnlyAbritelWebsiteAndManualUseTheRetrocommissionFormula(): void
    {
        $retro = array_values(array_filter(Platform::cases(), static fn(Platform $p): bool => $p->isRetrocommission()));

        self::assertEqualsCanonicalizing([Platform::Abritel, Platform::Website, Platform::Manual], $retro);
    }
}

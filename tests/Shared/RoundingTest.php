<?php

declare(strict_types=1);

namespace Casamiza\Tests\Shared;

use Casamiza\Shared\Rounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoundingTest extends TestCase
{
    /** @return iterable<string, array{float, float}> */
    public static function values(): iterable
    {
        // 15 % × 420,5 vaut 63,07499999… en flottant : PHP 8.4 donnerait 63,07, Facturiza 63,08.
        yield 'produit à 15 chiffres' => [0.15 * 420.5, 63.08];
        yield 'demi supérieur' => [1.005, 1.01];
        yield 'demi supérieur négatif' => [-1.005, -1.01];
        yield 'déjà arrondi' => [602.5, 602.5];
        yield 'bruit de somme' => [500.5 + 90.0 + 12.0, 602.5];
        yield 'arrondi bas' => [12.344, 12.34];
        yield 'zéro' => [0.0, 0.0];
    }

    #[DataProvider('values')]
    public function testRoundsHalfUpLikeFacturiza(float $value, float $expected): void
    {
        self::assertSame($expected, Rounding::cents($value));
    }
}

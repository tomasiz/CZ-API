<?php

declare(strict_types=1);

namespace Casamiza\Tests\Infrastructure;

use Casamiza\Infrastructure\Pdf\PdfNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Les nombres des PDF s'écrivent comme dans Facturiza (cast PHP à 14 chiffres significatifs), pas comme `number_format`. */
final class PdfNumberTest extends TestCase
{
    /** @return iterable<string, array{int|float, string, string}> */
    public static function numbers(): iterable
    {
        yield 'entier' => [143, '143', '143'];
        yield 'flottant entier' => [143.0, '143', '143'];
        yield 'un seul décimal' => [16.1, '16.1', '16.1'];
        yield 'deux décimales' => [1181.35, '1181.35', '1181.35'];
        yield 'bruit de calcul' => [23.58000000000004, '23.58', '23.58'];
        yield 'somme de flottants' => [0.1 + 0.2, '0.3', '0.3'];
        yield 'zéro' => [0.0, '0', '0'];
        yield 'négatif' => [-59.0, '-59', '-59'];
        yield 'arrondi demi supérieur' => [63.075, '63.075', '63.08'];
        yield 'demi flottant' => [0.15 * 420.5, '63.075', '63.08'];
        yield 'milliers sans séparateur' => [12345.6, '12345.6', '12345.6'];
    }

    #[DataProvider('numbers')]
    public function testNumbersAreWrittenLikeFacturiza(int|float $value, string $text, string $display): void
    {
        self::assertSame($text, PdfNumber::text($value));
        self::assertSame($display, PdfNumber::display($value));
    }

    public function testItMatchesThePhpCastWithTheDefaultPrecision(): void
    {
        $previous = ini_set('precision', '14');
        try {
            foreach ([0.1 + 0.7, 1 / 3, 2 / 3 * 100, 1234.5678, 99999.99, 0.07, 41.66] as $value) {
                self::assertSame((string) $value, PdfNumber::text($value));
            }
        } finally {
            ini_set('precision', $previous === false ? '14' : $previous);
        }
    }
}

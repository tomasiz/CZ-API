<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Pdf;

use Casamiza\Shared\Rounding;

/**
 * Écriture des nombres dans les PDF, identique à celle de Facturiza : un flottant s'imprime avec 14 chiffres significatifs et
 * sans zéro de fin (`143`, `16.1`, `1181.35`), jamais avec `number_format`. Le séparateur décimal est le point ; le
 * récapitulatif le remplace par une virgule.
 */
final class PdfNumber
{
    /** `(string) $value` de PHP, mais indépendant de l'option `precision` du serveur. */
    public static function text(int|float $value): string
    {
        return is_int($value) ? (string) $value : sprintf('%.14G', $value);
    }

    /** Montant arrondi au centime puis écrit comme ci-dessus (`toDisplay` de Facturiza). */
    public static function display(int|float $value): string
    {
        return self::text(is_int($value) ? $value : Rounding::cents($value));
    }
}

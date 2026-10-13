<?php

declare(strict_types=1);

namespace Casamiza\Shared;

/**
 * Arrondi monétaire identique à celui de Facturiza (PHP antérieur à 8.4). Depuis PHP 8.4, `round()` ne pré-arrondit plus :
 * `round(0.15 * 420.5, 2)` (soit 63,07499999… en flottant) donne 63,07 au lieu de 63,08. Les montants des factures reprises
 * doivent se retrouver au centime près : la valeur est d'abord ramenée à 15 chiffres significatifs (l'ancien pré-arrondi),
 * puis arrondie à 2 décimales, demi supérieur.
 */
final class Rounding
{
    public static function cents(float $value): float
    {
        return round((float) sprintf('%.15g', $value), 2);
    }
}

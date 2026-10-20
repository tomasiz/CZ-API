<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

use DateTimeImmutable;

/** Cours de change de référence (BCE). */
interface ExchangeRatesInterface
{
    /**
     * Euros pour un dollar, six décimales, au jour demandé (le dernier jour ouvré avant lui si la BCE n'a rien publié ce jour-là).
     *
     * @return numeric-string
     * @throws \Casamiza\Shared\Exception\ServiceUnavailableException `exchange_rate_unavailable`
     */
    public function usdToEur(DateTimeImmutable $day): string;
}

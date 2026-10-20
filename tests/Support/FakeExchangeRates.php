<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Cost\ExchangeRatesInterface;
use Casamiza\Shared\Exception\ServiceUnavailableException;
use DateTimeImmutable;

/** Cours posés par le test (un cours par défaut, ou par jour), et la liste des jours demandés. */
final class FakeExchangeRates implements ExchangeRatesInterface
{
    /** @var numeric-string */
    public string $default = '0.900000';

    /** @var array<string, numeric-string> AAAA-MM-JJ => cours */
    public array $byDay = [];

    public bool $down = false;

    /** @var list<string> */
    public array $asked = [];

    public function usdToEur(DateTimeImmutable $day): string
    {
        $this->asked[] = $day->format('Y-m-d');
        if ($this->down) {
            throw new ServiceUnavailableException('exchange_rate_unavailable');
        }

        return $this->byDay[$day->format('Y-m-d')] ?? $this->default;
    }
}

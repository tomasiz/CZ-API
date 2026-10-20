<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Integration;

use Casamiza\Domain\Cost\ExchangeRatesInterface;
use Casamiza\Shared\Exception\ServiceUnavailableException;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Cours de référence de la BCE par l'API publique Frankfurter (sans clé). Pour un week-end ou un jour férié, l'API répond avec
 * le dernier jour ouvré ; une date future est ramenée à aujourd'hui.
 *
 * @see https://frankfurter.dev
 */
final readonly class FrankfurterExchangeRates implements ExchangeRatesInterface
{
    private const BASE_URL = 'https://api.frankfurter.dev/v1/';

    public function __construct(
        private ClientInterface $http,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function usdToEur(DateTimeImmutable $day): string
    {
        $today = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $date = min($day->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0), $today)->format('Y-m-d');

        try {
            $response = $this->http->request('GET', self::BASE_URL . $date, ['query' => ['base' => 'USD', 'symbols' => 'EUR'], 'timeout' => 15]);
            $body = json_decode((string) $response->getBody(), true);
        } catch (GuzzleException $e) {
            $this->logger->warning('Exchange rate lookup failed.', ['exception' => $e::class]);

            throw new ServiceUnavailableException('exchange_rate_unavailable');
        }
        $rate = is_array($body) && is_array($body['rates'] ?? null) ? ($body['rates']['EUR'] ?? null) : null;
        if (!is_numeric($rate) || (float) $rate <= 0.0 || (float) $rate > 10.0) {
            $this->logger->warning('Exchange rate lookup returned an unexpected payload.');

            throw new ServiceUnavailableException('exchange_rate_unavailable');
        }

        return number_format((float) $rate, 6, '.', '');
    }
}

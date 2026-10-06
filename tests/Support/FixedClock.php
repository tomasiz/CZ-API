<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final class FixedClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $at = '2026-10-07 09:00:00')
    {
        $this->now = new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}

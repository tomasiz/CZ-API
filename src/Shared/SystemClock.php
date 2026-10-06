<?php

declare(strict_types=1);

namespace Casamiza\Shared;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/** Horloge réelle, en UTC. Les tests la remplacent par une horloge figée. */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

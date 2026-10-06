<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;

/** Les dates sont stockées en UTC (DATETIME) et manipulées en DateTimeImmutable UTC. */
final class DbDate
{
    public static function toDb(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function fromDb(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }

    public static function fromDbOrNull(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : self::fromDb($value);
    }
}

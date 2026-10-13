<?php

declare(strict_types=1);

namespace Casamiza\Domain\Guesty;

use DateTimeImmutable;

final readonly class SyncReport
{
    public function __construct(
        public SyncCounts $owners,
        public SyncCounts $properties,
        public DateTimeImmutable $syncedAt,
    ) {}
}

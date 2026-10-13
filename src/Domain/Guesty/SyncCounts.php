<?php

declare(strict_types=1);

namespace Casamiza\Domain\Guesty;

/** Bilan de synchronisation d'une ressource. Un élément qui réapparaît dans Guesty compte comme « mis à jour ». */
final readonly class SyncCounts
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $archived = 0,
        public int $unchanged = 0,
    ) {}
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Guesty;

use Casamiza\Domain\Owner\OwnerType;

/** Propriétaire tel que Guesty le décrit (lecture seule). */
final readonly class GuestyOwner
{
    public function __construct(
        public string $guestyId,
        public string $name,
        public string $email,
        public string $phone,
        public string $address,
        public OwnerType $type,
        public ?float $commission,
        public bool $active,
    ) {}
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Dashboard;

final readonly class DashboardProperty
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $ownerId,
        public string $ownerName,
        public bool $active,
    ) {}
}

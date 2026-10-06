<?php

declare(strict_types=1);

namespace Casamiza\Domain\Tenant;

final readonly class Tenant
{
    public function __construct(
        public string $id,
        public string $name,
        public TenantStatus $status = TenantStatus::Active,
    ) {}
}

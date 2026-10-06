<?php

declare(strict_types=1);

namespace Casamiza\Domain\Tenant;

enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}

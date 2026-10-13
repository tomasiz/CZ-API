<?php

declare(strict_types=1);

namespace Casamiza\Domain\Owner;

enum OwnerType: string
{
    case Individual = 'individual';
    case Company = 'company';
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\User;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
}

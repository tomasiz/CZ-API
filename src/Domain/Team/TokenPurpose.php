<?php

declare(strict_types=1);

namespace Casamiza\Domain\Team;

enum TokenPurpose: string
{
    case Activation = 'activation';
    case PasswordReset = 'password_reset';
}

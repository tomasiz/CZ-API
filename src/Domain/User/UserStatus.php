<?php

declare(strict_types=1);

namespace Casamiza\Domain\User;

enum UserStatus: string
{
    /** Invité : le compte existe mais n'a pas encore de mot de passe (impossible de se connecter). */
    case Invited = 'invited';
    case Active = 'active';
}

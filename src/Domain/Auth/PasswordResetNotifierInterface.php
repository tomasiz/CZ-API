<?php

declare(strict_types=1);

namespace Casamiza\Domain\Auth;

use Casamiza\Domain\User\User;

/** Fait parvenir à l'utilisateur son lien de réinitialisation de mot de passe (e-mail). */
interface PasswordResetNotifierInterface
{
    /** @return bool vrai si l'e-mail est parti ; faux sinon (jamais d'exception) */
    public function notify(User $user, string $token): bool;
}

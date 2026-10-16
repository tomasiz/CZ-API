<?php

declare(strict_types=1);

namespace Casamiza\Domain\Team;

use Casamiza\Domain\User\User;

/** Fait parvenir au membre invité son lien d'activation (e-mail). */
interface InvitationNotifierInterface
{
    /** @return bool vrai si l'e-mail est parti ; faux sinon (jamais d'exception : l'invitation existe de toute façon) */
    public function notify(User $user, string $token): bool;
}

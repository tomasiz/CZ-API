<?php

declare(strict_types=1);

namespace Casamiza\Domain\Team;

use Casamiza\Domain\User\User;

/** Fait parvenir au membre invité son lien d'activation (e-mail). */
interface InvitationNotifierInterface
{
    public function notify(User $user, string $token): void;
}

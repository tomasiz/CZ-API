<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Team\InvitationNotifierInterface;
use Casamiza\Domain\User\User;

/** Garde les jetons « envoyés » pour que les tests puissent activer le compte. */
final class InMemoryInvitationNotifier implements InvitationNotifierInterface
{
    /** @var array<string, string> e-mail => jeton */
    public array $sent = [];

    public function notify(User $user, string $token): void
    {
        $this->sent[$user->email] = $token;
    }
}

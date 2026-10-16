<?php

declare(strict_types=1);

namespace Casamiza\Domain\Auth;

use Casamiza\Shared\Settings;

/** Lien de réinitialisation d'un mot de passe : la page `/reset-password` du front (même origine que celle autorisée par CORS). */
final readonly class PasswordResetLink
{
    public function __construct(private Settings $settings) {}

    public function for(string $token): string
    {
        return $this->settings->corsOrigin . '/reset-password?token=' . $token;
    }
}

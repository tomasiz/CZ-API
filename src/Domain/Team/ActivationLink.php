<?php

declare(strict_types=1);

namespace Casamiza\Domain\Team;

use Casamiza\Shared\Settings;

/** Construit le lien d'activation d'un compte : la page `/activate` du front (même origine que celle autorisée par CORS). */
final readonly class ActivationLink
{
    public function __construct(private Settings $settings) {}

    public function for(string $token): string
    {
        return $this->settings->corsOrigin . '/activate?token=' . $token;
    }
}

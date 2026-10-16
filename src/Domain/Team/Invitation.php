<?php

declare(strict_types=1);

namespace Casamiza\Domain\Team;

use Casamiza\Domain\User\User;
use DateTimeImmutable;

/**
 * Invitation émise : le membre, le jeton en clair (affiché une seule fois à l'administrateur), sa date d'expiration et
 * l'issue de l'envoi du lien par e-mail.
 */
final readonly class Invitation
{
    public function __construct(
        public User $user,
        public string $token,
        public DateTimeImmutable $expiresAt,
        public bool $emailSent = false,
    ) {}

    public function withEmailSent(bool $sent): self
    {
        return new self($this->user, $this->token, $this->expiresAt, $sent);
    }
}

<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Auth\PasswordResetNotifierInterface;
use Casamiza\Domain\User\User;
use Casamiza\Shared\TenantContext;

/** Garde les jetons de réinitialisation « envoyés » et le tenant posé au moment de l'envoi. */
final class InMemoryPasswordResetNotifier implements PasswordResetNotifierInterface
{
    /** @var array<string, string> e-mail => dernier jeton */
    public array $sent = [];

    /** @var list<string|null> tenant posé dans le contexte à chaque envoi (la route est publique : c'est celui de l'utilisateur) */
    public array $tenants = [];

    public function __construct(private readonly TenantContext $context) {}

    public function notify(User $user, string $token): bool
    {
        $this->sent[$user->email] = $token;
        $this->tenants[] = $this->context->idOrNull();

        return true;
    }
}

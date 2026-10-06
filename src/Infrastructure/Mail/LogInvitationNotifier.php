<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Mail;

use Casamiza\Domain\Team\InvitationNotifierInterface;
use Casamiza\Domain\User\User;
use Casamiza\Shared\Settings;
use Psr\Log\LoggerInterface;

/**
 * TODO(Mailsend) : remplacer par l'envoi réel de l'e-mail d'invitation (clé Mailsend du tenant).
 * En attendant, hors production, le lien d'activation est écrit dans les logs pour pouvoir tester le parcours.
 */
final readonly class LogInvitationNotifier implements InvitationNotifierInterface
{
    public function __construct(private LoggerInterface $logger, private Settings $settings) {}

    public function notify(User $user, string $token): void
    {
        if ($this->settings->isProd()) {
            $this->logger->warning('Invitation not sent: no mailer configured yet.', ['user_id' => $user->id]);

            return;
        }

        $this->logger->info('Invitation activation link (dev only, no e-mail sent).', [
            'user_id' => $user->id,
            'email' => $user->email,
            'url' => $this->settings->corsOrigin . '/activate?token=' . $token,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Mail;

use Casamiza\Domain\Auth\PasswordResetLink;
use Casamiza\Domain\Auth\PasswordResetNotifierInterface;
use Casamiza\Domain\Company\CompanySettings;
use Casamiza\Domain\Company\CompanySettingsRepositoryInterface;
use Casamiza\Domain\Mail\MailerInterface;
use Casamiza\Domain\Mail\OutgoingEmail;
use Casamiza\Domain\User\User;
use Casamiza\Shared\Exception\ApiException;
use Psr\Log\LoggerInterface;

/**
 * Envoie le lien de réinitialisation avec la clé MailerSend et l'adresse de contact du tenant de l'utilisateur (le
 * `TenantContext` doit déjà être posé). Un envoi impossible est journalisé (code seulement) : la réponse de la demande ne
 * dépend jamais de son issue. Le français de l'e-mail est du contenu destiné à l'utilisateur, pas un message de l'API.
 */
final readonly class MailerSendPasswordResetNotifier implements PasswordResetNotifierInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private CompanySettingsRepositoryInterface $company,
        private PasswordResetLink $link,
        private LoggerInterface $logger,
    ) {}

    public function notify(User $user, string $token): bool
    {
        $settings = $this->company->find() ?? CompanySettings::blank();
        if (trim($settings->contactEmail) === '') {
            $this->logger->warning('Password reset not e-mailed: the company has no contact e-mail.', ['user_id' => $user->id]);

            return false;
        }

        $organization = trim($settings->legalName) === '' ? 'Casamiza' : $settings->legalName;
        $text = "Bonjour {$user->firstName},\n\n"
            . "Vous avez demandé à réinitialiser votre mot de passe {$organization}. "
            . "Ouvrez ce lien pour en choisir un nouveau (valable 1 heure) :\n\n"
            . $this->link->for($token)
            . "\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe actuel reste inchangé.";

        try {
            $this->mailer->send(new OutgoingEmail(
                $settings->contactEmail,
                trim($settings->contactName) === '' ? $organization : $settings->contactName,
                $user->email,
                trim($user->firstName . ' ' . $user->lastName),
                'Réinitialisation de votre mot de passe',
                $text,
            ));
        } catch (ApiException $error) {
            $this->logger->warning('Password reset e-mail not sent.', ['user_id' => $user->id, 'code' => $error->errorCode()]);

            return false;
        }

        return true;
    }
}

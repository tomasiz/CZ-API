<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Mail;

use Casamiza\Domain\Company\CompanySettings;
use Casamiza\Domain\Company\CompanySettingsRepositoryInterface;
use Casamiza\Domain\Mail\MailerInterface;
use Casamiza\Domain\Mail\OutgoingEmail;
use Casamiza\Domain\Team\ActivationLink;
use Casamiza\Domain\Team\InvitationNotifierInterface;
use Casamiza\Domain\User\User;
use Casamiza\Shared\Exception\ApiException;
use Psr\Log\LoggerInterface;

/**
 * Envoie au membre invité son lien d'activation, avec la clé MailerSend et l'adresse de contact du tenant. L'invitation
 * existe déjà : un envoi impossible (MailerSend non configuré, refusé, injoignable) est journalisé mais ne la fait pas échouer,
 * l'administrateur reçoit toujours le lien dans la réponse de l'API.
 *
 * Le français de l'e-mail est du contenu destiné au membre, pas un message de l'API. Ni lien ni adresse dans les logs.
 */
final readonly class MailerSendInvitationNotifier implements InvitationNotifierInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private CompanySettingsRepositoryInterface $company,
        private ActivationLink $link,
        private LoggerInterface $logger,
    ) {}

    public function notify(User $user, string $token): bool
    {
        $settings = $this->company->find() ?? CompanySettings::blank();
        if (trim($settings->contactEmail) === '') {
            $this->logger->warning('Invitation not e-mailed: the company has no contact e-mail.', ['user_id' => $user->id]);

            return false;
        }

        $organization = trim($settings->legalName) === '' ? 'Casamiza' : $settings->legalName;
        $text = "Bonjour {$user->firstName},\n\n"
            . "Vous avez été invité(e) à rejoindre l'espace de gestion de {$organization}. "
            . "Pour activer votre compte et choisir votre mot de passe, ouvrez ce lien (valable 7 jours) :\n\n"
            . $this->link->for($token)
            . "\n\nSi vous n'attendiez pas cette invitation, ignorez simplement ce message.";

        try {
            $this->mailer->send(new OutgoingEmail(
                $settings->contactEmail,
                trim($settings->contactName) === '' ? $organization : $settings->contactName,
                $user->email,
                trim($user->firstName . ' ' . $user->lastName),
                'Invitation à rejoindre ' . $organization,
                $text,
            ));
        } catch (ApiException $error) {
            $this->logger->warning('Invitation e-mail not sent.', ['user_id' => $user->id, 'code' => $error->errorCode()]);

            return false;
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Mail;

/** Envoie un e-mail avec le service du tenant courant (MailerSend). */
interface MailerInterface
{
    /**
     * @throws \Casamiza\Shared\Exception\NotFoundException `integration_not_configured`
     * @throws \Casamiza\Shared\Exception\ApiException le fournisseur a refusé ou n'a pas répondu
     *         (`integration_invalid_credentials`, `integration_forbidden`, `integration_rate_limited`, `integration_unreachable`, `email_rejected`)
     */
    public function send(OutgoingEmail $email): void;
}

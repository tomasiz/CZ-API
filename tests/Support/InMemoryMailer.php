<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Mail\MailerInterface;
use Casamiza\Domain\Mail\OutgoingEmail;
use Casamiza\Shared\Exception\ApiException;

/** Garde les e-mails « envoyés » ; peut refuser l'envoi avec l'erreur donnée. */
final class InMemoryMailer implements MailerInterface
{
    /** @var list<OutgoingEmail> */
    public array $sent = [];

    public ?ApiException $failure = null;

    public function send(OutgoingEmail $email): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->sent[] = $email;
    }
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Mail;

/**
 * Un e-mail à envoyer. `text` est le message en texte brut (retours à la ligne compris) : c'est l'envoyeur qui l'habille
 * (modèle du fournisseur) et qui échappe le HTML.
 */
final readonly class OutgoingEmail
{
    /** @param list<MailAttachment> $attachments */
    public function __construct(
        public string $fromEmail,
        public string $fromName,
        public string $toEmail,
        public string $toName,
        public string $subject,
        public string $text,
        public array $attachments = [],
        /** Adresse en copie cachée (« m'envoyer une copie »), null = aucune */
        public ?string $copyEmail = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Mail;

/** E-mail préparé pour un propriétaire et un mois : l'administrateur le relit et le corrige avant l'envoi. */
final readonly class EmailDraft
{
    /** @param list<array{document: EmailDocument, name: string}> $attachments */
    public function __construct(
        public string $to,
        public string $firstName,
        public string $lastName,
        public string $subject,
        public string $message,
        public array $attachments,
    ) {}
}

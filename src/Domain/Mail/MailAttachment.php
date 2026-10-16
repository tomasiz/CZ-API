<?php

declare(strict_types=1);

namespace Casamiza\Domain\Mail;

/** Pièce jointe d'un e-mail : nom du fichier et octets. */
final readonly class MailAttachment
{
    public function __construct(
        public string $filename,
        public string $contents,
    ) {}
}

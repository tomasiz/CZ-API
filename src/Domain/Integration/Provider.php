<?php

declare(strict_types=1);

namespace Casamiza\Domain\Integration;

/** Services tiers configurables par un tenant. */
enum Provider: string
{
    case Guesty = 'guesty';
    case MailerSend = 'mailersend';
    case Pennylane = 'pennylane';
}

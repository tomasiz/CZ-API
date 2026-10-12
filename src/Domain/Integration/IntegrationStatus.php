<?php

declare(strict_types=1);

namespace Casamiza\Domain\Integration;

/** Résultat du dernier contrôle. « Non configuré » n'est pas un statut : il n'y a alors pas de ligne. */
enum IntegrationStatus: string
{
    case Connected = 'connected';
    case Error = 'error';
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Audit;

/** Famille d'actions : sert aux filtres du journal. */
enum AuditFamily: string
{
    case Access = 'access';
    case Team = 'team';
    case Settings = 'settings';
    case Invoices = 'invoices';
}

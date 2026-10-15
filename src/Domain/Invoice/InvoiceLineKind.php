<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

/** Nature d'une ligne facturée. La catégorie (conciergerie, linge, ménage) est portée à part, pour les totaux. */
enum InvoiceLineKind: string
{
    /** Composante d'une réservation : frais de conciergerie, linge ou ménage du logement. */
    case Reservation = 'reservation';

    /** Commission d'une plateforme avancée par la conciergerie et refacturée (Abritel), rangée dans la conciergerie. */
    case PlatformCommission = 'platform_commission';

    /** Prestation supplémentaire du mois (table `extras`). */
    case Extra = 'extra';
}

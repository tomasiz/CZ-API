<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

/** Origine d'une facture : créée dans Casamiza, ou reprise de Facturiza (jamais envoyée à Pennylane). */
enum InvoiceSource: string
{
    case Casamiza = 'casamiza';
    case FacturizaImport = 'facturiza_import';
}

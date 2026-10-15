<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice\Document;

/** Fabrique les PDF d'une facture et de son récapitulatif ; renvoie les octets du fichier. */
interface DocumentRendererInterface
{
    public function invoice(DocumentCompany $company, InvoiceDocument $document): string;

    public function recap(DocumentCompany $company, RecapDocument $document): string;
}

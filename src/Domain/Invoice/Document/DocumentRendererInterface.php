<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice\Document;

/**
 * Fabrique les PDF d'une facture et de son récapitulatif ; renvoie les octets du fichier.
 * `$specimen` : aperçu qui ne vaut pas document (filigrane « specimen » sur chaque page).
 */
interface DocumentRendererInterface
{
    public function invoice(DocumentCompany $company, InvoiceDocument $document, bool $specimen = false): string;

    public function recap(DocumentCompany $company, RecapDocument $document, bool $specimen = false): string;
}

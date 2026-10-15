<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Pdf;

use Casamiza\Domain\Invoice\Document\DocumentCompany;
use Casamiza\Domain\Invoice\Document\DocumentRendererInterface;
use Casamiza\Domain\Invoice\Document\InvoiceDocument;
use Casamiza\Domain\Invoice\Document\RecapDocument;

/** Fabrique les PDF avec la mise en page de Facturiza (`DocumentPdf`). */
final readonly class PdfDocumentRenderer implements DocumentRendererInterface
{
    /** @param string $specimenFile image du filigrane des aperçus */
    public function __construct(private PdfFontDirectory $fonts, private string $specimenFile) {}

    public function invoice(DocumentCompany $company, InvoiceDocument $document, bool $specimen = false): string
    {
        $pdf = new DocumentPdf($this->fonts->path());
        $pdf->begin($company, true, $document->number, $specimen ? $this->specimenFile : null);
        $pdf->firstPageHeader($document->recipient, $document->periodStart, $document->periodEnd, $document->date, $document->note);

        foreach ($document->sections as $section) {
            $pdf->invoiceSection($section->title, $section->rows);
        }
        $pdf->vatBlock($document->vat);
        $pdf->totalBlock($document->totalHt, $document->totalVat, $document->totalTtc);
        $pdf->dueDate($document->totalTtc, $document->dueDate);
        $pdf->terms();

        return $pdf->bytes();
    }

    public function recap(DocumentCompany $company, RecapDocument $document, bool $specimen = false): string
    {
        $pdf = new DocumentPdf($this->fonts->path());
        $pdf->begin($company, false, '', $specimen ? $this->specimenFile : null);
        $pdf->firstPageHeader($document->recipient, $document->periodStart, $document->periodEnd, $document->periodEnd);

        $total = 0;
        foreach ($document->cards as $card) {
            $pdf->recapCard($card);
            $total += $card->netOwnerRevenue;
        }
        $pdf->recapTotal($document->periodStart, $document->periodEnd, (float) $total);

        return $pdf->bytes();
    }
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

use Casamiza\Domain\Company\CompanySettings;
use Casamiza\Domain\Company\CompanySettingsRepositoryInterface;
use Casamiza\Domain\Company\LogoStorageInterface;
use Casamiza\Domain\Invoice\Document\DocumentCompany;
use Casamiza\Domain\Invoice\Document\DocumentRendererInterface;
use Casamiza\Domain\Invoice\Document\SnapshotDocumentFactory;
use Casamiza\Shared\Exception\NotFoundException;
use RuntimeException;

/**
 * Fabrique et range les PDF d'une facture et de son récapitulatif **à partir de l'instantané légal** (jamais de la base vivante :
 * le document reste celui de l'émission), avec l'identité de l'entreprise telle qu'elle est au moment de la fabrication. Un PDF
 * rattaché à une facture n'est jamais réécrit : seuls les fichiers manquants sont produits.
 */
final readonly class InvoiceDocumentService
{
    public function __construct(
        private InvoiceRepositoryInterface $invoices,
        private CompanySettingsRepositoryInterface $company,
        private LogoStorageInterface $logos,
        private SnapshotDocumentFactory $documents,
        private DocumentRendererInterface $renderer,
        private InvoiceFileStorageInterface $files,
    ) {}

    /**
     * Produit les PDF manquants de la facture (la facture, son récapitulatif, ou les deux) et les rattache.
     *
     * @return Invoice la facture à jour
     * @throws NotFoundException `snapshot_not_found` (facture sans instantané : les premières de Facturiza, non régénérables)
     */
    public function ensure(Invoice $invoice): Invoice
    {
        if ($invoice->pdfPath !== null && $invoice->recapPdfPath !== null) {
            return $invoice;
        }
        $snapshot = $invoice->snapshot ?? throw new NotFoundException('snapshot_not_found');
        $company = $this->documentCompany($this->company->find() ?? CompanySettings::blank());
        $year = $invoice->invoiceDate->format('Y');

        if ($invoice->pdfPath === null) {
            $bytes = $this->renderer->invoice($company, $this->documents->invoice($snapshot, $invoice->dueDate));
            $this->attach($invoice, false, $bytes, $year);
        }
        if ($invoice->recapPdfPath === null) {
            $bytes = $this->renderer->recap($company, $this->documents->recap($snapshot));
            $this->attach($invoice, true, $bytes, $year);
        }

        return $this->invoices->find($invoice->id) ?? $invoice;
    }

    /** Range le fichier puis le rattache ; si une autre requête l'a fait entre-temps, le nôtre est supprimé. */
    private function attach(Invoice $invoice, bool $recap, string $bytes, string $year): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'invoice');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }
        try {
            if (file_put_contents($temporary, $bytes) === false) {
                throw new RuntimeException('Cannot write the PDF.');
            }
            $stored = $this->files->store($temporary, $year);
        } finally {
            @unlink($temporary);
        }

        try {
            $attached = $this->invoices->attachPdf($invoice->id, $recap, $stored);
        } catch (\Throwable $error) {
            $this->files->discard($stored->path);
            throw $error;
        }
        if (!$attached) {
            $this->files->discard($stored->path);
        }
    }

    private function documentCompany(CompanySettings $settings): DocumentCompany
    {
        return new DocumentCompany(
            legalName: $settings->legalName,
            legalForm: $settings->legalForm,
            capital: $settings->capital,
            address: $settings->legalAddress,
            siret: $settings->siret,
            vatNumber: $settings->vatNumber,
            iban: $settings->iban,
            contactName: trim($settings->signatoryFirstName . ' ' . $settings->signatoryLastName),
            email: $settings->legalEmail,
            phone: $settings->legalPhone,
            logoPng: $settings->logoPath === null ? null : $this->logos->read($settings->logoPath),
        );
    }
}

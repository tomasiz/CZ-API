<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Invoice\Document\DocumentCompany;
use Casamiza\Domain\Invoice\Document\DocumentRendererInterface;
use Casamiza\Domain\Invoice\Document\InvoiceDocument;
use Casamiza\Domain\Invoice\Document\RecapDocument;
use RuntimeException;

/** Moteur de PDF factice : garde ce qu'on lui a demandé d'imprimer, sans fabriquer de vrai PDF (lent et inutile hors de ses propres tests). */
final class FakeDocumentRenderer implements DocumentRendererInterface
{
    /** @var list<InvoiceDocument> */
    public array $invoices = [];

    /** @var list<RecapDocument> */
    public array $recaps = [];

    /** @var list<bool> pour chaque PDF fabriqué (facture ou récapitulatif), s'il portait le filigrane */
    public array $specimens = [];

    public bool $failing = false;

    public function invoice(DocumentCompany $company, InvoiceDocument $document, bool $specimen = false): string
    {
        $this->check();
        $this->invoices[] = $document;
        $this->specimens[] = $specimen;

        return '%PDF-fake invoice ' . $document->number;
    }

    public function recap(DocumentCompany $company, RecapDocument $document, bool $specimen = false): string
    {
        $this->check();
        $this->recaps[] = $document;
        $this->specimens[] = $specimen;

        return '%PDF-fake recap';
    }

    private function check(): void
    {
        if ($this->failing) {
            throw new RuntimeException('Renderer failure.');
        }
    }
}

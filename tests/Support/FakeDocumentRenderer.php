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

    public bool $failing = false;

    public function invoice(DocumentCompany $company, InvoiceDocument $document): string
    {
        $this->check();
        $this->invoices[] = $document;

        return '%PDF-fake invoice ' . $document->number;
    }

    public function recap(DocumentCompany $company, RecapDocument $document): string
    {
        $this->check();
        $this->recaps[] = $document;

        return '%PDF-fake recap';
    }

    private function check(): void
    {
        if ($this->failing) {
            throw new RuntimeException('Renderer failure.');
        }
    }
}

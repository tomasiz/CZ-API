<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice;

use Casamiza\Domain\Extra\ExtraType;

/**
 * Une ligne de la facture (futur `invoice_lines`). Le TTC est la donnée ; le HT est réparti pour que la somme des lignes
 * retombe sur le HT du total (voir `InvoiceCalculator`). `label` ne sert qu'aux prestations (leur description) : le texte
 * des autres lignes est composé à l'impression à partir des données.
 */
final readonly class DraftLine
{
    public function __construct(
        public int $position,
        public InvoiceLineKind $kind,
        public ExtraType $category,
        public ?string $propertyId,
        public ?string $reservationId,
        public ?string $extraId,
        public string $label,
        public float $priceTtc,
        public float $vatRate,
        public float $priceHt = 0.0,
    ) {}

    public function withPriceHt(float $priceHt): self
    {
        return new self(
            $this->position,
            $this->kind,
            $this->category,
            $this->propertyId,
            $this->reservationId,
            $this->extraId,
            $this->label,
            $this->priceTtc,
            $this->vatRate,
            $priceHt,
        );
    }
}

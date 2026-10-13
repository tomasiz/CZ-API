<?php

declare(strict_types=1);

namespace Casamiza\Domain\Statement;

/** Ce que Casamiza retient d'un (propriétaire, mois) : pour l'instant, « pas de facture ce mois-ci ». */
final readonly class Statement
{
    /** @param non-empty-string $id */
    public function __construct(
        public string $id,
        public string $ownerId,
        public string $month,
        public bool $nothingToInvoice,
    ) {}

    public function withNothingToInvoice(bool $value): self
    {
        return new self($this->id, $this->ownerId, $this->month, $value);
    }
}

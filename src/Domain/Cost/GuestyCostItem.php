<?php

declare(strict_types=1);

namespace Casamiza\Domain\Cost;

/** Une ligne de facture Guesty enregistrée : son logement (nul si aucun ne porte le nom de l'annonce) et son montant en euros. */
final readonly class GuestyCostItem
{
    /** @param non-empty-string $id */
    public function __construct(
        public string $id,
        public string $invoiceId,
        public GuestyCostLine $line,
        public ?string $propertyId,
        public int $amountCents,
        /** Faux pour une ligne déjà portée par une autre facture : elle ne compte pas deux fois */
        public bool $counted,
    ) {}
}

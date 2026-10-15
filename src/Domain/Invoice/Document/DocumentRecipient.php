<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice\Document;

/** Le propriétaire destinataire, tel que figé dans la facture. Une chaîne vide = non renseigné (la ligne n'est pas imprimée). */
final readonly class DocumentRecipient
{
    public function __construct(
        public string $name,
        public string $legalName,
        public string $siret,
        public string $address,
    ) {}
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Invoice\Document;

/** L'émetteur des documents : mentions légales de l'en-tête et du pied de page, et son logo (octets du PNG, s'il en a un). */
final readonly class DocumentCompany
{
    public function __construct(
        public string $legalName,
        public string $legalForm,
        public string $capital,
        public string $address,
        public string $siret,
        public string $vatNumber,
        public string $iban,
        public string $contactName,
        public string $email,
        public string $phone,
        public ?string $logoPng = null,
    ) {}
}

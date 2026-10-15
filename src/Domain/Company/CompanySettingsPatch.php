<?php

declare(strict_types=1);

namespace Casamiza\Domain\Company;

/**
 * Modification partielle des réglages : un champ nul reste inchangé, une chaîne vide efface la valeur.
 * La commission par défaut peut elle-même être effacée : `setDefaultCommission` dit si elle est fournie.
 */
final readonly class CompanySettingsPatch
{
    public function __construct(
        public ?string $legalName = null,
        public ?string $legalForm = null,
        public ?string $capital = null,
        public ?string $legalAddress = null,
        public ?string $legalEmail = null,
        public ?string $legalPhone = null,
        public ?string $siret = null,
        public ?string $vatNumber = null,
        public ?string $iban = null,
        public ?string $signatoryFirstName = null,
        public ?string $signatoryLastName = null,
        public bool $setDefaultCommission = false,
        public ?float $defaultCommission = null,
        public ?string $contactName = null,
        public ?string $contactEmail = null,
        public ?string $emailRecapTemplate = null,
        public ?string $emailInvoiceTemplate = null,
        public ?float $vatRateConcierge = null,
        public ?float $vatRateLinen = null,
        public ?float $vatRateCleaning = null,
        public ?float $softwareCost = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Company;

use Casamiza\Domain\Extra\VatRates;

/**
 * Identité de la conciergerie (une par tenant) : mentions légales des documents, contact des e-mails, modèles
 * d'e-mail et logo. Une chaîne vide signifie « non renseigné ».
 */
final readonly class CompanySettings
{
    public function __construct(
        public string $legalName,
        public string $legalForm,
        public string $capital,
        public string $legalAddress,
        public string $legalEmail,
        public string $legalPhone,
        public string $siret,
        public string $vatNumber,
        public string $iban,
        public string $signatoryFirstName,
        public string $signatoryLastName,
        public ?float $defaultCommission,
        public string $contactName,
        public string $contactEmail,
        public string $emailRecapTemplate,
        public string $emailInvoiceTemplate,
        public ?string $logoPath,
        /** TVA proposée à la saisie d'une prestation supplémentaire, par type. */
        public VatRates $vatRates = new VatRates(),
        /** Coût logiciel mensuel HT par logement (tableau de bord) ; 0 = non renseigné. */
        public float $softwareCost = 0.0,
    ) {}

    /** Réglages d'un tenant qui n'a encore rien saisi. */
    public static function blank(): self
    {
        return new self('', '', '', '', '', '', '', '', '', '', '', null, '', '', '', '', null);
    }

    /** Applique les champs fournis ; les autres restent inchangés. */
    public function patched(CompanySettingsPatch $patch): self
    {
        return new self(
            $patch->legalName ?? $this->legalName,
            $patch->legalForm ?? $this->legalForm,
            $patch->capital ?? $this->capital,
            $patch->legalAddress ?? $this->legalAddress,
            $patch->legalEmail ?? $this->legalEmail,
            $patch->legalPhone ?? $this->legalPhone,
            $patch->siret ?? $this->siret,
            $patch->vatNumber ?? $this->vatNumber,
            $patch->iban ?? $this->iban,
            $patch->signatoryFirstName ?? $this->signatoryFirstName,
            $patch->signatoryLastName ?? $this->signatoryLastName,
            $patch->setDefaultCommission ? $patch->defaultCommission : $this->defaultCommission,
            $patch->contactName ?? $this->contactName,
            $patch->contactEmail ?? $this->contactEmail,
            $patch->emailRecapTemplate ?? $this->emailRecapTemplate,
            $patch->emailInvoiceTemplate ?? $this->emailInvoiceTemplate,
            $this->logoPath,
            new VatRates(
                $patch->vatRateConcierge ?? $this->vatRates->concierge,
                $patch->vatRateLinen ?? $this->vatRates->linen,
                $patch->vatRateCleaning ?? $this->vatRates->cleaning,
            ),
            $patch->softwareCost ?? $this->softwareCost,
        );
    }

    public function withLogoPath(?string $logoPath): self
    {
        return new self(
            $this->legalName,
            $this->legalForm,
            $this->capital,
            $this->legalAddress,
            $this->legalEmail,
            $this->legalPhone,
            $this->siret,
            $this->vatNumber,
            $this->iban,
            $this->signatoryFirstName,
            $this->signatoryLastName,
            $this->defaultCommission,
            $this->contactName,
            $this->contactEmail,
            $this->emailRecapTemplate,
            $this->emailInvoiceTemplate,
            $logoPath,
            $this->vatRates,
            $this->softwareCost,
        );
    }

    public function hasLogo(): bool
    {
        return $this->logoPath !== null && $this->logoPath !== '';
    }
}

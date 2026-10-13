<?php

declare(strict_types=1);

namespace Casamiza\Domain\Company;

/** Réglages de l'entreprise du tenant courant (au plus une ligne). */
interface CompanySettingsRepositoryInterface
{
    public function find(): ?CompanySettings;

    /** Crée la ligne du tenant ou la remplace. */
    public function save(CompanySettings $settings): void;
}

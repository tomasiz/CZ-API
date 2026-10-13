<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Company\CompanySettings;

/** Représentation JSON des réglages de l'entreprise. Le logo se lit sur `/settings/company/logo` : on n'expose que sa présence. */
final class CompanySettingsResource
{
    /** @return array<string, mixed> */
    public static function toArray(CompanySettings $s): array
    {
        return [
            'legalName' => $s->legalName,
            'legalForm' => $s->legalForm,
            'legalAddress' => $s->legalAddress,
            'siret' => $s->siret,
            'vatNumber' => $s->vatNumber,
            'capital' => $s->capital,
            'legalEmail' => $s->legalEmail,
            'legalPhone' => $s->legalPhone,
            'iban' => $s->iban,
            'signatoryFirstName' => $s->signatoryFirstName,
            'signatoryLastName' => $s->signatoryLastName,
            'defaultCommission' => $s->defaultCommission,
            'contactName' => $s->contactName,
            'contactEmail' => $s->contactEmail,
            'emailRecap' => $s->emailRecapTemplate,
            'emailInvoice' => $s->emailInvoiceTemplate,
            'vatRateConcierge' => $s->vatRates->concierge,
            'vatRateLinen' => $s->vatRates->linen,
            'vatRateCleaning' => $s->vatRates->cleaning,
            'hasLogo' => $s->hasLogo(),
        ];
    }
}

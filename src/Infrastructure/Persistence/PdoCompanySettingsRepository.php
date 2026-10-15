<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Company\CompanySettings;
use Casamiza\Domain\Company\CompanySettingsRepositoryInterface;
use Casamiza\Domain\Extra\VatRates;
use Ramsey\Uuid\Uuid;

final class PdoCompanySettingsRepository extends BaseRepository implements CompanySettingsRepositoryInterface
{
    protected function table(): string
    {
        return 'company_settings';
    }

    public function find(): ?CompanySettings
    {
        $row = $this->selectOne('1 = 1', []);

        return $row === null ? null : $this->hydrate($row);
    }

    public function save(CompanySettings $settings): void
    {
        $existing = $this->selectOne('1 = 1', [], 'id');
        if ($existing === null) {
            $this->insertRow(['id' => Uuid::uuid7()->toString()] + $this->values($settings));

            return;
        }
        $this->updateRow((string) $existing['id'], $this->values($settings));
    }

    /** @return array<string, mixed> */
    private function values(CompanySettings $s): array
    {
        return [
            'legal_name' => $s->legalName,
            'legal_form' => $s->legalForm,
            'capital' => $s->capital,
            'legal_address' => $s->legalAddress,
            'legal_email' => $s->legalEmail,
            'legal_phone' => $s->legalPhone,
            'siret' => $s->siret,
            'vat_number' => $s->vatNumber,
            'iban' => $s->iban,
            'signatory_first_name' => $s->signatoryFirstName,
            'signatory_last_name' => $s->signatoryLastName,
            'default_commission' => $s->defaultCommission,
            'contact_name' => $s->contactName,
            'contact_email' => $s->contactEmail,
            'email_recap_template' => $s->emailRecapTemplate,
            'email_invoice_template' => $s->emailInvoiceTemplate,
            'logo_path' => $s->logoPath,
            'vat_rate_concierge' => $s->vatRates->concierge,
            'vat_rate_linen' => $s->vatRates->linen,
            'vat_rate_cleaning' => $s->vatRates->cleaning,
            'software_cost' => $s->softwareCost,
        ];
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): CompanySettings
    {
        return new CompanySettings(
            legalName: (string) $row['legal_name'],
            legalForm: (string) $row['legal_form'],
            capital: (string) $row['capital'],
            legalAddress: (string) $row['legal_address'],
            legalEmail: (string) $row['legal_email'],
            legalPhone: (string) $row['legal_phone'],
            siret: (string) $row['siret'],
            vatNumber: (string) $row['vat_number'],
            iban: (string) $row['iban'],
            signatoryFirstName: (string) $row['signatory_first_name'],
            signatoryLastName: (string) $row['signatory_last_name'],
            defaultCommission: $row['default_commission'] === null ? null : (float) $row['default_commission'],
            contactName: (string) $row['contact_name'],
            contactEmail: (string) $row['contact_email'],
            emailRecapTemplate: (string) ($row['email_recap_template'] ?? ''),
            emailInvoiceTemplate: (string) ($row['email_invoice_template'] ?? ''),
            logoPath: $row['logo_path'] === null || $row['logo_path'] === '' ? null : (string) $row['logo_path'],
            vatRates: new VatRates((float) $row['vat_rate_concierge'], (float) $row['vat_rate_linen'], (float) $row['vat_rate_cleaning']),
            softwareCost: (float) $row['software_cost'],
        );
    }
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Extra;

use Casamiza\Domain\Company\CompanySettingsRepositoryInterface;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Domain\Statement\InvoicedMonthsInterface;
use Casamiza\Shared\Exception\ConflictException;
use Casamiza\Shared\Exception\NotFoundException;
use Casamiza\Shared\Rounding;
use Ramsey\Uuid\Uuid;

/**
 * Prestations supplémentaires du suivi mensuel : ajout, modification, suppression, **tant qu'aucune facture n'existe**
 * pour le mois (409 `month_already_invoiced` ensuite).
 */
final readonly class ExtraService
{
    public function __construct(
        private ExtraRepositoryInterface $extras,
        private OwnerRepositoryInterface $owners,
        private InvoicedMonthsInterface $invoiced,
        private CompanySettingsRepositoryInterface $company,
    ) {}

    /**
     * Les prestations du mois d'un propriétaire.
     *
     * @return list<Extra>
     * @throws NotFoundException `owner_not_found`
     */
    public function list(string $ownerId, string $month): array
    {
        $this->owner($ownerId);

        return $this->extras->forOwnerMonth($ownerId, $month);
    }

    /** Taux de TVA proposés par type (réglages de l'entreprise, 20 % tant qu'ils ne sont pas saisis). */
    public function vatRates(): VatRates
    {
        $settings = $this->company->find();

        return $settings === null ? new VatRates() : $settings->vatRates;
    }

    /**
     * Ajoute une prestation. Sans `$vatRate`, la TVA proposée pour le type s'applique.
     *
     * @throws NotFoundException `owner_not_found`
     * @throws ConflictException `month_already_invoiced`
     */
    public function add(string $ownerId, string $month, ExtraType $type, string $description, float $priceTtc, ?float $vatRate): Extra
    {
        $this->owner($ownerId);
        $this->assertNotInvoiced($ownerId, $month);

        $extra = Extra::priced(Uuid::uuid7()->toString(), $ownerId, $month, $type, $description, Rounding::cents($priceTtc), $vatRate ?? $this->vatRates()->for($type));
        $this->extras->add($extra);

        return $extra;
    }

    /**
     * Modifie une prestation ; `null` laisse la valeur inchangée.
     *
     * @throws NotFoundException `owner_not_found`, `extra_not_found`
     * @throws ConflictException `month_already_invoiced`
     */
    public function update(string $ownerId, string $id, ?ExtraType $type, ?string $description, ?float $priceTtc, ?float $vatRate): Extra
    {
        $extra = $this->ownedExtra($ownerId, $id);
        $this->assertNotInvoiced($ownerId, $extra->month);

        $updated = $extra->with($type, $description, $priceTtc === null ? null : Rounding::cents($priceTtc), $vatRate);
        $this->extras->update($updated);

        return $updated;
    }

    /**
     * @throws NotFoundException `owner_not_found`, `extra_not_found`
     * @throws ConflictException `month_already_invoiced`
     */
    public function remove(string $ownerId, string $id): void
    {
        $extra = $this->ownedExtra($ownerId, $id);
        $this->assertNotInvoiced($ownerId, $extra->month);
        $this->extras->remove($extra->id);
    }

    private function owner(string $ownerId): void
    {
        $this->owners->find($ownerId) ?? throw new NotFoundException('owner_not_found');
    }

    private function ownedExtra(string $ownerId, string $id): Extra
    {
        $this->owner($ownerId);
        $extra = $this->extras->find($id);
        if ($extra === null || $extra->ownerId !== $ownerId) {
            throw new NotFoundException('extra_not_found');
        }

        return $extra;
    }

    private function assertNotInvoiced(string $ownerId, string $month): void
    {
        if ($this->invoiced->isInvoiced($ownerId, $month)) {
            throw new ConflictException('month_already_invoiced');
        }
    }
}

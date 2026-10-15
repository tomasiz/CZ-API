<?php

declare(strict_types=1);

namespace Casamiza\Domain\Statement;

use Casamiza\Domain\Invoice\InvoiceRepositoryInterface;
use Casamiza\Domain\Invoice\PaymentStatus;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Shared\Exception\ConflictException;
use Casamiza\Shared\Exception\NotFoundException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/** Statut de facturation des propriétaires, mois par mois, et bascule « pas de facture ce mois-ci ». */
final readonly class StatementService
{
    public function __construct(
        private OwnerRepositoryInterface $owners,
        private StatementRepositoryInterface $statements,
        private InvoicedMonthsInterface $invoiced,
        private InvoiceRepositoryInterface $invoices,
        private ClockInterface $clock,
    ) {}

    /**
     * Le statut de chaque propriétaire pour chaque mois demandé. Sans filtre : tous les propriétaires (archivés compris) et
     * tous les mois facturables ; avec `$month`, ce mois seulement (même s'il n'est pas terminé).
     *
     * @return list<StatementRow>
     * @throws NotFoundException `owner_not_found`
     */
    public function grid(?string $ownerId, ?string $month): array
    {
        $owners = $ownerId === null
            ? $this->owners->all()
            : [$this->owners->find($ownerId) ?? throw new NotFoundException('owner_not_found')];
        $months = $month === null ? BillingMonth::until($this->clock->now()) : [$month];

        $flagged = [];
        foreach ($this->statements->where($ownerId, $month) as $statement) {
            if ($statement->nothingToInvoice) {
                $flagged[$statement->ownerId . '|' . $statement->month] = true;
            }
        }
        $now = $this->clock->now();
        $invoices = [];
        foreach ($this->invoices->active($ownerId, $month) as $invoice) {
            $invoices[$invoice->ownerId . '|' . $invoice->month] = $invoice;
        }

        $rows = [];
        foreach ($owners as $owner) {
            foreach ($months as $m) {
                $key = $owner->id . '|' . $m;
                $invoice = $invoices[$key] ?? null;
                $rows[] = new StatementRow($owner->id, $m, match (true) {
                    $invoice?->paymentStatus === PaymentStatus::Paid => BillingStatus::Paid,
                    $invoice?->isOverdue($now) === true => BillingStatus::Overdue,
                    $invoice !== null => BillingStatus::Invoiced,
                    isset($flagged[$key]) => BillingStatus::NothingToInvoice,
                    default => BillingStatus::ToInvoice,
                });
            }
        }

        return $rows;
    }

    /**
     * Déclare (ou annule) « pas de facture ce mois-ci ».
     *
     * @throws NotFoundException `owner_not_found`
     * @throws ConflictException `month_already_invoiced`
     */
    public function setNothingToInvoice(string $ownerId, string $month, bool $value): StatementRow
    {
        $owner = $this->owners->find($ownerId) ?? throw new NotFoundException('owner_not_found');
        if ($this->invoiced->isInvoiced($owner->id, $month)) {
            throw new ConflictException('month_already_invoiced');
        }

        $statement = $this->statements->find($owner->id, $month) ?? new Statement(Uuid::uuid7()->toString(), $owner->id, $month, false);
        $this->statements->save($statement->withNothingToInvoice($value));

        return new StatementRow($owner->id, $month, $value ? BillingStatus::NothingToInvoice : BillingStatus::ToInvoice);
    }
}

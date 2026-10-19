<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Dashboard\BilledAmount;
use Casamiza\Domain\Dashboard\BilledCategory;
use Casamiza\Domain\Dashboard\DashboardProperty;
use Casamiza\Domain\Dashboard\DashboardRepositoryInterface;
use Casamiza\Domain\Dashboard\InvoiceActivity;
use Casamiza\Domain\Extra\ExtraType;
use Casamiza\Domain\Invoice\InvoiceLineKind;

/**
 * Agrégats du tableau de bord sur les colonnes figées des factures **non annulées**. Les montants sont additionnés en
 * SQL puis ramenés en centimes entiers ; aucune requête ne lit `invoices.snapshot`.
 */
final class PdoDashboardRepository extends BaseRepository implements DashboardRepositoryInterface
{
    protected function table(): string
    {
        return 'invoices';
    }

    public function activity(): array
    {
        $rows = $this->fetch(
            'SELECT r.invoice_id, r.property_id, r.month, COUNT(*) AS reservations, CAST(SUM(r.nights) AS SIGNED) AS nights,
                    CAST(ROUND(SUM(r.total_payout) * 100) AS SIGNED) AS payout,
                    CAST(ROUND(SUM(r.net_owner_revenue) * 100) AS SIGNED) AS owner_net
             FROM invoice_reservations r
             JOIN invoices i ON i.tenant_id = r.tenant_id AND i.id = r.invoice_id
             WHERE r.tenant_id = :tenant AND i.cancelled_at IS NULL
             GROUP BY r.invoice_id, r.property_id, r.month',
        );

        return array_map(static fn(array $row): InvoiceActivity => new InvoiceActivity(
            (string) $row['invoice_id'],
            (string) $row['property_id'],
            (string) $row['month'],
            (int) $row['reservations'],
            (int) $row['nights'],
            (int) $row['payout'],
            (int) $row['owner_net'],
        ), $rows);
    }

    public function billed(): array
    {
        $rows = $this->fetch(
            'SELECT l.invoice_id, i.owner_id, l.property_id, l.month, l.kind, l.category,
                    CAST(ROUND(SUM(l.price_ttc) * 100) AS SIGNED) AS ttc, CAST(ROUND(SUM(l.price_ht) * 100) AS SIGNED) AS ht
             FROM invoice_lines l
             JOIN invoices i ON i.tenant_id = l.tenant_id AND i.id = l.invoice_id
             WHERE l.tenant_id = :tenant AND i.cancelled_at IS NULL
             GROUP BY l.invoice_id, i.owner_id, l.property_id, l.month, l.kind, l.category',
        );

        return array_map(static fn(array $row): BilledAmount => new BilledAmount(
            (string) $row['invoice_id'],
            (string) $row['owner_id'],
            $row['property_id'] === null ? null : (string) $row['property_id'],
            (string) $row['month'],
            BilledCategory::of(InvoiceLineKind::from((string) $row['kind']), ExtraType::from((string) $row['category'])),
            (int) $row['ttc'],
            (int) $row['ht'],
        ), $rows);
    }

    public function properties(): array
    {
        $rows = $this->fetch(
            'SELECT p.id, p.name, p.owner_id, p.archived_at, o.name AS owner_name
             FROM properties p
             LEFT JOIN owners o ON o.tenant_id = p.tenant_id AND o.id = p.owner_id
             WHERE p.tenant_id = :tenant
             ORDER BY p.name, p.id',
        );

        return array_map(static fn(array $row): DashboardProperty => new DashboardProperty(
            (string) $row['id'],
            (string) $row['name'],
            $row['owner_id'] === null ? null : (string) $row['owner_id'],
            (string) ($row['owner_name'] ?? ''),
            $row['archived_at'] === null,
        ), $rows);
    }

    /**
     * @param non-empty-string $sql requête écrite ici, bornée par `:tenant`
     * @return list<array<array-key, mixed>>
     */
    private function fetch(string $sql): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['tenant' => $this->tenant->id()]);

        return array_values($statement->fetchAll());
    }
}

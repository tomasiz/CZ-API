<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Dashboard\DashboardProperty;
use Casamiza\Domain\Extra\ExtraType;
use Casamiza\Domain\OwnerStats\OwnerNights;
use Casamiza\Domain\OwnerStats\OwnerStatsRepositoryInterface;
use Casamiza\Domain\OwnerStats\StatsActivity;
use Casamiza\Domain\OwnerStats\StatsBilled;
use Casamiza\Domain\OwnerStats\StatsPoste;
use Casamiza\Domain\Statement\BillingMonth;

/**
 * Agrégats des statistiques d'un propriétaire sur les colonnes figées de ses factures **non annulées**. Les montants sont
 * additionnés en SQL puis ramenés en centimes entiers ; aucune requête ne lit `invoices.snapshot`.
 */
final class PdoOwnerStatsRepository extends BaseRepository implements OwnerStatsRepositoryInterface
{
    protected function table(): string
    {
        return 'invoices';
    }

    public function properties(string $ownerId): array
    {
        $rows = $this->fetch(
            'SELECT p.id, p.name, p.owner_id, p.archived_at, o.name AS owner_name
             FROM properties p
             LEFT JOIN owners o ON o.tenant_id = p.tenant_id AND o.id = p.owner_id
             WHERE p.tenant_id = :tenant
               AND (p.owner_id = :owner OR p.id IN (
                   SELECT r.property_id FROM invoice_reservations r
                   JOIN invoices i ON i.tenant_id = r.tenant_id AND i.id = r.invoice_id
                   WHERE r.tenant_id = :tenant2 AND i.owner_id = :owner2 AND i.cancelled_at IS NULL))
             ORDER BY p.name, p.id',
            ['owner' => $ownerId, 'tenant2' => $this->tenant->id(), 'owner2' => $ownerId],
        );

        return array_map(static fn(array $row): DashboardProperty => new DashboardProperty(
            (string) $row['id'],
            (string) $row['name'],
            $row['owner_id'] === null ? null : (string) $row['owner_id'],
            (string) ($row['owner_name'] ?? ''),
            $row['archived_at'] === null,
        ), $rows);
    }

    public function activity(string $ownerId): array
    {
        $rows = $this->fetch(
            'SELECT r.invoice_id, r.property_id, r.month, r.platform, COUNT(*) AS reservations, CAST(SUM(r.nights) AS SIGNED) AS nights,
                    CAST(ROUND(SUM(r.total_payout) * 100) AS SIGNED) AS payout,
                    CAST(ROUND(SUM(r.channel_commission + r.payment_fee) * 100) AS SIGNED) AS platform_fee,
                    CAST(ROUND(SUM(r.tourist_tax) * 100) AS SIGNED) AS tourist_tax
             FROM invoice_reservations r
             JOIN invoices i ON i.tenant_id = r.tenant_id AND i.id = r.invoice_id
             WHERE r.tenant_id = :tenant AND i.owner_id = :owner AND i.cancelled_at IS NULL
             GROUP BY r.invoice_id, r.property_id, r.month, r.platform',
            ['owner' => $ownerId],
        );

        return array_map(static fn(array $row): StatsActivity => new StatsActivity(
            (string) $row['invoice_id'],
            (string) $row['property_id'],
            (string) $row['month'],
            (string) $row['platform'],
            (int) $row['reservations'],
            (int) $row['nights'],
            (int) $row['payout'],
            (int) $row['platform_fee'],
            (int) $row['tourist_tax'],
        ), $rows);
    }

    public function billed(string $ownerId): array
    {
        // Les lignes `platform_commission` répètent la commission déjà comptée dans `invoice_reservations.channel_commission`.
        $rows = $this->fetch(
            "SELECT l.invoice_id, l.property_id, l.month, l.category, CAST(ROUND(SUM(l.price_ttc) * 100) AS SIGNED) AS ttc
             FROM invoice_lines l
             JOIN invoices i ON i.tenant_id = l.tenant_id AND i.id = l.invoice_id
             WHERE l.tenant_id = :tenant AND i.owner_id = :owner AND i.cancelled_at IS NULL AND l.kind <> 'platform_commission'
             GROUP BY l.invoice_id, l.property_id, l.month, l.category",
            ['owner' => $ownerId],
        );

        return array_map(static fn(array $row): StatsBilled => new StatsBilled(
            (string) $row['invoice_id'],
            $row['property_id'] === null ? null : (string) $row['property_id'],
            (string) $row['month'],
            StatsPoste::of(ExtraType::from((string) $row['category'])),
            (int) $row['ttc'],
        ), $rows);
    }

    public function ownerNights(string $ownerId): array
    {
        $rows = $this->fetch(
            "SELECT property_id, check_out, nights
             FROM reservations
             WHERE tenant_id = :tenant AND owner_id = :owner AND platform = 'owner' AND archived_at IS NULL",
            ['owner' => $ownerId],
        );

        return array_map(static fn(array $row): OwnerNights => new OwnerNights(
            (string) $row['property_id'],
            BillingMonth::of(DbDate::fromDb($row['check_out'])),
            (int) $row['nights'],
        ), $rows);
    }

    /**
     * @param non-empty-string $sql requête écrite ici, bornée par `:tenant`
     * @param array<string, string> $params
     * @return list<array<array-key, mixed>>
     */
    private function fetch(string $sql, array $params): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['tenant' => $this->tenant->id()] + $params);

        return array_values($statement->fetchAll());
    }
}

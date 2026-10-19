<?php

declare(strict_types=1);

namespace Casamiza\Domain\Audit;

use Psr\Clock\ClockInterface;

/** Lecture du journal du tenant courant et purge de l'historique ancien. */
final readonly class AuditService
{
    /** Durée de conservation par défaut (RGPD), en mois. */
    public const RETENTION_MONTHS = 13;

    public function __construct(
        private AuditLogRepositoryInterface $entries,
        private ClockInterface $clock,
    ) {}

    /** @return array{items: list<AuditEntry>, total: int} */
    public function search(AuditFilter $filter): array
    {
        return $this->entries->search($filter);
    }

    /** Supprime, pour tous les tenants, les lignes plus vieilles que `$months` mois. @return int lignes supprimées */
    public function purge(int $months = self::RETENTION_MONTHS): int
    {
        return $this->entries->purgeBefore($this->clock->now()->modify("-{$months} months"));
    }
}

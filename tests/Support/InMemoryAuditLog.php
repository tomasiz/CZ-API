<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditEntry;
use Casamiza\Domain\Audit\AuditFilter;
use Casamiza\Domain\Audit\AuditLogRepositoryInterface;
use Casamiza\Shared\TenantContext;
use DateTimeImmutable;

/** Journal d'audit en mémoire : même contrat que le dépôt PDO (écriture avec tenant explicite, lecture bornée au tenant courant). */
final class InMemoryAuditLog implements AuditLogRepositoryInterface
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    public function __construct(private readonly TenantContext $tenant) {}

    public function add(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function search(AuditFilter $filter): array
    {
        $tenantId = $this->tenant->id();
        $matches = array_values(array_filter($this->entries, static fn(AuditEntry $e): bool => $e->tenantId === $tenantId
            && ($filter->actions === [] || in_array($e->action, $filter->actions, true))
            && ($filter->actorId === null || $e->actorUserId === $filter->actorId)
            && ($filter->targetType === null || $e->targetType === $filter->targetType)
            && ($filter->from === null || $e->occurredAt >= $filter->from)
            && ($filter->to === null || $e->occurredAt < $filter->to)));
        usort($matches, static fn(AuditEntry $a, AuditEntry $b): int => [$b->occurredAt, $b->id] <=> [$a->occurredAt, $a->id]);

        return [
            'items' => array_slice($matches, ($filter->page - 1) * $filter->perPage, $filter->perPage),
            'total' => count($matches),
        ];
    }

    public function purgeBefore(DateTimeImmutable $before): int
    {
        $kept = array_values(array_filter($this->entries, static fn(AuditEntry $e): bool => $e->occurredAt >= $before));
        $removed = count($this->entries) - count($kept);
        $this->entries = $kept;

        return $removed;
    }

    /** @return list<AuditAction> actions écrites, dans l'ordre (tous tenants) */
    public function actions(): array
    {
        return array_map(static fn(AuditEntry $e): AuditAction => $e->action, $this->entries);
    }

    /** @return list<AuditEntry> */
    public function of(AuditAction $action): array
    {
        return array_values(array_filter($this->entries, static fn(AuditEntry $e): bool => $e->action === $action));
    }
}

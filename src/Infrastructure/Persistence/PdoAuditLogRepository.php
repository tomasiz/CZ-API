<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditEntry;
use Casamiza\Domain\Audit\AuditFilter;
use Casamiza\Domain\Audit\AuditLogRepositoryInterface;
use Casamiza\Shared\TenantContext;
use DateTimeImmutable;

/**
 * Exception assumée au `BaseRepository` : l'écriture porte son tenant dans l'entrée (une tentative de connexion n'a pas
 * de contexte de tenant) et la purge traverse les tenants. La **lecture**, elle, est toujours bornée au tenant courant.
 */
final readonly class PdoAuditLogRepository implements AuditLogRepositoryInterface
{
    public function __construct(private Database $database, private TenantContext $tenant) {}

    public function add(AuditEntry $entry): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO audit_log (id, tenant_id, occurred_at, actor_user_id, actor_label, action, target_type, target_id, target_label, ip, request_id, context)
             VALUES (:id, :tenant, :at, :actor, :label, :action, :type, :target, :target_label, :ip, :request, :context)',
        )->execute([
            'id' => $entry->id,
            'tenant' => $entry->tenantId,
            'at' => DbDate::toDb($entry->occurredAt),
            'actor' => $entry->actorUserId,
            'label' => $entry->actorLabel,
            'action' => $entry->action->value,
            'type' => $entry->targetType,
            'target' => $entry->targetId,
            'target_label' => $entry->targetLabel,
            'ip' => $entry->ip,
            'request' => $entry->requestId,
            'context' => $entry->context === [] ? null : json_encode($entry->context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function search(AuditFilter $filter): array
    {
        $where = ['tenant_id = :tenant'];
        $params = ['tenant' => $this->tenant->id()];

        if ($filter->actions !== []) {
            $marks = [];
            foreach ($filter->actions as $i => $action) {
                $marks[] = ':action' . $i;
                $params['action' . $i] = $action->value;
            }
            $where[] = 'action IN (' . implode(', ', $marks) . ')';
        }
        if ($filter->actorId !== null) {
            $where[] = 'actor_user_id = :actor';
            $params['actor'] = $filter->actorId;
        }
        if ($filter->targetType !== null) {
            $where[] = 'target_type = :type';
            $params['type'] = $filter->targetType;
        }
        if ($filter->from !== null) {
            $where[] = 'occurred_at >= :from';
            $params['from'] = DbDate::toDb($filter->from);
        }
        if ($filter->to !== null) {
            $where[] = 'occurred_at < :to';
            $params['to'] = DbDate::toDb($filter->to);
        }
        $clause = implode(' AND ', $where);

        $count = $this->database->pdo()->prepare("SELECT COUNT(*) FROM audit_log WHERE {$clause}");
        $count->execute($params);

        $offset = ($filter->page - 1) * $filter->perPage;
        $select = $this->database->pdo()->prepare(
            "SELECT * FROM audit_log WHERE {$clause} ORDER BY occurred_at DESC, id DESC LIMIT {$filter->perPage} OFFSET {$offset}",
        );
        $select->execute($params);

        $items = [];
        foreach ($select->fetchAll() as $row) {
            $items[] = $this->hydrate($row);
        }

        return ['items' => $items, 'total' => (int) $count->fetchColumn()];
    }

    public function purgeBefore(DateTimeImmutable $before): int
    {
        $statement = $this->database->pdo()->prepare('DELETE FROM audit_log WHERE occurred_at < :before');
        $statement->execute(['before' => DbDate::toDb($before)]);

        return $statement->rowCount();
    }

    private function hydrate(mixed $row): AuditEntry
    {
        if (!is_array($row)) {
            throw new \UnexpectedValueException('Ligne d\'audit illisible.');
        }
        $context = is_string($row['context'] ?? null) ? json_decode($row['context'], true) : [];

        return new AuditEntry(
            (string) $row['id'],
            (string) $row['tenant_id'],
            DbDate::fromDb($row['occurred_at']),
            AuditAction::from((string) $row['action']),
            self::nullable($row['actor_user_id']),
            self::nullable($row['actor_label']),
            self::nullable($row['target_type']),
            self::nullable($row['target_id']),
            self::nullable($row['target_label']),
            self::nullable($row['ip']),
            self::nullable($row['request_id']),
            is_array($context) ? $context : [],
        );
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}

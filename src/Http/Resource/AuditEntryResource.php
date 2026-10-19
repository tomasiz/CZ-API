<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Audit\AuditEntry;
use DateTimeInterface;

/** Représentation JSON d'une ligne du journal d'audit. */
final class AuditEntryResource
{
    /**
     * @param array{items: list<AuditEntry>, total: int} $result
     * @return array<string, mixed>
     */
    public static function page(array $result, int $page, int $perPage): array
    {
        return [
            'items' => array_map(self::toArray(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /** @return array<string, mixed> */
    public static function toArray(AuditEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'occurredAt' => $entry->occurredAt->format(DateTimeInterface::ATOM),
            'action' => $entry->action->value,
            'family' => $entry->action->family()->value,
            'actorId' => $entry->actorUserId,
            'actorLabel' => $entry->actorLabel,
            'targetType' => $entry->targetType,
            'targetId' => $entry->targetId,
            'targetLabel' => $entry->targetLabel,
            'ip' => $entry->ip,
            'context' => (object) $entry->context,
        ];
    }
}

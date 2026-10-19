<?php

declare(strict_types=1);

namespace Casamiza\Domain\Audit;

use DateTimeImmutable;

/** Une ligne du journal : qui, quoi, sur quoi, quand et d'où. `context` ne contient que des identifiants et des codes. */
final readonly class AuditEntry
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public DateTimeImmutable $occurredAt,
        public AuditAction $action,
        public ?string $actorUserId = null,
        public ?string $actorLabel = null,
        public ?string $targetType = null,
        public ?string $targetId = null,
        public ?string $targetLabel = null,
        public ?string $ip = null,
        public ?string $requestId = null,
        public array $context = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace Casamiza\Domain\Audit;

use DateTimeImmutable;

/** Critères de recherche du journal (tous facultatifs). `from` inclus, `to` exclu (bornes déjà converties en UTC). */
final readonly class AuditFilter
{
    /**
     * @param list<AuditAction> $actions
     */
    public function __construct(
        public array $actions = [],
        public ?string $actorId = null,
        public ?string $targetType = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public int $page = 1,
        public int $perPage = 50,
    ) {}
}

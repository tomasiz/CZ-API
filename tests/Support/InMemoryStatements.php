<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Statement\Statement;
use Casamiza\Domain\Statement\StatementRepositoryInterface;
use Casamiza\Shared\TenantContext;

/** Mentions mensuelles en mémoire, bornées au tenant courant comme en base. */
final class InMemoryStatements implements StatementRepositoryInterface
{
    /** @var array<string, Statement> clé « tenant|propriétaire|mois » */
    public array $rows = [];

    public function __construct(private readonly TenantContext $context) {}

    public function where(?string $ownerId, ?string $month): array
    {
        $prefix = $this->context->id() . '|';

        return array_values(array_filter(
            $this->rows,
            static fn(Statement $s, string $key): bool => str_starts_with($key, $prefix)
                && ($ownerId === null || $s->ownerId === $ownerId)
                && ($month === null || $s->month === $month),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public function find(string $ownerId, string $month): ?Statement
    {
        return $this->rows[$this->context->id() . '|' . $ownerId . '|' . $month] ?? null;
    }

    public function save(Statement $statement): void
    {
        $this->rows[$this->context->id() . '|' . $statement->ownerId . '|' . $statement->month] = $statement;
    }
}

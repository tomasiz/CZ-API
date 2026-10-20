<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Cost\BeyondPeriod;
use Casamiza\Domain\Cost\BeyondStatement;
use Casamiza\Domain\Cost\BeyondStatementRepositoryInterface;
use Casamiza\Shared\TenantContext;
use DateTimeImmutable;

/** Relevés Beyond traités en mémoire, bornés au tenant courant. */
final class InMemoryBeyondStatements implements BeyondStatementRepositoryInterface
{
    /** @var array<string, BeyondStatement> clé « tenant|AAAA-MM-JJ du début » */
    public array $statements = [];

    public function __construct(private readonly TenantContext $context) {}

    public function find(BeyondPeriod $period): ?BeyondStatement
    {
        return $this->statements[$this->context->id() . '|' . $period->key()] ?? null;
    }

    public function startingBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $prefix = $this->context->id() . '|';
        $found = array_values(array_filter(
            $this->statements,
            static fn(BeyondStatement $s, string $key): bool => str_starts_with($key, $prefix) && $s->period->key() >= $from->format('Y-m-d') && $s->period->key() < $to->format('Y-m-d'),
            ARRAY_FILTER_USE_BOTH,
        ));
        usort($found, static fn(BeyondStatement $a, BeyondStatement $b): int => $a->period->key() <=> $b->period->key());

        return $found;
    }

    public function save(BeyondStatement $statement): void
    {
        $this->statements[$this->context->id() . '|' . $statement->period->key()] = $statement;
    }

    public function count(): int
    {
        $prefix = $this->context->id() . '|';

        return count(array_filter($this->statements, static fn(string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY));
    }
}

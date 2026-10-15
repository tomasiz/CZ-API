<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Extra\Extra;
use Casamiza\Domain\Extra\ExtraRepositoryInterface;
use Casamiza\Shared\TenantContext;

/** Prestations en mémoire, bornées au tenant courant comme en base. */
final class InMemoryExtras implements ExtraRepositoryInterface
{
    /** @var array<string, Extra> clé « tenant|id » (les prestations supprimées en sont retirées) */
    public array $rows = [];

    /** @var list<string> clés des prestations supprimées */
    public array $removed = [];

    public function __construct(private readonly TenantContext $context) {}

    public function forOwnerMonth(string $ownerId, string $month): array
    {
        $prefix = $this->context->id() . '|';
        $found = array_values(array_filter(
            $this->rows,
            static fn(Extra $e, string $key): bool => str_starts_with($key, $prefix) && $e->ownerId === $ownerId && $e->month === $month,
            ARRAY_FILTER_USE_BOTH,
        ));
        usort($found, static fn(Extra $a, Extra $b): int => $a->id <=> $b->id);

        return $found;
    }

    public function find(string $id): ?Extra
    {
        return $this->rows[$this->context->id() . '|' . $id] ?? null;
    }

    /** @var array<string, array<int, true>> identifiants d'origine par tenant */
    public array $legacy = [];

    public function addLegacy(Extra $extra, int $legacyId): void
    {
        $this->add($extra);
        $this->legacy[$this->context->id()][$legacyId] = true;
    }

    public function legacyIds(): array
    {
        return $this->legacy[$this->context->id()] ?? [];
    }

    public function add(Extra $extra): void
    {
        $this->rows[$this->context->id() . '|' . $extra->id] = $extra;
    }

    public function update(Extra $extra): void
    {
        $key = $this->context->id() . '|' . $extra->id;
        if (isset($this->rows[$key])) {
            $this->rows[$key] = $extra;
        }
    }

    public function remove(string $id): void
    {
        $key = $this->context->id() . '|' . $id;
        unset($this->rows[$key]);
        $this->removed[] = $key;
    }
}

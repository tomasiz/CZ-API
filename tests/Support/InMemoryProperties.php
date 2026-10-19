<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Property\Property;
use Casamiza\Domain\Property\PropertyRepositoryInterface;
use Casamiza\Shared\TenantContext;

/** Logements en mémoire, bornés au tenant courant comme en base. */
final class InMemoryProperties implements PropertyRepositoryInterface
{
    /** @var array<string, Property> clé « tenant|id » */
    public array $rows = [];

    public function __construct(private readonly TenantContext $context) {}

    public function all(): array
    {
        $prefix = $this->context->id() . '|';

        return array_values(array_filter($this->rows, fn(string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY));
    }

    public function find(string $id): ?Property
    {
        return $this->rows[$this->context->id() . '|' . $id] ?? null;
    }

    public function add(Property $property): void
    {
        $this->rows[$this->context->id() . '|' . $property->id] = $property;
    }

    public function update(Property $property): void
    {
        $this->rows[$this->context->id() . '|' . $property->id] = $property;
    }

    public function byGuestyId(string $tenantId, string $guestyId): ?Property
    {
        foreach ($this->rows as $key => $property) {
            if (str_starts_with($key, $tenantId . '|') && $property->guestyId === $guestyId) {
                return $property;
            }
        }

        return null;
    }
}

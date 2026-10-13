<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerRepositoryInterface;
use Casamiza\Shared\TenantContext;

/** Propriétaires en mémoire, bornés au tenant courant comme en base. */
final class InMemoryOwners implements OwnerRepositoryInterface
{
    /** @var array<string, Owner> clé « tenant|id » */
    public array $rows = [];

    public function __construct(private readonly TenantContext $context) {}

    public function all(): array
    {
        $prefix = $this->context->id() . '|';

        return array_values(array_filter($this->rows, fn(string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY));
    }

    public function find(string $id): ?Owner
    {
        return $this->rows[$this->context->id() . '|' . $id] ?? null;
    }

    public function add(Owner $owner): void
    {
        $this->rows[$this->context->id() . '|' . $owner->id] = $owner;
    }

    /** Comme en base : les champs issus de Guesty sont réécrits, les champs locaux restent ceux déjà enregistrés. */
    public function update(Owner $owner): void
    {
        $key = $this->context->id() . '|' . $owner->id;
        $stored = $this->rows[$key] ?? null;
        if ($stored === null) {
            return;
        }
        $this->rows[$key] = $owner->withLocalFields($stored->companyName, $stored->siret)->withStripeSecret($stored->stripeSecretEncrypted);
    }

    /** Comme en base : seuls les champs locaux sont réécrits. */
    public function updateLocal(Owner $owner): void
    {
        $key = $this->context->id() . '|' . $owner->id;
        $stored = $this->rows[$key] ?? null;
        if ($stored === null) {
            return;
        }
        $this->rows[$key] = $stored->withLocalFields($owner->companyName, $owner->siret)->withStripeSecret($owner->stripeSecretEncrypted);
    }

    public function byGuestyId(string $tenantId, string $guestyId): ?Owner
    {
        foreach ($this->rows as $key => $owner) {
            if (str_starts_with($key, $tenantId . '|') && $owner->guestyId === $guestyId) {
                return $owner;
            }
        }

        return null;
    }
}

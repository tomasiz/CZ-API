<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Integration\Integration;
use Casamiza\Domain\Integration\IntegrationRepositoryInterface;
use Casamiza\Domain\Integration\Provider;
use Casamiza\Shared\TenantContext;

/** Configurations en mémoire, bornées au tenant courant comme en base. */
final class InMemoryIntegrations implements IntegrationRepositoryInterface
{
    /** @var array<string, Integration> clé « tenant|provider » */
    public array $rows = [];

    public function __construct(private readonly TenantContext $context) {}

    public function all(): array
    {
        $prefix = $this->context->id() . '|';

        return array_values(array_filter($this->rows, fn(string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY));
    }

    public function find(Provider $provider, bool $lock = false): ?Integration
    {
        return $this->rows[$this->key($provider)] ?? null;
    }

    public function save(Integration $integration): void
    {
        $this->rows[$this->key($integration->provider)] = $integration;
    }

    public function delete(Provider $provider): void
    {
        unset($this->rows[$this->key($provider)]);
    }

    private function key(Provider $provider): string
    {
        return $this->context->id() . '|' . $provider->value;
    }
}

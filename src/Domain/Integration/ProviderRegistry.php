<?php

declare(strict_types=1);

namespace Casamiza\Domain\Integration;

use Casamiza\Shared\Exception\NotFoundException;

/** Services tiers pris en charge par l'API. */
final readonly class ProviderRegistry
{
    /** @var array<string, IntegrationProviderInterface> */
    private array $providers;

    /** @param iterable<IntegrationProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        $byName = [];
        foreach ($providers as $provider) {
            $byName[$provider->provider()->value] = $provider;
        }
        $this->providers = $byName;
    }

    /** @return list<IntegrationProviderInterface> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /** @throws NotFoundException `provider_not_found` : nom inconnu ou service pas encore pris en charge */
    public function get(string $name): IntegrationProviderInterface
    {
        return $this->providers[$name] ?? throw new NotFoundException('provider_not_found');
    }
}

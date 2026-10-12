<?php

declare(strict_types=1);

namespace Casamiza\Domain\Integration;

/** Un service tiers et sa configuration pour le tenant (null tant qu'il n'est pas configuré). */
final readonly class IntegrationEntry
{
    public function __construct(
        public IntegrationProviderInterface $provider,
        public ?Integration $integration,
    ) {}
}

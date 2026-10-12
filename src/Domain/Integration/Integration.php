<?php

declare(strict_types=1);

namespace Casamiza\Domain\Integration;

use DateTimeImmutable;

/** Configuration d'un service tiers pour un tenant. Les secrets restent chiffrés : seul IntegrationService les ouvre. */
final readonly class Integration
{
    /**
     * @param array<string, string> $settings réglages non secrets
     */
    public function __construct(
        public string $id,
        public Provider $provider,
        public string $credentialsEncrypted,
        public array $settings,
        public ?string $tokenEncrypted,
        public ?DateTimeImmutable $tokenExpiresAt,
        public IntegrationStatus $status,
        public ?string $lastErrorCode,
        public ?DateTimeImmutable $lastCheckedAt,
        public DateTimeImmutable $configuredAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace Casamiza\Http\Resource;

use Casamiza\Domain\Integration\IntegrationEntry;
use DateTimeInterface;

/** Représentation JSON d'un service tiers : jamais de secret ni de jeton, seulement des réglages non secrets. */
final class IntegrationResource
{
    /** @return array<string, mixed> */
    public static function toArray(IntegrationEntry $entry): array
    {
        $integration = $entry->integration;

        return [
            'provider' => $entry->provider->provider()->value,
            'configured' => $integration !== null,
            'status' => $integration?->status->value,
            'lastErrorCode' => $integration?->lastErrorCode,
            'lastCheckedAt' => $integration?->lastCheckedAt?->format(DateTimeInterface::ATOM),
            'settings' => (object) ($integration->settings ?? []),
        ];
    }
}

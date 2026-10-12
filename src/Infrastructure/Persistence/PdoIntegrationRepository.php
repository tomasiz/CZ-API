<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Integration\Integration;
use Casamiza\Domain\Integration\IntegrationRepositoryInterface;
use Casamiza\Domain\Integration\IntegrationStatus;
use Casamiza\Domain\Integration\Provider;
use Casamiza\Shared\Exception\ConflictException;
use PDOException;

final class PdoIntegrationRepository extends BaseRepository implements IntegrationRepositoryInterface
{
    protected function table(): string
    {
        return 'integrations';
    }

    public function all(): array
    {
        return array_map($this->hydrate(...), $this->select(suffix: 'ORDER BY provider'));
    }

    public function find(Provider $provider, bool $lock = false): ?Integration
    {
        $rows = $this->select('*', 'provider = :provider', ['provider' => $provider->value], 'LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));

        return isset($rows[0]) ? $this->hydrate($rows[0]) : null;
    }

    public function save(Integration $integration): void
    {
        $values = [
            'credentials_encrypted' => $integration->credentialsEncrypted,
            'settings' => json_encode($integration->settings, JSON_THROW_ON_ERROR),
            'token_encrypted' => $integration->tokenEncrypted,
            'token_expires_at' => $integration->tokenExpiresAt === null ? null : DbDate::toDb($integration->tokenExpiresAt),
            'status' => $integration->status->value,
            'last_error_code' => $integration->lastErrorCode,
            'last_checked_at' => $integration->lastCheckedAt === null ? null : DbDate::toDb($integration->lastCheckedAt),
            'configured_at' => DbDate::toDb($integration->configuredAt),
        ];

        if ($this->findRow($integration->id) !== null) {
            $this->updateRow($integration->id, $values);

            return;
        }
        try {
            $this->insertRow(['id' => $integration->id, 'provider' => $integration->provider->value] + $values);
        } catch (PDOException $e) {
            // 1062 : deux enregistrements simultanés du même service.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new ConflictException();
            }
            throw $e;
        }
    }

    public function delete(Provider $provider): void
    {
        $row = $this->selectOne('provider = :provider', ['provider' => $provider->value], 'id');
        if ($row !== null) {
            $this->deleteRow((string) $row['id']);
        }
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Integration
    {
        $settings = json_decode((string) ($row['settings'] ?? '{}'), true);

        return new Integration(
            id: (string) $row['id'],
            provider: Provider::from((string) $row['provider']),
            credentialsEncrypted: (string) $row['credentials_encrypted'],
            settings: is_array($settings) ? array_map(strval(...), $settings) : [],
            tokenEncrypted: $row['token_encrypted'] === null ? null : (string) $row['token_encrypted'],
            tokenExpiresAt: DbDate::fromDbOrNull($row['token_expires_at']),
            status: IntegrationStatus::from((string) $row['status']),
            lastErrorCode: $row['last_error_code'] === null ? null : (string) $row['last_error_code'],
            lastCheckedAt: DbDate::fromDbOrNull($row['last_checked_at']),
            configuredAt: DbDate::fromDb($row['configured_at']),
        );
    }
}

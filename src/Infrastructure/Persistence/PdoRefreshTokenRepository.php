<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Auth\RefreshToken;
use Casamiza\Domain\Auth\RefreshTokenRepositoryInterface;
use DateTimeImmutable;

final readonly class PdoRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(private Database $database) {}

    public function add(RefreshToken $token): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO refresh_tokens (id, tenant_id, user_id, family_id, token_hash, expires_at, session_expires_at)
             VALUES (:id, :tenant, :user, :family, :hash, :expires, :session)',
        )->execute([
            'id' => $token->id,
            'tenant' => $token->tenantId,
            'user' => $token->userId,
            'family' => $token->familyId,
            'hash' => $token->tokenHash,
            'expires' => DbDate::toDb($token->expiresAt),
            'session' => DbDate::toDb($token->sessionExpiresAt),
        ]);
    }

    public function findByHash(string $hash): ?RefreshToken
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM refresh_tokens WHERE token_hash = :hash');
        $statement->execute(['hash' => $hash]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new RefreshToken(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            userId: (string) $row['user_id'],
            familyId: (string) $row['family_id'],
            tokenHash: (string) $row['token_hash'],
            expiresAt: DbDate::fromDb($row['expires_at']),
            sessionExpiresAt: DbDate::fromDb($row['session_expires_at']),
            usedAt: DbDate::fromDbOrNull($row['used_at']),
            revokedAt: DbDate::fromDbOrNull($row['revoked_at']),
        );
    }

    public function markUsed(string $id, DateTimeImmutable $at): bool
    {
        // Atomique : si deux requêtes présentent le même jeton, une seule met à jour la ligne.
        $statement = $this->database->pdo()->prepare(
            'UPDATE refresh_tokens SET used_at = :at WHERE id = :id AND used_at IS NULL AND revoked_at IS NULL',
        );
        $statement->execute(['at' => DbDate::toDb($at), 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function revokeFamily(string $familyId, DateTimeImmutable $at): void
    {
        $this->database->pdo()
            ->prepare('UPDATE refresh_tokens SET revoked_at = :at WHERE family_id = :family AND revoked_at IS NULL')
            ->execute(['at' => DbDate::toDb($at), 'family' => $familyId]);
    }

    public function revokeAllForUser(string $tenantId, string $userId, DateTimeImmutable $at): void
    {
        $this->database->pdo()
            ->prepare('UPDATE refresh_tokens SET revoked_at = :at WHERE tenant_id = :tenant AND user_id = :user AND revoked_at IS NULL')
            ->execute(['at' => DbDate::toDb($at), 'tenant' => $tenantId, 'user' => $userId]);
    }
}

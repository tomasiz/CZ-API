<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Persistence;

use Casamiza\Domain\Team\TokenPurpose;
use Casamiza\Domain\Team\UserToken;
use Casamiza\Domain\Team\UserTokenRepositoryInterface;
use DateTimeImmutable;

final readonly class PdoUserTokenRepository implements UserTokenRepositoryInterface
{
    public function __construct(private Database $database) {}

    public function add(UserToken $token): void
    {
        $this->database->pdo()->prepare(
            'INSERT INTO user_tokens (id, tenant_id, user_id, purpose, token_hash, expires_at)
             VALUES (:id, :tenant, :user, :purpose, :hash, :expires)',
        )->execute([
            'id' => $token->id,
            'tenant' => $token->tenantId,
            'user' => $token->userId,
            'purpose' => $token->purpose->value,
            'hash' => $token->tokenHash,
            'expires' => DbDate::toDb($token->expiresAt),
        ]);
    }

    public function findByHash(string $hash): ?UserToken
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM user_tokens WHERE token_hash = :hash');
        $statement->execute(['hash' => $hash]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new UserToken(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            userId: (string) $row['user_id'],
            purpose: TokenPurpose::from((string) $row['purpose']),
            tokenHash: (string) $row['token_hash'],
            expiresAt: DbDate::fromDb($row['expires_at']),
            usedAt: DbDate::fromDbOrNull($row['used_at']),
        );
    }

    public function markUsed(string $id, DateTimeImmutable $at): bool
    {
        $statement = $this->database->pdo()->prepare('UPDATE user_tokens SET used_at = :at WHERE id = :id AND used_at IS NULL');
        $statement->execute(['at' => DbDate::toDb($at), 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function invalidateForUser(string $tenantId, string $userId, TokenPurpose $purpose, DateTimeImmutable $at): void
    {
        $this->database->pdo()->prepare(
            'UPDATE user_tokens SET used_at = :at
             WHERE tenant_id = :tenant AND user_id = :user AND purpose = :purpose AND used_at IS NULL',
        )->execute([
            'at' => DbDate::toDb($at),
            'tenant' => $tenantId,
            'user' => $userId,
            'purpose' => $purpose->value,
        ]);
    }
}

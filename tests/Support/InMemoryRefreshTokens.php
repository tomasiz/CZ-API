<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Auth\RefreshToken;
use Casamiza\Domain\Auth\RefreshTokenRepositoryInterface;
use DateTimeImmutable;

final class InMemoryRefreshTokens implements RefreshTokenRepositoryInterface
{
    /** @var array<string, RefreshToken> indexés par id */
    public array $tokens = [];

    public function add(RefreshToken $token): void
    {
        $this->tokens[$token->id] = $token;
    }

    public function findByHash(string $hash): ?RefreshToken
    {
        foreach ($this->tokens as $token) {
            if ($token->tokenHash === $hash) {
                return $token;
            }
        }

        return null;
    }

    public function markUsed(string $id, DateTimeImmutable $at): bool
    {
        $t = $this->tokens[$id] ?? null;
        if ($t === null || $t->usedAt !== null || $t->revokedAt !== null) {
            return false;
        }
        $this->tokens[$id] = new RefreshToken($t->id, $t->tenantId, $t->userId, $t->familyId, $t->tokenHash, $t->expiresAt, $t->sessionExpiresAt, $at, null);

        return true;
    }

    public function revokeAllForUser(string $tenantId, string $userId, DateTimeImmutable $at): void
    {
        foreach ($this->tokens as $id => $t) {
            if ($t->tenantId === $tenantId && $t->userId === $userId && $t->revokedAt === null) {
                $this->tokens[$id] = new RefreshToken($t->id, $t->tenantId, $t->userId, $t->familyId, $t->tokenHash, $t->expiresAt, $t->sessionExpiresAt, $t->usedAt, $at);
            }
        }
    }

    public function revokeFamily(string $familyId, DateTimeImmutable $at): void
    {
        foreach ($this->tokens as $id => $t) {
            if ($t->familyId === $familyId && $t->revokedAt === null) {
                $this->tokens[$id] = new RefreshToken($t->id, $t->tenantId, $t->userId, $t->familyId, $t->tokenHash, $t->expiresAt, $t->sessionExpiresAt, $t->usedAt, $at);
            }
        }
    }
}

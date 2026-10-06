<?php

declare(strict_types=1);

namespace Casamiza\Tests\Support;

use Casamiza\Domain\Team\UserToken;
use Casamiza\Domain\Team\UserTokenRepositoryInterface;
use DateTimeImmutable;

final class InMemoryUserTokens implements UserTokenRepositoryInterface
{
    /** @var array<string, UserToken> */
    public array $tokens = [];

    public function add(UserToken $token): void
    {
        $this->tokens[$token->id] = $token;
    }

    public function findByHash(string $hash): ?UserToken
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
        if ($t === null || $t->usedAt !== null) {
            return false;
        }
        $this->tokens[$id] = new UserToken($t->id, $t->tenantId, $t->userId, $t->purpose, $t->tokenHash, $t->expiresAt, $at);

        return true;
    }
}

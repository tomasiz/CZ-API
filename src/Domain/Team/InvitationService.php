<?php

declare(strict_types=1);

namespace Casamiza\Domain\Team;

use Casamiza\Domain\Auth\AuthUserRepositoryInterface;
use Casamiza\Domain\User\PasswordPolicy;
use Casamiza\Domain\User\Role;
use Casamiza\Domain\User\User;
use Casamiza\Domain\User\UserRepositoryInterface;
use Casamiza\Domain\User\UserStatus;
use Casamiza\Infrastructure\Security\PasswordHasher;
use Casamiza\Shared\Exception\UnprocessableException;
use Casamiza\Shared\TenantContext;
use Casamiza\Shared\Transaction;
use DateInterval;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/** Invitation d'un membre et activation de son compte (il choisit alors son mot de passe). */
final readonly class InvitationService
{
    private const VALIDITY = 'P7D';

    public function __construct(
        private UserRepositoryInterface $users,
        private AuthUserRepositoryInterface $authUsers,
        private UserTokenRepositoryInterface $tokens,
        private InvitationNotifierInterface $notifier,
        private PasswordHasher $hasher,
        private Transaction $transaction,
        private TenantContext $tenant,
        private ClockInterface $clock,
    ) {}

    /** @throws \Casamiza\Shared\Exception\ConflictException `email_already_used` */
    public function invite(string $firstName, string $lastName, string $email, Role $role): User
    {
        $user = new User(
            Uuid::uuid7()->toString(),
            $this->tenant->id(),
            $firstName,
            $lastName,
            strtolower($email),
            null,
            $role,
            status: UserStatus::Invited,
        );
        $token = bin2hex(random_bytes(32));

        $this->transaction->run(function () use ($user, $token): void {
            $this->users->add($user);
            $this->tokens->add(new UserToken(
                Uuid::uuid7()->toString(),
                $user->tenantId,
                $user->id,
                TokenPurpose::Activation,
                self::hash($token),
                $this->clock->now()->add(new DateInterval(self::VALIDITY)),
            ));
        });

        $this->notifier->notify($user, $token);

        return $user;
    }

    /** @throws UnprocessableException `invalid_activation_token` */
    public function activate(string $plainToken, string $password): void
    {
        PasswordPolicy::assert($password);

        $now = $this->clock->now();
        $token = $this->tokens->findByHash(self::hash($plainToken));
        if (
            $token === null
            || $token->purpose !== TokenPurpose::Activation
            || $token->usedAt !== null
            || $token->expiresAt <= $now
            || !$this->tokens->markUsed($token->id, $now)
        ) {
            throw new UnprocessableException('invalid_activation_token');
        }

        $this->authUsers->activate($token->tenantId, $token->userId, $this->hasher->hash($password));
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}

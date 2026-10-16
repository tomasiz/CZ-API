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
use Casamiza\Shared\Exception\ConflictException;
use Casamiza\Shared\Exception\NotFoundException;
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

    /**
     * Le lien est envoyé par e-mail au membre (`emailSent` dit si l'envoi a réussi). L'invitation renvoyée porte aussi le
     * jeton en clair : l'administrateur peut transmettre le lien lui-même si l'e-mail n'est pas parti.
     *
     * @throws \Casamiza\Shared\Exception\ConflictException `email_already_used`
     */
    public function invite(string $firstName, string $lastName, string $email, Role $role): Invitation
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

        $invitation = $this->transaction->run(function () use ($user): Invitation {
            $this->users->add($user);

            return $this->issueToken($user);
        });

        return $invitation->withEmailSent($this->notifier->notify($user, $invitation->token));
    }

    /**
     * Génère un nouveau lien pour un membre qui n'a pas encore activé son compte ; les liens précédents cessent de
     * fonctionner.
     *
     * @throws \Casamiza\Shared\Exception\NotFoundException `user_not_found`
     * @throws \Casamiza\Shared\Exception\ConflictException `member_already_active`
     */
    public function resend(string $userId): Invitation
    {
        $invitation = $this->transaction->run(function () use ($userId): Invitation {
            $user = $this->users->find($userId) ?? throw new NotFoundException('user_not_found');
            if ($user->status !== UserStatus::Invited) {
                throw new ConflictException('member_already_active');
            }
            $this->tokens->invalidateForUser($user->tenantId, $user->id, TokenPurpose::Activation, $this->clock->now());

            return $this->issueToken($user);
        });

        return $invitation->withEmailSent($this->notifier->notify($invitation->user, $invitation->token));
    }

    private function issueToken(User $user): Invitation
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = $this->clock->now()->add(new DateInterval(self::VALIDITY));

        $this->tokens->add(new UserToken(
            Uuid::uuid7()->toString(),
            $user->tenantId,
            $user->id,
            TokenPurpose::Activation,
            self::hash($token),
            $expiresAt,
        ));

        return new Invitation($user, $token, $expiresAt);
    }

    /**
     * Membre invité auquel appartient ce jeton, sans le consommer : la page d'activation peut ainsi accueillir
     * la personne et signaler tout de suite un lien invalide ou expiré.
     *
     * @throws UnprocessableException `invalid_activation_token`
     */
    public function inspect(string $plainToken): User
    {
        $token = $this->usableToken($plainToken);
        $user = $this->authUsers->findInTenant($token->tenantId, $token->userId);

        if ($user === null || $user->status !== UserStatus::Invited) {
            throw new UnprocessableException('invalid_activation_token');
        }

        return $user;
    }

    /** @throws UnprocessableException `invalid_activation_token` */
    public function activate(string $plainToken, string $password): void
    {
        PasswordPolicy::assert($password);

        $token = $this->usableToken($plainToken);
        if (!$this->tokens->markUsed($token->id, $this->clock->now())) {
            throw new UnprocessableException('invalid_activation_token');
        }

        $this->authUsers->activate($token->tenantId, $token->userId, $this->hasher->hash($password));
    }

    private function usableToken(string $plainToken): UserToken
    {
        $token = $this->tokens->findByHash(self::hash($plainToken));
        if (
            $token === null
            || $token->purpose !== TokenPurpose::Activation
            || $token->usedAt !== null
            || $token->expiresAt <= $this->clock->now()
        ) {
            throw new UnprocessableException('invalid_activation_token');
        }

        return $token;
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}

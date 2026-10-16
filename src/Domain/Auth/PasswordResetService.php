<?php

declare(strict_types=1);

namespace Casamiza\Domain\Auth;

use Casamiza\Domain\Team\TokenPurpose;
use Casamiza\Domain\Team\UserToken;
use Casamiza\Domain\Team\UserTokenRepositoryInterface;
use Casamiza\Domain\User\PasswordPolicy;
use Casamiza\Domain\User\User;
use Casamiza\Domain\User\UserStatus;
use Casamiza\Infrastructure\Security\PasswordHasher;
use Casamiza\Shared\AfterResponse;
use Casamiza\Shared\Exception\UnprocessableException;
use Casamiza\Shared\TenantContext;
use Casamiza\Shared\Transaction;
use DateInterval;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * « Mot de passe oublié » : un lien à usage unique (1 h) est envoyé par e-mail, puis l'utilisateur choisit un nouveau mot
 * de passe, ce qui **coupe toutes ses sessions**.
 *
 * - La demande répond toujours de la même façon, que l'adresse existe ou non, **et dans le même temps** : tout ce qui
 *   distingue les deux cas (jeton, envoi de l'e-mail) s'exécute après l'envoi de la réponse.
 * - Seul un compte actif d'un tenant actif reçoit un lien ; un membre invité passe par son invitation.
 */
final readonly class PasswordResetService
{
    private const VALIDITY = 'PT1H';

    public function __construct(
        private AuthUserRepositoryInterface $users,
        private UserTokenRepositoryInterface $tokens,
        private RefreshTokenRepositoryInterface $refreshTokens,
        private PasswordResetNotifierInterface $notifier,
        private PasswordResetThrottle $throttle,
        private PasswordHasher $hasher,
        private AfterResponse $afterResponse,
        private Transaction $transaction,
        private TenantContext $tenant,
        private ClockInterface $clock,
    ) {}

    /**
     * @throws \Casamiza\Shared\Exception\TooManyRequestsException `too_many_attempts`
     */
    public function request(string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        $this->throttle->hit($email, $ip);

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return;
        }

        $this->afterResponse->run(fn() => $this->issueAndSend($user));
    }

    /**
     * Choisit un nouveau mot de passe avec le lien reçu ; toutes les sessions du compte sont révoquées.
     *
     * @throws \Casamiza\Shared\Exception\ValidationException `password` (`too_short`, `too_long`) : le lien n'est alors pas consommé
     * @throws UnprocessableException `invalid_reset_token` (inconnu, déjà utilisé, expiré ou compte inactif)
     */
    public function reset(string $plainToken, string $password): void
    {
        PasswordPolicy::assert($password);

        $this->transaction->run(function () use ($plainToken, $password): void {
            $now = $this->clock->now();
            $token = $this->tokens->findByHash(self::hash($plainToken));
            if (
                $token === null
                || $token->purpose !== TokenPurpose::PasswordReset
                || $token->usedAt !== null
                || $token->expiresAt <= $now
                || !$this->tokens->markUsed($token->id, $now)
            ) {
                throw new UnprocessableException('invalid_reset_token');
            }

            $user = $this->users->findInTenant($token->tenantId, $token->userId);
            if ($user === null || $user->status !== UserStatus::Active) {
                throw new UnprocessableException('invalid_reset_token');
            }

            $this->users->updatePasswordHash($user->tenantId, $user->id, $this->hasher->hash($password));
            // Un autre lien demandé entre-temps ne doit plus servir, et toute session ouverte avec l'ancien mot de passe prend fin.
            $this->tokens->invalidateForUser($user->tenantId, $user->id, TokenPurpose::PasswordReset, $now);
            $this->refreshTokens->revokeAllForUser($user->tenantId, $user->id, $now);
        });
    }

    private function issueAndSend(User $user): void
    {
        $token = bin2hex(random_bytes(32));
        $now = $this->clock->now();

        $this->transaction->run(function () use ($user, $token, $now): void {
            // Un seul lien valable à la fois : une nouvelle demande invalide la précédente.
            $this->tokens->invalidateForUser($user->tenantId, $user->id, TokenPurpose::PasswordReset, $now);
            $this->tokens->add(new UserToken(
                Uuid::uuid7()->toString(),
                $user->tenantId,
                $user->id,
                TokenPurpose::PasswordReset,
                self::hash($token),
                $now->add(new DateInterval(self::VALIDITY)),
            ));
        });

        // La route est publique : le tenant (clé MailerSend, contact) est celui de l'utilisateur trouvé, jamais un paramètre.
        $this->tenant->reset();
        $this->tenant->set($user->tenantId);
        $this->notifier->notify($user, $token);
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}

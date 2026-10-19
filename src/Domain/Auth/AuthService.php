<?php

declare(strict_types=1);

namespace Casamiza\Domain\Auth;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditLogInterface;
use Casamiza\Domain\Tenant\TenantRepositoryInterface;
use Casamiza\Domain\User\User;
use Casamiza\Infrastructure\Security\JwtService;
use Casamiza\Infrastructure\Security\PasswordHasher;
use Casamiza\Shared\Exception\UnauthorizedException;
use Casamiza\Shared\Settings;
use DateInterval;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final readonly class AuthService
{
    public function __construct(
        private AuthUserRepositoryInterface $users,
        private RefreshTokenRepositoryInterface $refreshTokens,
        private TenantRepositoryInterface $tenants,
        private PasswordHasher $hasher,
        private JwtService $jwt,
        private LoginThrottle $throttle,
        private ClockInterface $clock,
        private Settings $settings,
        private LoggerInterface $logger,
        private AuditLogInterface $audit,
    ) {}

    public function login(string $email, string $password, string $ip): AuthSession
    {
        $email = strtolower(trim($email));
        $this->throttle->assertAllowed($email, $ip);

        $user = $this->users->findByEmail($email);
        // Le hash est vérifié même si l'utilisateur n'existe pas : même durée de réponse, pas d'énumération d'e-mails.
        $valid = $this->hasher->verify($password, $user?->passwordHash);

        if ($user === null || !$valid) {
            $this->throttle->record($email, $ip, false);
            // L'e-mail saisi est conservé (pour repérer une faute de frappe ou une attaque), mais seulement quand il désigne un
            // compte : une adresse inconnue n'appartient à aucun tenant, aucun administrateur ne pourrait la lire.
            if ($user !== null) {
                // L'auteur est anonyme (rien ne prouve que ce soit le titulaire) : le compte visé est la cible.
                $this->audit->recordSystem($user->tenantId, null, AuditAction::LoginFailed, 'user', $user->id, trim($user->firstName . ' ' . $user->lastName), ['email' => $email]);
            }
            throw new UnauthorizedException('invalid_credentials');
        }
        $this->throttle->record($email, $ip, true);

        if ($user->passwordHash !== null && $this->hasher->needsRehash($user->passwordHash)) {
            $this->users->updatePasswordHash($user->tenantId, $user->id, $this->hasher->hash($password));
        }

        $now = $this->clock->now();
        $this->users->recordLogin($user->tenantId, $user->id, $now);
        $this->audit->recordAs($user, AuditAction::LoginSucceeded, 'user', $user->id);

        $max = $this->settings->auth->sessionMaxDays;
        // Sans plafond (0), la session est limitée à 10 ans : valeur pratique, jamais atteinte.
        $sessionEnd = $now->add(new DateInterval($max > 0 ? "P{$max}D" : 'P3650D'));

        return $this->openSession($user, Uuid::uuid7()->toString(), $sessionEnd);
    }

    /** Échange un refresh token contre une nouvelle session (rotation : l'ancien jeton devient inutilisable). */
    public function refresh(string $plainToken): AuthSession
    {
        $now = $this->clock->now();
        $record = $this->refreshTokens->findByHash(self::hashToken($plainToken));

        if (
            $record === null
            || $record->revokedAt !== null
            || $record->expiresAt <= $now
            || $record->sessionExpiresAt <= $now
        ) {
            throw new UnauthorizedException('invalid_refresh_token');
        }

        // Jeton déjà utilisé : il a probablement été volé. On coupe toute la chaîne de la session.
        if ($record->usedAt !== null || !$this->refreshTokens->markUsed($record->id, $now)) {
            $this->refreshTokens->revokeFamily($record->familyId, $now);
            $this->logger->warning('Refresh token reuse detected, session revoked.', [
                'family_id' => $record->familyId,
                'user_id' => $record->userId,
            ]);
            $victim = $this->users->findInTenant($record->tenantId, $record->userId);
            if ($victim !== null) {
                $this->audit->recordAs($victim, AuditAction::RefreshReuseDetected, 'user', $victim->id);
            }
            throw new UnauthorizedException('refresh_token_reused');
        }

        $user = $this->users->findInTenant($record->tenantId, $record->userId);
        if ($user === null) {
            $this->refreshTokens->revokeFamily($record->familyId, $now);
            throw new UnauthorizedException('invalid_refresh_token');
        }

        return $this->openSession($user, $record->familyId, $record->sessionExpiresAt);
    }

    /** Révoque la session du refresh token fourni. Silencieux si le jeton est inconnu. */
    public function logout(string $plainToken): void
    {
        $record = $this->refreshTokens->findByHash(self::hashToken($plainToken));
        if ($record !== null) {
            $this->refreshTokens->revokeFamily($record->familyId, $this->clock->now());
            $user = $this->users->findInTenant($record->tenantId, $record->userId);
            if ($user !== null) {
                $this->audit->recordAs($user, AuditAction::Logout, 'user', $user->id);
            }
        }
    }

    private function openSession(User $user, string $familyId, \DateTimeImmutable $sessionEnd): AuthSession
    {
        $now = $this->clock->now();
        $plain = bin2hex(random_bytes(32));
        $expiresAt = min(
            $now->add(new DateInterval("P{$this->settings->auth->refreshTtlDays}D")),
            $sessionEnd,
        );

        $this->refreshTokens->add(new RefreshToken(
            Uuid::uuid7()->toString(),
            $user->tenantId,
            $user->id,
            $familyId,
            self::hashToken($plain),
            $expiresAt,
            $sessionEnd,
        ));

        $access = $this->jwt->issue($user);
        $tenant = $this->tenants->find($user->tenantId) ?? throw new UnauthorizedException('invalid_credentials');

        return new AuthSession($access->token, $access->expiresIn, $plain, $expiresAt, $user, $tenant);
    }

    private static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }
}

<?php

declare(strict_types=1);

namespace Casamiza\Infrastructure\Security;

use Casamiza\Domain\Auth\AccessClaims;
use Casamiza\Domain\User\Role;
use Casamiza\Domain\User\User;
use Casamiza\Shared\Exception\UnauthorizedException;
use Casamiza\Shared\Settings;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/** Émet et vérifie les access tokens (JWT HS256 de courte durée). */
final readonly class JwtService
{
    private Configuration $config;

    public function __construct(private Settings $settings, private ClockInterface $clock)
    {
        $this->config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($settings->auth->jwtSecret),
        );
    }

    public function issue(User $user): IssuedAccessToken
    {
        $auth = $this->settings->auth;
        $now = $this->clock->now();
        $expiresAt = $now->modify("+{$auth->accessTtlMinutes} minutes");

        $token = $this->config->builder()
            ->issuedBy($auth->issuer)
            ->permittedFor($auth->audience)
            ->identifiedBy(Uuid::uuid7()->toString())
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->relatedTo($user->id)
            ->withClaim('tid', $user->tenantId)
            ->withClaim('role', $user->role->value)
            ->getToken($this->config->signer(), $this->config->signingKey());

        return new IssuedAccessToken($token->toString(), $auth->accessTtlMinutes * 60);
    }

    /**
     * @throws UnauthorizedException `invalid_token` (illisible, mal signé, mauvais émetteur…) ou `token_expired`
     */
    public function parse(string $jwt): AccessClaims
    {
        if ($jwt === '') {
            throw new UnauthorizedException('invalid_token');
        }

        try {
            $token = $this->config->parser()->parse($jwt);
        } catch (Throwable) {
            throw new UnauthorizedException('invalid_token');
        }

        $valid = $token instanceof UnencryptedToken
            && $this->config->validator()->validate(
                $token,
                new SignedWith($this->config->signer(), $this->config->verificationKey()),
                new IssuedBy($this->settings->auth->issuer),
                new PermittedFor($this->settings->auth->audience),
            );
        if (!$valid) {
            throw new UnauthorizedException('invalid_token');
        }

        $now = $this->clock->now();
        if ($token->isExpired($now)) {
            throw new UnauthorizedException('token_expired');
        }

        $claims = $token->claims();
        $userId = $claims->get('sub');
        $tenantId = $claims->get('tid');
        $role = Role::tryFrom((string) $claims->get('role'));

        if (!is_string($userId) || !is_string($tenantId) || $role === null) {
            throw new UnauthorizedException('invalid_token');
        }

        return new AccessClaims($userId, $tenantId, $role);
    }
}

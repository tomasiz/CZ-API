<?php

declare(strict_types=1);

namespace Casamiza\Tests\Auth;

use Casamiza\Domain\User\Role;
use Casamiza\Http\Middleware\AuthMiddleware;
use Casamiza\Http\Middleware\RoleMiddleware;
use Casamiza\Shared\TenantContext;
use Casamiza\Tests\Http\ThrowingAction;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

final class AccessControlTest extends AuthTestCase
{
    public function testMeRequiresAToken(): void
    {
        $response = $this->request($this->app, 'GET', '/api/v1/me');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthenticated', $this->errorCode($response));
    }

    public function testMeReturnsTheTenantOfTheToken(): void
    {
        $alice = $this->json($this->request($this->app, 'GET', '/api/v1/me', $this->bearer($this->accessToken('alice@a.test'))))['data'];
        $bob = $this->json($this->request($this->app, 'GET', '/api/v1/me', $this->bearer($this->accessToken('bob@b.test'))))['data'];

        self::assertIsArray($alice);
        self::assertIsArray($bob);
        self::assertSame('Conciergerie A', $alice['tenant']['name']);
        self::assertSame('Conciergerie B', $bob['tenant']['name']);
        self::assertSame('bob@b.test', $bob['email']);
    }

    public function testExpiredTokenIsDistinguishedFromInvalidOne(): void
    {
        $token = $this->accessToken();

        $this->clock->advance('+16 minutes');
        $response = $this->request($this->app, 'GET', '/api/v1/me', $this->bearer($token));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('token_expired', $this->errorCode($response));
    }

    public function testTokensOfFifteenMinutesAreStillValidBeforeExpiry(): void
    {
        $token = $this->accessToken();

        $this->clock->advance('+14 minutes');

        self::assertSame(200, $this->request($this->app, 'GET', '/api/v1/me', $this->bearer($token))->getStatusCode());
    }

    public function testForgedTokensAreRejected(): void
    {
        $now = $this->clock->now();
        $build = static fn(Configuration $config): string => $config->builder()
            ->issuedBy('casamiza-test')->permittedFor('casamiza-admin')
            ->issuedAt($now)->expiresAt($now->modify('+10 minutes'))
            ->relatedTo('user-admin')->withClaim('tid', 'tenant-a')->withClaim('role', 'admin')
            ->getToken($config->signer(), $config->signingKey())->toString();

        $wrongSecret = $build(Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(str_repeat('x', 40))));
        $b64 = static fn(string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $unsigned = $b64('{"typ":"JWT","alg":"none"}') . '.' . $b64((string) json_encode([
            'iss' => 'casamiza-test', 'aud' => 'casamiza-admin', 'exp' => $now->getTimestamp() + 600,
            'sub' => 'user-admin', 'tid' => 'tenant-a', 'role' => 'admin',
        ])) . '.';

        foreach ([$wrongSecret, $unsigned, 'not-a-jwt'] as $token) {
            $response = $this->request($this->app, 'GET', '/api/v1/me', $this->bearer($token));
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('invalid_token', $this->errorCode($response));
        }
    }

    public function testTokenFromAnotherIssuerOrAudienceIsRejected(): void
    {
        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::JWT_SECRET));
        $now = $this->clock->now();
        $token = $config->builder()
            ->issuedBy('somebody-else')->permittedFor('casamiza-admin')
            ->issuedAt($now)->expiresAt($now->modify('+10 minutes'))
            ->relatedTo('user-admin')->withClaim('tid', 'tenant-a')->withClaim('role', 'admin')
            ->getToken($config->signer(), $config->signingKey())->toString();

        self::assertSame('invalid_token', $this->errorCode($this->request($this->app, 'GET', '/api/v1/me', $this->bearer($token))));
    }

    public function testRoleMiddlewareSeparatesAdminsFromManagers(): void
    {
        $this->app->get('/admin-only', new ThrowingAction(new \RuntimeException('should not run for managers')))
            ->add(new RoleMiddleware(Role::Admin))
            ->add(AuthMiddleware::class);

        $manager = $this->request($this->app, 'GET', '/admin-only', $this->bearer($this->accessToken('marc@a.test')));
        self::assertSame(403, $manager->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($manager));

        // Un admin passe le contrôle de rôle (la route de test échoue ensuite, avec une 500 : preuve qu'elle a été atteinte).
        $admin = $this->request($this->app, 'GET', '/admin-only', $this->bearer($this->accessToken('alice@a.test')));
        self::assertSame(500, $admin->getStatusCode());
    }

    public function testUpdateProfileChangesOnlyTheCallersOwnName(): void
    {
        $token = $this->accessToken('alice@a.test');

        $response = $this->request($this->app, 'PATCH', '/api/v1/me', $this->bearer($token), ['firstName' => 'Alicia']);
        $data = $this->json($response)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($data);
        self::assertSame('Alicia', $data['firstName']);
        self::assertSame('Admin', $data['lastName']);
        self::assertSame('Marc', $this->users->users['user-manager']->firstName);
    }

    public function testUpdateProfileValidatesInput(): void
    {
        $token = $this->accessToken();

        $empty = $this->request($this->app, 'PATCH', '/api/v1/me', $this->bearer($token), []);
        self::assertSame(422, $empty->getStatusCode());

        $wrongType = $this->request($this->app, 'PATCH', '/api/v1/me', $this->bearer($token), ['firstName' => 42]);
        $error = $this->json($wrongType)['error'];
        self::assertIsArray($error);
        self::assertSame(['firstName' => ['invalid_type']], $error['fields']);
    }

    public function testTenantContextIsOnlyEverSetFromTheToken(): void
    {
        // Un en-tête ou un paramètre « tenant » est sans effet : seul le claim `tid` compte.
        $token = $this->accessToken('alice@a.test');
        $response = $this->request($this->app, 'GET', '/api/v1/me?tenant_id=tenant-b', $this->bearer($token) + ['X-Tenant-Id' => 'tenant-b']);
        $data = $this->json($response)['data'];

        self::assertIsArray($data);
        self::assertSame('Conciergerie A', $data['tenant']['name']);
        self::assertInstanceOf(TenantContext::class, new TenantContext());
    }
}

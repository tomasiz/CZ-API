<?php

declare(strict_types=1);

namespace Casamiza\Tests\Auth;

final class LoginTest extends AuthTestCase
{
    public function testLoginReturnsTokenUserAndHttpOnlyCookie(): void
    {
        $response = $this->login();
        $data = $this->json($response)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($data);
        self::assertSame(900, $data['expiresIn']);
        self::assertSame('alice@a.test', $data['user']['email']);
        self::assertSame('admin', $data['user']['role']);
        self::assertSame('Conciergerie A', $data['user']['tenant']['name']);
        self::assertStringNotContainsString('password', (string) $response->getBody());

        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('Secure', $cookie);
        self::assertStringContainsString('SameSite=None', $cookie);
        self::assertStringContainsString('Path=/api/v1/auth', $cookie);
    }

    public function testWrongPasswordAndUnknownEmailGiveTheSameError(): void
    {
        $wrong = $this->login('alice@a.test', 'not-the-password');
        $unknown = $this->login('nobody@a.test');

        self::assertSame(401, $wrong->getStatusCode());
        self::assertSame(401, $unknown->getStatusCode());
        self::assertSame('invalid_credentials', $this->errorCode($wrong));
        self::assertSame($this->errorCode($wrong), $this->errorCode($unknown));
        self::assertFalse($wrong->hasHeader('Set-Cookie'));
    }

    public function testEmailIsCaseInsensitive(): void
    {
        self::assertSame(200, $this->login('ALICE@a.test')->getStatusCode());
    }

    public function testValidationErrorsListCodesPerField(): void
    {
        $response = $this->request($this->app, 'POST', '/api/v1/auth/login', $this->browser(), ['email' => 'not-an-email']);
        $error = $this->json($response)['error'];

        self::assertSame(422, $response->getStatusCode());
        self::assertIsArray($error);
        self::assertSame(['email' => ['invalid_email'], 'password' => ['required']], $error['fields']);
    }

    public function testBruteForceIsThrottledEvenWithTheRightPassword(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(401, $this->login('alice@a.test', 'wrong')->getStatusCode());
        }

        $response = $this->login();
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('too_many_attempts', $this->errorCode($response));

        // La fenêtre est de 15 minutes.
        $this->clock->advance('+16 minutes');
        self::assertSame(200, $this->login()->getStatusCode());
    }

    public function testUsersOfSuspendedTenantsCannotLogIn(): void
    {
        $this->tenants->tenants['tenant-a'] = new \Casamiza\Domain\Tenant\Tenant('tenant-a', 'A', \Casamiza\Domain\Tenant\TenantStatus::Suspended);

        self::assertSame(401, $this->login()->getStatusCode());
    }

    public function testCrossSiteOriginIsRejected(): void
    {
        $response = $this->request($this->app, 'POST', '/api/v1/auth/login', ['Origin' => 'https://evil.example'], ['email' => 'alice@a.test', 'password' => self::PASSWORD]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden_origin', $this->errorCode($response));
    }
}

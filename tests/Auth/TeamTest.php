<?php

declare(strict_types=1);

namespace Casamiza\Tests\Auth;

use Psr\Http\Message\ResponseInterface;

final class TeamTest extends AuthTestCase
{
    /** @param array<string, mixed>|null $json */
    private function asAdmin(string $method, string $uri, ?array $json = null, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, $method, $uri, $this->bearer($this->accessToken($email)), $json);
    }

    /** @return list<array<string, mixed>> */
    private function members(ResponseInterface $response): array
    {
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return array_values($data);
    }

    public function testEveryMemberCanReadTheTeamOfItsOwnTenantOnly(): void
    {
        $asManager = $this->members($this->asAdmin('GET', '/api/v1/team', null, 'marc@a.test'));

        self::assertSame(['Alice', 'Marc', 'Zoé'], array_column($asManager, 'firstName'));
        self::assertNotContains('bob@b.test', array_column($asManager, 'email'));
        self::assertStringNotContainsString('password', (string) json_encode($asManager));
        self::assertSame('active', $asManager[0]['status']);
    }

    public function testManagersCannotChangeTheTeam(): void
    {
        foreach ([
            ['POST', '/api/v1/team', ['firstName' => 'N', 'lastName' => 'N', 'email' => 'n@a.test', 'role' => 'manager']],
            ['PATCH', '/api/v1/team/user-manager', ['firstName' => 'X']],
            ['DELETE', '/api/v1/team/user-manager', null],
        ] as [$method, $uri, $body]) {
            $response = $this->asAdmin($method, $uri, $body, 'marc@a.test');
            self::assertSame(403, $response->getStatusCode(), $method);
            self::assertSame('forbidden', $this->errorCode($response));
        }
    }

    public function testAdminInvitesAMemberWhoActivatesTheAccount(): void
    {
        $response = $this->asAdmin('POST', '/api/v1/team', ['firstName' => 'Nina', 'lastName' => 'Nouvelle', 'email' => 'Nina@A.test', 'role' => 'manager']);
        $data = $this->json($response)['data'];

        self::assertSame(201, $response->getStatusCode());
        self::assertIsArray($data);
        self::assertSame('invited', $data['status']);
        self::assertSame('nina@a.test', $data['email']);
        self::assertArrayHasKey('nina@a.test', $this->notifier->sent);

        // Tant que le compte n'est pas activé, la connexion est impossible.
        self::assertSame('invalid_credentials', $this->errorCode($this->login('nina@a.test', 'un-mot-de-passe-solide')));

        $activate = $this->request($this->app, 'POST', '/api/v1/auth/activate', $this->browser(), [
            'token' => $this->notifier->sent['nina@a.test'],
            'password' => 'un-mot-de-passe-solide',
        ]);
        self::assertSame(204, $activate->getStatusCode());

        $login = $this->login('nina@a.test', 'un-mot-de-passe-solide');
        self::assertSame(200, $login->getStatusCode());
        $user = $this->json($login)['data'];
        self::assertIsArray($user);
        self::assertSame('manager', $user['user']['role']);
        self::assertSame('Conciergerie A', $user['user']['tenant']['name']);
    }

    public function testActivationTokenWorksOnlyOnce(): void
    {
        $this->asAdmin('POST', '/api/v1/team', ['firstName' => 'N', 'lastName' => 'N', 'email' => 'n@a.test', 'role' => 'manager']);
        $token = $this->notifier->sent['n@a.test'];
        $body = ['token' => $token, 'password' => 'un-mot-de-passe-solide'];

        self::assertSame(204, $this->request($this->app, 'POST', '/api/v1/auth/activate', $this->browser(), $body)->getStatusCode());

        $again = $this->request($this->app, 'POST', '/api/v1/auth/activate', $this->browser(), ['token' => $token, 'password' => 'un-autre-mot-de-passe']);
        self::assertSame(422, $again->getStatusCode());
        self::assertSame('invalid_activation_token', $this->errorCode($again));
    }

    public function testActivationTokenExpiresAfterSevenDays(): void
    {
        $this->asAdmin('POST', '/api/v1/team', ['firstName' => 'N', 'lastName' => 'N', 'email' => 'n@a.test', 'role' => 'manager']);

        $this->clock->advance('+7 days +1 minute');
        $response = $this->request($this->app, 'POST', '/api/v1/auth/activate', $this->browser(), ['token' => $this->notifier->sent['n@a.test'], 'password' => 'un-mot-de-passe-solide']);

        self::assertSame('invalid_activation_token', $this->errorCode($response));
    }

    public function testActivationRejectsUnknownTokensAndWeakPasswords(): void
    {
        $unknown = $this->request($this->app, 'POST', '/api/v1/auth/activate', $this->browser(), ['token' => 'nope', 'password' => 'un-mot-de-passe-solide']);
        self::assertSame('invalid_activation_token', $this->errorCode($unknown));

        $this->asAdmin('POST', '/api/v1/team', ['firstName' => 'N', 'lastName' => 'N', 'email' => 'n@a.test', 'role' => 'manager']);
        $weak = $this->request($this->app, 'POST', '/api/v1/auth/activate', $this->browser(), ['token' => $this->notifier->sent['n@a.test'], 'password' => 'court']);
        $error = $this->json($weak)['error'];
        self::assertIsArray($error);
        self::assertSame(422, $weak->getStatusCode());
        self::assertSame(['password' => ['too_short']], $error['fields']);
    }

    public function testInvitingAnExistingEmailIsAConflict(): void
    {
        $response = $this->asAdmin('POST', '/api/v1/team', ['firstName' => 'A', 'lastName' => 'B', 'email' => 'marc@a.test', 'role' => 'manager']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('email_already_used', $this->errorCode($response));
    }

    public function testInvitationValidatesInput(): void
    {
        $response = $this->asAdmin('POST', '/api/v1/team', ['firstName' => '', 'email' => 'nope', 'role' => 'owner']);
        $error = $this->json($response)['error'];

        self::assertIsArray($error);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ['firstName' => ['required'], 'lastName' => ['required'], 'email' => ['invalid_email'], 'role' => ['invalid_value']],
            $error['fields'],
        );
    }

    public function testAdminChangesRoleAndName(): void
    {
        $response = $this->asAdmin('PATCH', '/api/v1/team/user-manager', ['role' => 'admin', 'firstName' => 'Marco']);
        $data = $this->json($response)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($data);
        self::assertSame('admin', $data['role']);
        self::assertSame('Marco', $data['firstName']);
        self::assertSame('Manager', $data['lastName']);
        self::assertSame('Marco', $this->users->users['user-manager']->firstName);
    }

    public function testTheLastAdminCannotBeDemoted(): void
    {
        // Alice et Zoé sont admins : on peut rétrograder l'une, pas l'autre ensuite.
        self::assertSame(200, $this->asAdmin('PATCH', '/api/v1/team/user-admin2', ['role' => 'manager'])->getStatusCode());

        $response = $this->asAdmin('PATCH', '/api/v1/team/user-admin', ['role' => 'manager']);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('last_admin_required', $this->errorCode($response));
    }

    public function testRemovalRules(): void
    {
        $self = $this->asAdmin('DELETE', '/api/v1/team/user-admin');
        self::assertSame(422, $self->getStatusCode());
        self::assertSame('cannot_delete_self', $this->errorCode($self));

        self::assertSame(204, $this->asAdmin('DELETE', '/api/v1/team/user-manager')->getStatusCode());
        self::assertArrayNotHasKey('user-manager', $this->users->users);
    }

    public function testTheOtherAdminCanBeRemovedWhileOneRemains(): void
    {
        self::assertSame(204, $this->asAdmin('DELETE', '/api/v1/team/user-admin2')->getStatusCode());
        self::assertSame('cannot_delete_self', $this->errorCode($this->asAdmin('DELETE', '/api/v1/team/user-admin')));
    }

    public function testAnAdminCannotTouchAnotherTenant(): void
    {
        foreach ([['PATCH', ['firstName' => 'X']], ['DELETE', null]] as [$method, $body]) {
            $response = $this->asAdmin($method, '/api/v1/team/user-b', $body);
            self::assertSame(404, $response->getStatusCode(), $method);
            self::assertSame('user_not_found', $this->errorCode($response));
        }
        self::assertSame('Bob', $this->users->users['user-b']->firstName);
    }
}

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
            ['POST', '/api/v1/team/user-manager/invitation', null],
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

    /** @return array{url: string, expiresAt: string, token: string} */
    private function invitationOf(ResponseInterface $response): array
    {
        $data = $this->json($response)['data'];
        self::assertIsArray($data);
        $invitation = $data['invitation'];
        self::assertIsArray($invitation);
        $matches = [];
        preg_match('#^' . preg_quote(self::ORIGIN, '#') . '/activate\?token=([a-f0-9]{64})$#', (string) $invitation['url'], $matches);

        return [
            'url' => (string) $invitation['url'],
            'expiresAt' => (string) $invitation['expiresAt'],
            'token' => $matches[1] ?? self::fail("Lien d'activation mal formé."),
        ];
    }

    public function testInvitationReturnsTheActivationLinkToTheAdmin(): void
    {
        $response = $this->asAdmin('POST', '/api/v1/team', ['firstName' => 'Nina', 'lastName' => 'Nouvelle', 'email' => 'nina@a.test', 'role' => 'manager']);
        $invitation = $this->invitationOf($response);

        self::assertSame('2026-10-14T09:00:00+00:00', $invitation['expiresAt']);

        // Le lien renvoyé est utilisable tel quel, sans e-mail.
        $activate = $this->request($this->app, 'POST', '/api/v1/auth/activate', $this->browser(), ['token' => $invitation['token'], 'password' => 'un-mot-de-passe-solide']);
        self::assertSame(204, $activate->getStatusCode());
        self::assertSame(200, $this->login('nina@a.test', 'un-mot-de-passe-solide')->getStatusCode());
    }

    public function testTheResponseSaysWhetherTheEmailWasSent(): void
    {
        $body = ['firstName' => 'Nina', 'lastName' => 'Nouvelle', 'email' => 'nina@a.test', 'role' => 'manager'];

        $sent = $this->json($this->asAdmin('POST', '/api/v1/team', $body))['data'];
        self::assertIsArray($sent);
        self::assertIsArray($sent['invitation']);
        self::assertTrue($sent['invitation']['emailSent']);

        // E-mail impossible : l'invitation existe, l'administrateur est prévenu et garde le lien
        $this->notifier->delivers = false;
        $failed = $this->json($this->asAdmin('POST', '/api/v1/team', [...$body, 'email' => 'nolan@a.test']))['data'];
        self::assertIsArray($failed);
        self::assertIsArray($failed['invitation']);
        self::assertFalse($failed['invitation']['emailSent']);
        self::assertNotSame('', $failed['invitation']['url']);

        $again = $this->json($this->asAdmin('POST', '/api/v1/team/' . $this->idOfInvited('nolan@a.test') . '/invitation'))['data'];
        self::assertIsArray($again);
        self::assertIsArray($again['invitation']);
        self::assertFalse($again['invitation']['emailSent']);
        $this->notifier->delivers = true;
        $resent = $this->json($this->asAdmin('POST', '/api/v1/team/' . $this->idOfInvited('nolan@a.test') . '/invitation'))['data'];
        self::assertIsArray($resent);
        self::assertIsArray($resent['invitation']);
        self::assertTrue($resent['invitation']['emailSent']);
    }

    public function testTheLinkIsNeverExposedByTheTeamListing(): void
    {
        $invitation = $this->invitationOf($this->asAdmin('POST', '/api/v1/team', ['firstName' => 'N', 'lastName' => 'N', 'email' => 'n@a.test', 'role' => 'manager']));

        $listing = (string) $this->asAdmin('GET', '/api/v1/team')->getBody();

        self::assertStringNotContainsString($invitation['token'], $listing);
        self::assertStringNotContainsString('invitation', $listing);
    }

    public function testResendingGivesANewLinkAndKillsThePreviousOne(): void
    {
        $first = $this->invitationOf($this->asAdmin('POST', '/api/v1/team', ['firstName' => 'N', 'lastName' => 'N', 'email' => 'n@a.test', 'role' => 'manager']));
        $id = $this->idOfInvited('n@a.test');

        $this->clock->advance('+3 days');
        $response = $this->asAdmin('POST', "/api/v1/team/{$id}/invitation");
        $second = $this->invitationOf($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame($first['token'], $second['token']);
        self::assertSame('2026-10-17T09:00:00+00:00', $second['expiresAt']);

        $old = $this->request($this->app, 'POST', '/api/v1/auth/activate', $this->browser(), ['token' => $first['token'], 'password' => 'un-mot-de-passe-solide']);
        self::assertSame('invalid_activation_token', $this->errorCode($old));

        $new = $this->request($this->app, 'POST', '/api/v1/auth/activate', $this->browser(), ['token' => $second['token'], 'password' => 'un-mot-de-passe-solide']);
        self::assertSame(204, $new->getStatusCode());
    }

    public function testResendingIsRefusedOnceTheAccountIsActive(): void
    {
        $response = $this->asAdmin('POST', '/api/v1/team/user-manager/invitation');

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('member_already_active', $this->errorCode($response));
    }

    public function testResendingCannotReachAnotherTenant(): void
    {
        $response = $this->asAdmin('POST', '/api/v1/team/user-b/invitation');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('user_not_found', $this->errorCode($response));
    }

    private function idOfInvited(string $email): string
    {
        foreach ($this->users->users as $user) {
            if ($user->email === $email) {
                return $user->id;
            }
        }
        self::fail("Membre {$email} introuvable.");
    }

    public function testActivationPageCanLookUpTheInvitedMemberWithoutConsumingTheToken(): void
    {
        $this->asAdmin('POST', '/api/v1/team', ['firstName' => 'Nina', 'lastName' => 'Nouvelle', 'email' => 'nina@a.test', 'role' => 'manager']);
        $token = $this->notifier->sent['nina@a.test'];

        $first = $this->request($this->app, 'POST', '/api/v1/auth/activation', $this->browser(), ['token' => $token]);
        $second = $this->request($this->app, 'POST', '/api/v1/auth/activation', $this->browser(), ['token' => $token]);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(['data' => ['firstName' => 'Nina', 'lastName' => 'Nouvelle', 'email' => 'nina@a.test']], $this->json($first));
        self::assertSame(200, $second->getStatusCode());

        // Une fois le compte activé, le lien ne sert plus.
        $this->request($this->app, 'POST', '/api/v1/auth/activate', $this->browser(), ['token' => $token, 'password' => 'un-mot-de-passe-solide']);
        $after = $this->request($this->app, 'POST', '/api/v1/auth/activation', $this->browser(), ['token' => $token]);
        self::assertSame('invalid_activation_token', $this->errorCode($after));
    }

    public function testActivationLookupRejectsUnknownAndExpiredTokens(): void
    {
        $unknown = $this->request($this->app, 'POST', '/api/v1/auth/activation', $this->browser(), ['token' => 'nope']);
        self::assertSame(422, $unknown->getStatusCode());
        self::assertSame('invalid_activation_token', $this->errorCode($unknown));

        $this->asAdmin('POST', '/api/v1/team', ['firstName' => 'N', 'lastName' => 'N', 'email' => 'n@a.test', 'role' => 'manager']);
        $this->clock->advance('+7 days +1 minute');
        $expired = $this->request($this->app, 'POST', '/api/v1/auth/activation', $this->browser(), ['token' => $this->notifier->sent['n@a.test']]);
        self::assertSame('invalid_activation_token', $this->errorCode($expired));
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

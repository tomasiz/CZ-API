<?php

declare(strict_types=1);

namespace Casamiza\Tests\Auth;

use Casamiza\Domain\Audit\AuditAction;
use Casamiza\Domain\Audit\AuditService;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Psr\Http\Message\ResponseInterface;

/** Journal d'audit : ce qui est écrit, ce qui n'y entre jamais, et la lecture (admin seulement, bornée au tenant). */
final class AuditLogTest extends AuthTestCase
{
    private const STRIPE_KEY = 'sk_live_51ABCdefGHIjklMNOpqrSTU';

    /** @param array<string, mixed>|null $json */
    private function call(string $method, string $uri, ?array $json = null, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, $method, '/api/v1' . $uri, $this->bearer($this->accessToken($email)), $json);
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int} */
    private function log(string $query = '', string $email = 'alice@a.test'): array
    {
        $response = $this->call('GET', '/audit-log' . $query, null, $email);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        /** @var array{items: list<array<string, mixed>>, total: int, page: int, perPage: int} $data */
        return $data;
    }

    /** @return list<string> */
    private function actions(string $query = '', string $email = 'alice@a.test'): array
    {
        return array_map(static fn(array $item): string => (string) $item['action'], $this->log($query, $email)['items']);
    }

    /** @return list<string> actions écrites dans l'ordre, sans les connexions que chaque appel de test provoque */
    private function writtenActions(): array
    {
        return array_values(array_map(
            static fn($e): string => $e->action->value,
            array_filter($this->audit->entries, static fn($e): bool => $e->action !== AuditAction::LoginSucceeded),
        ));
    }

    public function testOnlyAdministratorsReadTheLog(): void
    {
        $manager = $this->call('GET', '/audit-log', null, 'marc@a.test');
        self::assertSame(403, $manager->getStatusCode());
        self::assertSame('forbidden', $this->errorCode($manager));

        $anonymous = $this->request($this->app, 'GET', '/api/v1/audit-log', []);
        self::assertSame(401, $anonymous->getStatusCode());

        self::assertSame(200, $this->call('GET', '/audit-log')->getStatusCode());
    }

    public function testASuccessfulLoginIsRecordedInTheTenantOfTheUser(): void
    {
        $this->login('alice@a.test');

        $entry = $this->audit->of(AuditAction::LoginSucceeded)[0];
        self::assertSame(['tenant-a', 'user-admin', 'Alice Admin'], [$entry->tenantId, $entry->actorUserId, $entry->actorLabel]);
        self::assertNotNull($entry->requestId);
    }

    public function testAFailedLoginKeepsTheTypedEmailWhenItDesignatesAnAccount(): void
    {
        self::assertSame(401, $this->login('Alice@A.test', 'wrong-password-123')->getStatusCode());

        $entry = $this->audit->of(AuditAction::LoginFailed)[0];
        // Auteur anonyme, compte visé en cible.
        self::assertSame(['tenant-a', null, 'user-admin', 'Alice Admin'], [$entry->tenantId, $entry->actorUserId, $entry->targetId, $entry->targetLabel]);
        self::assertSame(['email' => 'alice@a.test'], $entry->context);
        // Le mot de passe saisi n'est écrit nulle part.
        self::assertStringNotContainsString('wrong-password-123', json_encode($entry, JSON_THROW_ON_ERROR));

        // Un administrateur du tenant le lit dans le journal.
        $items = $this->log('?action=login_failed')['items'];
        self::assertCount(1, $items);
        $context = $items[0]['context'];
        self::assertIsArray($context);
        self::assertSame('alice@a.test', $context['email']);
    }

    public function testAnUnknownEmailBelongsToNoTenantAndIsNotLogged(): void
    {
        self::assertSame(401, $this->login('personne@nulle-part.test', 'wrong-password-123')->getStatusCode());

        self::assertSame([], $this->audit->of(AuditAction::LoginFailed));
    }

    public function testLogoutAndRefreshReuseAreRecorded(): void
    {
        $cookie = $this->refreshCookie($this->login('alice@a.test'));
        $this->refresh($cookie);
        // Le même jeton une seconde fois : session volée présumée.
        self::assertSame('refresh_token_reused', $this->errorCode($this->refresh($cookie)));

        self::assertCount(1, $this->audit->of(AuditAction::RefreshReuseDetected));

        $next = $this->refreshCookie($this->login('alice@a.test'));
        $this->request($this->app, 'POST', '/api/v1/auth/logout', $this->browser(), null, ['refresh_token' => $next]);
        self::assertCount(1, $this->audit->of(AuditAction::Logout));
    }

    public function testTeamChangesAreRecordedWithTheActor(): void
    {
        $invite = $this->call('POST', '/team', ['firstName' => 'Nina', 'lastName' => 'Nouvelle', 'email' => 'nina@a.test', 'role' => 'manager']);
        self::assertSame(201, $invite->getStatusCode());
        $ninaId = (string) $this->data($invite)['id'];

        $this->call('POST', '/team/' . $ninaId . '/invitation');
        $this->call('PATCH', '/team/' . $ninaId, ['role' => 'admin']);
        $this->call('DELETE', '/team/' . $ninaId);

        self::assertSame(['member_invited', 'invitation_resent', 'member_role_changed', 'member_removed'], $this->writtenActions());
        $role = $this->audit->of(AuditAction::MemberRoleChanged)[0];
        self::assertSame(['manager', 'admin'], [$role->context['from'], $role->context['to']]);
        self::assertSame(['user-admin', 'Alice Admin', 'Nina Nouvelle', $ninaId], [$role->actorUserId, $role->actorLabel, $role->targetLabel, $role->targetId]);
    }

    public function testAnInvitedMemberActivationIsRecorded(): void
    {
        $this->call('POST', '/team', ['firstName' => 'Nina', 'lastName' => 'Nouvelle', 'email' => 'nina@a.test', 'role' => 'manager']);
        $token = $this->notifier->sent['nina@a.test'] ?? self::fail('Aucune invitation.');

        $this->request($this->app, 'POST', '/api/v1/auth/activate', $this->browser(), ['token' => $token, 'password' => 'un-mot-de-passe-solide']);

        $entry = $this->audit->of(AuditAction::MemberActivated)[0];
        self::assertSame(['tenant-a', 'Nina Nouvelle'], [$entry->tenantId, $entry->actorLabel]);
    }

    public function testPasswordResetsAreRecorded(): void
    {
        $this->request($this->app, 'POST', '/api/v1/auth/forgot-password', $this->browser(), ['email' => 'alice@a.test']);
        $token = $this->resetNotifier->sent['alice@a.test'] ?? self::fail('Aucun lien.');
        $this->request($this->app, 'POST', '/api/v1/auth/reset-password', $this->browser(), ['token' => $token, 'password' => 'un-tout-nouveau-mot-de-passe']);
        self::assertCount(1, $this->audit->of(AuditAction::PasswordResetCompleted));

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $service = $container->get(\Casamiza\Domain\Auth\PasswordResetService::class);
        self::assertInstanceOf(\Casamiza\Domain\Auth\PasswordResetService::class, $service);
        $service->resetByAdministrator('zoe@a.test', 'encore-un-mot-de-passe');

        $cli = $this->audit->of(AuditAction::PasswordResetByCli)[0];
        self::assertSame(['tenant-a', null, 'cli', 'Zoé Admin'], [$cli->tenantId, $cli->actorUserId, $cli->actorLabel, $cli->targetLabel]);
    }

    public function testTheStripeKeyAndIntegrationsAreTracedWithoutTheirSecrets(): void
    {
        $this->context->set('tenant-a');
        $this->owners->add(new Owner('owner-1', 'G1', 'Alice Martin', 'Alice', 'Martin', 'alice@example.test', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->context->reset();

        $this->call('PATCH', '/owners/owner-1', ['stripeKey' => self::STRIPE_KEY, 'siret' => '12345678901234']);
        $this->call('DELETE', '/owners/owner-1/stripe-key');
        $this->call('PATCH', '/settings/company', ['iban' => 'FR7630006000011234567890189', 'legalName' => 'Casamea']);

        self::assertSame(['owner_stripe_key_set', 'owner_stripe_key_removed', 'company_settings_updated'], $this->writtenActions());
        $company = $this->audit->of(AuditAction::CompanySettingsUpdated)[0];
        self::assertEqualsCanonicalizing(['iban', 'legalName'], $company->context['fields']);

        // Rien de secret dans tout le journal : ni la clé, ni le SIRET, ni l'IBAN.
        $all = json_encode($this->audit->entries, JSON_THROW_ON_ERROR);
        foreach ([self::STRIPE_KEY, '12345678901234', 'FR7630006000011234567890189', 'sk_live'] as $secret) {
            self::assertStringNotContainsString($secret, $all);
        }
    }

    public function testACompanyUpdateThatChangesNothingWritesNothing(): void
    {
        $this->call('PATCH', '/settings/company', ['legalName' => 'Casamea']);
        $before = count($this->audit->of(AuditAction::CompanySettingsUpdated));

        $this->call('PATCH', '/settings/company', ['legalName' => 'Casamea']);

        self::assertSame($before, count($this->audit->of(AuditAction::CompanySettingsUpdated)));
    }

    public function testTheLogIsScopedToTheTenant(): void
    {
        $this->login('alice@a.test');
        $this->login('bob@b.test');

        $a = $this->log('', 'alice@a.test');
        $b = $this->log('', 'bob@b.test');

        // Les connexions de chaque tenant, et seulement elles (alice se connecte aussi pour lire).
        foreach ($a['items'] as $item) {
            self::assertNotSame('user-b', $item['actorId']);
        }
        foreach ($b['items'] as $item) {
            self::assertSame('user-b', $item['actorId']);
        }
        self::assertGreaterThan(0, $b['total']);
    }

    public function testFiltersAndPagination(): void
    {
        $this->call('POST', '/team', ['firstName' => 'Nina', 'lastName' => 'Nouvelle', 'email' => 'nina@a.test', 'role' => 'manager']);
        self::assertSame(401, $this->login('alice@a.test', 'wrong-password-123')->getStatusCode());

        self::assertSame(['member_invited'], $this->actions('?action=member_invited'));
        self::assertSame(['member_invited'], $this->actions('?family=team'));
        self::assertContains('login_failed', $this->actions('?family=access'));
        self::assertNotContains('member_invited', $this->actions('?family=access'));
        self::assertSame(['member_invited'], $this->actions('?targetType=user&action=member_invited&actorId=user-admin&from=2026-10-07&to=2026-10-07&perPage=1'));
        self::assertSame([], $this->actions('?action=member_invited&actorId=user-b'));

        // Un jour sans événement
        self::assertSame([], $this->actions('?from=2026-10-08'));
        self::assertSame([], $this->actions('?to=2026-10-06'));

        $page = $this->log('?perPage=2&page=2');
        self::assertSame([2, 2], [$page['page'], $page['perPage']]);
        self::assertLessThanOrEqual(2, count($page['items']));
        self::assertGreaterThan(2, $page['total']);
    }

    public function testQueryIsValidated(): void
    {
        foreach (['?action=hack', '?family=nope', '?from=hier', '?perPage=0', '?perPage=500', '?unknown=1'] as $query) {
            $response = $this->call('GET', '/audit-log' . $query);
            self::assertSame(422, $response->getStatusCode(), $query);
            self::assertSame('validation_failed', $this->errorCode($response));
        }
    }

    public function testThePurgeRemovesOnlyOldRows(): void
    {
        $this->login('alice@a.test');
        $this->clock->advance('+14 months');
        $this->login('alice@a.test');

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $service = $container->get(AuditService::class);
        self::assertInstanceOf(AuditService::class, $service);

        self::assertSame(1, $service->purge());
        self::assertCount(1, $this->audit->entries);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace Casamiza\Tests\Auth;

use Psr\Http\Message\ResponseInterface;

/** « Mot de passe oublié » : `POST /auth/forgot-password` puis `POST /auth/reset-password` (routes publiques à contrôle d'origine). */
final class PasswordResetTest extends AuthTestCase
{
    private const NEW_PASSWORD = 'un-tout-nouveau-mot-de-passe';

    /** @param array<string, string>|null $headers en-têtes ; par défaut ceux du navigateur du front */
    private function forgot(string $email, ?array $headers = null): ResponseInterface
    {
        return $this->request($this->app, 'POST', '/api/v1/auth/forgot-password', $headers ?? $this->browser(), ['email' => $email]);
    }

    private function reset(string $token, string $password = self::NEW_PASSWORD): ResponseInterface
    {
        return $this->request($this->app, 'POST', '/api/v1/auth/reset-password', $this->browser(), ['token' => $token, 'password' => $password]);
    }

    private function tokenOf(string $email): string
    {
        return $this->resetNotifier->sent[$email] ?? self::fail('Aucun lien envoyé à ' . $email);
    }

    public function testAKnownAddressReceivesALinkInItsOwnTenant(): void
    {
        $response = $this->forgot('bob@b.test');

        self::assertSame(204, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->tokenOf('bob@b.test'));
        // La route est publique : le tenant de l'envoi (clé MailerSend, contact) est celui de l'utilisateur, pas un paramètre.
        self::assertSame(['tenant-b'], $this->resetNotifier->tenants);
        self::assertSame(1, $this->afterResponse->runs);
    }

    public function testAnUnknownOrInvitedAddressGetsTheSameAnswerAndNoLink(): void
    {
        $this->asInvited('nina@a.test');

        $known = $this->forgot('alice@a.test');
        $unknown = $this->forgot('personne@a.test');
        $invited = $this->forgot('nina@a.test');

        self::assertSame(204, $known->getStatusCode());
        foreach ([$unknown, $invited] as $response) {
            self::assertSame($known->getStatusCode(), $response->getStatusCode());
            self::assertSame((string) $known->getBody(), (string) $response->getBody());
        }
        self::assertSame(['alice@a.test'], array_keys($this->resetNotifier->sent));
    }

    public function testTheAddressIsValidatedAndTheOriginChecked(): void
    {
        $invalid = $this->forgot('pas-un-email');
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($invalid));

        $foreign = $this->forgot('alice@a.test', ['Origin' => 'https://evil.test']);
        self::assertSame(403, $foreign->getStatusCode());
        self::assertSame('forbidden_origin', $this->errorCode($foreign));
        self::assertSame([], $this->resetNotifier->sent);
    }

    public function testRequestsAreThrottledWhateverTheAddress(): void
    {
        foreach ([1, 2, 3] as $_) {
            self::assertSame(204, $this->forgot('personne@a.test')->getStatusCode());
        }

        $blocked = $this->forgot('personne@a.test');
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame('too_many_attempts', $this->errorCode($blocked));

        // Même refus pour une adresse qui existe : la limite ne révèle rien du compte.
        foreach ([1, 2, 3] as $_) {
            $this->forgot('alice@a.test');
        }
        self::assertSame(429, $this->forgot('alice@a.test')->getStatusCode());

        // La fenêtre est de 15 minutes
        $this->clock->advance('+16 minutes');
        self::assertSame(204, $this->forgot('alice@a.test')->getStatusCode());
    }

    public function testTheLinkChoosesANewPasswordAndEndsEverySession(): void
    {
        $cookie = $this->refreshCookie($this->login('alice@a.test'));
        $this->forgot('alice@a.test');

        $response = $this->reset($this->tokenOf('alice@a.test'));

        self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(401, $this->login('alice@a.test')->getStatusCode(), 'L’ancien mot de passe ne marche plus.');
        self::assertSame(200, $this->login('alice@a.test', self::NEW_PASSWORD)->getStatusCode());
        self::assertSame('invalid_refresh_token', $this->errorCode($this->refresh($cookie)), 'La session ouverte avec l’ancien mot de passe est coupée.');
    }

    public function testALinkWorksOnlyOnce(): void
    {
        $this->forgot('alice@a.test');
        $token = $this->tokenOf('alice@a.test');

        self::assertSame(204, $this->reset($token)->getStatusCode());

        $again = $this->reset($token, 'encore-un-autre-mot-de-passe');
        self::assertSame(422, $again->getStatusCode());
        self::assertSame('invalid_reset_token', $this->errorCode($again));
        self::assertSame(200, $this->login('alice@a.test', self::NEW_PASSWORD)->getStatusCode());
    }

    public function testANewRequestReplacesThePreviousLink(): void
    {
        $this->forgot('alice@a.test');
        $first = $this->tokenOf('alice@a.test');
        $this->clock->advance('+16 minutes');
        $this->forgot('alice@a.test');
        $second = $this->tokenOf('alice@a.test');

        self::assertNotSame($first, $second);
        self::assertSame('invalid_reset_token', $this->errorCode($this->reset($first)));
        self::assertSame(204, $this->reset($second)->getStatusCode());
    }

    public function testALinkExpiresAfterOneHour(): void
    {
        $this->forgot('alice@a.test');
        $token = $this->tokenOf('alice@a.test');

        $this->clock->advance('+61 minutes');

        $expired = $this->reset($token);
        self::assertSame(422, $expired->getStatusCode());
        self::assertSame('invalid_reset_token', $this->errorCode($expired));
    }

    public function testAWeakPasswordDoesNotConsumeTheLink(): void
    {
        $this->forgot('alice@a.test');
        $token = $this->tokenOf('alice@a.test');

        $weak = $this->reset($token, 'court');
        self::assertSame(422, $weak->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($weak));

        self::assertSame(204, $this->reset($token)->getStatusCode());
    }

    public function testUnknownTokensAndOtherPurposesAreRefused(): void
    {
        self::assertSame('invalid_reset_token', $this->errorCode($this->reset(str_repeat('a', 64))));

        // Le jeton d'une invitation n'ouvre pas la réinitialisation d'un mot de passe
        $this->asInvited('nina@a.test');
        $activation = $this->notifier->sent['nina@a.test'] ?? self::fail('Aucune invitation.');
        self::assertSame('invalid_reset_token', $this->errorCode($this->reset($activation)));
    }

    public function testUnknownFieldsAreRejected(): void
    {
        $response = $this->request($this->app, 'POST', '/api/v1/auth/reset-password', $this->browser(), ['token' => 'x', 'password' => self::NEW_PASSWORD, 'userId' => 'user-b']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
    }

    public function testTheCommandLineFallbackSetsThePasswordAndEndsEverySession(): void
    {
        $cookie = $this->refreshCookie($this->login('alice@a.test'));
        $this->forgot('alice@a.test');
        $token = $this->tokenOf('alice@a.test');

        $this->service()->resetByAdministrator(' Alice@A.test ', self::NEW_PASSWORD);

        self::assertSame(401, $this->login('alice@a.test')->getStatusCode());
        self::assertSame(200, $this->login('alice@a.test', self::NEW_PASSWORD)->getStatusCode());
        self::assertSame('invalid_refresh_token', $this->errorCode($this->refresh($cookie)));
        self::assertSame('invalid_reset_token', $this->errorCode($this->reset($token)), 'Un lien demandé avant ne sert plus.');
    }

    public function testTheCommandLineFallbackRefusesWeakPasswordsUnknownAndInvitedAccounts(): void
    {
        $this->asInvited('nina@a.test');
        $service = $this->service();

        foreach (['personne@a.test', 'nina@a.test'] as $email) {
            try {
                $service->resetByAdministrator($email, self::NEW_PASSWORD);
                self::fail('Le compte ' . $email . ' aurait dû être refusé.');
            } catch (\Casamiza\Shared\Exception\UnprocessableException $e) {
                self::assertSame('user_not_found', $e->errorCode());
            }
        }

        $this->expectException(\Casamiza\Shared\Exception\ValidationException::class);
        $service->resetByAdministrator('alice@a.test', 'court');
    }

    private function service(): \Casamiza\Domain\Auth\PasswordResetService
    {
        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $service = $container->get(\Casamiza\Domain\Auth\PasswordResetService::class);
        self::assertInstanceOf(\Casamiza\Domain\Auth\PasswordResetService::class, $service);

        return $service;
    }

    /** Invite un membre (jeton d'activation conservé dans le notifieur d'invitation). */
    private function asInvited(string $email): void
    {
        $this->request(
            $this->app,
            'POST',
            '/api/v1/team',
            $this->bearer($this->accessToken('alice@a.test')),
            ['firstName' => 'Nina', 'lastName' => 'Nouvelle', 'email' => $email, 'role' => 'manager'],
        );
    }
}

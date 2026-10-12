<?php

declare(strict_types=1);

namespace Casamiza\Tests\Auth;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

final class IntegrationTest extends AuthTestCase
{
    private const URI = '/api/v1/settings/integrations';

    private function tokenResponse(string $token = 'guesty-token'): Response
    {
        return new Response(200, [], (string) json_encode(['access_token' => $token, 'expires_in' => 86400, 'token_type' => 'Bearer']));
    }

    private function accountResponse(int $status = 200): Response
    {
        return new Response($status, [], '{"_id":"acc-1"}');
    }

    /** @param array<string, mixed>|null $json */
    private function call(string $method, string $uri, ?array $json = null, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, $method, $uri, $this->bearer($this->accessToken($email)), $json);
    }

    /** @return array<string, mixed> */
    private function entry(ResponseInterface $response): array
    {
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    private function connectGuesty(string $email = 'alice@a.test'): ResponseInterface
    {
        $this->thirdParty->append($this->tokenResponse(), $this->accountResponse());

        return $this->call('PUT', self::URI . '/guesty', ['clientId' => 'id-1', 'clientSecret' => 'secret-1'], $email);
    }

    public function testManagersCannotTouchIntegrations(): void
    {
        foreach ([['GET', ''], ['PUT', '/guesty'], ['POST', '/guesty/check'], ['DELETE', '/guesty']] as [$method, $path]) {
            $response = $this->call($method, self::URI . $path, null, 'marc@a.test');
            self::assertSame(403, $response->getStatusCode(), $method);
            self::assertSame('forbidden', $this->errorCode($response));
        }
    }

    public function testListShowsGuestyAsNotConfiguredWithoutCallingTheProvider(): void
    {
        $response = $this->call('GET', self::URI);
        $list = $this->json($response)['data'];

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($list);
        self::assertSame('guesty', $list[0]['provider']);
        self::assertFalse($list[0]['configured']);
        self::assertNull($list[0]['status']);
        self::assertCount(0, $this->thirdPartyCalls);
    }

    public function testSavingTestsTheCredentialsThenStoresThemEncrypted(): void
    {
        $response = $this->connectGuesty();
        $data = $this->entry($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['configured']);
        self::assertSame('connected', $data['status']);
        self::assertNull($data['lastErrorCode']);
        // Jamais de secret dans la réponse, ni en clair en base.
        self::assertStringNotContainsString('secret-1', (string) $response->getBody());
        self::assertStringNotContainsString('guesty-token', (string) $response->getBody());
        $row = $this->integrations->rows['tenant-a|guesty'];
        self::assertStringNotContainsString('secret-1', $row->credentialsEncrypted);
        self::assertNotNull($row->tokenEncrypted);
        self::assertStringNotContainsString('guesty-token', $row->tokenEncrypted);
    }

    public function testRefusedCredentialsAreNotSaved(): void
    {
        $this->thirdParty->append(new Response(401, [], '{"error":"invalid_client"}'));

        $response = $this->call('PUT', self::URI . '/guesty', ['clientId' => 'bad', 'clientSecret' => 'bad']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('integration_invalid_credentials', $this->errorCode($response));
        self::assertSame([], $this->integrations->rows);
    }

    public function testUnreachableAndRateLimitedProvidersAreReportedByCode(): void
    {
        $this->thirdParty->append(new Response(429));
        $limited = $this->call('PUT', self::URI . '/guesty', ['clientId' => 'a', 'clientSecret' => 'b']);
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('integration_rate_limited', $this->errorCode($limited));

        $this->thirdParty->append(new Response(503));
        $down = $this->call('PUT', self::URI . '/guesty', ['clientId' => 'a', 'clientSecret' => 'b']);
        self::assertSame(503, $down->getStatusCode());
        self::assertSame('integration_unreachable', $this->errorCode($down));
    }

    public function testSaveValidatesFields(): void
    {
        $missing = $this->call('PUT', self::URI . '/guesty', ['clientId' => 'a']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame(['clientSecret' => ['required']], $this->json($missing)['error']['fields']);

        $unknown = $this->call('PUT', self::URI . '/guesty', ['clientId' => 'a', 'clientSecret' => 'b', 'role' => 'admin']);
        self::assertSame(['role' => ['unknown_field']], $this->json($unknown)['error']['fields']);

        self::assertSame('provider_not_found', $this->errorCode($this->call('PUT', self::URI . '/unknown', ['a' => 'b'])));
        self::assertCount(0, $this->thirdPartyCalls);
    }

    public function testBlankSecretsKeepTheStoredValuesAndTheCachedToken(): void
    {
        $this->connectGuesty();
        $before = count($this->thirdPartyCalls);

        // Identifiants inchangés : le jeton en cache sert, aucun nouveau jeton n'est demandé.
        $this->thirdParty->append($this->accountResponse());
        $response = $this->call('PUT', self::URI . '/guesty', ['clientId' => 'id-1', 'clientSecret' => '']);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount($before + 1, $this->thirdPartyCalls);
        self::assertSame('/v1/accounts/me', $this->thirdPartyPaths()[$before]);
    }

    public function testChangedCredentialsRequestANewToken(): void
    {
        $this->connectGuesty();
        $this->thirdParty->append($this->tokenResponse('other-token'), $this->accountResponse());

        $this->call('PUT', self::URI . '/guesty', ['clientId' => 'id-2', 'clientSecret' => '']);

        $paths = $this->thirdPartyPaths();
        self::assertSame(['/oauth2/token', '/v1/accounts/me', '/oauth2/token', '/v1/accounts/me'], $paths);
    }

    public function testCheckReusesTheCachedTokenAndRenewsItWhenExpired(): void
    {
        $this->connectGuesty();
        $this->thirdParty->append($this->accountResponse(), $this->accountResponse());

        $this->call('POST', self::URI . '/guesty/check');
        $this->call('POST', self::URI . '/guesty/check');
        $paths = $this->thirdPartyPaths();
        self::assertSame(1, count(array_keys($paths, '/oauth2/token', true)), 'un seul jeton demandé malgré trois contrôles');

        $this->clock->advance('+25 hours');
        $this->thirdParty->append($this->tokenResponse('renewed'), $this->accountResponse());
        $this->call('POST', self::URI . '/guesty/check');
        $paths = $this->thirdPartyPaths();
        self::assertSame(2, count(array_keys($paths, '/oauth2/token', true)));
    }

    public function testCheckRecordsAnErrorStatusWithoutFailingTheRequest(): void
    {
        $this->connectGuesty();
        $this->thirdParty->append(new Response(403));

        $response = $this->call('POST', self::URI . '/guesty/check');
        $data = $this->entry($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('error', $data['status']);
        self::assertSame('integration_forbidden', $data['lastErrorCode']);

        $list = $this->json($this->call('GET', self::URI))['data'];
        self::assertIsArray($list);
        self::assertSame('error', $list[0]['status']);
    }

    public function testCheckOfAnUnconfiguredIntegrationIsNotFound(): void
    {
        $response = $this->call('POST', self::URI . '/guesty/check');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('integration_not_configured', $this->errorCode($response));
    }

    public function testTenantsNeverSeeEachOthersConfiguration(): void
    {
        $this->connectGuesty();

        $other = $this->json($this->call('GET', self::URI, null, 'bob@b.test'))['data'];
        self::assertIsArray($other);
        self::assertFalse($other[0]['configured']);
        self::assertSame(404, $this->call('POST', self::URI . '/guesty/check', null, 'bob@b.test')->getStatusCode());

        $this->call('DELETE', self::URI . '/guesty', null, 'bob@b.test');
        self::assertArrayHasKey('tenant-a|guesty', $this->integrations->rows);
    }

    public function testDisconnectRemovesTheConfiguration(): void
    {
        $this->connectGuesty();

        self::assertSame(204, $this->call('DELETE', self::URI . '/guesty')->getStatusCode());
        self::assertSame([], $this->integrations->rows);
    }
}

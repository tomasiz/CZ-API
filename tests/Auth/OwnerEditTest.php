<?php

declare(strict_types=1);

namespace Casamiza\Tests\Auth;

use Casamiza\Domain\Owner\Owner;
use Casamiza\Domain\Owner\OwnerType;
use Casamiza\Infrastructure\Security\Encryptor;
use Psr\Http\Message\ResponseInterface;

/** Saisies locales d'un propriétaire : SIRET, raison sociale, clé Stripe (écriture seule, admin seulement). */
final class OwnerEditTest extends AuthTestCase
{
    private const STRIPE_KEY = 'sk_live_51ABCdefGHIjklMNOpqrSTU';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOwner('tenant-a', 'owner-1', 'G1');
        $this->seedOwner('tenant-b', 'owner-b', 'GB');
    }

    /** @param non-empty-string $id */
    private function seedOwner(string $tenantId, string $id, string $guestyId): void
    {
        $this->context->reset();
        $this->context->set($tenantId);
        $this->owners->add(new Owner($id, $guestyId, 'Alice Martin', 'Alice', 'Martin', 'alice@example.test', '', '', OwnerType::Individual, '', '', 20.0, null, null));
        $this->context->reset();
    }

    /** @param array<string, mixed>|null $json */
    private function call(string $method, string $uri, ?array $json = null, string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, $method, $uri, $this->bearer($this->accessToken($email)), $json);
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    public function testManagersCannotEditOwners(): void
    {
        foreach ([['PATCH', '/api/v1/owners/owner-1', ['siret' => '12345678901234']], ['DELETE', '/api/v1/owners/owner-1/stripe-key', null]] as [$method, $uri, $body]) {
            $response = $this->call($method, $uri, $body, 'marc@a.test');
            self::assertSame(403, $response->getStatusCode(), $method);
            self::assertSame('forbidden', $this->errorCode($response));
        }
        self::assertSame('', $this->owners->rows['tenant-a|owner-1']->siret);
    }

    public function testLocalFieldsAreSavedNormalizedAndCanBeCleared(): void
    {
        $response = $this->call('PATCH', '/api/v1/owners/owner-1', ['siret' => '123 456 789 01234', 'companyName' => '  SCI Alice  ']);
        $data = $this->data($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('12345678901234', $data['siret']);
        self::assertSame('SCI Alice', $data['companyName']);
        // Un champ absent reste inchangé : on ne touche que la raison sociale.
        $this->call('PATCH', '/api/v1/owners/owner-1', ['companyName' => 'SCI Bob']);
        self::assertSame('12345678901234', $this->owners->rows['tenant-a|owner-1']->siret);
        self::assertSame('SCI Bob', $this->owners->rows['tenant-a|owner-1']->companyName);
        // Une valeur vide efface.
        $cleared = $this->data($this->call('PATCH', '/api/v1/owners/owner-1', ['siret' => '']));
        self::assertSame('', $cleared['siret']);
        self::assertSame('SCI Bob', $cleared['companyName']);
        // Les champs issus de Guesty ne bougent pas.
        self::assertSame('Alice', $cleared['firstName']);
        self::assertEquals(20, $cleared['commission']);
    }

    public function testASirenOfNineDigitsIsAccepted(): void
    {
        $data = $this->data($this->call('PATCH', '/api/v1/owners/owner-1', ['siret' => '953 508 736']));

        self::assertSame('953508736', $data['siret']);
        self::assertSame(422, $this->call('PATCH', '/api/v1/owners/owner-1', ['siret' => '9535087361'])->getStatusCode());
    }

    public function testTheStripeKeyIsWriteOnlyAndEncryptedAtRest(): void
    {
        $response = $this->call('PATCH', '/api/v1/owners/owner-1', ['stripeKey' => self::STRIPE_KEY]);
        $data = $this->data($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['stripeConfigured']);
        self::assertStringNotContainsString(self::STRIPE_KEY, (string) $response->getBody());
        self::assertArrayNotHasKey('stripeKey', $data);

        $stored = $this->owners->rows['tenant-a|owner-1']->stripeSecretEncrypted;
        self::assertNotNull($stored);
        self::assertStringNotContainsString(self::STRIPE_KEY, $stored);
        self::assertSame(self::STRIPE_KEY, (new Encryptor(str_repeat('k', 32)))->decrypt($stored));

        // La liste n'expose jamais la clé non plus.
        $list = $this->call('GET', '/api/v1/owners', null, 'marc@a.test');
        self::assertStringNotContainsString(self::STRIPE_KEY, (string) $list->getBody());
        self::assertStringNotContainsString($stored, (string) $list->getBody());
        $owners = $this->json($list)['data'];
        self::assertIsArray($owners);
        self::assertTrue($owners[0]['stripeConfigured']);

        $removed = $this->data($this->call('DELETE', '/api/v1/owners/owner-1/stripe-key'));
        self::assertFalse($removed['stripeConfigured']);
        self::assertNull($this->owners->rows['tenant-a|owner-1']->stripeSecretEncrypted);
    }

    public function testInvalidInputIsRejectedWithFieldCodes(): void
    {
        $siret = $this->call('PATCH', '/api/v1/owners/owner-1', ['siret' => '1234']);
        self::assertSame(422, $siret->getStatusCode());
        self::assertSame(['siret' => ['invalid_format']], $this->json($siret)['error']['fields'] ?? null);

        $stripe = $this->call('PATCH', '/api/v1/owners/owner-1', ['stripeKey' => 'not-a-key']);
        self::assertSame(['stripeKey' => ['invalid_format']], $this->json($stripe)['error']['fields'] ?? null);

        // Champs inconnus rejetés, y compris ceux qui appartiennent à Guesty (anti mass-assignment).
        $unknown = $this->call('PATCH', '/api/v1/owners/owner-1', ['commission' => 0, 'name' => 'Pirate']);
        self::assertSame(['commission' => ['unknown_field'], 'name' => ['unknown_field']], $this->json($unknown)['error']['fields'] ?? null);

        $empty = $this->call('PATCH', '/api/v1/owners/owner-1', []);
        self::assertSame(422, $empty->getStatusCode());

        self::assertSame(20.0, $this->owners->rows['tenant-a|owner-1']->commission);
        self::assertSame('Alice Martin', $this->owners->rows['tenant-a|owner-1']->name);
    }

    public function testAnOwnerOfAnotherTenantIsNotFoundAndUntouched(): void
    {
        foreach ([['PATCH', '/api/v1/owners/owner-b', ['siret' => '12345678901234']], ['DELETE', '/api/v1/owners/owner-b/stripe-key', null], ['PATCH', '/api/v1/owners/unknown', ['siret' => '']]] as [$method, $uri, $body]) {
            $response = $this->call($method, $uri, $body);
            self::assertSame(404, $response->getStatusCode(), $uri);
            self::assertSame('owner_not_found', $this->errorCode($response));
        }
        self::assertSame('', $this->owners->rows['tenant-b|owner-b']->siret);
    }
}

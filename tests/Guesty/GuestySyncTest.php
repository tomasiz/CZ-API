<?php

declare(strict_types=1);

namespace Casamiza\Tests\Guesty;

use Casamiza\Console\SyncGuestyCommand;
use Casamiza\Domain\Integration\Provider;
use Casamiza\Domain\Owner\Owner;
use Casamiza\Tests\Auth\AuthTestCase;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class GuestySyncTest extends AuthTestCase
{
    private const SYNC = '/api/v1/sync/guesty';

    /** @param array<array-key, mixed> $body */
    private static function ok(array $body): Response
    {
        return new Response(200, [], (string) json_encode($body));
    }

    private static function token(string $value = 'guesty-token'): Response
    {
        return self::ok(['access_token' => $value, 'expires_in' => 86400, 'token_type' => 'Bearer']);
    }

    /** @return array<string, mixed> */
    private static function owner(string $id, string $name, bool $active = true, int $commission = 20, string $type = 'INDIVIDUAL'): array
    {
        return [
            '_id' => $id,
            'fullName' => $name,
            'email' => strtolower($id) . '@example.test',
            'phone' => '+33600000000',
            'address' => '1 rue du Port, Ajaccio',
            'active' => $active,
            'businessInformation' => ['businessType' => ['type' => $type], 'ownerCommission' => $commission],
        ];
    }

    /** @return array<string, mixed> */
    private static function listing(string $id, string $nickname, ?string $ownerId, int $fee = 80, bool $active = true, int $linen = 20): array
    {
        return [
            '_id' => $id,
            'nickname' => $nickname,
            'title' => 'Titre ' . $nickname,
            'address' => ['full' => '2 quai Napoléon, Ajaccio'],
            'active' => $active,
            'owners' => $ownerId === null ? [] : [$ownerId],
            // Valeurs des champs personnalisés, indexées par fieldId ; Guesty peut les renvoyer en texte.
            'customFields' => [
                ['fieldId' => 'F-OTHER', 'value' => 'https://maps.example/x'],
                ['fieldId' => 'F-CLEAN', 'value' => $fee],
                ['fieldId' => 'F-LINEN', 'value' => (string) $linen],
            ],
        ];
    }

    /** Réponse de `/v1/properties-api/custom-fields/{id}` : les définitions des champs personnalisés. */
    private static function fieldDefinitions(): Response
    {
        return self::ok(['propertyId' => 'L1', 'customFields' => [
            ['fieldId' => 'F-OTHER', 'displayName' => 'lien_gps', 'key' => 'lien_gps', 'type' => 'longtext', 'value' => 'x'],
            ['fieldId' => 'F-CLEAN', 'displayName' => 'cout_mnage', 'key' => 'cout_ménage', 'type' => 'number', 'value' => '80'],
            ['fieldId' => 'F-LINEN', 'displayName' => 'cout_linge', 'key' => 'cout_linge', 'type' => 'number', 'value' => '20'],
        ]]);
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array<string, mixed>
     */
    private static function page(array $results, int $count, int $skip = 0): array
    {
        return ['results' => $results, 'count' => $count, 'limit' => 100, 'skip' => $skip];
    }

    /** Connecte Guesty pour le tenant de l'utilisateur (jeton et sonde du compte). */
    private function connect(string $email = 'alice@a.test'): void
    {
        $this->thirdParty->append(self::token(), self::ok(['_id' => 'acc-1']));
        $response = $this->request($this->app, 'PUT', '/api/v1/settings/integrations/guesty', $this->bearer($this->accessToken($email)), ['clientId' => 'id-1', 'clientSecret' => 'secret-1']);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Réponses de Guesty pour une synchronisation : sonde du compte puis pages, pour chaque ressource, puis les
     * définitions des champs personnalisés (demandées dès qu'un logement en renseigne, donc pas pour une liste vide).
     *
     * @param list<Response> $ownerPages
     * @param list<Response> $listingPages
     */
    private function queue(array $ownerPages, array $listingPages, bool $fieldLookup = true): void
    {
        $this->thirdParty->append(self::ok(['_id' => 'acc-1']), ...$ownerPages);
        $this->thirdParty->append(self::ok(['_id' => 'acc-1']), ...$listingPages);
        if ($fieldLookup) {
            $this->thirdParty->append(self::fieldDefinitions());
        }
    }

    private function sync(string $email = 'alice@a.test'): ResponseInterface
    {
        return $this->request($this->app, 'POST', self::SYNC, $this->bearer($this->accessToken($email)));
    }

    /** @return list<array<string, mixed>> */
    private function listOf(string $path, string $email = 'alice@a.test'): array
    {
        $response = $this->request($this->app, 'GET', '/api/v1' . $path, $this->bearer($this->accessToken($email)));
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        /** @var list<array<string, mixed>> $data */
        return $data;
    }

    /** @return array<string, mixed> */
    private function report(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];
        self::assertIsArray($data);

        return $data;
    }

    public function testSyncNeedsAnAuthenticatedUser(): void
    {
        $response = $this->request($this->app, 'POST', self::SYNC);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testSyncFailsWhenGuestyIsNotConfigured(): void
    {
        $response = $this->sync();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('integration_not_configured', $this->errorCode($response));
    }

    public function testEveryPageOfOwnersAndListingsIsImported(): void
    {
        $this->connect();
        $this->queue(
            [self::ok(self::page([self::owner('O1', 'Alice'), self::owner('O2', 'Bob', commission: 22, type: 'OTHER')], 3)), self::ok(self::page([self::owner('O3', 'Carol', active: false)], 3, 2))],
            [self::ok(self::page([self::listing('L1', 'Villa', 'O1'), self::listing('L2', 'Studio', 'O2', fee: 60)], 4)), self::ok(self::page([self::listing('L3', 'Loft', 'O1'), self::listing('L4', 'Orphelin', null, active: false)], 4, 2))],
        );

        $report = $this->report($this->sync());

        self::assertSame(['created' => 2, 'updated' => 0, 'archived' => 1, 'unchanged' => 0], $report['owners']);
        self::assertSame(['created' => 3, 'updated' => 0, 'archived' => 1, 'unchanged' => 0], $report['properties']);
        self::assertSame(['/v1/accounts/me', '/v1/owners', '/v1/owners', '/v1/accounts/me', '/v1/listings', '/v1/listings', '/v1/properties-api/custom-fields/L1'], array_slice($this->thirdPartyPaths(), 2));

        // La deuxième page est demandée à partir du bon décalage.
        $queries = array_map(static fn(array $call): string => $call['request']->getUri()->getQuery(), $this->thirdPartyCalls->getArrayCopy());
        self::assertStringContainsString('pagination=true', $queries[3]);
        self::assertStringContainsString('skip=2', $queries[4]);

        $owners = $this->listOf('/owners');
        // Les propriétaires inactifs (Carol) restent listés, marqués inactifs.
        self::assertSame(['Alice', 'Bob', 'Carol'], array_column($owners, 'name'));
        self::assertSame([true, true, false], array_column($owners, 'active'));
        self::assertSame('company', $owners[1]['type']);
        self::assertEquals(22, $owners[1]["commission"]);
        self::assertSame(['Villa', 'Loft'], array_column($owners[0]['properties'], 'name'));

        $properties = $this->listOf('/properties');
        // Orphelin (L4) est inactif et sans propriétaire : listé quand même.
        self::assertSame(['Villa', 'Studio', 'Loft', 'Orphelin'], array_column($properties, 'name'));
        self::assertSame([true, true, true, false], array_column($properties, 'active'));
        self::assertSame('Bob', $properties[1]['ownerName']);
        self::assertEquals(60, $properties[1]['cleaningFee']);
        self::assertEquals(20, $properties[1]['linenFee']);
        self::assertSame('2 quai Napoléon, Ajaccio', $properties[0]['address']);
    }

    public function testThePrefixGuestyAddsToOwnerNamesIsDropped(): void
    {
        $this->connect();
        $this->queue(
            [self::ok(self::page([
                self::owner('O1', 'P - Alice Martin'),
                self::owner('O2', 'P-Bob'),
                self::owner('O3', 'Paul Pierre'),     // « P » initial d'un vrai prénom : intact
                self::owner('O4', 'Philippe P - Durand'), // préfixe au milieu : intact
            ], 4))],
            [self::ok(self::page([], 0))],
            fieldLookup: false,
        );

        $this->sync();

        self::assertSame(['Alice Martin', 'Bob', 'Paul Pierre', 'Philippe P - Durand'], array_column($this->listOf('/owners'), 'name'));
    }

    public function testAnUnpaginatedOwnerArrayIsTheCompleteList(): void
    {
        $this->connect();
        $this->queue(
            [self::ok([self::owner('O1', 'Alice'), self::owner('O2', 'Bob')])],
            [self::ok(self::page([], 0))],
            fieldLookup: false,
        );

        $report = $this->report($this->sync());

        self::assertSame(2, $report['owners']['created']);
        self::assertCount(1, array_filter($this->thirdPartyPaths(), static fn(string $p): bool => $p === '/v1/owners'), 'une seule requête owners suffit');
    }

    public function testLocalOwnerFieldsSurviveAndGuestyChangesAreApplied(): void
    {
        $this->connect();
        $this->queue([self::ok(self::page([self::owner('O1', 'Alice')], 1))], [self::ok(self::page([self::listing('L1', 'Villa', 'O1', fee: 80, linen: 20)], 1))]);
        $this->sync();

        // Saisies locales du propriétaire : SIRET et raison sociale.
        $owner = $this->owners->byGuestyId('tenant-a', 'O1');
        self::assertInstanceOf(Owner::class, $owner);
        $this->context->reset();
        $this->context->set('tenant-a');
        $this->owners->update(new Owner($owner->id, 'O1', $owner->name, $owner->email, $owner->phone, $owner->address, $owner->type, 'SCI Alice', '12345678901234', $owner->commission, null));
        $this->context->reset();

        $this->queue([self::ok(self::page([self::owner('O1', 'Alice Renommée', commission: 18)], 1))], [self::ok(self::page([self::listing('L1', 'Villa Neuve', 'O1', fee: 120, linen: 25)], 1))]);
        $report = $this->report($this->sync());

        self::assertSame(['created' => 0, 'updated' => 1, 'archived' => 0, 'unchanged' => 0], $report['owners']);
        $owner = $this->owners->byGuestyId('tenant-a', 'O1');
        self::assertSame('Alice Renommée', $owner->name ?? null);
        self::assertSame(18.0, $owner->commission ?? null);
        self::assertSame('12345678901234', $owner->siret ?? null);
        self::assertSame('SCI Alice', $owner->companyName ?? null);
        // Les coûts de ménage et de linge appartiennent à Guesty : ils suivent ses changements.
        $property = $this->properties->byGuestyId('tenant-a', 'L1');
        self::assertSame('Villa Neuve', $property->name ?? null);
        self::assertSame(120.0, $property->cleaningFee ?? null);
        self::assertSame(25.0, $property->linenFee ?? null);
    }

    public function testFeesComeFromTheCustomFieldsAndDefaultToZero(): void
    {
        $this->connect();
        $noFields = self::listing('L2', 'Sans coûts', null);
        $noFields['customFields'] = [['fieldId' => 'F-OTHER', 'value' => 'x'], ['fieldId' => 'F-CLEAN', 'value' => 'pas un nombre']];
        $this->queue(
            [self::ok(self::page([self::owner('O1', 'Alice')], 1))],
            [self::ok(self::page([self::listing('L1', 'Avec coûts', 'O1', fee: 83, linen: 17), $noFields], 2))],
        );

        $this->sync();

        $withFees = $this->properties->byGuestyId('tenant-a', 'L1');
        $without = $this->properties->byGuestyId('tenant-a', 'L2');
        self::assertSame([83.0, 17.0], [$withFees->cleaningFee ?? null, $withFees->linenFee ?? null]);
        self::assertSame([0.0, 0.0], [$without->cleaningFee ?? null, $without->linenFee ?? null]);
        // Les noms des champs sont lus une seule fois, sur le logement qui en renseigne le plus.
        self::assertSame(1, count(array_filter($this->thirdPartyPaths(), static fn(string $p): bool => str_contains($p, '/properties-api/custom-fields/'))));
        self::assertContains('/v1/properties-api/custom-fields/L1', $this->thirdPartyPaths());
    }

    public function testASecondIdenticalSyncChangesNothing(): void
    {
        $this->connect();
        $pages = fn(): array => [[self::ok(self::page([self::owner('O1', 'Alice'), self::owner('O2', 'Bob', active: false)], 2))], [self::ok(self::page([self::listing('L1', 'Villa', 'O1'), self::listing('L2', 'Studio', 'O1', active: false)], 2))]];
        $this->queue(...$pages());
        $this->sync();
        $this->queue(...$pages());

        $report = $this->report($this->sync());

        self::assertSame(['created' => 0, 'updated' => 0, 'archived' => 0, 'unchanged' => 2], $report['owners']);
        self::assertSame(['created' => 0, 'updated' => 0, 'archived' => 0, 'unchanged' => 2], $report['properties']);
    }

    public function testWhatDisappearsIsArchivedAndRestoredWhenItComesBack(): void
    {
        $this->connect();
        $this->queue(
            [self::ok(self::page([self::owner('O1', 'Alice'), self::owner('O2', 'Bob')], 2))],
            [self::ok(self::page([self::listing('L1', 'Villa', 'O1'), self::listing('L2', 'Studio', 'O2')], 2))],
        );
        $this->sync();

        // O2 et L2 disparaissent de Guesty : archivés, pas supprimés.
        $this->queue([self::ok(self::page([self::owner('O1', 'Alice')], 1))], [self::ok(self::page([self::listing('L1', 'Villa', 'O1')], 1))]);
        $report = $this->report($this->sync());

        self::assertSame(1, $report['owners']['archived']);
        $owners = $this->listOf('/owners');
        self::assertSame(['Alice', 'Bob'], array_column($owners, 'name'));
        self::assertSame([true, false], array_column($owners, 'active'));
        // Les logements disparus restent listés, marqués inactifs, avec l'état de leur propriétaire.
        $properties = $this->listOf('/properties');
        self::assertSame(['Villa', 'Studio'], array_column($properties, 'name'));
        self::assertSame([true, false], array_column($properties, 'active'));
        self::assertSame([true, false], array_column($properties, 'ownerActive'));
        self::assertNotNull($this->owners->byGuestyId('tenant-a', 'O2')?->archivedAt);

        // Ils reviennent : rétablis avec leur identifiant d'origine.
        $before = $this->owners->byGuestyId('tenant-a', 'O2')->id;
        $this->queue(
            [self::ok(self::page([self::owner('O1', 'Alice'), self::owner('O2', 'Bob')], 2))],
            [self::ok(self::page([self::listing('L1', 'Villa', 'O1'), self::listing('L2', 'Studio', 'O2')], 2))],
        );
        $this->sync();

        self::assertSame(['Alice', 'Bob'], array_column($this->listOf('/owners'), 'name'));
        self::assertSame($before, $this->owners->byGuestyId('tenant-a', 'O2')?->id);
    }

    public function testAFailureHalfwayThroughArchivesAndWritesNothing(): void
    {
        $this->connect();
        $this->queue([self::ok(self::page([self::owner('O1', 'Alice'), self::owner('O2', 'Bob')], 2))], [self::ok(self::page([self::listing('L1', 'Villa', 'O1')], 1))]);
        $this->sync();

        // Propriétaires lus en entier, mais la page 2 des logements échoue : aucune écriture, aucun archivage.
        $this->queue(
            [self::ok(self::page([self::owner('O1', 'Alice Renommée')], 1))],
            [self::ok(self::page([self::listing('L1', 'Villa', 'O1')], 2)), new Response(500)],
            fieldLookup: false,
        );
        $response = $this->sync();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('integration_unreachable', $this->errorCode($response));
        self::assertSame(['Alice', 'Bob'], array_column($this->listOf('/owners'), 'name'));
        self::assertCount(1, $this->listOf('/properties'));
    }

    public function testAnExpiredTokenIsRenewedOnceThenTheCallIsRetried(): void
    {
        $this->connect();
        $this->thirdParty->append(
            self::ok(['_id' => 'acc-1']),
            new Response(401),                       // le jeton en cache est refusé
            self::token('fresh-token'),              // nouveau jeton
            self::ok(['_id' => 'acc-1']),            // sonde avec le nouveau jeton
            self::ok(self::page([self::owner('O1', 'Alice')], 1)),
            self::ok(['_id' => 'acc-1']),
            self::ok(self::page([], 0)),
        );

        $report = $this->report($this->sync());

        self::assertSame(1, $report['owners']['created']);
        $last = $this->thirdPartyCalls->getArrayCopy();
        self::assertSame('Bearer fresh-token', $last[count($last) - 3]['request']->getHeaderLine('Authorization'));
    }

    public function testASecond401IsNotRetriedForever(): void
    {
        $this->connect();
        $this->thirdParty->append(self::ok(['_id' => 'acc-1']), new Response(401), self::token('fresh'), self::ok(['_id' => 'acc-1']), new Response(401));

        $response = $this->sync();

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('integration_invalid_credentials', $this->errorCode($response));
    }

    public function testGuestyRateLimitingIsReported(): void
    {
        $this->connect();
        $this->thirdParty->append(self::ok(['_id' => 'acc-1']), new Response(429));

        $response = $this->sync();

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('integration_rate_limited', $this->errorCode($response));
    }

    public function testManagersCanSyncAndRead(): void
    {
        $this->connect();
        $this->queue([self::ok(self::page([self::owner('O1', 'Alice')], 1))], [self::ok(self::page([self::listing('L1', 'Villa', 'O1')], 1))]);

        $this->report($this->sync('marc@a.test'));

        self::assertCount(1, $this->listOf('/owners', 'marc@a.test'));
        self::assertCount(1, $this->listOf('/properties', 'marc@a.test'));
    }

    public function testTenantsNeverSeeEachOthersData(): void
    {
        $this->connect();
        $this->queue([self::ok(self::page([self::owner('O1', 'Alice')], 1))], [self::ok(self::page([self::listing('L1', 'Villa', 'O1')], 1))]);
        $this->sync();

        // B n'a rien synchronisé : il ne voit rien de A.
        self::assertSame([], $this->listOf('/owners', 'bob@b.test'));
        self::assertSame([], $this->listOf('/properties', 'bob@b.test'));

        // Mêmes identifiants Guesty chez B : lignes distinctes, A reste intact.
        $this->connect('bob@b.test');
        $this->queue([self::ok(self::page([self::owner('O1', 'Autre')], 1))], [self::ok(self::page([self::listing('L1', 'Chalet', 'O1')], 1))]);
        $this->sync('bob@b.test');

        self::assertSame(['Alice'], array_column($this->listOf('/owners'), 'name'));
        self::assertSame(['Autre'], array_column($this->listOf('/owners', 'bob@b.test'), 'name'));
        self::assertSame(['Chalet'], array_column($this->listOf('/properties', 'bob@b.test'), 'name'));
        $a = $this->properties->byGuestyId('tenant-a', 'L1');
        $b = $this->properties->byGuestyId('tenant-b', 'L1');
        self::assertNotSame($a->id ?? '', $b->id ?? '');
        self::assertSame($this->owners->byGuestyId('tenant-a', 'O1')?->id, $a->ownerId ?? 'x');
    }

    public function testNightlyCommandSyncsEveryTenantAndReportsFailures(): void
    {
        $this->connect();
        $this->connect('bob@b.test');
        $this->tenants->integrations = ['tenant-a' => [Provider::Guesty], 'tenant-b' => [Provider::Guesty]];
        $this->queue([self::ok(self::page([self::owner('O1', 'Alice')], 1))], [self::ok(self::page([self::listing('L1', 'Villa', 'O1')], 1))]);
        $this->thirdParty->append(self::ok(['_id' => 'acc-1']), new Response(500)); // tenant B : Guesty en panne

        $container = $this->app->getContainer();
        self::assertNotNull($container);
        $command = $container->get(SyncGuestyCommand::class);
        self::assertInstanceOf(SyncGuestyCommand::class, $command);
        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('tenant-a : propriétaires +1', $tester->getDisplay());
        self::assertStringContainsString('tenant-b : échec (integration_unreachable)', $tester->getDisplay());
        self::assertNotNull($this->owners->byGuestyId('tenant-a', 'O1'));
        self::assertNull($this->owners->byGuestyId('tenant-b', 'O1'));
    }
}
